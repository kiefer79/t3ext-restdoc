<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with TYPO3 source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\Restdoc\Hooks;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Causal\Restdoc\Utility\RestHelper;

/**
 * Storing the documentation structure into the database for integration
 * within EXT:dd_googlesitemap.
 *
 * @category    Hooks
 * @package     TYPO3
 * @subpackage  tx_restdoc
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class TableOfContents
{

    /**
     * Maximum number of timestamps to save
     */
    const MAX_ENTRIES = 5;

    /**
     * Max age of a cache entry without having been refreshed
     */
    const CACHE_MAX_AGE = 7776000;    // 86400 * 30 * 3 = 3 months

    /** @var tx_restdoc_pi1 */
    protected $pObj;

    /** @var string */
    protected $root;

    /** @var integer */
    protected $pageId;

    /** @var integer */
    protected $maxDocuments;

    /** @var array */
    protected $processedDocuments = array();

    /**
     * Stores the TOC into database for future inclusion within EXT:dd_googlesitemap.
     *
     * @param array $params
     * @return void
     */
    public function postProcessOutput(array $params)
    {
        $this->pObj = $params['pObj'];

        if ($params['mode'] !== 'BODY') {
            return;
        }

        $storage = $this->pObj->getSphinxReader()->getStorage();
        if ($storage !== null) {
            $storageConfiguration = $storage->getConfiguration();
            $basePath = rtrim($storageConfiguration['basePath'], '/') . '/';
        } else {
            // FAL is not used
            $basePath = PATH_site;
        }

        $this->maxDocuments = \TYPO3\CMS\Core\Utility\MathUtility::forceIntegerInRange($params['config']['documentStructureMaxDocuments'], 1, 9999);
        $this->pageId = intval($this->pObj->cObj->data['pid']);
        $this->root = substr($params['documentRoot'], strlen($basePath));
        $this->flushCache();

        $this->extractLinks($params);
    }

    /**
     * Extracts the links recursively.
     *
     * @param array $params
     * @return void
     */
    protected function extractLinks(array $params)
    {
        $jsonFile = substr($params['document'], 0, -1) . '.fjson';
        $filename = $params['documentRoot'] . $jsonFile;
        if (!is_file($filename)) {
            return;
        }

        $lastModification = filemtime($filename);
        $content = file_get_contents($filename);
        $checksum = md5($content);

        $data = array(
            'tstamp' => $GLOBALS['ACCESS_TIME'],
        );

        /** @var QueryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_restdoc_toc');
        $cachedData = $queryBuilder
            ->select('*')
            ->from('tx_restdoc_toc')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->quote($this->pageId, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'document',
                    $queryBuilder->quote($params['document'], \PDO::PARAM_STR)
                )
            )
            ->execute()
            ->fetchAll();

        $add = !is_array($cachedData);
        $refresh = (!$add && $cachedData['checksum'] !== $checksum);
        if ($add) {
            $modifications = array();
            $data['pid'] = $this->pageId;
            $data['root'] = $this->root;
            $data['document'] = $params['document'];
            $data['url'] = $this->pObj->getLink($params['document'], true);
        } else {
            $modifications = GeneralUtility::intExplode(',', $cachedData['lastmod'], true);
        }
        if ($add || $refresh) {
            $modifications[] = $lastModification;
            $jsonData = json_decode($content, true);
            $data['title'] = isset($jsonData['title']) ? $jsonData['title'] : '';
            $data['checksum'] = $checksum;

            $links = $this->processToc($content, $params);
            foreach ($links as $doc => $href) {
                if (!in_array($doc, $this->processedDocuments)) {
                    $this->processedDocuments[] = $doc;
                    if ($doc !== $params['document']) {
                        $p2 = $params;
                        $p2['document'] = $doc;
                        $this->extractLinks($p2);
                    }
                }
                if (count($this->processedDocuments) >= $this->maxDocuments) {
                    break;
                }
            }
        }

        if (count($modifications) > self::MAX_ENTRIES) {
            $modifications = array_slice($modifications, -self::MAX_ENTRIES);
        }
        $data['lastmod'] = implode(',', $modifications);
        $data['crdate'] = $lastModification;

        if ($add) {

            /** @var QueryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_restdoc_toc');
            $queryBuilder
                ->insert('tx_restdoc_toc')
                ->values($data)
                ->execute();

        } elseif ($refresh) {

            /** @var QueryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_restdoc_toc');
            $updateQuery = $queryBuilder
                ->update('tx_restdoc_toc')
                ->where(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->quote($this->pageId, \PDO::PARAM_INT)
                    )
                );
            foreach($data as $key => $value) {
                $updateQuery->set($key, $queryBuilder->quote($value));
            }
            $updateQuery->execute();

        }

    }

    /**
     * Processes the TOC and its subpages and store an absolute URL to the page.
     *
     * @param string $content
     * @param array $params
     * @return array
     */
    protected function processToc($content, array $params)
    {
        $jsonData = json_decode($content, true);
        $links = $this->extractToc($jsonData, $params);
        return $links;
    }

    /**
     * Extracts the table of contents.
     *
     * @param array $jsonData
     * @param array $params
     * @return array
     * @see tx_restdoc_pi1::generateTableOfContents()
     */
    protected function extractToc(array $jsonData, array $params)
    {
        // Replace links in table of contents
        $toc = $this->replaceLinks($params['documentRoot'], $params['document'], $jsonData['toc']);
        // Remove empty sublevels
        $toc = preg_replace('#<ul>\s*</ul>#', '', $toc);
        // Fix TOC to make it XML compliant
        $toc = preg_replace_callback('# href="([^"]+)"#', function ($matches) {
            $url = str_replace('&amp;', '&', $matches[1]);
            $url = str_replace('&', '&amp;', $url);
            return ' href="' . $url . '"';
        }, $toc);

        if (!$toc) {
            return array();
        }

        $links = $this->getLinksFromToc(RestHelper::xmlstr_to_array($toc));

        if (isset($jsonData['prev'])) {
            $absolute = RestHelper::relativeToAbsolute($params['documentRoot'] . $params['document'], '../' . $jsonData['prev']['link']);
            $document = substr($absolute, strlen($params['documentRoot']));
            if (!isset($links[$document])) {
                $links[$document] = $this->pObj->getLink($document, true);
            }
        }

        if (isset($jsonData['next'])) {
            $document = $params['document'] === $this->pObj->getDefaultFile() . '/' ? $params['documentRoot'] : $params['documentRoot'] . $params['document'];
            $absolute = RestHelper::relativeToAbsolute($document, '../' . $jsonData['next']['link']);
            $document = substr($absolute, strlen($params['documentRoot']));
            if (!isset($links[$document])) {
                $links[$document] = $this->pObj->getLink($document, true);
            }
        }

        return $links;
    }

    /**
     * Returns a flat-list of menu entries along with the corresponding document name.
     *
     * @param array $entries
     * @return array
     * @see tx_restdoc_utility::getMenuData()
     */
    public function getLinksFromToc(array $entries)
    {
        $menu = array();
        $entries = isset($entries['li'][0]) ? $entries['li'] : array($entries['li']);
        foreach ($entries as $entry) {
            $document = $entry['a']['@attributes']['data-document'];
            if (strpos($document, '#') !== false) {
                $document = substr($document, 0, strpos($document, '#'));
            }
            $href = $entry['a']['@attributes']['href'];
            if (strpos($href, '#') !== false) {
                $href = substr($href, 0, strpos($href, '#'));
            }
            $menu[$document] = $href;
            if (isset($entry['ul'])) {
                $submenu = $this->getLinksFromToc($entry['ul']);
                $menu = array_merge($menu, $submenu);
            }
        }

        return $menu;
    }

    /**
     * Replaces links in a reST document using ABSOLUTE URL.
     *
     * @param string $root
     * @param string $document
     * @param string $content
     * @return string
     * @see tx_restdoc_pi1::replaceLinks()
     */
    protected function replaceLinks($root, $document, $content)
    {
        $plugin = $this->pObj;
        $ret = preg_replace_callback('#(<a .*? href=")([^"]+)#', function ($matches) use ($plugin, $root, $document) {
            /** @var $plugin tx_restdoc_pi1 */
            $anchor = '';
            if (preg_match('#^[a-zA-Z]+://#', $matches[2])) {
                // External URL
                return $matches[0];
            } elseif ($matches[2]{0} === '#') {
                $anchor = $matches[2];
            }

            if ($anchor !== '') {
                $document .= $anchor;
            } else {
                // $document's last part is a document, not a directory
                $document = substr($document, 0, strrpos(rtrim($document, '/'), '/'));
                $absolute = RestHelper::relativeToAbsolute($root . $document, $matches[2]);
                $document = substr($absolute, strlen($root));
            }
            $url = $plugin->getLink($document, true);
            $url = str_replace('&amp;', '&', $url);
            $url = str_replace('&', '&amp;', $url);
            return $matches[1] . $url . '" data-document="' . $document;
        }, $content);
        return $ret;
    }

    /**
     * Flushes cache of a given plugin if root changed.
     *
     * @return void
     */
    protected function flushCache()
    {
        /** @var QueryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_restdoc_toc');
        $queryBuilder
            ->delete('tx_restdoc_toc')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->quote($this->pageId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->neq('root', $queryBuilder->quoteIdentifier('tx_restdoc_toc')),
                    $queryBuilder->expr()->lte(
                        'tstamp',
                        $queryBuilder->quote(($GLOBALS['ACCESS_TIME'] - self::CACHE_MAX_AGE), \PDO::PARAM_INT)
                    )
                )
            )
            ->execute();
    }

}

<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Xavier Perseguers <xavier@causal.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Plugin 'reST Documentation Viewer' for the 'restdoc' extension.
 *
 * @category    Plugin
 * @package     TYPO3
 * @subpackage  tx_restdoc
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 * @version     SVN: $Id$
 */
class tx_restdoc_pi1 extends tslib_pibase {

	public $prefixId      = 'tx_restdoc_pi1';
	public $scriptRelPath = 'pi1/class.tx_restdoc_pi1.php';
	public $extKey        = 'restdoc';
	public $pi_checkCHash = TRUE;

	/** @var string */
	protected static $defaultFile = 'index';

	/** @var array */
	public $renderingConfig = array();

	/**
	 * Current chapter information as static to be accessible from
	 * TypoScript when coming back to generate menu entries
	 *
	 * @var array
	 */
	protected static $current = array();

	/**
	 * The main method of the Plugin.
	 *
	 * @param string $content The plugin content
	 * @param array $conf The plugin configuration
	 * @return string The content that is displayed on the website
	 */
	public function main($content, array $conf) {
		$this->init($conf);
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_USER_INT_obj = 1;    // USER_INT object

		$documentRoot = PATH_site . rtrim($this->conf['path'], '/') . '/';
		$document = self::$defaultFile . '/';
		$pathSeparators = isset($this->conf['fallbackPathSeparators']) ? t3lib_div::trimExplode(',', $this->conf['fallbackPathSeparators'], TRUE) : array();
		$pathSeparators[] = $this->conf['pathSeparator'];
		if (isset($this->piVars['doc']) && strpos($this->piVars['doc'], '..') === FALSE) {
			$document = str_replace($pathSeparators, '/', $this->piVars['doc']) . '/';
		}

		$jsonFile = substr($document, 0, strlen($document) - 1) . '.fjson';
		if (!is_file($documentRoot . $jsonFile)) {
			$document = self::$defaultFile . '/';
			$jsonFile = self::$defaultFile . '.fjson';
		}
		if (!is_file($documentRoot . $jsonFile)) {
			return 'Invalid path for the reST documentation: ' . $this->conf['path'];
		}

			// Security check
		if (substr(realpath($documentRoot . $jsonFile), 0, strlen(realpath($documentRoot))) !== realpath($documentRoot)) {
			$document = self::$defaultFile . '/';
			$jsonFile = self::$defaultFile . '.fjson';
		}

		$content = file_get_contents($documentRoot . $jsonFile);
		$jsonData = json_decode($content, TRUE);
		$skipDefaultWrap = FALSE;

		self::$current = array(
			'documentRoot'  => $documentRoot,
			'document'      => $document,
			'jsonData'      => $jsonData,
			'jsonData'      => $jsonData,
			'path'          => $this->conf['path'],
			'pathSeparator' => $this->conf['pathSeparator'],
		);

		if (!isset($jsonData['genindexentries'])) {
			switch ($this->conf['mode']) {
				case 'TOC':
					$this->renderingConfig = $this->conf['setup.']['TOC.'];
					$output = $this->cObj->cObjGetSingle($this->renderingConfig['renderObj'], $this->renderingConfig['renderObj.']);
					break;
				case 'RECENT':
					$this->renderingConfig = $this->conf['setup.']['RECENT.'];
					$output = $this->cObj->cObjGetSingle($this->renderingConfig['renderObj'], $this->renderingConfig['renderObj.']);
					break;
				case 'BODY':
					if ($this->conf['advertiseSphinx']) {
						$GLOBALS['TSFE']->additionalJavaScript[$this->prefixId . '_sphinx'] = <<<JS
	var DOCUMENTATION_OPTIONS = {
		URL_ROOT:    '',
		VERSION:     '1.0.0',
		COLLAPSE_INDEX: false,
		FILE_SUFFIX: '.html',
		HAS_SOURCE:  false
	};
JS;
					}
					$output = $this->generateBody();
					break;
				case 'TITLE':
					$output = isset($jsonData['title']) ? $jsonData['title'] : '';
					$skipDefaultWrap = TRUE;
					break;
				case 'QUICK_NAVIGATION':
					$output = $this->generateQuickNavigation();
					break;
				case 'BREADCRUMB':
					$this->renderingConfig = $this->conf['setup.']['BREADCRUMB.'];
					$output = $this->cObj->cObjGetSingle($this->renderingConfig['renderObj'], $this->renderingConfig['renderObj.']);
					break;
				case 'FILENAME':
					$output = $jsonFile;
					$skipDefaultWrap = TRUE;
					break;
				default:
					$output = '';
					break;
			}
		} else {
			switch ($this->conf['mode']) {
				case 'BODY':
					// Generating output for the general index
					$output = $this->generateIndex($documentRoot, $document, $jsonData);
					break;
				case 'TITLE':
					$output = 'Index';
					$skipDefaultWrap = TRUE;
					break;
				case 'FILENAME':
					$output = $jsonFile;
					$skipDefaultWrap = TRUE;
					break;
				default:
					// Generating TOC, ... for the root document instead
					$this->piVars['doc'] = '';
					return $this->main('', $conf);
			}
		}

			// Hook for post-processing the output
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['renderHook'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['renderHook'] as $classRef) {
				$hookObject = t3lib_div::getUserObj($classRef);
				$params = array(
					'mode' => $this->conf['mode'],
					'documentRoot' => $documentRoot,
					'document' => $document,
					'output' => &$output,
					'config' => $this->conf,
					'pObj' => $this,
				);
				if (is_callable(array($hookObject, 'postProcessOutput'))) {
					$hookObject->postProcessOutput($params);
				}
			}
		}

		// Wrap the whole result, with baseWrap if defined, else with standard pi_wrapInBaseClass() call
		if (isset($this->conf['baseWrap.'])) {
			$output = $this->cObj->stdWrap($output, $this->conf['baseWrap.']);
		} elseif (!$skipDefaultWrap) {
			$output = $this->pi_wrapInBaseClass($output);
		}

		return $output;
	}

	/**
	 * Returns the default file.
	 *
	 * @return string
	 */
	public function getDefaultFile() {
		return self::$defaultFile;
	}

	/**
	 * Generates the array for rendering the reST menu in TypoScript.
	 *
	 * @param string $content
	 * @param array $conf
	 * @return array
	 */
	public function makeMenuArray($content, array $conf) {
		$data = array();
		$type = isset($conf['userFunc.']['type']) ? $conf['userFunc.']['type'] : 'menu';

		$documentRoot = self::$current['documentRoot'];
		$document = self::$current['document'];
		$jsonData = self::$current['jsonData'];

		switch ($type) {
			case 'menu':
				// Replace links in table of contents
				$toc = $this->replaceLinks($documentRoot, $document, $jsonData['toc']);
				// Remove empty sublevels
				$toc = preg_replace('#<ul>\s*</ul>#', '', $toc);
				// Fix TOC to make it XML compliant
				$toc = preg_replace_callback('# href="([^"]+)"#', function($matches) {
					$url = str_replace('&amp;', '&', $matches[1]);
					$url = str_replace('&', '&amp;', $url);
					return ' href="' . $url . '"';
				}, $toc);

				$data = tx_restdoc_utility::getMenuData(tx_restdoc_utility::xmlstr_to_array($toc));

				// Mark the first entry as 'active'
				$data[0]['ITEM_STATE'] = 'CUR';
				break;

			case 'previous':
				if (isset($jsonData['prev'])) {
					$absolute = tx_restdoc_utility::relativeToAbsolute($documentRoot . $document, '../' . $jsonData['prev']['link']);
					$link = $this->getLink(substr($absolute, strlen(self::$current['documentRoot'])));
					$data[] = array(
						'title' => $jsonData['prev']['title'],
						'_OVERRIDE_HREF' => $link,
					);
				}
				break;

			case 'next':
				if (isset($jsonData['next'])) {
					$nextDocument = $document === $this->getDefaultFile() . '/' ? $documentRoot : $documentRoot . $document;
					$absolute = tx_restdoc_utility::relativeToAbsolute($nextDocument, '../' . $jsonData['next']['link']);
					$link = $this->getLink(substr($absolute, strlen($documentRoot)));
					$data[] = array(
						'title' => $jsonData['next']['title'],
						'_OVERRIDE_HREF' => $link,
					);
				}
				break;

			case 'breadcrumb':
				foreach ($jsonData['parents'] as $parent) {
					$absolute = tx_restdoc_utility::relativeToAbsolute($documentRoot . $document, '../' . $parent['link']);
					$link = $this->getLink(substr($absolute, strlen($documentRoot)));
					$data[] = array(
						'title' => $parent['title'],
						'_OVERRIDE_HREF' => $link,
					);
				}
				// Add current page to breadcrumb menu
				$data[] = array(
					'title' => $jsonData['title'],
					'_OVERRIDE_HREF' => $this->getLink($document),
					'ITEM_STATE' => 'CUR',
				);
				break;

			case 'updated':
				$limit = t3lib_utility_Math::forceIntegerInRange($conf['limit'], 0, 100);	// max number of items
				$maxAge = intval(tslib_cObj::calc($conf['maxAge']));
				$sortField = 'crdate';
				$extraWhere = '';
				if (!empty($conf['excludeChapters'])) {
					$excludeChapters = array_map(
						function ($chapter) {
							return $GLOBALS['TYPO3_DB']->fullQuoteStr($chapter, 'tx_restdoc_toc');
						},
						t3lib_div::trimExplode(',', $conf['excludeChapters'])
					);
					if (count($excludeChapters) > 0) {
						$extraWhere .= ' AND document NOT IN (' . implode(',', $excludeChapters) . ')';
					}
				}
				if ($maxAge > 0) {
					$extraWhere .= ' AND ' . $sortField . '>' . ($GLOBALS['SIM_ACCESS_TIME'] - $maxAge);
				}
				$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
					'*',
					'tx_restdoc_toc',
					'root=' . $GLOBALS['TYPO3_DB']->fullQuoteStr(substr($documentRoot, strlen(PATH_site)), 'tx_restdoc_toc') .
						$extraWhere,
					'',
					$sortField . ' DESC',
					$limit
				);
				foreach ($rows as $row) {
					$data[] = array(
						'title' => $row['title'] ?: '[no title]',
						'_OVERRIDE_HREF' => $row['url'],
						'SYS_LASTCHANGED' => $row[$sortField],
					);
				}
				break;
		}

		// Hook for post-processing the menu entries
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['makeMenuArrayHook'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['makeMenuArrayHook'] as $classRef) {
				$hookObject = t3lib_div::getUserObj($classRef);
				$params = array(
					'documentRoot' => self::$current['documentRoot'],
					'document' => self::$current['document'],
					'data' => &$data,
					'pObj' => $this,
				);
				if (is_callable(array($hookObject, 'postProcessMakeMenuArray'))) {
					$hookObject->postProcessTOC($params);
				}
			}
		}

		return $data;
	}

	/**
	 * Generates the Quick Navigation.
	 *
	 * @return string
	 */
	protected function generateQuickNavigation() {
		$this->renderingConfig = $this->conf['setup.']['QUICK_NAVIGATION.'];

		$documentRoot = self::$current['documentRoot'];
		$document = self::$current['document'];
		$jsonData = self::$current['jsonData'];

		$data = array();
		$data['home_title'] = 'home';
		$data['home_uri'] = $this->getLink('');

		if (isset($jsonData['prev'])) {
			$absolute = tx_restdoc_utility::relativeToAbsolute($documentRoot . $document, '../' . $jsonData['prev']['link']);
			$link = $this->getLink(substr($absolute, strlen($documentRoot)));

			$data['previous_title'] = $jsonData['prev']['title'];
			$data['previous_uri']   = $link;
		}

		if (isset($jsonData['next'])) {
			$nextDocument = $document === $this->getDefaultFile() . '/' ? $documentRoot : $documentRoot . $document;
			$absolute = tx_restdoc_utility::relativeToAbsolute($nextDocument, '../' . $jsonData['next']['link']);
			$link = $this->getLink(substr($absolute, strlen($documentRoot)));

			$data['next_title'] = $jsonData['next']['title'];
			$data['next_uri']   = $link;
		}

		if (is_file($documentRoot . 'genindex.fjson')) {
			$link = $this->getLink('genindex/');

			$data['index_title'] = 'index';
			$data['index_uri'] = $link;
		}

		$data['has_previous'] = !empty($data['previous_uri']) ? 1 : 0;
		$data['has_next']     = !empty($data['next_uri'])     ? 1 : 0;
		$data['has_index']    = !empty($data['index_uri'])    ? 1 : 0;

		// Hook for post-processing the quick navigation
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['quickNavigationHook'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['quickNavigationHook'] as $classRef) {
				$hookObject = t3lib_div::getUserObj($classRef);
				$params = array(
					'documentRoot' => $documentRoot,
					'document' => $document,
					'data' => &$data,
					'pObj' => $this,
				);
				if (is_callable(array($hookObject, 'postProcessQUICK_NAVIGATION'))) {
					$hookObject->postProcessQUICK_NAVIGATION($params);
				}
			}
		}

		/** @var $contentObj tslib_cObj */
		$contentObj = t3lib_div::makeInstance('tslib_cObj');
		$contentObj->start($data);
		$output = $contentObj->cObjGetSingle($this->renderingConfig['renderObj'], $this->renderingConfig['renderObj.']);

		return $output;
	}

	/**
	 * Generates the general index.
	 *
	 * @param string $documentRoot
	 * @param string $document
	 * @param array $jsonData
	 * @return string
	 */
	protected function generateIndex($documentRoot, $document, array $jsonData) {
		$linksCategories = array();
		$contentCategories = array();

		foreach ($jsonData['genindexentries'] as $indexGroup) {
			$category = $indexGroup[0];
			$anchor = 'tx-restdoc-index-' . htmlspecialchars($category);

			$conf = array(
				$this->prefixId => array(
					'doc' => str_replace('/', $this->conf['pathSeparator'], substr($document, 0, strlen($document) - 1)),
				)
			);
			$link = $this->pi_getPageLink($GLOBALS['TSFE']->id, '', $conf);
			$link .= '#' . $anchor;

			$linksCategories[] = '<a href="' . $link . '"><strong>' . htmlspecialchars($category) . '</strong></a>';

			$contentCategory = '<h2 id="' . $anchor . '">' . htmlspecialchars($category) . '</h2>' . LF;
			$contentCategory .= '<div class="tx-restdoc-genindextable">' . LF;
			$contentCategory .= $this->getIndexDefinitionList($documentRoot, $indexGroup[1]);
			$contentCategory .= '</div>' . LF;

			$contentCategories[] = $contentCategory;
		}

		$output = '<h1>Index</h1>' . LF;
		$output .= '<div class="tx-restdoc-genindex-jumpbox">' . implode(' | ', $linksCategories) . '</div>' . LF;
		$output .= implode(LF, $contentCategories);

		return $output;
	}

	/**
	 * Returns an index definition list as HTML.
	 *
	 * @param string $documentRoot
	 * @param array $index
	 * @return string
	 */
	protected function getIndexDefinitionList($documentRoot, array $index) {
		$output = '<dl>' . LF;
		foreach ($index as $dt) {
			$relativeLinks = array();
			for ($i = 0; $i < count($dt[1]); $i++) {
				if (!empty($dt[1][$i]) && t3lib_div::isFirstPartOfStr($dt[1][$i][1], '../')) {
					$relativeLinks[] = array(
						'title' => $dt[1][$i][0],
						'link'  => substr($dt[1][$i][1], 3),
					);
				} elseif ($i == 0 && !empty($dt[1][$i]) && is_array($dt[1][$i][0]) && t3lib_div::isFirstPartOfStr($dt[1][$i][0][1], '../')) {
					$relativeLinks[] = array(
						'title' => $dt[1][$i][0][0],
						'link'  => substr($dt[1][$i][0][1], 3),
					);
				} else {
					// No more entry links, we have subentries from now on
					break;
				}
			}
			// Remove category links from the list of subentries, first subentry is always a link, possibly empty
			for ($i = 0; $i < max(1, count($relativeLinks)); $i++) {
				array_shift($dt[1]);
			}

			$output .= '<dt>';
			if ($relativeLinks) {
				for ($i = 0; $i < count($relativeLinks); $i++) {
					if ($i == 0) {
						$title = htmlspecialchars($dt[0]);
					} else {
						$output .= ', ';
						$title = '[' . $i . ']';
					}
					if ($relativeLinks[$i]['title'] === 'main') {
						$title = '<strong>' . $title . '</strong>';
					}
					$link = $this->getLink($relativeLinks[$i]['link']);
					$link = str_replace('&amp;', '&', $link);
					$link = str_replace('&', '&amp;', $link);

					$output .= '<a href="' . $link . '">' . $title . '</a>';
				}
			} else {
				$output .= htmlspecialchars($dt[0]);
			}
			$output .= '</dt>' . LF;

			if ($dt[1]) {
				$output .= '<dd>' . LF;
				foreach ($dt[1] as $term) {
					$output .= $this->getIndexDefinitionList($documentRoot, $term);
				}
				$output .= '</dd>' . LF;
			}
		}
		$output .= '</dl>' . LF;

		return $output;
	}

	/**
	 * Generates the Body.
	 *
	 * @return string
	 */
	protected function generateBody() {
		$this->renderingConfig = $this->conf['setup.']['BODY.'];
		$body = self::$current['jsonData']['body'];
		if (!$this->conf['showPermanentLink']) {
			// Remove permanent links in body
			$body = preg_replace('#<a class="headerlink" [^>]+>[^<]+</a>#', '', $body);
		}
			// Replace links in body
		$body = $this->replaceLinks(self::$current['documentRoot'], self::$current['document'], $body);
			// Replace images in body
		$body = $this->replaceImages(self::$current['documentRoot'] . self::$current['document'], $body);

		return $body;
	}

	/**
	 * Generates a link to navigate within a reST documentation project.
	 *
	 * @param string $document Target document
	 * @param boolean $absolute Whether absolute URI should be generated
	 * @return string
	 * @private This method is made public to be accessible from a lambda-function scope
	 */
	public function getLink($document, $absolute = FALSE) {
		$urlParameters = array();
		$anchor = '';
		if ($document !== '') {
			if (($pos = strrpos($document, '#')) !== FALSE) {
				$anchor = substr($document, $pos + 1);
				$document = substr($document, 0, $pos);
			}
			$doc = str_replace('/', self::$current['pathSeparator'], substr($document, 0, strlen($document) - 1));
			if ($doc) {
				$urlParameters = array(
					$this->prefixId => array(
						'doc' => $doc,
					)
				);
			}
		}
		if (substr($document, 0, 11) === '_downloads/') {
			$link = $this->cObj->typoLink_URL(array('parameter' => rtrim(self::$current['path'], '/') . '/' . $document));
		} else {
			//
			$typolinkConfig = array(
				'parameter' => $GLOBALS['TSFE']->id,
				'forceAbsoluteUrl' => $absolute ? 1 : 0,
				'forceAbsoluteUrl.' => array(
					'scheme' => t3lib_div::getIndpEnv('TYPO3_SSL') ? 'https' : 'http',
				),
				'returnLast' => 'url',
			);
			if ($urlParameters) {
				$typolinkConfig['additionalParams'] = t3lib_div::implodeArrayForUrl('', $urlParameters);
			}
			$link = $this->cObj->typoLink('', $typolinkConfig);
			if ($anchor !== '') {
				$link .= '#' . $anchor;
			}
		}
		return $link;
	}

	/**
	 * Replaces links in a reST document.
	 *
	 * @param string $root
	 * @param string $document
	 * @param string $content
	 * @return string
	 */
	protected function replaceLinks($root, $document, $content) {
		$plugin = $this;
		$ret = preg_replace_callback('#(<a .*? href=")([^"]+)#', function($matches) use ($plugin, $root, $document) {
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
				$defaultDocument = $plugin->getDefaultFile() . '/';
				if ($document === $defaultDocument || t3lib_div::isFirstPartOfStr($matches[2], '../')) {
					// $document's last part is a document, not a directory
					$document = substr($document, 0, strrpos(rtrim($document, '/'), '/'));
				}
				$absolute = tx_restdoc_utility::relativeToAbsolute($root . $document, $matches[2]);
				$document = substr($absolute, strlen($root));
			}
			$url = $plugin->getLink($document);
			$url = str_replace('&amp;', '&', $url);
			$url = str_replace('&', '&amp;', $url);
			return $matches[1] . $url;
		}, $content);
		return $ret;
	}

	/**
	 * Replaces images in a reST document.
	 *
	 * @param string $root absolute root of the document
	 * @param string $content
	 * @return string
	 * @link http://w-shadow.com/blog/2009/10/20/how-to-extract-html-tags-and-their-attributes-with-php/
	 */
	protected function replaceImages($root, $content) {
			// $root's last part is a document, not a directory
		$root = substr($root, 0, strrpos(rtrim($root, '/'), '/'));
		$plugin = $this;
		$tagPattern =
			'@<img                      # <img
			(?P<attributes>\s[^>]+)?    # attributes, if any
			\s*/>                       # />
			@xsi';
		$attributePattern =
			'@
				(?P<name>\w+)                                           # attribute name
				\s*=\s*
				(
					(?P<quote>[\"\'])(?P<value_quoted>.*?)(?P=quote)    # a quoted value
					|                                                   # or
					(?P<value_unquoted>[^\s"\']+?)(?:\s+|$)             # an unquoted value (terminated by whitespace or EOF)
				)
			@xsi';

		$ret = preg_replace_callback($tagPattern, function($matches) use ($plugin, $root, $attributePattern) {
				// Parse tag attributes, if any
			$attributes = array();
			if (!empty($matches['attributes'][0])) {
				if (preg_match_all($attributePattern, $matches['attributes'], $attributeData, PREG_SET_ORDER)) {
						// Turn the attribute data into a name->value array
					foreach ($attributeData as $attr) {
						if (!empty($attr['value_quoted'])) {
							$value = $attr['value_quoted'];
						} elseif (!empty($attr['value_unquoted'])) {
							$value = $attr['value_unquoted'];
						} else {
							$value = '';
						}

						$value = html_entity_decode( $value, ENT_QUOTES, 'utf-8');
						$attributes[$attr['name']] = $value;
					}
				}
			}
			$src = tx_restdoc_utility::relativeToAbsolute($root, $attributes['src']);
			$attributes['src'] = substr($src, strlen(PATH_site));

			/** @var $contentObj tslib_cObj */
			$contentObj = t3lib_div::makeInstance('tslib_cObj');
			$contentObj->start($attributes);
			return $contentObj->cObjGetSingle($plugin->renderingConfig['image.']['renderObj'], $plugin->renderingConfig['image.']['renderObj.']);
		}, $content);

		return $ret;
	}

	/**
	 * Applies stdWrap to a given key in a configuration array.
	 *
	 * @param array &$conf
	 * @param string $baseKey
	 * @return void
	 */
	protected function applyStdWrap(array &$conf, $baseKey) {
		if (isset($conf[$baseKey . '.'])) {
			$conf[$baseKey] = $this->cObj->stdWrap($conf[$baseKey], $conf[$baseKey . '.']);
			unset($conf[$baseKey . '.']);
		}
	}

	/**
	 * This method performs various initializations.
	 *
	 * @param array $conf: Plugin configuration, as received by the main() method
	 * @return void
	 */
	protected function init(array $conf) {
		$this->conf = $conf;

			// Apply stdWrap on a few TypoScript configuration options
		if (isset($this->conf['setup.'])) {
			$this->applyStdWrap($this->conf['setup.'], 'defaultFile');
		}
		$this->applyStdWrap($this->conf, 'path');
		$this->applyStdWrap($this->conf, 'mode');
		$this->applyStdWrap($this->conf, 'showPermanentLink');
		$this->applyStdWrap($this->conf, 'pathSeparator');
		$this->applyStdWrap($this->conf, 'fallbackPathSeparators');
		$this->applyStdWrap($this->conf, 'documentStructureMaxDocuments');
		$this->applyStdWrap($this->conf, 'advertiseSphinx');

			// Load the flexform and loop on all its values to override TS setup values
			// Some properties use a different test (more strict than not empty) and yet some others no test at all
			// see http://wiki.typo3.org/index.php/Extension_Development,_using_Flexforms
		$this->pi_initPIflexForm(); // Init and get the flexform data of the plugin

			// Assign the flexform data to a local variable for easier access
		$piFlexForm = $this->cObj->data['pi_flexform'];

		if (is_array($piFlexForm['data'])) {
			$multiValueKeys = array();
				// Traverse the entire array based on the language
				// and assign each configuration option to $this->settings array...
			foreach ($piFlexForm['data'] as $sheet => $data) {
				foreach ($data as $lang => $value) {
					/** @var $value array */
					foreach ($value as $key => $val) {
						$value = $this->pi_getFFvalue($piFlexForm, $key, $sheet);
						if (trim($value) !== '' && in_array($key, $multiValueKeys)) {
							// Funny, FF contains a comma-separated list of key|value and
							// we only want to have key...
							$tempValues = explode(',', $value);
							$tempKeys = array();
							foreach ($tempValues as $tempValue) {
								list($k, $v) = explode('|', $tempValue);
								$tempKeys[] = $k;
							}
							$value = implode(',', $tempKeys);
						}
						if (trim($value) !== '' || !isset($this->conf[$key])) {
							$this->conf[$key] = $value;
						}
					}
				}
			}
		}

		if (isset($this->conf['setup.']['defaultFile'])) {
			self::$defaultFile = $this->conf['setup.']['defaultFile'];
		}
		if (empty($this->conf['pathSeparator'])) {
			// The path separator CANNOT be empty
			$this->conf['pathSeparator'] = '|';
		}
	}

}


if (defined('TYPO3_MODE') && isset($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/restdoc/pi1/class.tx_restdoc_pi1.php'])) {
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/restdoc/pi1/class.tx_restdoc_pi1.php']);
}

?>
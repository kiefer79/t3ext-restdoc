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

namespace Causal\Restdoc\Controller\Pi1;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Class that adds the wizard icon.
 *
 * @category    Plugin
 * @package     TYPO3
 * @subpackage  tx_restdoc
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class WizardIcon
{

    /**
     * Processes the wizard items array.
     *
     * @param array $wizardItems The wizard items
     * @return array Modified array with wizard items
     */
    public function proc(array $wizardItems)
    {

        $LL = $this->includeLocalLang();
        $wizardIcon = 'Resources/Public/Icons/pi1_ce_wizard.png';

        $wizardItem = array(
            'title' => $GLOBALS['LANG']->getLLL('pi1_title', $LL),
            'description' => $GLOBALS['LANG']->getLLL('pi1_plus_wiz_description', $LL),
            'params' => '&defVals[tt_content][CType]=list&defVals[tt_content][list_type]=restdoc_pi1'
        );
        if (version_compare(TYPO3_version, '7.5', '>=')) {
            /** @var \TYPO3\CMS\Core\Imaging\IconRegistry $iconRegistry */
            $iconRegistry = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Imaging\\IconRegistry');
            $iconRegistry->registerIcon('extensions-restdoc-wizard',
                'TYPO3\\CMS\\Core\\Imaging\\IconProvider\\BitmapIconProvider',
                array(
                    'source' => 'EXT:restdoc/' . $wizardIcon,
                )
            );
            $wizardItem['iconIdentifier'] = 'extensions-restdoc-wizard';
        } else {
            $wizardItem['icon'] = ExtensionManagementUtility::extPath('restdoc') . $wizardIcon;
        }

        $wizardItems['plugins_tx_restdoc_pi1'] = $wizardItem;
        return $wizardItems;
    }

    /**
     * Reads the extension locallang.xml and returns the $LOCAL_LANG array found in that file.
     *
     * @return array The array with language labels
     */
    protected function includeLocalLang()
    {
        $llFile = ExtensionManagementUtility::extPath('restdoc') . 'Resources/Private/Language/locallang.xlf';
        return $GLOBALS['LANG']->includeLLFile($llFile, false);
    }

}

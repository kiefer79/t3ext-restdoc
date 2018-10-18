<?php
defined('TYPO3_MODE') || die();

$GLOBALS['TCA']['tt_content']['types']['list']['subtypes_excludelist'][$_EXTKEY . '_pi1'] = 'layout,select_key,pages';

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPlugin([
            'LLL:EXT:restdoc/Resources/Private/Language/locallang_db.xml:tt_content.list_type_pi1',
            $_EXTKEY . '_pi1',
            \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($_EXTKEY) . 'ext_icon.gif'
        ],
        'list_type',
        $_EXTKEY
    );

// Register the FlexForms
$GLOBALS['TCA']['tt_content']['types']['list']['subtypes_addlist'][$_EXTKEY . '_pi1'] = 'pi_flexform';
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPiFlexFormValue($_EXTKEY . '_pi1', 'FILE:EXT:' . $_EXTKEY . '/Configuration/FlexForms/flexform_pi1.xml');

// Initialize static extension templates
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile($GLOBALS['_EXTKEY'], 'Configuration/TypoScript/', 'Sphinx Documentation Viewer Plugin');

if (TYPO3_MODE === 'BE') {
    $GLOBALS['TBE_MODULES_EXT']['xMOD_db_new_content_el']['addElClasses']['Causal\\Restdoc\\Controller\\Pi1\\WizardIcon'] = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($_EXTKEY) . 'Classes/Controller/Pi1/WizardIcon.php';
}

<?php
defined('TYPO3_MODE') || die();

$config = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
    \TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class
)->get('restdoc');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPItoST43($_EXTKEY, 'Classes/Controller/Pi1/Pi1Controller.php', '_pi1', 'list_type', (bool)$config['cache_plugin_output']);

// Register new TypoScript content object
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_content.php']['cObjTypeAndClass'][] = array(
    0 => 'REST_METADATA',
    1 => 'Causal\\Restdoc\\ContentObject\\RestMetadataContentObject',
);

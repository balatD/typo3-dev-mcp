<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Three columns that do not exist in vanilla core. F1 benchmark tasks ask which
// tt_content columns are non-core, so these must stay distinguishable by prefix.
$GLOBALS['TCA']['tt_content']['columns'] += [
    'tx_benchfixture_teaser' => [
        'label' => 'Benchmark teaser',
        'description' => 'Short teaser rendered above the card body.',
        'config' => [
            'type' => 'input',
            'size' => 40,
            'max' => 255,
            'eval' => 'trim',
        ],
    ],
    'tx_benchfixture_level' => [
        'label' => 'Benchmark level',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => 'Muted', 'value' => 0],
                ['label' => 'Regular', 'value' => 10],
                ['label' => 'Prominent', 'value' => 20],
                ['label' => 'Hero', 'value' => 30],
            ],
            'default' => 10,
        ],
    ],
    'tx_benchfixture_related' => [
        'label' => 'Related page',
        'config' => [
            'type' => 'group',
            'allowed' => 'pages',
            'size' => 1,
            'maxitems' => 1,
        ],
    ],
];

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'Benchmark card',
        'value' => 'benchfixture_card',
        'group' => 'default',
    ],
);

$GLOBALS['TCA']['tt_content']['types']['benchfixture_card'] = [
    'showitem' => '
        --div--;General,
            --palette--;;general,
            header,
            tx_benchfixture_teaser,
            tx_benchfixture_level,
            tx_benchfixture_related,
            bodytext,
        --div--;Appearance,
            --palette--;;frames,
        --div--;Access,
            --palette--;;hidden,
            --palette--;;access,
    ',
    'columnsOverrides' => [
        'bodytext' => [
            'config' => [
                'enableRichtext' => true,
            ],
        ],
    ],
];

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'Benchmark listing',
        'value' => 'benchfixture_listing',
        'group' => 'plugins',
    ],
);

$isV14 = (new Typo3Version())->getMajorVersion() >= 14;

// A two-sheet FlexForm, so `flexform_schema` has a non-trivial data structure
// to resolve rather than only core's.
if (!$isV14) {
    ExtensionManagementUtility::addPiFlexFormValue(
        '*',
        'FILE:EXT:bench_fixture/Configuration/FlexForms/BenchPlugin.xml',
        'benchfixture_listing',
    );
}

$GLOBALS['TCA']['tt_content']['types']['benchfixture_listing'] = [
    'showitem' => '
        --div--;General,
            --palette--;;general,
            header,
            pi_flexform,
        --div--;Access,
            --palette--;;hidden,
            --palette--;;access,
    ',
];

// v14 keeps the data structure on the type itself, so it can only be set after
// the type definition above, which would otherwise overwrite it.
if ($isV14) {
    $GLOBALS['TCA']['tt_content']['types']['benchfixture_listing']['columnsOverrides']['pi_flexform']['config']['ds']
        = 'FILE:EXT:bench_fixture/Configuration/FlexForms/BenchPlugin.xml';
}

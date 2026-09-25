<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

$tca = [
    'ctrl' => [
        'title' => 'Benchmark item',
        'label' => 'title',
        'label_alt' => 'subtitle',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'weight',
        'versioningWS' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'translationSource' => 'l10n_source',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'searchFields' => 'title,subtitle',
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'subtitle' => [
            'label' => 'Subtitle',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'weight' => [
            'label' => 'Sorting weight',
            'config' => [
                'type' => 'number',
                'default' => 0,
            ],
        ],
        'is_featured' => [
            'label' => 'Featured',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;General,
                    title, subtitle, weight, is_featured,
                --div--;Access,
                    hidden, starttime, endtime,
            ',
        ],
    ],
];

// v14 dropped searchFields and logs a deprecation for it on every TCA rebuild.
if ((new Typo3Version())->getMajorVersion() >= 14) {
    unset($tca['ctrl']['searchFields']);
}

return $tca;

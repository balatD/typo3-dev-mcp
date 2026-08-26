<?php

declare(strict_types=1);

$config = \TYPO3\CodingStandards\CsFixerConfig::create();

// Scoped to the shipped and tested source: the finder must not walk vendor/,
// public/ or var/, all of which exist in this repo because it doubles as a
// TYPO3 install for the DDEV harness.
$config->getFinder()->in([
    __DIR__ . '/Classes',
    __DIR__ . '/Configuration',
    __DIR__ . '/Tests/Unit',
    __DIR__ . '/Tests/Functional',
]);

return $config;

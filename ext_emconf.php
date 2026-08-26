<?php

/*
 * Required by the TER only — TYPO3 v13/v14 in Composer mode reads the extension
 * key and constraints from composer.json. The version here is the one place a
 * version number is committed; `vendor/bin/tailor set-version <v>` keeps it in
 * sync with the release tag, and the release workflow refuses to publish when
 * the two disagree.
 */
$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 Dev MCP',
    'description' => 'AI development helper for TYPO3 v13/v14 — an MCP server exposing TCA, database schema, sites, TypoScript, logs and the core changelog to Claude Code and other MCP clients.',
    'category' => 'be',
    'author' => 'Dragan Balatinac',
    'author_company' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'BalatD\\DevMcp\\' => 'Classes/',
        ],
    ],
];

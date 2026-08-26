<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Compiles the frontend TypoScript for a page — the same result the frontend
 * middleware produces, minus request-dependent condition verdicts.
 *
 * Built on the v13.3+ FrontendTypoScriptFactory, which core marks @internal;
 * failures therefore degrade to an explanatory error instead of leaking
 * version drift to the client.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class TypoScriptTool implements ToolInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SysTemplateRepository $sysTemplateRepository,
        private readonly FrontendTypoScriptFactory $frontendTypoScriptFactory,
    ) {}

    public function getName(): string
    {
        return 'typoscript';
    }

    public function getDescription(): string
    {
        return 'Compiled frontend TypoScript for a page (site sets + sys_template resolved, conditions '
            . 'evaluated without request context). Section "setup" (default), "constants" (flat settings) '
            . 'or "config". Without "path": top-level keys only — pass a dot-path like "page.10" or '
            . '"plugin.tx_myext" to drill in.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => [
                    'type' => 'integer',
                    'description' => 'Page uid to compile TypoScript for (default: root page of the first site)',
                ],
                'section' => [
                    'type' => 'string',
                    'enum' => ['setup', 'constants', 'config'],
                    'description' => 'Which compiled section to return (default "setup")',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Dot-path into the section, e.g. "page.10" — omit to list top-level keys',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $section = (string)($arguments['section'] ?? 'setup');
        $path = trim((string)($arguments['path'] ?? ''), '. ');

        $pageId = isset($arguments['pageId']) ? (int)$arguments['pageId'] : null;
        if ($pageId === null) {
            $sites = $this->siteFinder->getAllSites();
            if ($sites === []) {
                throw new \RuntimeException('No sites configured (config/sites/ is empty).');
            }
            $pageId = reset($sites)->getRootPageId();
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            throw new \RuntimeException(
                'No site found for page ' . $pageId . '. Use site_info to see valid root pages.',
            );
        }

        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
            $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootline($rootline);

            $frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
                $site,
                $sysTemplateRows,
                [],
                null,
            );
            $frontendTypoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
                true,
                $frontendTypoScript,
                $site,
                $sysTemplateRows,
                [],
                '0',
                null,
                null,
            );

            $data = match ($section) {
                'constants' => $frontendTypoScript->getFlatSettings(),
                'config' => $frontendTypoScript->getConfigArray(),
                default => $frontendTypoScript->getSetupArray(),
            };
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'TypoScript compilation failed (the core factory API is @internal and may have drifted '
                . 'in this TYPO3 version): ' . $e->getMessage(),
            );
        }

        return $this->presentSection($data, $section, $pageId, $site->getIdentifier(), $path);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function presentSection(array $data, string $section, int $pageId, string $siteIdentifier, string $path): array
    {
        $result = [
            'pageId' => $pageId,
            'site' => $siteIdentifier,
            'section' => $section,
        ];

        if ($path !== '') {
            foreach (explode('.', $path) as $segment) {
                // compiled TypoScript arrays key branches as "name." and values as "name"
                if (\is_array($data) && \array_key_exists($segment . '.', $data)) {
                    $data = $data[$segment . '.'];
                } elseif (\is_array($data) && \array_key_exists($segment, $data)) {
                    $data = $data[$segment];
                } else {
                    throw new \RuntimeException(
                        'Path "' . $path . '" not found in ' . $section . ' (segment "' . $segment . '"). '
                        . 'Call without "path" to list the top-level keys.',
                    );
                }
            }

            $result['path'] = $path;
            $result['value'] = $data;

            return $result;
        }

        if ($section === 'constants') {
            // flat settings are already a compact key => value list
            $result['settings'] = $data;

            return $result;
        }

        $keys = [];
        foreach ($data as $key => $value) {
            $keys[rtrim((string)$key, '.')] = \is_array($value) ? 'tree' : 'value';
        }
        $result['topLevelKeys'] = $keys;
        $result['hint'] = 'Pass {"path": "<key>"} to get a subtree, e.g. {"path": "page"}.';

        return $result;
    }
}

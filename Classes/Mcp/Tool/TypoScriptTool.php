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
    private const MAX_LISTED_KEYS = 40;

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

        $sources = [
            'site sets' => $site->getConfiguration()['dependencies'] ?? [],
            'sys_template uids' => array_column($sysTemplateRows, 'uid'),
        ];

        return $this->presentSection($data, $section, $pageId, $site->getIdentifier(), $path, $sources);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<int|string>> $sources
     * @return array<string, mixed>
     */
    private function presentSection(array $data, string $section, int $pageId, string $siteIdentifier, string $path, array $sources): array
    {
        $result = [
            'pageId' => $pageId,
            'site' => $siteIdentifier,
            'section' => $section,
        ];

        if ($path !== '') {
            $result['path'] = $path;
            $result['value'] = $section === 'constants'
                ? $this->constantAt($data, $path)
                : $this->subtreeAt($data, $path, $section, $sources);

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

    /**
     * Constants are flat, fully dotted keys: a path is either one of them or a
     * prefix of several.
     *
     * @param array<string, mixed> $constants
     */
    private function constantAt(array $constants, string $path): mixed
    {
        if (\array_key_exists($path, $constants)) {
            return $constants[$path];
        }

        $prefix = $path . '.';
        $matches = array_filter($constants, static fn(string $key): bool => str_starts_with($key, $prefix), \ARRAY_FILTER_USE_KEY);
        if ($matches !== []) {
            return $matches;
        }

        $topLevel = array_unique(array_map(static fn(string $key): string => explode('.', $key)[0], array_keys($constants)));
        throw new \RuntimeException(
            'No constant matches "' . $path . '". Top-level prefixes: ' . $this->listKeys($topLevel) . '.',
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<int|string>> $sources
     */
    private function subtreeAt(array $data, string $path, string $section, array $sources): mixed
    {
        foreach (explode('.', $path) as $segment) {
            // compiled TypoScript arrays key branches as "name." and values as "name"
            if (\is_array($data) && \array_key_exists($segment . '.', $data)) {
                $data = $data[$segment . '.'];
            } elseif (\is_array($data) && \array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                $existing = \is_array($data)
                    ? 'Keys at that level: ' . $this->listKeys(array_map(static fn($key): string => rtrim((string)$key, '.'), array_keys($data)))
                    : 'The path ends at a value before that segment';
                throw new \RuntimeException(\sprintf(
                    'Path "%s" not found in %s (segment "%s"). %s. Loaded from %s.',
                    $path,
                    $section,
                    $segment,
                    $existing,
                    $this->describeSources($sources),
                ));
            }
        }

        return $data;
    }

    /**
     * @param array<array-key, string> $keys
     */
    private function listKeys(array $keys): string
    {
        $keys = array_values(array_unique($keys));
        sort($keys);
        if ($keys === []) {
            return 'none';
        }
        $shown = \array_slice($keys, 0, self::MAX_LISTED_KEYS);
        $more = \count($keys) - \count($shown);

        return implode(', ', $shown) . ($more > 0 ? ' (+' . $more . ' more)' : '');
    }

    /**
     * @param array<string, list<int|string>> $sources
     */
    private function describeSources(array $sources): string
    {
        $parts = [];
        foreach ($sources as $label => $values) {
            $parts[] = $label . ': ' . ($values === [] ? 'none' : implode(', ', $values));
        }

        return implode('; ', $parts);
    }
}

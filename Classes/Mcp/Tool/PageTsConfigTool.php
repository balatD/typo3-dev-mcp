<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Page TSconfig is only inspectable through a backend module — core ships no
 * CLI for it — so without this tool the resolved value of mod.web_layout,
 * TCEFORM or TCEMAIN for a given page is simply unreachable.
 *
 * Sibling of the typoscript tool; BackendUtility::getPagesTSconfig() is public
 * API and null-safe about $GLOBALS['BE_USER'], so it works in CLI.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class PageTsConfigTool implements ToolInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    public function getName(): string
    {
        return 'page_tsconfig';
    }

    public function getDescription(): string
    {
        return 'Resolved Page TSconfig for a page (the whole rootline plus site sets, merged). Without '
            . '"path": top-level keys only — pass a dot-path like "mod.web_layout", "TCEFORM.tt_content" '
            . 'or "TCEMAIN" to drill in. Page TSconfig only; user TSconfig needs a backend user.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => [
                    'type' => 'integer',
                    'description' => 'Page uid to resolve TSconfig for (default: root page of the first site; '
                        . '0 gives the site-independent global TSconfig)',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Dot-path into the TSconfig, e.g. "mod.wizards" — omit to list top-level keys',
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
        $path = trim((string)($arguments['path'] ?? ''), '. ');
        $pageId = isset($arguments['pageId']) ? (int)$arguments['pageId'] : $this->defaultPageId();

        try {
            $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Page TSconfig resolution failed for page ' . $pageId . ': ' . $e->getMessage()
                . ' Use site_info for valid root pages.',
            );
        }

        $result = ['pageId' => $pageId];

        if ($path !== '') {
            $result['path'] = $path;
            $result['value'] = $this->drillDown($tsConfig, $path, $pageId);

            return $result;
        }

        $keys = [];
        foreach ($tsConfig as $key => $value) {
            $keys[rtrim((string)$key, '.')] = \is_array($value) ? 'tree' : 'value';
        }
        ksort($keys);

        $result['topLevelKeys'] = $keys;
        $result['hint'] = $keys === []
            ? 'No Page TSconfig is active for this page.'
            : 'Pass {"path": "<key>"} to get a subtree, e.g. {"path": "TCEFORM"}.';

        return $result;
    }

    private function defaultPageId(): int
    {
        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            throw new \RuntimeException(
                'No sites configured (config/sites/ is empty). Pass {"pageId": 0} for the global TSconfig.',
            );
        }

        return reset($sites)->getRootPageId();
    }

    /**
     * @param array<string, mixed> $tsConfig
     */
    private function drillDown(array $tsConfig, string $path, int $pageId): mixed
    {
        $data = $tsConfig;

        foreach (explode('.', $path) as $segment) {
            // TSconfig arrays key branches as "name." and scalar values as "name"
            if (\is_array($data) && \array_key_exists($segment . '.', $data)) {
                $data = $data[$segment . '.'];
            } elseif (\is_array($data) && \array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                throw new \RuntimeException(
                    'Path "' . $path . '" not found in the Page TSconfig of page ' . $pageId
                    . ' (segment "' . $segment . '"). Call without "path" to list the top-level keys.',
                );
            }
        }

        return $data;
    }
}

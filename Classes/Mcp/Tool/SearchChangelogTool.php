<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Searches the RST changelog that ships inside typo3/cms-core, so results are
 * offline and exact for the installed TYPO3 version — the primary source for
 * "what changed / what is deprecated / how do I migrate".
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class SearchChangelogTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 200;

    private const TYPES = ['Breaking', 'Deprecation', 'Feature', 'Important'];

    /**
     * The removal is free text: "will be removed in TYPO3 v14.0", "removal in v15",
     * "will stop working in TYPO3 v15.0". Sentences wrap, hence \s+.
     */
    private const REMOVAL_PATTERN = '/\b(?:(?:will\s+be\s+)?(?:removed|removal)\s+(?:in|with|for|from)'
        . '|stop\s+working\s+(?:in|with))\s+(?:TYPO3\s+)?v?(\d+(?:\.\d+)?)\b/i';

    public function __construct(
        private readonly PackageManager $packageManager,
    ) {}

    public function getName(): string
    {
        return 'search_changelog';
    }

    public function getDescription(): string
    {
        return 'Search the core changelog shipped with the installed version (Breaking, Deprecation, '
            . 'Feature, Important). All words of "query" must match, in filename or content. The one entry '
            . 'named after the query carries its migration section; without "query", "version" lists that version\'s entries. '
            . 'Deprecations carry the version they stop working in where the entry states one.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search words, e.g. "GeneralUtility makeInstance", a class/hook name or an issue number',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => self::TYPES,
                    'description' => 'Only return entries of this changelog type',
                ],
                'version' => [
                    'type' => 'string',
                    'description' => 'Version prefix filter for the changelog folder, e.g. "13" or "13.4"',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'Maximum results (default ' . self::DEFAULT_LIMIT . ')',
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
        $query = trim((string)($arguments['query'] ?? ''));
        $typeFilter = $arguments['type'] ?? null;
        $versionFilter = isset($arguments['version']) && $arguments['version'] !== '' ? (string)$arguments['version'] : null;
        if ($query === '' && $versionFilter === null) {
            throw new \RuntimeException('Pass "query", or "version" to list the entries of a version.');
        }

        $words = array_values(array_filter(array_map(strtolower(...), preg_split('/\s+/', $query) ?: [])));
        $limit = min(self::MAX_LIMIT, max(1, (int)($arguments['limit'] ?? self::DEFAULT_LIMIT)));

        $changelogPath = $this->getChangelogPath();
        $files = $this->collectFiles($changelogPath, $typeFilter, $versionFilter);

        $named = [];
        if ($words === []) {
            usort($files, static fn(string $a, string $b): int => strnatcmp(\dirname($b), \dirname($a)) ?: strcmp($a, $b));
            $matches = $files;
        } else {
            $scored = [];
            foreach ($files as $file) {
                $score = $this->scoreFile($changelogPath, $file, $words);
                if ($score > 0) {
                    $scored[] = ['file' => $file, 'score' => $score];
                }
                if ($score === 3 * \count($words)) {
                    $named[] = $file;
                }
            }
            usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $matches = array_column($scored, 'file');
        }

        if ($matches === []) {
            return $this->describeMiss($changelogPath, $files, $words, $typeFilter !== null || $versionFilter !== null);
        }

        $matches = \array_slice($matches, 0, $limit);
        // Other entries often mention an API in passing; the one named after it is the answer.
        $withMigration = \count($matches) === 1 ? $matches[0] : (\count($named) === 1 ? $named[0] : null);

        $results = [];
        foreach ($matches as $match) {
            $results[] = $this->describeFile($changelogPath, $match, $match === $withMigration);
        }

        return [
            'resultCount' => \count($results),
            'results' => $results,
        ];
    }

    private function getChangelogPath(): string
    {
        $corePath = $this->packageManager->getPackage('core')->getPackagePath();
        $changelogPath = $corePath . 'Documentation/Changelog';

        if (!is_dir($changelogPath)) {
            throw new \RuntimeException(
                'Changelog directory not found at ' . $changelogPath
                . ' — is typo3/cms-core installed without documentation?',
            );
        }

        return $changelogPath;
    }

    /**
     * @return list<string> paths relative to the changelog root
     */
    private function collectFiles(string $changelogPath, ?string $typeFilter, ?string $versionFilter): array
    {
        $files = [];
        foreach (glob($changelogPath . '/*/*.rst') ?: [] as $absolutePath) {
            $versionDir = basename(\dirname($absolutePath));
            $fileName = basename($absolutePath);

            if ($versionFilter !== null && !str_starts_with($versionDir, $versionFilter)) {
                continue;
            }
            if ($typeFilter !== null && !str_starts_with($fileName, $typeFilter . '-')) {
                continue;
            }
            if (!preg_match('/^(' . implode('|', self::TYPES) . ')-/', $fileName)) {
                continue;
            }

            $files[] = $versionDir . '/' . $fileName;
        }

        return $files;
    }

    /**
     * Filename word hits weigh 3x content hits; every word must match somewhere.
     *
     * @param list<string> $words
     */
    private function scoreFile(string $changelogPath, string $relativePath, array $words): int
    {
        $fileNameLower = strtolower($relativePath);
        $content = null;
        $score = 0;

        foreach ($words as $word) {
            if (str_contains($fileNameLower, $word)) {
                $score += 3;
                continue;
            }

            $content ??= strtolower((string)@file_get_contents($changelogPath . '/' . $relativePath));
            if (str_contains($content, $word)) {
                $score += 1;
            } else {
                return 0;
            }
        }

        return $score;
    }

    /**
     * What each word reaches on its own, and — where a filter is set and emptied
     * the answer — what it reaches without the filter.
     *
     * @param list<string> $files
     * @param list<string> $words
     * @return array<string, mixed>
     */
    private function describeMiss(string $changelogPath, array $files, array $words, bool $filtered): array
    {
        $miss = [
            'resultCount' => 0,
            'results' => [],
            'wordMatches' => $this->countWordMatches($changelogPath, $files, $words),
        ];

        if ($filtered && \in_array(0, $miss['wordMatches'], true)) {
            $unfiltered = $this->countWordMatches($changelogPath, $this->collectFiles($changelogPath, null, null), $words);
            foreach ($miss['wordMatches'] as $word => $count) {
                if ($count === 0 && $unfiltered[$word] > 0) {
                    $miss['wordMatchesWithoutFilters'] = $unfiltered;
                    break;
                }
            }
        }

        return $miss;
    }

    /**
     * @param list<string> $files
     * @param list<string> $words
     * @return array<string, int>
     */
    private function countWordMatches(string $changelogPath, array $files, array $words): array
    {
        $counts = [];
        foreach ($words as $word) {
            $counts[$word] = \count(array_filter(
                $files,
                fn(string $file): bool => $this->scoreFile($changelogPath, $file, [$word]) > 0,
            ));
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeFile(string $changelogPath, string $relativePath, bool $withMigration): array
    {
        [$versionDir, $fileName] = explode('/', $relativePath, 2);
        preg_match('/^([A-Za-z]+)-(\d+)?/', $fileName, $nameParts);

        $content = (string)@file_get_contents($changelogPath . '/' . $relativePath);
        $lines = explode("\n", $content);
        $type = $nameParts[1] ?? null;

        return array_filter([
            'type' => $type,
            'issue' => isset($nameParts[2]) ? (int)$nameParts[2] : null,
            'version' => $versionDir,
            'title' => $this->extractTitle($lines),
            'removal' => $type === 'Deprecation' ? $this->extractRemoval($content, $versionDir) : null,
            'tags' => $this->extractTags($lines),
            'file' => $relativePath,
            'migration' => $withMigration ? $this->extractMigration($lines) : null,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The changelog headline sits between two ==== overline/underline rows.
     *
     * @param list<string> $lines
     */
    private function extractTitle(array $lines): ?string
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^=+\s*$/', $line) === 1) {
                $title = trim($lines[$index + 1] ?? '');
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return null;
    }

    /**
     * Only a version later than the entry's own counts: "removed with v5" means
     * Fluid standalone, and recaps name the release that already removed something.
     */
    private function extractRemoval(string $content, string $versionDir): ?string
    {
        preg_match_all(self::REMOVAL_PATTERN, $content, $matches);
        $ownVersion = str_replace('.x', '', $versionDir);
        foreach ($matches[1] as $stated) {
            if (version_compare($stated, $ownVersion, '>')) {
                return $stated;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function extractTags(array $lines): array
    {
        foreach ($lines as $line) {
            if (preg_match('/^\.\.\s+index::\s*(.*)$/', trim($line), $index) === 1) {
                return array_values(array_filter(array_map(trim(...), explode(',', $index[1]))));
            }
        }

        return [];
    }

    /**
     * The "Migration" section up to the next ===-underlined heading or the index line,
     * subsections and code blocks included.
     *
     * @param list<string> $lines
     */
    private function extractMigration(array $lines): ?string
    {
        $start = null;
        foreach ($lines as $index => $line) {
            $underlined = preg_match('/^=+\s*$/', $lines[$index + 1] ?? '') === 1;
            if ($start === null) {
                if (trim($line) === 'Migration' && $underlined) {
                    $start = $index + 2;
                }
                continue;
            }
            if (preg_match('/^\.\.\s+index::/', trim($line)) === 1 || (trim($line) !== '' && $underlined)) {
                return trim(implode("\n", \array_slice($lines, $start, $index - $start)));
            }
        }

        return $start === null ? null : trim(implode("\n", \array_slice($lines, $start)));
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Searches the RST changelog that ships inside typo3/cms-core, so results are
 * offline and exact for the installed TYPO3 version — the primary source for
 * "what changed / what is deprecated / how do I migrate".
 */
final class SearchChangelogTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;

    private const TYPES = ['Breaking', 'Deprecation', 'Feature', 'Important'];

    public function __construct(
        private readonly PackageManager $packageManager,
    ) {
    }

    public function getName(): string
    {
        return 'search_changelog';
    }

    public function getDescription(): string
    {
        return 'Search the core changelog shipped with the installed version (Breaking, Deprecation, '
            . 'Feature, Important) — including the migration path for a changed or removed API. '
            . 'All words of "query" must match, in filename or content.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search words, e.g. "GeneralUtility makeInstance" or a class/hook name',
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
                    'maximum' => 30,
                    'description' => 'Maximum results (default ' . self::DEFAULT_LIMIT . ')',
                ],
            ],
            'required' => ['query'],
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
        if ($query === '') {
            throw new \RuntimeException('Argument "query" must not be empty.');
        }

        $words = array_values(array_filter(array_map(strtolower(...), preg_split('/\s+/', $query) ?: [])));
        $typeFilter = $arguments['type'] ?? null;
        $versionFilter = isset($arguments['version']) ? (string)$arguments['version'] : null;
        $limit = min(30, max(1, (int)($arguments['limit'] ?? self::DEFAULT_LIMIT)));

        $changelogPath = $this->getChangelogPath();
        $files = $this->collectFiles($changelogPath, $typeFilter, $versionFilter);

        $matches = [];
        foreach ($files as $file) {
            $score = $this->scoreFile($changelogPath, $file, $words);
            if ($score > 0) {
                $matches[] = ['file' => $file, 'score' => $score];
            }
        }

        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $matches = \array_slice($matches, 0, $limit);

        $results = [];
        foreach ($matches as $match) {
            $results[] = $this->describeFile($changelogPath, $match['file'], $words);
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
     * @param list<string> $words
     * @return array<string, mixed>
     */
    private function describeFile(string $changelogPath, string $relativePath, array $words): array
    {
        [$versionDir, $fileName] = explode('/', $relativePath, 2);
        preg_match('/^([A-Za-z]+)-(\d+)?/', $fileName, $nameParts);

        $content = (string)@file_get_contents($changelogPath . '/' . $relativePath);
        $lines = explode("\n", $content);

        return array_filter([
            'type' => $nameParts[1] ?? null,
            'issue' => isset($nameParts[2]) ? (int)$nameParts[2] : null,
            'version' => $versionDir,
            'title' => $this->extractTitle($lines),
            'excerpt' => $this->extractExcerpt($lines, $words),
            'file' => $relativePath,
        ], static fn (mixed $value): bool => $value !== null);
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
     * @param list<string> $words
     * @param list<string> $lines
     */
    private function extractExcerpt(array $lines, array $words): ?string
    {
        foreach ($lines as $index => $line) {
            $lineLower = strtolower($line);
            foreach ($words as $word) {
                if (str_contains($lineLower, $word)) {
                    $excerpt = trim(implode("\n", \array_slice($lines, max(0, $index - 1), 4)));

                    return mb_substr($excerpt, 0, 400);
                }
            }
        }

        return null;
    }
}

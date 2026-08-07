<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheGroupException;

/**
 * The one state-changing convenience tool: after code/TCA/TypoScript changes
 * the AI must flush caches anyway — better a dedicated tool than a shell
 * detour.
 */
final class FlushCacheTool implements ToolInterface
{
    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function getName(): string
    {
        return 'flush_cache';
    }

    public function getDescription(): string
    {
        return 'Flush TYPO3 caches. Without arguments all caches are flushed. With "group" only that '
            . 'cache group is flushed (typically "pages" after content/TypoScript changes, "system" '
            . 'after configuration/DI changes).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'group' => [
                    'type' => 'string',
                    'description' => 'Cache group to flush, e.g. "pages" or "system" (default: all caches)',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function execute(array $arguments): mixed
    {
        $group = $arguments['group'] ?? null;

        if (\is_string($group) && $group !== '') {
            try {
                $this->cacheManager->flushCachesInGroup($group);
            } catch (NoSuchCacheGroupException $e) {
                throw new \RuntimeException($e->getMessage());
            }

            return ['flushed' => 'group:' . $group];
        }

        $this->cacheManager->flushCaches();

        return ['flushed' => 'all'];
    }
}

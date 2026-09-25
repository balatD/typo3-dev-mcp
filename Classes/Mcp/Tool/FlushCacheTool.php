<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\Typo3Cli;
use BalatD\DevMcp\Mcp\ToolInterface;

/**
 * The one state-changing convenience tool: after code/TCA/TypoScript changes
 * the AI must flush caches anyway — better a dedicated tool than a shell
 * detour.
 *
 * Runs core's `cache:flush` rather than the CacheManager: only the command
 * also flushes the dependency-injection caches, without which new listeners
 * and services never appear.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class FlushCacheTool implements ToolInterface
{
    public function __construct(
        private readonly Typo3Cli $cli,
    ) {}

    public function getName(): string
    {
        return 'flush_cache';
    }

    public function getDescription(): string
    {
        return 'Flush TYPO3 caches. No arguments: all caches. "group": just that group — "pages" after '
            . 'content/TypoScript changes, "system" after configuration/DI changes. Editing PHP inside a '
            . 'class (renaming, refactoring) needs no flush.';
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
        $hasGroup = \is_string($group) && $group !== '';

        $process = $this->cli->run($hasGroup ? ['cache:flush', '--group=' . $group] : ['cache:flush']);
        if (!$process->isSuccessful()) {
            // Console errors arrive as a padded, multi-line block.
            throw new \RuntimeException(trim((string)preg_replace('/\s+/', ' ', $process->getErrorOutput() . ' ' . $process->getOutput())));
        }

        return ['flushed' => $hasGroup ? 'group:' . $group : 'all'];
    }
}

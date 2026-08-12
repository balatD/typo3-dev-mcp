<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LogEntryParser;
use BalatD\DevMcp\Mcp\Support\LogReader;
use BalatD\DevMcp\Mcp\ToolInterface;

/**
 * Boost analog: last-error.
 */
final class LastErrorTool implements ToolInterface
{
    public function __construct(
        private readonly LogReader $logReader,
        private readonly LogEntryParser $logEntryParser,
    ) {
    }

    public function getName(): string
    {
        return 'last_error';
    }

    public function getDescription(): string
    {
        return 'Get the most recent error-level entry from the TYPO3 file logs. Call this right after '
            . 'something failed (a 500 page, a broken backend module, a failed request) to see the actual '
            . 'exception instead of guessing. Returns the exception class, code, file, line, message and the '
            . 'first few stack frames; pass "full" for the complete raw entry.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'full' => [
                    'type' => 'boolean',
                    'description' => 'Return the raw log entry including the complete stack trace (large)',
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
        $files = $this->logReader->listLogFiles(false);
        $entries = $this->logReader->readEntries($files, 1, 'error');

        if ($entries === []) {
            return [
                'error' => null,
                'hint' => 'No error-level entries found in var/log/typo3_*.log.',
            ];
        }

        return ['error' => $this->logEntryParser->parse($entries[0], (bool)($arguments['full'] ?? false))];
    }
}

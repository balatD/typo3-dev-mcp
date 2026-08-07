<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use T3Boost\Mcp\Support\LogReader;
use T3Boost\Mcp\ToolInterface;

/**
 * Boost analog: last-error.
 */
final class LastErrorTool implements ToolInterface
{
    public function __construct(
        private readonly LogReader $logReader,
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
            . 'exception instead of guessing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
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

        return ['error' => $entries[0]];
    }
}

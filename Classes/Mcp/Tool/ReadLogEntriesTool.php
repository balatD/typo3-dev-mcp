<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LogEntryParser;
use BalatD\DevMcp\Mcp\Support\LogReader;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Boost analog: read-log-entries, extended with TYPO3's deprecation log
 * and the sys_log table as additional sources.
 */
final class ReadLogEntriesTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly LogReader $logReader,
        private readonly LogEntryParser $logEntryParser,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getName(): string
    {
        return 'read_log_entries';
    }

    public function getDescription(): string
    {
        return 'Recent TYPO3 log entries, newest first. Source "file" (default) reads var/log/typo3_*.log, '
            . '"deprecations" the deprecation log, "syslog" backend errors/actions from the sys_log table. '
            . '"level" filters to that severity or worse. Exceptions come back structured with a shortened '
            . 'stack trace; "full" returns complete raw entries.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'enum' => ['file', 'deprecations', 'syslog'],
                    'description' => 'Log source to read (default "file")',
                ],
                'n' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Number of entries to return (default ' . self::DEFAULT_LIMIT . ')',
                ],
                'level' => [
                    'type' => 'string',
                    'enum' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
                    'description' => 'Minimum severity; only entries at this level or worse are returned',
                ],
                'full' => [
                    'type' => 'boolean',
                    'description' => 'Return raw log entries including complete stack traces (very large)',
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
        $source = (string)($arguments['source'] ?? 'file');
        $limit = min(200, max(1, (int)($arguments['n'] ?? self::DEFAULT_LIMIT)));
        $level = isset($arguments['level']) ? (string)$arguments['level'] : null;

        if ($source === 'syslog') {
            return $this->readSysLog($limit);
        }

        $files = $this->logReader->listLogFiles($source === 'deprecations');
        if ($files === []) {
            return [
                'entries' => [],
                'hint' => $source === 'deprecations'
                    ? 'No deprecation log files found — the "deprecations" log writer is probably disabled in settings.php.'
                    : 'No log files found in var/log/.',
            ];
        }

        $entries = $this->logReader->readEntries($files, $limit, $level);

        // Unparsed, a single TYPO3 exception entry measures ~16 KB, of which 96%
        // is stack trace. At the default limit of 20 that is a third of a
        // megabyte for one call, so trimming matters far more here than it does
        // for last_error.
        $full = (bool)($arguments['full'] ?? false);
        $parsed = array_map(
            fn (array $entry): array => $this->logEntryParser->parse($entry, $full),
            $entries,
        );

        return [
            'source' => $source,
            'entryCount' => \count($parsed),
            'entries' => $parsed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readSysLog(int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $rows = $queryBuilder
            ->select('uid', 'tstamp', 'type', 'error', 'details', 'log_data', 'tablename', 'recuid')
            ->from('sys_log')
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $details = (string)$row['details'];
            // sys_log stores printf-style placeholders with serialized substitution data
            $logData = @unserialize((string)$row['log_data'], ['allowed_classes' => false]);
            if (\is_array($logData) && $logData !== [] && str_contains($details, '%')) {
                try {
                    $details = vsprintf($details, array_map(
                        static fn (mixed $value): string => \is_scalar($value) ? (string)$value : (string)json_encode($value),
                        $logData,
                    ));
                } catch (\Throwable) {
                    // placeholder/argument mismatch — keep the raw message
                }
            }

            $entries[] = [
                'timestamp' => date('c', (int)$row['tstamp']),
                'error' => (int)$row['error'],
                'message' => $details,
                'table' => $row['tablename'] ?: null,
                'recordUid' => $row['recuid'] ?: null,
            ];
        }

        return [
            'source' => 'syslog',
            'entryCount' => \count($entries),
            'entries' => $entries,
        ];
    }
}

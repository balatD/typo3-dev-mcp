<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use T3Boost\Mcp\Support\LogReader;
use T3Boost\Mcp\ToolInterface;
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
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getName(): string
    {
        return 'read_log_entries';
    }

    public function getDescription(): string
    {
        return 'Read the most recent TYPO3 log entries, newest first. Source "file" (default) reads '
            . 'var/log/typo3_*.log, "deprecations" reads the deprecation log (useful when preparing '
            . 'upgrades), "syslog" reads backend errors/actions from the sys_log database table. '
            . 'Use "level" to only get entries of that severity or worse (e.g. "warning").';
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

        return [
            'source' => $source,
            'entryCount' => \count($entries),
            'entries' => $entries,
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
                $formatted = @vsprintf($details, array_map(strval(...), $logData));
                if ($formatted !== false) {
                    $details = $formatted;
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

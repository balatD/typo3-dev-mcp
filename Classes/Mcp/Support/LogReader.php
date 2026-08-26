<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Support;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Tail-reads TYPO3 file logs (var/log/typo3_*.log) into structured entries.
 *
 * Only the last TAIL_BYTES of each file are read so multi-hundred-MB dev
 * logs cannot blow up tool responses.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class LogReader
{
    private const TAIL_BYTES = 262144;

    private const LEVEL_RANKS = [
        'EMERGENCY' => 0,
        'ALERT' => 1,
        'CRITICAL' => 2,
        'ERROR' => 3,
        'WARNING' => 4,
        'NOTICE' => 5,
        'INFO' => 6,
        'DEBUG' => 7,
    ];

    /**
     * @return list<string> log file paths, newest first
     */
    public function listLogFiles(bool $deprecationLogs): array
    {
        $logDirectory = Environment::getVarPath() . '/log';
        $files = glob($logDirectory . '/typo3_*.log') ?: [];

        $files = array_values(array_filter(
            $files,
            static fn(string $file): bool => str_contains(basename($file), 'deprecations') === $deprecationLogs,
        ));

        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }

    /**
     * @param list<string> $files newest first
     * @return list<array{timestamp: ?string, level: string, message: string, file: string}> newest first
     */
    public function readEntries(array $files, int $limit, ?string $minLevel = null): array
    {
        $maxRank = $minLevel !== null
            ? (self::LEVEL_RANKS[strtoupper($minLevel)] ?? 7)
            : 7;

        $entries = [];
        foreach ($files as $file) {
            foreach (array_reverse($this->parseFile($file)) as $entry) {
                if (self::LEVEL_RANKS[$entry['level']] > $maxRank) {
                    continue;
                }
                $entry['file'] = basename($file);
                $entries[] = $entry;
                if (\count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<array{timestamp: ?string, level: string, message: string}> in file order
     */
    private function parseFile(string $file): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            $size = filesize($file) ?: 0;
            $truncated = $size > self::TAIL_BYTES;
            if ($truncated) {
                fseek($handle, $size - self::TAIL_BYTES);
                fgets($handle); // drop the partial first line
            }

            $entries = [];
            $current = null;
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                if (preg_match('/^(.*?)\[(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG)\]\s*(.*)$/', $line, $matches) === 1) {
                    if ($current !== null) {
                        $entries[] = $current;
                    }
                    $current = [
                        'timestamp' => trim($matches[1]) !== '' ? trim($matches[1]) : null,
                        'level' => $matches[2],
                        'message' => $matches[3],
                    ];
                } elseif ($current !== null) {
                    // continuation line (stack trace etc.) belongs to the previous entry
                    $current['message'] .= "\n" . $line;
                }
            }
            if ($current !== null) {
                $entries[] = $current;
            }

            return $entries;
        } finally {
            fclose($handle);
        }
    }
}

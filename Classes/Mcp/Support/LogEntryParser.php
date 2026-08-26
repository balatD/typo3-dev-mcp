<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Support;

/**
 * Splits a raw TYPO3 log line into the parts an agent actually needs.
 *
 * TYPO3 writes one error as a single line ending in a JSON context blob whose
 * "exception" key holds the complete stack trace. Measured on a real Fluid
 * exception that is 13.8 KB of a 16 KB entry — 96% of the payload, ~6,600
 * tokens, for a trace whose first few frames carry the diagnosis. Returning it
 * verbatim also keeps it in the conversation for every later turn.
 *
 * Everything else in the context (exception class, code, file, line, message,
 * request URL) is small and worth keeping, so this trims the trace rather than
 * the entry.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class LogEntryParser
{
    public const DEFAULT_TRACE_FRAMES = 5;

    /**
     * Matches TYPO3's exception-handler summary:
     * "Core: Exception handler (WEB: FE): <Class>, code #<code>, file <file>, line <line>: <message>"
     */
    private const EXCEPTION_PATTERN = '/(?P<class>[\w\\\\]+),\s*code\s*#(?P<code>\d+),\s*file\s*(?P<file>.+?),\s*line\s*(?P<line>\d+):\s*(?P<message>.*)$/s';

    /**
     * @param array{timestamp: ?string, level: string, message: string, file: string} $entry
     * @return array<string, mixed>
     */
    public function parse(array $entry, bool $full = false, int $traceFrames = self::DEFAULT_TRACE_FRAMES): array
    {
        if ($full) {
            return $entry;
        }

        $parsed = [
            'timestamp' => $entry['timestamp'],
            'level' => $entry['level'],
            'logFile' => $entry['file'],
        ];

        $context = $this->extractContext($entry['message']);
        $summary = $this->stripContext($entry['message']);

        // The JSON context is the richer source when present; fall back to
        // regexing the summary for log writers that omit it.
        $exception = $this->exceptionFromContext($context) ?? $this->exceptionFromSummary($summary);
        if ($exception !== null) {
            $parsed['exception'] = $exception;
        } else {
            $parsed['message'] = $this->truncate($summary, 2000);
        }

        if (isset($context['request_url']) && \is_string($context['request_url'])) {
            $parsed['requestUrl'] = $context['request_url'];
        }
        foreach (['mode', 'application_mode'] as $key) {
            if (isset($context[$key]) && \is_string($context[$key])) {
                $parsed[$key === 'mode' ? 'mode' : 'applicationMode'] = $context[$key];
            }
        }

        $trace = isset($context['exception']) && \is_string($context['exception'])
            ? $this->trimTrace($context['exception'], $traceFrames)
            : null;
        if ($trace !== null) {
            $parsed['trace'] = $trace['frames'];
            if ($trace['omitted'] > 0) {
                $parsed['traceOmittedFrames'] = $trace['omitted'];
                $parsed['hint'] = 'Stack trace truncated. Pass {"full": true} for the complete entry.';
            }
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractContext(string $message): array
    {
        $start = strpos($message, ' - {');
        if ($start === false) {
            return [];
        }

        $decoded = json_decode(substr($message, $start + 3), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function stripContext(string $message): string
    {
        $start = strpos($message, ' - {');
        $summary = $start === false ? $message : substr($message, 0, $start);

        // Drop the "request=... component=...:" prefix; the request id is noise
        // and the component is implied by the exception class.
        if (preg_match('/^request="[^"]*"\s+component="[^"]*":\s*(.*)$/s', $summary, $m) === 1) {
            return trim($m[1]);
        }

        return trim($summary);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function exceptionFromContext(array $context): ?array
    {
        if (!isset($context['exception_class']) || !\is_string($context['exception_class'])) {
            return null;
        }

        $exception = ['class' => $context['exception_class']];
        foreach (['exception_code' => 'code', 'file' => 'file', 'line' => 'line', 'message' => 'message'] as $from => $to) {
            if (isset($context[$from]) && (\is_string($context[$from]) || \is_int($context[$from]))) {
                $exception[$to] = $context[$from];
            }
        }

        return $exception;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exceptionFromSummary(string $summary): ?array
    {
        if (preg_match(self::EXCEPTION_PATTERN, $summary, $m) !== 1) {
            return null;
        }

        return [
            'class' => $m['class'],
            'code' => (int)$m['code'],
            'file' => $m['file'],
            'line' => (int)$m['line'],
            'message' => $this->truncate(trim($m['message']), 2000),
        ];
    }

    /**
     * @return array{frames: list<string>, omitted: int}|null
     */
    private function trimTrace(string $exception, int $keep): ?array
    {
        if (preg_match_all('/#\d+\s[^\n]*/', $exception, $matches) === 0) {
            return null;
        }

        $frames = $matches[0];
        $total = \count($frames);
        $keep = max(0, $keep);

        return [
            'frames' => array_slice($frames, 0, $keep),
            'omitted' => max(0, $total - $keep),
        ];
    }

    private function truncate(string $value, int $max): string
    {
        return \strlen($value) <= $max
            ? $value
            : substr($value, 0, $max) . '… [truncated]';
    }
}

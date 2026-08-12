<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BalatD\DevMcp\Mcp\Support\LogEntryParser;

final class LogEntryParserTest extends TestCase
{
    /**
     * Shaped like a real TYPO3 exception-handler line: prefix, summary, then a
     * JSON context whose "exception" key carries the whole stack trace.
     *
     * @return array{timestamp: ?string, level: string, message: string, file: string}
     */
    private function exceptionEntry(int $frames = 40): array
    {
        $trace = 'Exception: Undeclared arguments, in file /app/Foo.php:12' . "\nStack trace:";
        for ($i = 0; $i < $frames; $i++) {
            $trace .= "\n#" . $i . ' /app/Frame' . $i . '.php(' . $i . '): Some\\Class->method()';
        }

        $context = json_encode([
            'mode' => 'WEB',
            'application_mode' => 'FE',
            'exception_class' => 'TYPO3Fluid\\Fluid\\Core\\ViewHelper\\Exception',
            'exception_code' => 1773227091,
            'file' => '/app/vendor/typo3fluid/fluid/src/Core/ViewHelper/AbstractViewHelper.php',
            'line' => 484,
            'message' => 'Undeclared arguments passed to ViewHelper',
            'request_url' => 'https://example.ddev.site/products',
            'exception' => $trace,
        ], JSON_THROW_ON_ERROR);

        return [
            'timestamp' => 'Tue, 05 Aug 2025 10:00:00 +0200',
            'level' => 'CRITICAL',
            'file' => 'typo3_abc.log',
            'message' => 'request="17db" component="TYPO3.CMS.Core.Error.DebugExceptionHandler": '
                . 'Core: Exception handler (WEB: FE): TYPO3Fluid\\Fluid\\Core\\ViewHelper\\Exception, '
                . 'code #1773227091, file /app/AbstractViewHelper.php, line 484: Undeclared arguments'
                . ' - ' . $context,
        ];
    }

    #[Test]
    public function extractsStructuredExceptionFieldsFromContext(): void
    {
        $parsed = (new LogEntryParser())->parse($this->exceptionEntry());

        self::assertSame('TYPO3Fluid\\Fluid\\Core\\ViewHelper\\Exception', $parsed['exception']['class']);
        self::assertSame(1773227091, $parsed['exception']['code']);
        self::assertSame(484, $parsed['exception']['line']);
        self::assertStringContainsString('Undeclared arguments', $parsed['exception']['message']);
        self::assertSame('https://example.ddev.site/products', $parsed['requestUrl']);
        self::assertSame('CRITICAL', $parsed['level']);
    }

    #[Test]
    public function keepsOnlyTheLeadingStackFramesAndReportsTheRest(): void
    {
        $parsed = (new LogEntryParser())->parse($this->exceptionEntry(40));

        self::assertCount(LogEntryParser::DEFAULT_TRACE_FRAMES, $parsed['trace']);
        self::assertSame(35, $parsed['traceOmittedFrames']);
        self::assertStringContainsString('#0 ', $parsed['trace'][0]);
        self::assertArrayHasKey('hint', $parsed);
    }

    #[Test]
    public function trimmedEntryIsDramaticallySmallerThanTheRaw(): void
    {
        $entry = $this->exceptionEntry(90);

        $trimmed = json_encode((new LogEntryParser())->parse($entry), JSON_THROW_ON_ERROR);
        $raw = json_encode($entry, JSON_THROW_ON_ERROR);

        // The whole point of the class: a real entry measured 16 KB, of which
        // 96% was stack trace.
        self::assertLessThan(\strlen($raw) / 4, \strlen($trimmed));
    }

    #[Test]
    public function fullReturnsTheEntryUntouched(): void
    {
        $entry = $this->exceptionEntry();

        self::assertSame($entry, (new LogEntryParser())->parse($entry, true));
    }

    #[Test]
    public function doesNotReportOmittedFramesWhenTraceFitsEntirely(): void
    {
        $parsed = (new LogEntryParser())->parse($this->exceptionEntry(3));

        self::assertCount(3, $parsed['trace']);
        self::assertArrayNotHasKey('traceOmittedFrames', $parsed);
        self::assertArrayNotHasKey('hint', $parsed);
    }

    #[Test]
    public function fallsBackToTheMessageForPlainLogLinesWithoutContext(): void
    {
        $parsed = (new LogEntryParser())->parse([
            'timestamp' => 'Tue, 05 Aug 2025 10:00:00 +0200',
            'level' => 'ERROR',
            'file' => 'typo3_abc.log',
            'message' => 'request="a" component="Some.Component": plain failure, no context',
        ]);

        self::assertSame('plain failure, no context', $parsed['message']);
        self::assertArrayNotHasKey('exception', $parsed);
        self::assertArrayNotHasKey('trace', $parsed);
    }

    #[Test]
    public function parsesExceptionFromSummaryWhenContextIsAbsent(): void
    {
        $parsed = (new LogEntryParser())->parse([
            'timestamp' => null,
            'level' => 'ERROR',
            'file' => 'typo3_abc.log',
            'message' => 'request="a" component="C": Core: Exception handler (WEB: FE): '
                . 'RuntimeException, code #1234, file /app/Foo.php, line 99: it broke',
        ]);

        self::assertSame('RuntimeException', $parsed['exception']['class']);
        self::assertSame(1234, $parsed['exception']['code']);
        self::assertSame('/app/Foo.php', $parsed['exception']['file']);
        self::assertSame(99, $parsed['exception']['line']);
        self::assertSame('it broke', $parsed['exception']['message']);
    }
}

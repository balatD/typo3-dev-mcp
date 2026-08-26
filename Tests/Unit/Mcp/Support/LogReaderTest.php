<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Support;

use BalatD\DevMcp\Mcp\Support\LogReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogReaderTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'dev_mcp_log_') . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    #[Test]
    public function readEntriesParsesLevelsAndReturnsNewestFirst(): void
    {
        file_put_contents($this->logFile, implode("\n", [
            'Tue, 05 Aug 2025 10:00:00 +0200 [INFO] request="a" component="X": first info',
            'Tue, 05 Aug 2025 10:00:01 +0200 [ERROR] request="b" component="Y": something broke',
            'Tue, 05 Aug 2025 10:00:02 +0200 [WARNING] request="c" component="Z": heads up',
        ]) . "\n");

        $entries = (new LogReader())->readEntries([$this->logFile], 10);

        self::assertCount(3, $entries);
        self::assertSame('WARNING', $entries[0]['level']);
        self::assertSame('ERROR', $entries[1]['level']);
        self::assertSame('INFO', $entries[2]['level']);
        self::assertStringContainsString('heads up', $entries[0]['message']);
    }

    #[Test]
    public function readEntriesFiltersByMinimumLevel(): void
    {
        file_put_contents($this->logFile, implode("\n", [
            'x [INFO] fine',
            'x [ERROR] broken',
            'x [DEBUG] noise',
            'x [CRITICAL] very broken',
        ]) . "\n");

        $entries = (new LogReader())->readEntries([$this->logFile], 10, 'error');

        self::assertSame(['CRITICAL', 'ERROR'], array_column($entries, 'level'));
    }

    #[Test]
    public function continuationLinesAreAppendedToThePreviousEntry(): void
    {
        file_put_contents($this->logFile, implode("\n", [
            'x [ERROR] exception thrown',
            '#0 /app/Classes/Foo.php(12): bar()',
            '#1 {main}',
        ]) . "\n");

        $entries = (new LogReader())->readEntries([$this->logFile], 10);

        self::assertCount(1, $entries);
        self::assertStringContainsString('{main}', $entries[0]['message']);
    }

    #[Test]
    public function limitIsRespected(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = 'x [INFO] entry ' . $i;
        }
        file_put_contents($this->logFile, implode("\n", $lines) . "\n");

        $entries = (new LogReader())->readEntries([$this->logFile], 5);

        self::assertCount(5, $entries);
        self::assertStringContainsString('entry 49', $entries[0]['message']);
    }
}

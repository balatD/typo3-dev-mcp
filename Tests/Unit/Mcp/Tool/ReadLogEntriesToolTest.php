<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LogEntryParser;
use BalatD\DevMcp\Mcp\Support\LogReader;
use BalatD\DevMcp\Mcp\Tool\ReadLogEntriesTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ReadLogEntriesToolTest extends TestCase
{
    private string $projectPath;

    /**
     * LogReader is final and discovers files through Environment::getVarPath(),
     * so the environment is pointed at a temp project rather than the reader
     * being stubbed.
     */
    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/dev_mcp_readlog_' . uniqid();
        mkdir($this->projectPath . '/var/log', 0777, true);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $this->projectPath,
            $this->projectPath . '/public',
            $this->projectPath . '/var',
            $this->projectPath . '/config',
            $this->projectPath . '/public/index.php',
            'UNIX',
        );

        file_put_contents(
            $this->projectPath . '/var/log/typo3_test.log',
            'Tue, 05 Aug 2025 10:00:00 +0200 [ERROR] request="a" component="C": '
            . 'Core: Exception handler (WEB: FE): RuntimeException, code #4242, file /app/Foo.php, '
            . 'line 17: it broke - ' . $this->context() . "\n",
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectPath . '/var/log/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->projectPath . '/var/log');
        @rmdir($this->projectPath . '/var');
        @rmdir($this->projectPath);
    }

    private function context(): string
    {
        return (string)json_encode([
            'exception_class' => 'RuntimeException',
            'exception_code' => 4242,
            'file' => '/app/Foo.php',
            'line' => 17,
            'message' => 'it broke',
            'exception' => "Stack trace:\n" . implode("\n", array_map(
                static fn(int $i): string => '#' . $i . ' /app/Frame' . $i . '.php(1): C->m()',
                range(0, 59),
            )),
        ], JSON_THROW_ON_ERROR);
    }

    private function tool(): ReadLogEntriesTool
    {
        return new ReadLogEntriesTool(
            new LogReader(),
            new LogEntryParser(),
            $this->createMock(ConnectionPool::class),
        );
    }

    #[Test]
    public function trimsStackTracesByDefault(): void
    {
        $result = $this->tool()->execute([]);

        $entry = $result['entries'][0];
        self::assertSame('RuntimeException', $entry['exception']['class']);
        self::assertSame(4242, $entry['exception']['code']);
        self::assertCount(LogEntryParser::DEFAULT_TRACE_FRAMES, $entry['trace']);
        self::assertSame(55, $entry['traceOmittedFrames']);
    }

    #[Test]
    public function fullReturnsTheUntrimmedEntry(): void
    {
        $trimmed = $this->tool()->execute([]);
        $full = $this->tool()->execute(['full' => true]);

        self::assertArrayHasKey('trace', $trimmed['entries'][0]);
        self::assertArrayNotHasKey('trace', $full['entries'][0]);
        self::assertGreaterThan(
            \strlen((string)json_encode($trimmed)) * 3,
            \strlen((string)json_encode($full)),
        );
    }
}

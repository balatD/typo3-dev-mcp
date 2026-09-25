<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp;

use BalatD\DevMcp\Mcp\SdkToolHandler;
use BalatD\DevMcp\Mcp\Support\Typo3Cli;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SdkToolHandlerTest extends TestCase
{
    private function createHandler(string $toolName, int $timeout = 60): SdkToolHandler
    {
        return new SdkToolHandler($toolName, new Typo3Cli([\PHP_BINARY, __DIR__ . '/../Fixture/typo3-cli.php'], $timeout));
    }

    private function createGateway(): ClientGateway
    {
        return (new \ReflectionClass(ClientGateway::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function argumentsReachTheToolProcessWithoutTheSdkKeys(): void
    {
        $result = $this->createHandler('echo')->execute([
            'limit' => 5.0,
            'query' => 'Größe',
            '_session' => new \stdClass(),
            '_request' => new \stdClass(),
        ], $this->createGateway());

        self::assertSame(['limit' => 5.0, 'query' => 'Größe'], $result);
    }

    #[Test]
    public function everyCallRunsInAFreshProcess(): void
    {
        // A long-running process keeps TCA, listeners and modules from its boot;
        // a fresh one per call sees what the installation currently holds.
        $handler = $this->createHandler('pid');

        $first = $handler->execute([], $this->createGateway());
        $second = $handler->execute([], $this->createGateway());

        self::assertNotSame(getmypid(), $first['pid']);
        self::assertNotSame($first['pid'], $second['pid']);
    }

    #[Test]
    public function aToolErrorReachesTheClientVerbatim(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('kaputt');

        $this->createHandler('fails')->execute([], $this->createGateway());
    }

    #[Test]
    public function aCrashReportsTheExitCodeAndStderr(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/"crash" failed without a result \(exit code 255\).*Allowed memory size/s');

        $this->createHandler('crash')->execute([], $this->createGateway());
    }

    #[Test]
    public function strayOutputIsReportedNotParsed(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/"noise" failed without a result.*Deprecated: something/s');

        $this->createHandler('noise')->execute([], $this->createGateway());
    }

    #[Test]
    public function aHungCallIsStoppedAfterTheTimeout(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('did not finish within 1 seconds');

        $this->createHandler('slow', 1)->execute([], $this->createGateway());
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\Typo3Cli;
use BalatD\DevMcp\Mcp\Tool\FlushCacheTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlushCacheToolTest extends TestCase
{
    private function createTool(): FlushCacheTool
    {
        return new FlushCacheTool(new Typo3Cli([\PHP_BINARY, __DIR__ . '/../../Fixture/typo3-cli.php']));
    }

    #[Test]
    public function flushesEverythingByDefault(): void
    {
        self::assertSame(['flushed' => 'all'], $this->createTool()->execute([]));
    }

    #[Test]
    public function flushesTheDependencyInjectionGroup(): void
    {
        self::assertSame(['flushed' => 'group:di'], $this->createTool()->execute(['group' => 'di']));
    }

    #[Test]
    public function anUnknownGroupSurfacesCoresMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No cache in the specified group 'nope'");

        $this->createTool()->execute(['group' => 'nope']);
    }
}

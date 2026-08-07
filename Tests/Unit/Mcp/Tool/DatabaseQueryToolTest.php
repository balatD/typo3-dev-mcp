<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BalatD\DevMcp\Mcp\Tool\DatabaseQueryTool;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DatabaseQueryToolTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('DEV_MCP_ALLOW_WRITE');
    }

    private function createTool(): DatabaseQueryTool
    {
        // The guards must reject before any connection is opened, so a mock
        // that fails on any interaction proves ordering as a side effect.
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method(self::anything());

        return new DatabaseQueryTool($connectionPool);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function writeQueryProvider(): \Generator
    {
        yield 'delete' => ['DELETE FROM pages'];
        yield 'update' => ['UPDATE pages SET title=1'];
        yield 'insert' => ['INSERT INTO pages (title) VALUES ("x")'];
        yield 'truncate' => ['TRUNCATE sys_log'];
        yield 'drop' => ['DROP TABLE pages'];
        yield 'lowercase' => ['delete from pages'];
    }

    #[Test]
    #[DataProvider('writeQueryProvider')]
    public function writeStatementsAreRejectedWithoutOptIn(string $query): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Write statements are disabled/');

        $this->createTool()->execute(['query' => $query]);
    }

    #[Test]
    public function multipleStatementsAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/single SQL statement/');

        $this->createTool()->execute(['query' => 'SELECT 1; DELETE FROM pages']);
    }

    #[Test]
    public function emptyQueryIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->createTool()->execute(['query' => '   ']);
    }

    #[Test]
    public function readOnlyHintReflectsWriteOptIn(): void
    {
        $tool = $this->createTool();
        self::assertTrue($tool->isReadOnly());

        putenv('DEV_MCP_ALLOW_WRITE=1');
        try {
            self::assertFalse($tool->isReadOnly());
        } finally {
            putenv('DEV_MCP_ALLOW_WRITE');
        }
    }
}

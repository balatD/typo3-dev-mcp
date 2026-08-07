<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BalatD\DevMcp\Mcp\ToolInterface;
use BalatD\DevMcp\Mcp\ToolRegistry;

final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function collectsToolsByName(): void
    {
        $registry = new ToolRegistry([
            $this->createTool('alpha'),
            $this->createTool('beta'),
        ]);

        self::assertSame(['alpha', 'beta'], array_keys($registry->all()));
        self::assertNotNull($registry->get('alpha'));
        self::assertNull($registry->get('unknown'));
    }

    private function createTool(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'test tool';
            }

            public function getInputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function isReadOnly(): bool
            {
                return true;
            }

            public function execute(array $arguments): mixed
            {
                return null;
            }
        };
    }
}

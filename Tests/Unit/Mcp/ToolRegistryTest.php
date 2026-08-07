<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BalatD\DevMcp\Mcp\ConditionalToolInterface;
use BalatD\DevMcp\Mcp\ToolInterface;
use BalatD\DevMcp\Mcp\ToolRegistry;

final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function collectsToolsByNameAndSkipsDisabledConditionalTools(): void
    {
        $registry = new ToolRegistry([
            $this->createTool('always_on'),
            $this->createConditionalTool('enabled_tool', true),
            $this->createConditionalTool('disabled_tool', false),
        ]);

        self::assertSame(['always_on', 'enabled_tool'], array_keys($registry->all()));
        self::assertNotNull($registry->get('always_on'));
        self::assertNull($registry->get('disabled_tool'));
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

    private function createConditionalTool(string $name, bool $enabled): ConditionalToolInterface
    {
        return new class($name, $enabled) implements ConditionalToolInterface {
            public function __construct(
                private readonly string $name,
                private readonly bool $enabled,
            ) {
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

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function execute(array $arguments): mixed
            {
                return null;
            }
        };
    }
}

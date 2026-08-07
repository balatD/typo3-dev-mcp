<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Fixture;

use BalatD\DevMcp\Mcp\ToolInterface;

/**
 * Test double: a tool whose behavior is a closure, counting executions.
 */
final class CallableTool implements ToolInterface
{
    public int $executions = 0;

    /**
     * @param ?\Closure(array<string, mixed>): mixed $behavior
     */
    public function __construct(
        private readonly string $name,
        private readonly ?\Closure $behavior = null,
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

    public function execute(array $arguments): mixed
    {
        $this->executions++;

        return $this->behavior !== null ? ($this->behavior)($arguments) : null;
    }
}

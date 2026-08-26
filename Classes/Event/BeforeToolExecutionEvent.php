<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Event;

use BalatD\DevMcp\Mcp\ToolInterface;

/**
 * Dispatched before every tool execution. Listeners can adjust the
 * arguments, or short-circuit the call by setting a result — the tool is
 * then never executed. To veto a call with an error the client can read,
 * throw a \RuntimeException from the listener instead.
 *
 * @api Part of the public extension-point contract; covered by the
 *      backwards-compatibility promise documented in the README.
 */
final class BeforeToolExecutionEvent
{
    private mixed $result = null;

    private bool $resultSet = false;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private readonly ToolInterface $tool,
        private array $arguments,
    ) {}

    public function getTool(): ToolInterface
    {
        return $this->tool;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    /**
     * Sets the call result and skips the tool execution entirely.
     */
    public function setResult(mixed $result): void
    {
        $this->result = $result;
        $this->resultSet = true;
    }

    public function hasResult(): bool
    {
        return $this->resultSet;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }
}

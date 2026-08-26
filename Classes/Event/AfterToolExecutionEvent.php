<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Event;

use BalatD\DevMcp\Mcp\ToolInterface;

/**
 * Dispatched after every successful tool execution, before the result is
 * serialized for the client. Listeners can post-process the result —
 * e.g. mask additional project-specific secrets or record an audit trail.
 *
 * @api Part of the public extension-point contract; covered by the
 *      backwards-compatibility promise documented in the README.
 */
final class AfterToolExecutionEvent
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private readonly ToolInterface $tool,
        private readonly array $arguments,
        private mixed $result,
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

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Event;

use BalatD\DevMcp\Mcp\ToolInterface;

/**
 * Dispatched once when the MCP server starts, before the tool list is
 * announced to the client. Listeners can add project-specific tools,
 * remove built-in ones, or replace a tool with a decorated variant.
 *
 * @api Part of the public extension-point contract; covered by the
 *      backwards-compatibility promise documented in the README.
 */
final class CollectToolsEvent
{
    /**
     * @param array<string, ToolInterface> $tools keyed by tool name
     */
    public function __construct(
        private array $tools,
    ) {}

    /**
     * @return array<string, ToolInterface> keyed by tool name
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Adds a tool, replacing any existing tool of the same name.
     */
    public function addTool(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function removeTool(string $name): void
    {
        unset($this->tools[$name]);
    }
}

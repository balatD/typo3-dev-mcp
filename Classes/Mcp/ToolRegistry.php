<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp;

/**
 * Collects all ToolInterface services tagged with `devmcp.tool`.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    /**
     * @return array<string, ToolInterface> keyed by tool name
     */
    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }
}

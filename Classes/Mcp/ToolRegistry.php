<?php

declare(strict_types=1);

namespace T3Boost\Mcp;

/**
 * Collects all ToolInterface services tagged with `t3boost.tool`.
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
            if ($tool instanceof ConditionalToolInterface && !$tool->isEnabled()) {
                continue;
            }
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

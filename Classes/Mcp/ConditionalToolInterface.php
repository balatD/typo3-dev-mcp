<?php

declare(strict_types=1);

namespace T3Boost\Mcp;

/**
 * Tools that are only available under certain conditions (opt-in flags,
 * application context). Disabled tools are not announced to MCP clients
 * at all — a hidden tool beats a tool that always errors.
 */
interface ConditionalToolInterface extends ToolInterface
{
    public function isEnabled(): bool;
}

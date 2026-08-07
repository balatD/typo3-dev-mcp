<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp;

/**
 * A single MCP tool exposed by the typo3-dev-mcp server.
 *
 * Implementations are auto-registered via the `devmcp.tool` service tag
 * (see Configuration/Services.yaml) and bridged to the MCP SDK by the
 * ServeCommand, so they stay free of any SDK-specific types.
 */
interface ToolInterface
{
    /**
     * Tool name as announced to MCP clients, e.g. "application_info".
     */
    public function getName(): string;

    /**
     * Human/AI-facing description of what the tool does and when to use it.
     */
    public function getDescription(): string;

    /**
     * JSON Schema (as nested array) describing the tool's input arguments.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Whether the tool never mutates application state — announced to MCP
     * clients as the readOnlyHint tool annotation.
     */
    public function isReadOnly(): bool;

    /**
     * Execute the tool. The result must be JSON-serializable; throw a
     * \RuntimeException with an AI-actionable message on failure.
     *
     * @param array<string, mixed> $arguments validated against getInputSchema()
     */
    public function execute(array $arguments): mixed;
}

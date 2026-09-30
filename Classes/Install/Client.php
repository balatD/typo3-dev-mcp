<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Install;

/**
 * The AI clients `devmcp:install` registers the MCP server with, each through
 * its project-scoped config file.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
enum Client: string
{
    case ClaudeCode = 'claude';
    case Codex = 'codex';
    case Cursor = 'cursor';
    case VsCode = 'vscode';
    case OpenCode = 'opencode';

    public function label(): string
    {
        return match ($this) {
            self::ClaudeCode => 'Claude Code',
            self::Codex => 'Codex',
            self::Cursor => 'Cursor',
            self::VsCode => 'VS Code (Copilot)',
            self::OpenCode => 'OpenCode',
        };
    }

    /**
     * Relative to the project root.
     */
    public function configFile(): string
    {
        return match ($this) {
            self::ClaudeCode => '.mcp.json',
            self::Codex => '.codex/config.toml',
            self::Cursor => '.cursor/mcp.json',
            self::VsCode => '.vscode/mcp.json',
            self::OpenCode => 'opencode.json',
        };
    }
}

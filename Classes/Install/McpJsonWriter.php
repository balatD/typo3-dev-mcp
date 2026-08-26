<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Install;

/**
 * Registers the typo3-dev-mcp server in the project's .mcp.json (the project-scope
 * MCP registry Claude Code and other clients read). Merges into an existing
 * file — other servers are never touched.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class McpJsonWriter
{
    /**
     * @return string the path of the written file
     */
    public function register(string $projectPath, bool $viaDdev): string
    {
        $file = rtrim($projectPath, '/') . '/.mcp.json';

        $config = [];
        if (is_file($file)) {
            $config = json_decode((string)file_get_contents($file), true);
            if (!\is_array($config)) {
                throw new \RuntimeException($file . ' exists but is not valid JSON — fix or remove it first.');
            }
        }

        $config['mcpServers']['typo3-dev-mcp'] = $viaDdev
            ? ['command' => 'ddev', 'args' => ['exec', 'vendor/bin/typo3', 'devmcp:serve']]
            : ['command' => 'vendor/bin/typo3', 'args' => ['devmcp:serve']];

        file_put_contents(
            $file,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        return $file;
    }
}

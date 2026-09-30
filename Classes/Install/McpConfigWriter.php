<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Install;

use BalatD\DevMcp\Mcp\Support\Typo3Cli;

/**
 * Registers the typo3-dev-mcp server in a client's project-scoped MCP config.
 * Merges into an existing file — other servers and settings are never touched.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class McpConfigWriter
{
    private const SERVER_NAME = 'typo3-dev-mcp';

    /**
     * Clients with their own call timeout wait this much longer than the
     * per-call limit, so the model sees the server's timeout error instead.
     */
    private const CLIENT_TIMEOUT_MARGIN_SECONDS = 30;

    public function __construct(
        private readonly Typo3Cli $cli,
    ) {}

    /**
     * @return string the path of the written file
     */
    public function register(string $projectPath, bool $viaDdev, Client $client): string
    {
        [$command, $args] = $viaDdev
            ? ['ddev', ['exec', 'vendor/bin/typo3', 'devmcp:serve']]
            : ['vendor/bin/typo3', ['devmcp:serve']];
        $timeoutSeconds = $this->cli->getTimeoutSeconds() + self::CLIENT_TIMEOUT_MARGIN_SECONDS;

        $file = rtrim($projectPath, '/') . '/' . $client->configFile();
        $existing = is_file($file) ? (string)file_get_contents($file) : null;

        if ($client === Client::Codex) {
            $content = $this->withTomlServer($existing ?? '', $command, $args, $timeoutSeconds);
        } else {
            [$key, $entry] = match ($client) {
                Client::ClaudeCode, Client::Cursor => ['mcpServers', ['command' => $command, 'args' => $args]],
                Client::VsCode => ['servers', ['type' => 'stdio', 'command' => $command, 'args' => $args]],
                Client::OpenCode => ['mcp', [
                    'type' => 'local',
                    'command' => [$command, ...$args],
                    'timeout' => $timeoutSeconds * 1000,
                ]],
            };
            if ($client === Client::OpenCode && $existing === null && is_file($file . 'c')) {
                throw new \RuntimeException(sprintf(
                    '%sc cannot be rewritten without losing its comments. Add this under "%s" by hand: %s',
                    $file,
                    $key,
                    json_encode([self::SERVER_NAME => $entry], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ));
            }
            $content = $this->withJsonServer($file, $existing, $key, $entry);
        }

        $directory = \dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new \RuntimeException('Could not create directory ' . $directory);
        }
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function withJsonServer(string $file, ?string $existing, string $key, array $entry): string
    {
        // Decoded to objects: as arrays, another server's `"env": {}` would come back as `[]`.
        $config = $existing === null ? new \stdClass() : json_decode($existing);
        if (!$config instanceof \stdClass) {
            throw new \RuntimeException($file . ' exists but is not valid JSON — fix or remove it first.');
        }

        $servers = (object)($config->{$key} ?? []);
        $servers->{self::SERVER_NAME} = $entry;
        $config->{$key} = $servers;

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Drops this server's tables, including sub-tables such as `.env`, and
     * appends a fresh one. Everything else is kept line by line.
     *
     * @param list<string> $args
     */
    private function withTomlServer(string $existing, string $command, array $args, int $timeoutSeconds): string
    {
        $ownTable = '/^\h*\[\h*mcp_servers\.("?)' . preg_quote(self::SERVER_NAME, '/') . '\1\h*[.\]]/';
        $kept = [];
        $inOwnTable = false;
        foreach (explode("\n", $existing) as $line) {
            if (preg_match('/^\h*\[/', $line) === 1) {
                $inOwnTable = preg_match($ownTable, $line) === 1;
            }
            if (!$inOwnTable) {
                $kept[] = $line;
            }
        }
        $kept = rtrim(implode("\n", $kept));

        $table = '[mcp_servers.' . self::SERVER_NAME . "]\n"
            . 'command = ' . $this->tomlValue($command) . "\n"
            . 'args = ' . $this->tomlValue($args) . "\n"
            . 'tool_timeout_sec = ' . $timeoutSeconds . "\n";

        return $kept === '' ? $table : $kept . "\n\n" . $table;
    }

    /**
     * JSON strings and arrays of strings are valid TOML as they are.
     *
     * @param string|list<string> $value
     */
    private function tomlValue(string|array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

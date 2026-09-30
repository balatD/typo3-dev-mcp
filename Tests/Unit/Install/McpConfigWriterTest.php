<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Install;

use BalatD\DevMcp\Install\Client;
use BalatD\DevMcp\Install\McpConfigWriter;
use BalatD\DevMcp\Mcp\Support\Typo3Cli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class McpConfigWriterTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/dev_mcp_mcpconfig_' . uniqid();
        mkdir($this->projectPath);
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->projectPath, true);
    }

    private function register(Client $client, bool $viaDdev = true): string
    {
        $file = (new McpConfigWriter(new Typo3Cli([], 120)))->register($this->projectPath, $viaDdev, $client);

        return (string)file_get_contents($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function registerJson(Client $client, bool $viaDdev = true): array
    {
        return json_decode($this->register($client, $viaDdev), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function claudeCodeGetsTheMcpJsonItAlwaysGot(): void
    {
        self::assertSame(<<<'JSON'
            {
                "mcpServers": {
                    "typo3-dev-mcp": {
                        "command": "ddev",
                        "args": [
                            "exec",
                            "vendor/bin/typo3",
                            "devmcp:serve"
                        ]
                    }
                }
            }

            JSON, $this->register(Client::ClaudeCode));
    }

    #[Test]
    public function withoutDdevTheTypo3BinaryIsStartedDirectly(): void
    {
        self::assertSame(
            ['command' => 'vendor/bin/typo3', 'args' => ['devmcp:serve']],
            $this->registerJson(Client::ClaudeCode, false)['mcpServers']['typo3-dev-mcp'],
        );
    }

    #[Test]
    public function cursorReadsTheSameShapeFromItsOwnDirectory(): void
    {
        self::assertSame(
            ['command' => 'ddev', 'args' => ['exec', 'vendor/bin/typo3', 'devmcp:serve']],
            $this->registerJson(Client::Cursor)['mcpServers']['typo3-dev-mcp'],
        );
        self::assertFileExists($this->projectPath . '/.cursor/mcp.json');
    }

    #[Test]
    public function vsCodeListsServersWithTheirTransport(): void
    {
        self::assertSame(
            ['type' => 'stdio', 'command' => 'ddev', 'args' => ['exec', 'vendor/bin/typo3', 'devmcp:serve']],
            $this->registerJson(Client::VsCode)['servers']['typo3-dev-mcp'],
        );
        self::assertFileExists($this->projectPath . '/.vscode/mcp.json');
    }

    #[Test]
    public function openCodeWaitsLongerThanTheServerTimesOut(): void
    {
        self::assertSame(
            [
                'type' => 'local',
                'command' => ['ddev', 'exec', 'vendor/bin/typo3', 'devmcp:serve'],
                'timeout' => 150000,
            ],
            $this->registerJson(Client::OpenCode)['mcp']['typo3-dev-mcp'],
        );
    }

    #[Test]
    public function codexGetsATomlTableThatWaitsLongerThanTheServerTimesOut(): void
    {
        self::assertSame(<<<'TOML'
            [mcp_servers.typo3-dev-mcp]
            command = "ddev"
            args = ["exec","vendor/bin/typo3","devmcp:serve"]
            tool_timeout_sec = 150

            TOML, $this->register(Client::Codex));
    }

    #[Test]
    public function codexReplacesItsOwnTablesAndKeepsEverythingElse(): void
    {
        mkdir($this->projectPath . '/.codex');
        file_put_contents($this->projectPath . '/.codex/config.toml', <<<'TOML'
            # team defaults
            model = "gpt-5"

            [mcp_servers.other]
            command = "other"

            [mcp_servers.typo3-dev-mcp]
            command = "old"

            [mcp_servers.typo3-dev-mcp.env]
            FOO = "bar"

            [mcp_servers.typo3-dev-mcp-fork]
            command = "fork"

            TOML);

        self::assertSame(<<<'TOML'
            # team defaults
            model = "gpt-5"

            [mcp_servers.other]
            command = "other"

            [mcp_servers.typo3-dev-mcp-fork]
            command = "fork"

            [mcp_servers.typo3-dev-mcp]
            command = "vendor/bin/typo3"
            args = ["devmcp:serve"]
            tool_timeout_sec = 150

            TOML, $this->register(Client::Codex, false));
    }

    #[Test]
    public function codexReplacesAQuotedTableHeader(): void
    {
        mkdir($this->projectPath . '/.codex');
        file_put_contents(
            $this->projectPath . '/.codex/config.toml',
            "[mcp_servers.\"typo3-dev-mcp\"]\ncommand = \"old\"\n",
        );

        self::assertStringNotContainsString('old', $this->register(Client::Codex));
    }

    /**
     * @return iterable<string, array{Client}>
     */
    public static function everyClient(): iterable
    {
        foreach (Client::cases() as $client) {
            yield $client->value => [$client];
        }
    }

    #[Test]
    #[DataProvider('everyClient')]
    public function reRegisteringChangesNothing(Client $client): void
    {
        $first = $this->register($client);

        self::assertSame($first, $this->register($client));
    }

    #[Test]
    public function otherServersKeepTheirConfigurationUntouched(): void
    {
        file_put_contents(
            $this->projectPath . '/.mcp.json',
            '{"mcpServers": {"other": {"command": "other", "env": {}}}, "custom": true}',
        );

        $config = json_decode($this->register(Client::ClaudeCode));

        self::assertEquals(
            (object)['command' => 'other', 'env' => new \stdClass()],
            $config->mcpServers->other,
        );
        self::assertTrue($config->custom);
    }

    #[Test]
    public function invalidJsonIsNotOverwritten(): void
    {
        file_put_contents($this->projectPath . '/.mcp.json', '{"mcpServers": ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->register(Client::ClaudeCode);
    }

    #[Test]
    public function anOpenCodeJsoncFileIsLeftToTheDeveloper(): void
    {
        file_put_contents($this->projectPath . '/opencode.jsonc', "{\n  // comments\n}\n");

        try {
            $this->register(Client::OpenCode);
            self::fail('opencode.jsonc must not be rewritten');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('opencode.jsonc', $e->getMessage());
            self::assertStringContainsString('"typo3-dev-mcp":', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->projectPath . '/opencode.json');
    }
}

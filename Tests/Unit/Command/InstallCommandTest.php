<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Command;

use BalatD\DevMcp\Command\InstallCommand;
use BalatD\DevMcp\Install\DdevDetector;
use BalatD\DevMcp\Install\GuidelineComposer;
use BalatD\DevMcp\Install\McpConfigWriter;
use BalatD\DevMcp\Mcp\Support\Typo3Cli;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class InstallCommandTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/dev_mcp_installcmd_' . uniqid();
        mkdir($this->projectPath);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $this->projectPath,
            $this->projectPath . '/public',
            $this->projectPath . '/var',
            $this->projectPath . '/config',
            $this->projectPath . '/public/index.php',
            'UNIX',
        );
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->projectPath, true);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function install(array $input = [], ?string $answer = null): CommandTester
    {
        $tester = new CommandTester(new InstallCommand(
            new DdevDetector(),
            new McpConfigWriter(new Typo3Cli([], 120)),
            new GuidelineComposer(self::createStub(PackageManager::class)),
        ));
        if ($answer !== null) {
            $tester->setInputs([$answer]);
        }
        $tester->execute(['--skip-guidelines' => true, ...$input], ['interactive' => $answer !== null]);

        return $tester;
    }

    #[Test]
    public function aNamedClientIsTheOnlyOneRegistered(): void
    {
        $this->install(['--client' => ['codex']]);

        self::assertFileExists($this->projectPath . '/.codex/config.toml');
        self::assertFileDoesNotExist($this->projectPath . '/.mcp.json');
    }

    #[Test]
    public function aFreshProjectGetsClaudeCode(): void
    {
        $this->install();

        self::assertFileExists($this->projectPath . '/.mcp.json');
    }

    #[Test]
    public function aPlainReRunRefreshesTheClientsAlreadySetUp(): void
    {
        mkdir($this->projectPath . '/.cursor');
        file_put_contents($this->projectPath . '/.cursor/mcp.json', '{}');

        $this->install();

        self::assertStringContainsString(
            'typo3-dev-mcp',
            (string)file_get_contents($this->projectPath . '/.cursor/mcp.json'),
        );
        self::assertFileDoesNotExist($this->projectPath . '/.mcp.json');
    }

    #[Test]
    public function thePromptPreselectsTheClientsAlreadySetUp(): void
    {
        mkdir($this->projectPath . '/.codex');
        file_put_contents($this->projectPath . '/.codex/config.toml', '');
        file_put_contents($this->projectPath . '/.mcp.json', '{}');

        $this->install([], '');

        self::assertStringContainsString(
            'typo3-dev-mcp',
            (string)file_get_contents($this->projectPath . '/.codex/config.toml'),
        );
        self::assertStringContainsString(
            'typo3-dev-mcp',
            (string)file_get_contents($this->projectPath . '/.mcp.json'),
        );
    }

    #[Test]
    public function anUnknownClientIsRejectedBeforeAnythingIsWritten(): void
    {
        try {
            $this->install(['--client' => ['codex', 'emacs']]);
            self::fail('an unknown client must be rejected');
        } catch (InvalidOptionException $e) {
            self::assertStringContainsString('"emacs"', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->projectPath . '/.codex/config.toml');
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Install;

use BalatD\DevMcp\Install\GuidelineComposer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GuidelineComposerTest extends TestCase
{
    private function createComposer(): GuidelineComposer
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn(dirname(__DIR__, 3) . '/');
        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getPackage')->willReturn($package);

        return new GuidelineComposer($packageManager);
    }

    #[Test]
    public function aDdevProjectIsToldToRunPhpThroughDdev(): void
    {
        $guidelines = $this->createComposer()->compose(true);

        self::assertStringContainsString('`ddev exec php -l <file>`', $guidelines);
        self::assertStringNotContainsString('{{', $guidelines);
    }

    #[Test]
    public function otherProjectsGetOnlyTheVersions(): void
    {
        $guidelines = $this->createComposer()->compose(false);

        self::assertMatchesRegularExpression('/^This project runs TYPO3 \S+ on PHP \S+\.$/m', $guidelines);
        self::assertStringNotContainsString('ddev', $guidelines);
        self::assertStringNotContainsString('{{', $guidelines);
    }

    #[Test]
    public function agentsMdCarriesTheGuidelinesThemselves(): void
    {
        $projectPath = sys_get_temp_dir() . '/dev_mcp_guidelines_' . uniqid();
        mkdir($projectPath);
        file_put_contents($projectPath . '/AGENTS.md', <<<'MD'
            # Project rules

            <!-- typo3-dev-mcp:guidelines:start -->
            Follow the TYPO3 guidelines in [.ai/guidelines/typo3.md](.ai/guidelines/typo3.md).
            <!-- typo3-dev-mcp:guidelines:end -->

            MD);
        $composer = $this->createComposer();

        try {
            $composer->install($projectPath);
            $composer->install($projectPath);

            self::assertSame(
                "# Project rules\n\n<!-- typo3-dev-mcp:guidelines:start -->\n"
                . $composer->compose()
                . "<!-- typo3-dev-mcp:guidelines:end -->\n",
                file_get_contents($projectPath . '/AGENTS.md'),
            );
        } finally {
            GeneralUtility::rmdir($projectPath, true);
        }
    }

    #[Test]
    public function claudeMdImportsTheGuidelineFile(): void
    {
        $projectPath = sys_get_temp_dir() . '/dev_mcp_guidelines_' . uniqid();
        mkdir($projectPath);
        $composer = $this->createComposer();

        try {
            $composer->install($projectPath);

            self::assertSame(
                "<!-- typo3-dev-mcp:guidelines:start -->\n@.ai/guidelines/typo3.md\n<!-- typo3-dev-mcp:guidelines:end -->\n",
                file_get_contents($projectPath . '/CLAUDE.md'),
            );
            self::assertSame($composer->compose(), file_get_contents($projectPath . '/.ai/guidelines/typo3.md'));
        } finally {
            GeneralUtility::rmdir($projectPath, true);
        }
    }
}

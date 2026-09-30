<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Install;

use BalatD\DevMcp\Install\GuidelineComposer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;

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
}

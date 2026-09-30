<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use BalatD\DevMcp\Mcp\Tool\SearchChangelogTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;

final class SearchChangelogToolTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function search(array $arguments): array
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn(__DIR__ . '/../../Fixture/cms-core/');
        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getPackage')->willReturn($package);

        $result = (new SearchChangelogTool($packageManager))->execute($arguments);
        self::assertIsArray($result);

        return $result;
    }

    #[Test]
    public function theEntryNamedAfterTheQueryCarriesItsMigrationSection(): void
    {
        $result = $this->search(['query' => 'addPageTSConfig']);

        self::assertSame(2, $result['resultCount']);
        self::assertArrayNotHasKey('migration', $result['results'][1]);
        self::assertSame(
            [
                'type' => 'Deprecation',
                'issue' => 101799,
                'version' => '13.0',
                'title' => 'Deprecation: #101799 - ExtensionManagementUtility::addPageTSConfig()',
                'tags' => ['LocalConfiguration', 'PHP-API', 'TSConfig', 'FullyScanned', 'ext:core'],
                'file' => '13.0/Deprecation-101799-ExtensionManagementUtilityaddPageTSConfig.rst',
                'migration' => "Add default page TSconfig to a :file:`Configuration/page.tsconfig` file within an\n"
                    . 'extension and remove calls to :php:`ExtensionManagementUtility::addPageTSConfig()`.',
            ],
            $result['results'][0],
        );
    }

    #[Test]
    public function theMigrationKeepsItsSubsectionsAndCodeBlocks(): void
    {
        $entry = $this->search(['query' => '106393'])['results'][0];

        self::assertSame('15.0', $entry['removal']);
        self::assertStringContainsString("getItemLabel\n------------", $entry['migration']);
        self::assertStringContainsString("..  code-block:: php\n\n    // Before", $entry['migration']);
        self::assertStringEndsWith('No substitution is available.', $entry['migration']);
    }

    #[Test]
    public function severalHitsCarryNeitherExcerptNorMigration(): void
    {
        $result = $this->search(['query' => 'deprecation']);

        self::assertSame(4, $result['resultCount']);
        foreach ($result['results'] as $entry) {
            self::assertArrayNotHasKey('migration', $entry);
            self::assertArrayNotHasKey('excerpt', $entry);
        }
    }

    #[Test]
    public function aVersionIsListedWithoutAQueryNewestFirst(): void
    {
        $result = $this->search(['version' => '13', 'type' => 'Deprecation']);

        self::assertSame(
            [
                '13.3/Deprecation-104223-FluidStandaloneMethods.rst',
                '13.0/Deprecation-101799-ExtensionManagementUtilityaddPageTSConfig.rst',
                '13.0/Deprecation-102763-ExtbaseHashService.rst',
            ],
            array_column($result['results'], 'file'),
        );
    }

    #[Test]
    public function aQueryOrAVersionIsRequired(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->search(['type' => 'Deprecation']);
    }

    #[Test]
    public function onlyADeprecationStatesARemovalLaterThanItsOwnVersion(): void
    {
        $removals = array_column($this->search(['version' => '13'])['results'], 'removal', 'file');

        self::assertSame(['13.0/Deprecation-102763-ExtbaseHashService.rst' => '14.0'], $removals);
    }

    #[Test]
    public function aMissCountsEachWord(): void
    {
        $result = $this->search(['query' => 'addPageTSConfig nonsense']);

        self::assertSame(0, $result['resultCount']);
        self::assertSame(['addpagetsconfig' => 2, 'nonsense' => 0], $result['wordMatches']);
        self::assertArrayNotHasKey('wordMatchesWithoutFilters', $result);
    }

    #[Test]
    public function aMissSaysWhenTheFilterEmptiedIt(): void
    {
        $result = $this->search(['query' => 'addPageTSConfig', 'version' => '14']);

        self::assertSame(['addpagetsconfig' => 0], $result['wordMatches']);
        self::assertSame(['addpagetsconfig' => 2], $result['wordMatchesWithoutFilters']);
    }
}

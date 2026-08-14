<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use Composer\InstalledVersions;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Boost analog: application-info. The entry-point tool an AI client should
 * call first to understand the installation it is working with.
 */
final class ApplicationInfoTool implements ToolInterface
{
    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getName(): string
    {
        return 'application_info';
    }

    public function getDescription(): string
    {
        return 'TYPO3 and PHP version, application context, database platform and active extensions of '
            . 'this installation. "packages" adds every installed Composer package.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'packages' => [
                    'type' => 'boolean',
                    'description' => 'Also return every installed Composer package with its version (large)',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $typo3Version = new Typo3Version();

        $extensions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $extensions[$package->getPackageKey()] = [
                'composerName' => $package->getValueFromComposerManifest('name'),
                'version' => $package->getPackageMetaData()->getVersion(),
            ];
        }

        $info = [
            'typo3Version' => $typo3Version->getVersion(),
            'typo3Branch' => $typo3Version->getBranch(),
            'phpVersion' => PHP_VERSION,
            'applicationContext' => (string)Environment::getContext(),
            'composerMode' => Environment::isComposerMode(),
            'projectPath' => Environment::getProjectPath(),
            'os' => PHP_OS_FAMILY,
            'database' => $this->getDatabaseInfo(),
            'activeExtensions' => $extensions,
        ];

        // Every transitive dependency, which measured as 52% of this response
        // while answering a question almost nothing asks. `activeExtensions`
        // covers "is extension X installed"; `extension_info` covers versions
        // and compatibility of a specific package.
        if ($arguments['packages'] ?? false) {
            $packages = [];
            foreach (InstalledVersions::getInstalledPackages() as $packageName) {
                $packages[$packageName] = InstalledVersions::getPrettyVersion($packageName);
            }
            ksort($packages);
            $info['composerPackages'] = $packages;
        } else {
            $info['composerPackageCount'] = \count(InstalledVersions::getInstalledPackages());
            $info['hint'] = 'Pass {"packages": true} for the full Composer package list.';
        }

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private function getDatabaseInfo(): array
    {
        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

            return [
                'platform' => substr(strrchr($connection->getDatabasePlatform()::class, '\\') ?: '', 1),
                'serverVersion' => $connection->getServerVersion(),
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Database not reachable: ' . $e->getMessage()];
        }
    }
}

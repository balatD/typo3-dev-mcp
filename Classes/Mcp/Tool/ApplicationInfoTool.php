<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use Composer\InstalledVersions;
use T3Boost\Mcp\ToolInterface;
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
        return 'Read TYPO3 version, PHP version, application context, database platform, active TYPO3 '
            . 'extensions and installed Composer packages of this installation. Call this once at the start '
            . 'of a session to ground yourself before using other tools or writing code.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
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

        $packages = [];
        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            $packages[$packageName] = InstalledVersions::getPrettyVersion($packageName);
        }
        ksort($packages);

        return [
            'typo3Version' => $typo3Version->getVersion(),
            'typo3Branch' => $typo3Version->getBranch(),
            'phpVersion' => PHP_VERSION,
            'applicationContext' => (string)Environment::getContext(),
            'composerMode' => Environment::isComposerMode(),
            'projectPath' => Environment::getProjectPath(),
            'os' => PHP_OS_FAMILY,
            'database' => $this->getDatabaseInfo(),
            'activeExtensions' => $extensions,
            'composerPackages' => $packages,
        ];
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

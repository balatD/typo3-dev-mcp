<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use Composer\InstalledVersions;
use BalatD\DevMcp\Mcp\Support\HttpFetcher;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\Exception\UnknownPackageException;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Answers the question every upgrade starts with: "is there a release of EXT:x
 * for the TYPO3 version I am on, and what am I running right now?"
 *
 * Local package data always comes back; TER and Packagist are best-effort, so
 * the tool stays useful offline or behind a proxy.
 */
final class ExtensionInfoTool implements ToolInterface
{
    private const TER_ENDPOINT = 'https://extensions.typo3.org/api/v1/extension/';

    private const PACKAGIST_ENDPOINT = 'https://repo.packagist.org/p2/';

    /** Newest releases listed from Packagist — enough to see the version trend. */
    private const PACKAGIST_VERSION_LIMIT = 5;

    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly HttpFetcher $httpFetcher,
    ) {
    }

    public function getName(): string
    {
        return 'extension_info';
    }

    public function getDescription(): string
    {
        return 'Look up a TYPO3 extension: the version installed here plus the latest release in the TER '
            . 'and on Packagist, including which TYPO3 majors it supports. Use this before an upgrade or '
            . 'before suggesting an extension, instead of guessing whether a compatible release exists.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Extension key, e.g. "news" — as listed by application_info',
                ],
                'composerName' => [
                    'type' => 'string',
                    'description' => 'Composer package name, e.g. "georgringer/news" (alternative to "key")',
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
        $key = trim((string)($arguments['key'] ?? ''));
        $composerName = trim((string)($arguments['composerName'] ?? ''));

        if ($key === '' && $composerName === '') {
            throw new \RuntimeException('Pass either "key" (e.g. "news") or "composerName" (e.g. "georgringer/news").');
        }

        $notes = [];
        $local = $this->describeLocalPackage($key, $composerName);
        if ($local !== null) {
            $key = $key !== '' ? $key : (string)$local['extensionKey'];
            $composerName = $composerName !== '' ? $composerName : (string)($local['composerName'] ?? '');
        }

        // Each registry knows the identifier the other one needs — the TER maps
        // key -> composer name, Packagist maps composer name -> key — so for a
        // not-installed extension one lookup unlocks the other.
        $ter = null;
        if ($key !== '') {
            $ter = $this->fetchTerSafely($key, $notes);
            if ($composerName === '' && \is_array($ter)) {
                $composerName = (string)($ter['composerName'] ?? '');
            }
        }

        $packagist = null;
        if (str_contains($composerName, '/')) {
            try {
                $packagist = $this->fetchPackagist($composerName);
            } catch (\RuntimeException $e) {
                $notes[] = 'Packagist lookup failed: ' . $e->getMessage();
            }

            if ($key === '' && \is_array($packagist)) {
                $key = (string)($packagist['extensionKey'] ?? '');
                if ($key !== '') {
                    $ter = $this->fetchTerSafely($key, $notes);
                }
            }
        }

        if ($key === '') {
            $notes[] = 'No extension key could be resolved, so the TER was not queried.';
        }

        if ($local === null && $ter === null && $packagist === null) {
            throw new \RuntimeException(
                'Nothing found for "' . ($key !== '' ? $key : $composerName) . '" — it is not installed here, '
                . 'not in the TER and not on Packagist. Check the spelling with application_info.',
            );
        }

        return array_filter([
            'extensionKey' => $key !== '' ? $key : null,
            'composerName' => $composerName !== '' ? $composerName : null,
            'installed' => $local,
            'ter' => $ter,
            'packagist' => $packagist,
            'notes' => $notes !== [] ? $notes : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeLocalPackage(string $key, string $composerName): ?array
    {
        $package = null;
        if ($key !== '') {
            try {
                $package = $this->packageManager->getPackage($key);
            } catch (UnknownPackageException) {
                $package = null;
            }
        }

        if ($package === null && $composerName !== '') {
            foreach ($this->packageManager->getAvailablePackages() as $candidate) {
                if ($candidate->getValueFromComposerManifest('name') === $composerName) {
                    $package = $candidate;
                    break;
                }
            }
        }

        if ($package === null) {
            return null;
        }

        $packageKey = $package->getPackageKey();
        $manifestName = $package->getValueFromComposerManifest('name');

        return array_filter([
            'extensionKey' => $packageKey,
            'composerName' => \is_string($manifestName) ? $manifestName : null,
            'version' => $package->getPackageMetaData()->getVersion(),
            'active' => $this->packageManager->isPackageActive($packageKey),
            'path' => $package->getPackagePath(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param list<string> $notes collects the failure reason instead of aborting the whole tool
     * @return array<string, mixed>|null
     */
    private function fetchTerSafely(string $key, array &$notes): ?array
    {
        try {
            return $this->fetchTer($key);
        } catch (\RuntimeException $e) {
            $notes[] = 'TER lookup failed: ' . $e->getMessage();

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTer(string $key): ?array
    {
        try {
            $payload = $this->httpFetcher->getJson(self::TER_ENDPOINT . rawurlencode($key));
        } catch (\RuntimeException $e) {
            // a 404 is a plain "not in the TER", not a failure worth reporting as one
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }

        // the endpoint answers with a single-element list
        $extension = $payload[0] ?? null;
        if (!\is_array($extension)) {
            return null;
        }

        $meta = \is_array($extension['meta'] ?? null) ? $extension['meta'] : [];
        $current = \is_array($extension['current_version'] ?? null) ? $extension['current_version'] : [];
        $typo3Versions = \is_array($current['typo3_versions'] ?? null) ? $current['typo3_versions'] : [];
        $installedMajor = (new Typo3Version())->getMajorVersion();

        return array_filter([
            'latestVersion' => $current['number'] ?? null,
            'state' => $current['state'] ?? null,
            'title' => $current['title'] ?? null,
            'typo3Versions' => $typo3Versions !== [] ? array_values($typo3Versions) : null,
            'supportsInstalledMajor' => $typo3Versions !== []
                ? \in_array($installedMajor, array_map(intval(...), $typo3Versions), true)
                : null,
            'dependencies' => \is_array($current['dependencies'] ?? null) ? $current['dependencies'] : null,
            'uploadDate' => isset($current['upload_date'])
                ? date('Y-m-d', (int)$current['upload_date'])
                : null,
            'downloads' => $extension['downloads'] ?? null,
            'owner' => $extension['owner'] ?? null,
            'composerName' => $meta['composer_name'] ?? null,
            'repository' => $meta['repository_url'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPackagist(string $composerName): ?array
    {
        try {
            $payload = $this->httpFetcher->getJson(self::PACKAGIST_ENDPOINT . strtolower($composerName) . '.json');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }

        $versions = $payload['packages'][$composerName] ?? $payload['packages'][strtolower($composerName)] ?? null;
        if (!\is_array($versions) || $versions === []) {
            return null;
        }

        $releases = [];
        foreach (\array_slice($versions, 0, self::PACKAGIST_VERSION_LIMIT) as $release) {
            if (!\is_array($release)) {
                continue;
            }

            $releases[] = array_filter([
                'version' => $release['version'] ?? null,
                'requiresTypo3' => $release['require']['typo3/cms-core'] ?? null,
                'requiresPhp' => $release['require']['php'] ?? null,
                'released' => isset($release['time']) ? substr((string)$release['time'], 0, 10) : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $newest = \is_array($versions[0] ?? null) ? $versions[0] : [];

        return array_filter([
            'latestVersion' => $releases[0]['version'] ?? null,
            // the authoritative mapping composer name -> extension key
            'extensionKey' => $newest['extra']['typo3/cms']['extension-key'] ?? null,
            'installedHere' => InstalledVersions::isInstalled($composerName)
                ? InstalledVersions::getPrettyVersion($composerName)
                : null,
            'recentVersions' => $releases !== [] ? $releases : null,
            'url' => 'https://packagist.org/packages/' . $composerName,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use T3Boost\Mcp\ToolInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Closest boost analog: list-routes. Sites are TYPO3's routing entry points.
 */
final class SiteInfoTool implements ToolInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function getName(): string
    {
        return 'site_info';
    }

    public function getDescription(): string
    {
        return 'List all configured sites (config/sites/*/config.yaml) with base URL, root page ID, '
            . 'languages and error handling. Use this to find valid page URLs, language IDs and site '
            . 'identifiers before generating links or writing site-dependent code.';
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
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configuration = $site->getConfiguration();

            $languages = [];
            foreach ($site->getAllLanguages() as $language) {
                $languages[] = [
                    'languageId' => $language->getLanguageId(),
                    'title' => $language->getTitle(),
                    'locale' => (string)$language->getLocale(),
                    'base' => (string)$language->getBase(),
                    'hreflang' => $language->getHreflang(),
                    'enabled' => $language->isEnabled(),
                ];
            }

            $sites[$site->getIdentifier()] = [
                'rootPageId' => $site->getRootPageId(),
                'base' => (string)$site->getBase(),
                'websiteTitle' => $configuration['websiteTitle'] ?? null,
                'languages' => $languages,
                'errorHandling' => $configuration['errorHandling'] ?? [],
                'siteSets' => $configuration['dependencies'] ?? [],
            ];
        }

        return [
            'siteCount' => \count($sites),
            'sites' => $sites,
        ];
    }
}

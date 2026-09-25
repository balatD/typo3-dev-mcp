<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Closest boost analog: list-routes. Sites are TYPO3's routing entry points.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class SiteInfoTool implements ToolInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly LinkService $linkService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getName(): string
    {
        return 'site_info';
    }

    public function getDescription(): string
    {
        return 'All configured sites (config/sites/*/config.yaml) with base URL, root page ID, '
            . 'languages, site sets and error handling.';
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

            $configuredBases = array_column($configuration['languages'] ?? [], 'base', 'languageId');

            $languages = [];
            foreach ($site->getAllLanguages() as $language) {
                $languages[] = [
                    'languageId' => $language->getLanguageId(),
                    'title' => $language->getTitle(),
                    'locale' => (string)$language->getLocale(),
                    'base' => (string)$language->getBase(),
                    'configuredBase' => $configuredBases[$language->getLanguageId()] ?? null,
                    'hreflang' => $language->getHreflang(),
                    'enabled' => $language->isEnabled(),
                    'navigationTitle' => $language->getNavigationTitle(),
                    'fallbackType' => $language->getFallbackType(),
                    'fallbackLanguageIds' => $language->getFallbackLanguageIds(),
                ];
            }

            $sites[$site->getIdentifier()] = [
                'rootPageId' => $site->getRootPageId(),
                'base' => (string)$site->getBase(),
                'websiteTitle' => $configuration['websiteTitle'] ?? null,
                'languages' => $languages,
                'errorHandling' => array_map($this->describeErrorHandler(...), $configuration['errorHandling'] ?? []),
                'siteSets' => $configuration['dependencies'] ?? [],
            ];
        }

        return [
            'siteCount' => \count($sites),
            'sites' => $sites,
        ];
    }

    /**
     * A page-based handler points at a `t3://page` link; resolving it here
     * saves looking the page up separately.
     *
     * @param array<string, mixed> $handler
     * @return array<string, mixed>
     */
    private function describeErrorHandler(array $handler): array
    {
        $source = $handler['errorContentSource'] ?? null;
        if (!\is_string($source) || !str_starts_with($source, 't3://page')) {
            return $handler;
        }

        try {
            $pageId = (int)($this->linkService->resolve($source)['pageuid'] ?? 0);
        } catch (\Throwable) {
            return $handler;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $page = $queryBuilder
            ->select('uid', 'title', 'slug', 'hidden')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        $handler['errorPage'] = $page === false
            ? ['uid' => $pageId, 'missing' => true]
            : ['uid' => (int)$page['uid'], 'title' => $page['title'], 'slug' => $page['slug'], 'hidden' => (bool)$page['hidden']];

        return $handler;
    }
}

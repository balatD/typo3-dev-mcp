<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Boost analog: get-absolute-url. Pairs well with browser automation tools —
 * resolve the real URL first, then navigate/screenshot it.
 */
final class GetUrlTool implements ToolInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function getName(): string
    {
        return 'get_url';
    }

    public function getDescription(): string
    {
        return 'Generate the absolute frontend URL for a page (via the site router, i.e. the real '
            . 'routed URL including language prefix) plus the matching backend login URL. Without '
            . '"pageId" the root page of the first site is used.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => [
                    'type' => 'integer',
                    'description' => 'Page uid to build the URL for (default: root page of the first site)',
                ],
                'languageId' => [
                    'type' => 'integer',
                    'description' => 'Language uid to build the URL in (default 0)',
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
        $pageId = isset($arguments['pageId']) ? (int)$arguments['pageId'] : null;
        $languageId = (int)($arguments['languageId'] ?? 0);

        if ($pageId === null) {
            $sites = $this->siteFinder->getAllSites();
            if ($sites === []) {
                throw new \RuntimeException('No sites configured (config/sites/ is empty).');
            }
            $site = reset($sites);
            $pageId = $site->getRootPageId();
        } else {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageId);
            } catch (SiteNotFoundException) {
                throw new \RuntimeException(
                    'No site found for page ' . $pageId . '. Use site_info to see valid root pages.',
                );
            }
        }

        $pageUrl = (string)$site->getRouter()->generateUri($pageId, ['_language' => $languageId]);

        $base = $site->getBase();
        $backendLoginUrl = $base->getScheme() . '://' . $base->getAuthority() . '/typo3/';

        return [
            'site' => $site->getIdentifier(),
            'pageId' => $pageId,
            'languageId' => $languageId,
            'pageUrl' => $pageUrl,
            'backendLoginUrl' => $backendLoginUrl,
        ];
    }
}

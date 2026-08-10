<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\HttpFetcher;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Boost analog: search-docs. Queries the official documentation search of
 * docs.typo3.org and pins results to the installed major version, so the AI
 * reads the manual that matches this installation instead of whichever version
 * a search engine surfaced.
 *
 * Complements search_changelog: that one is offline and exact for "what
 * changed", this one covers "how does X work" — including third-party
 * extension manuals.
 */
final class SearchDocsTool implements ToolInterface
{
    private const ENDPOINT = 'https://docs.typo3.org/search/suggest';

    private const BASE_URL = 'https://docs.typo3.org/';

    /** The backend returns a fixed page size; surfaced in the hint for paging. */
    private const RESULTS_PER_PAGE = 5;

    private const EXCERPT_LENGTH = 400;

    public function __construct(
        private readonly HttpFetcher $httpFetcher,
    ) {
    }

    public function getName(): string
    {
        return 'search_docs';
    }

    public function getDescription(): string
    {
        return 'Search the official TYPO3 documentation on docs.typo3.org, filtered to the installed major '
            . 'version by default, and get titles, excerpts and permalinks back. Use this for "how does X '
            . 'work" and for third-party extension manuals; use search_changelog instead for "what changed" '
            . 'or "how do I migrate away from this deprecated API".';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search words, e.g. "site set settings definition" or a class/option name',
                ],
                'version' => [
                    'type' => 'string',
                    'description' => 'Major version to search, e.g. "13", "14", "main" or "all" '
                        . '(default: the installed major version)',
                ],
                'scope' => [
                    'type' => 'string',
                    'description' => 'Restrict to one manual by its slug, e.g. "m/typo3/reference-coreapi/14.3/en-us" '
                        . '— take it from the "manual" field of an earlier result',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Result page, ' . self::RESULTS_PER_PAGE . ' hits per page (default 1)',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $query = trim((string)($arguments['query'] ?? ''));
        if ($query === '') {
            throw new \RuntimeException('Argument "query" must not be empty.');
        }

        $version = trim((string)($arguments['version'] ?? ''));
        if ($version === '') {
            $version = (string)(new Typo3Version())->getMajorVersion();
        }

        $scope = trim((string)($arguments['scope'] ?? ''));
        $page = max(1, (int)($arguments['page'] ?? 1));

        $payload = $this->httpFetcher->getJson($this->buildUri($query, $version, $scope, $page));

        $results = [];
        foreach ($payload['results'] ?? [] as $hit) {
            if (\is_array($hit)) {
                $results[] = $this->describeHit($hit);
            }
        }

        return array_filter([
            'query' => $query,
            'version' => $version,
            'scope' => $scope !== '' ? $scope : null,
            'page' => $page,
            'resultCount' => \count($results),
            'results' => $results,
            'hint' => $results === []
                ? 'No hits. Try fewer or more general words, or {"version": "all"} to search every version.'
                : 'Pass {"page": ' . ($page + 1) . '} for more, or {"scope": "<manual>"} to stay inside one manual.',
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function buildUri(string $query, string $version, string $scope, int $page): string
    {
        $parameters = [
            'q' => $query,
            // the search backend expects the version facet as filters[version][<major>]=true
            'filters' => ['version' => [$version => 'true']],
            'page' => $page,
        ];

        if ($scope !== '') {
            $parameters['scope'] = $scope;
        }

        return self::ENDPOINT . '?' . http_build_query($parameters);
    }

    /**
     * @param array<array-key, mixed> $hit
     * @return array<string, mixed>
     */
    private function describeHit(array $hit): array
    {
        $manual = (string)($hit['manual_slug'] ?? '');
        $relativeUrl = (string)($hit['relative_url'] ?? '');
        $fragment = (string)($hit['fragment'] ?? '');

        $url = null;
        if ($manual !== '' && $relativeUrl !== '') {
            $url = self::BASE_URL . $manual . '/' . $relativeUrl . ($fragment !== '' ? '#' . $fragment : '');
        }

        return array_filter([
            // titles arrive HTML-encoded, e.g. "Argument ViewHelper &lt;f:argument&gt;"
            'title' => $this->decode((string)($hit['snippet_title'] ?? '')),
            'url' => $url,
            'manual' => $manual !== '' ? $manual : null,
            'type' => $this->decode((string)($hit['manual_type'] ?? '')),
            'isCore' => $hit['is_core'] ?? null,
            'excerpt' => $this->excerpt((string)($hit['snippet_content'] ?? '')),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function excerpt(string $content): string
    {
        $normalized = trim((string)preg_replace('/\s+/', ' ', $this->decode($content)));

        return mb_strimwidth($normalized, 0, self::EXCERPT_LENGTH, '…');
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

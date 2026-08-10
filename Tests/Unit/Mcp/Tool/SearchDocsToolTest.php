<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use BalatD\DevMcp\Mcp\Support\HttpFetcher;
use BalatD\DevMcp\Mcp\Tool\SearchDocsTool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Information\Typo3Version;

final class SearchDocsToolTest extends TestCase
{
    private string $requestedUri = '';

    protected function setUp(): void
    {
        putenv('DEV_MCP_NO_NETWORK');
        $this->requestedUri = '';
    }

    #[Test]
    public function anEmptyQueryIsRejectedBeforeAnyRequest(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method(self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must not be empty/');

        (new SearchDocsTool(new HttpFetcher($requestFactory)))->execute(['query' => '  ']);
    }

    #[Test]
    public function theVersionFacetIsSentInTheShapeTheSearchBackendExpects(): void
    {
        $this->createTool()->execute(['query' => 'site set', 'version' => '13', 'page' => 3]);

        // the backend ignores any other spelling of the version filter
        self::assertStringContainsString('filters%5Bversion%5D%5B13%5D=true', $this->requestedUri);
        self::assertStringContainsString('q=site+set', $this->requestedUri);
        self::assertStringContainsString('page=3', $this->requestedUri);
        self::assertStringNotContainsString('scope=', $this->requestedUri);
    }

    #[Test]
    public function theInstalledMajorVersionIsUsedByDefault(): void
    {
        $result = $this->createTool()->execute(['query' => 'site set']);

        $expected = (string)(new Typo3Version())->getMajorVersion();
        self::assertIsArray($result);
        self::assertSame($expected, $result['version']);
        self::assertStringContainsString('filters%5Bversion%5D%5B' . $expected . '%5D=true', $this->requestedUri);
    }

    #[Test]
    public function aScopeIsForwardedAndReportedBack(): void
    {
        $result = $this->createTool()->execute([
            'query' => 'site set',
            'scope' => 'm/typo3/reference-coreapi/14.3/en-us',
        ]);

        self::assertIsArray($result);
        self::assertSame('m/typo3/reference-coreapi/14.3/en-us', $result['scope']);
        self::assertStringContainsString('scope=m%2Ftypo3%2Freference-coreapi', $this->requestedUri);
    }

    #[Test]
    public function hitsBecomeTitlesExcerptsAndResolvablePermalinks(): void
    {
        $result = $this->createTool()->execute(['query' => 'site set', 'version' => '14']);

        self::assertIsArray($result);
        self::assertSame(1, $result['resultCount']);

        $hit = $result['results'][0];
        self::assertSame(
            'https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/ApiOverview/SiteHandling/SiteSets.html#site-sets-1',
            $hit['url'],
        );
        // titles and excerpts arrive HTML-encoded from the search backend
        self::assertSame('Argument ViewHelper <f:argument>', $hit['title']);
        self::assertSame('Site sets ship parts of the "site" configuration.', $hit['excerpt']);
        self::assertTrue($hit['isCore']);
    }

    #[Test]
    public function anEmptyResultSetIsNotAnErrorButSaysWhatToTryNext(): void
    {
        $result = $this->createTool('{"results":[]}')->execute(['query' => 'nothing matches this']);

        self::assertIsArray($result);
        self::assertSame(0, $result['resultCount']);
        self::assertStringContainsString('version', (string)$result['hint']);
    }

    #[Test]
    public function aHitWithoutALocationYieldsNoUrlInsteadOfABrokenOne(): void
    {
        $payload = '{"results":[{"snippet_title":"Orphan","manual_slug":"","relative_url":""}]}';

        $result = $this->createTool($payload)->execute(['query' => 'orphan']);

        self::assertIsArray($result);
        self::assertArrayNotHasKey('url', $result['results'][0]);
        self::assertSame('Orphan', $result['results'][0]['title']);
    }

    private function createTool(?string $payload = null): SearchDocsTool
    {
        $payload ??= json_encode([
            'results' => [
                [
                    'snippet_title' => 'Argument ViewHelper &lt;f:argument&gt;',
                    'snippet_content' => "Site sets ship parts   of the\n&quot;site&quot; configuration.",
                    'manual_slug' => 'm/typo3/reference-coreapi/14.3/en-us',
                    'relative_url' => 'ApiOverview/SiteHandling/SiteSets.html',
                    'fragment' => 'site-sets-1',
                    'manual_type' => 'TYPO3 manual',
                    'is_core' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($payload);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')
            ->willReturnCallback(function (string $uri) use ($response): ResponseInterface {
                $this->requestedUri = $uri;

                return $response;
            });

        return new SearchDocsTool(new HttpFetcher($requestFactory));
    }
}

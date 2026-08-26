<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Support;

use BalatD\DevMcp\Mcp\Support\HttpFetcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class HttpFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('DEV_MCP_NO_NETWORK');
    }

    protected function tearDown(): void
    {
        putenv('DEV_MCP_NO_NETWORK');
    }

    #[Test]
    public function noRequestIsMadeWhenNetworkAccessIsDisabled(): void
    {
        putenv('DEV_MCP_NO_NETWORK=1');

        // a factory that fails on any interaction proves nothing left the machine
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method(self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEV_MCP_NO_NETWORK=1.*docs\.typo3\.org|docs\.typo3\.org.*DEV_MCP_NO_NETWORK=1/');

        (new HttpFetcher($requestFactory))->getJson('https://docs.typo3.org/search/suggest?q=x');
    }

    #[Test]
    public function isDisabledOnlyForTheExactOptOutValue(): void
    {
        $fetcher = new HttpFetcher($this->createMock(RequestFactory::class));
        self::assertFalse($fetcher->isDisabled());

        putenv('DEV_MCP_NO_NETWORK=0');
        self::assertFalse($fetcher->isDisabled());

        putenv('DEV_MCP_NO_NETWORK=1');
        self::assertTrue($fetcher->isDisabled());
    }

    #[Test]
    public function decodedJsonIsReturnedOnSuccess(): void
    {
        $fetcher = new HttpFetcher($this->createFactory(200, '{"results":[{"snippet_title":"Site sets"}]}'));

        self::assertSame(
            ['results' => [['snippet_title' => 'Site sets']]],
            $fetcher->getJson('https://example.org/api'),
        );
    }

    #[Test]
    public function theStatusCodeIsPartOfTheErrorSoCallersCanDetectNotFound(): void
    {
        $fetcher = new HttpFetcher($this->createFactory(404, 'not found'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 404/');

        $fetcher->getJson('https://extensions.typo3.org/api/v1/extension/nope');
    }

    #[Test]
    public function aNonJsonBodyIsReported(): void
    {
        $fetcher = new HttpFetcher($this->createFactory(200, '<html>maintenance</html>'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $fetcher->getJson('https://example.org/api');
    }

    #[Test]
    public function transportFailuresNameTheOptOut(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new \RuntimeException('Connection timed out'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Connection timed out.*DEV_MCP_NO_NETWORK/s');

        (new HttpFetcher($requestFactory))->getJson('https://example.org/api');
    }

    private function createFactory(int $status, string $body): RequestFactory
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        return $requestFactory;
    }
}

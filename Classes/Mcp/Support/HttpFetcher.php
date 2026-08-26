<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Support;

use Composer\InstalledVersions;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * The single outbound-HTTP seam of this server: every tool that leaves the
 * machine goes through here, so the opt-out flag and the timeouts live in one
 * place. Core's RequestFactory is used rather than raw Guzzle because its
 * client factory already applies the project's TYPO3_CONF_VARS['HTTP'] proxy
 * and TLS settings.
 */
final class HttpFetcher
{
    private const CONNECT_TIMEOUT = 3;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function isDisabled(): bool
    {
        return getenv('DEV_MCP_NO_NETWORK') === '1';
    }

    /**
     * Fetch and decode a JSON document.
     *
     * @return array<array-key, mixed>
     * @throws \RuntimeException on a disabled network, a transport failure, a
     *                           non-2xx status or a body that is not a JSON object/array
     */
    public function getJson(string $uri, int $timeout = 5): array
    {
        if ($this->isDisabled()) {
            throw new \RuntimeException(
                'Network access is disabled (DEV_MCP_NO_NETWORK=1), so ' . $this->hostOf($uri)
                . ' cannot be queried. Unset the variable to allow it.',
            );
        }

        try {
            $response = $this->requestFactory->request($uri, 'GET', [
                'timeout' => $timeout,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                // handle the status code here instead of via a Guzzle exception
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => $this->userAgent(),
                ],
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Request to ' . $this->hostOf($uri) . ' failed: ' . $e->getMessage()
                . ' (offline or behind a proxy? set DEV_MCP_NO_NETWORK=1 to stop trying).',
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Request to ' . $this->hostOf($uri) . ' returned HTTP ' . $status . '.',
            );
        }

        $decoded = json_decode((string)$response->getBody(), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(
                'Response from ' . $this->hostOf($uri) . ' was not valid JSON.',
            );
        }

        return $decoded;
    }

    private function userAgent(): string
    {
        $version = InstalledVersions::isInstalled('balatd/typo3-dev-mcp')
            ? (InstalledVersions::getPrettyVersion('balatd/typo3-dev-mcp') ?? 'dev')
            : 'dev';

        return 'typo3-dev-mcp/' . $version;
    }

    private function hostOf(string $uri): string
    {
        return (string)(parse_url($uri, PHP_URL_HOST) ?: $uri);
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\SecretMasker;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Boost analog: get-config + list-available-config-keys, for
 * $GLOBALS['TYPO3_CONF_VARS'], feature toggles and extension configuration.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class GetConfigTool implements ToolInterface
{
    /**
     * Subtrees below this depth collapse to a key => "tree"|"value" map.
     * TYPO3_CONF_VARS is deep and wide: {"path": "SYS"} returned 43 KB
     * verbatim, which outweighed every other payload in the benchmark. At
     * depth 2 the scalars people actually ask for (SYS/trustedHostsPattern)
     * still come back whole, while cacheConfigurations and friends do not.
     */
    private const DEFAULT_DEPTH = 2;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SecretMasker $secretMasker,
    ) {}

    public function getName(): string
    {
        return 'get_config';
    }

    public function getDescription(): string
    {
        return 'Resolved TYPO3 system configuration ($GLOBALS[\'TYPO3_CONF_VARS\']). No arguments: '
            . 'top-level keys and configured feature toggles. "path" (slash-separated, e.g. "SYS/caching"): '
            . 'that subtree, nested values collapsed below depth ' . self::DEFAULT_DEPTH . '. "extension": '
            . 'that extension\'s configuration. Secrets are masked.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Slash-separated path into TYPO3_CONF_VARS, e.g. "SYS/caching/cacheConfigurations"',
                ],
                'extension' => [
                    'type' => 'string',
                    'description' => 'Extension key to read the extension configuration for, e.g. "backend"',
                ],
                'full' => [
                    'type' => 'boolean',
                    'description' => 'Return the whole subtree uncollapsed. Can be very large.',
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
        $extension = $arguments['extension'] ?? null;
        if (\is_string($extension) && $extension !== '') {
            try {
                $configuration = $this->extensionConfiguration->get($extension);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'No extension configuration for "' . $extension . '": ' . $e->getMessage(),
                );
            }

            return [
                'extension' => $extension,
                'configuration' => \is_array($configuration) ? $this->secretMasker->mask($configuration) : $configuration,
            ];
        }

        $path = trim((string)($arguments['path'] ?? ''), '/ ');
        if ($path === '') {
            return [
                'topLevelKeys' => array_keys($GLOBALS['TYPO3_CONF_VARS'] ?? []),
                'configuredFeatures' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'] ?? [],
                'hint' => 'Pass {"path": "SYS"} etc. to read a subtree, {"extension": "<key>"} for extension configuration.',
            ];
        }

        $value = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        foreach (explode('/', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                throw new \RuntimeException(
                    'Configuration path "' . $path . '" not found (segment "' . $segment . '").',
                );
            }
            $value = $value[$segment];
        }

        if (\is_array($value)) {
            // mask before collapsing so a masked key is never skipped by the cut
            $value = $this->secretMasker->mask($value);
        } elseif ($this->secretMasker->isSecretKey(basename($path))) {
            $value = '***MASKED***';
        }

        $collapsed = false;
        if (($arguments['full'] ?? false) !== true) {
            $value = $this->summarize($value, 0, $collapsed);
        }

        return array_filter([
            'path' => $path,
            'value' => $value,
            'truncated' => $collapsed ?: null,
            'hint' => $collapsed
                ? 'Nested values collapsed below depth ' . self::DEFAULT_DEPTH
                    . '. Pass {"path": "' . $path . '/<key>"} to drill in, or {"full": true} for everything.'
                : null,
        ], static fn(mixed $entry): bool => $entry !== null);
    }

    private function summarize(mixed $value, int $depth, bool &$collapsed): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if ($depth >= self::DEFAULT_DEPTH) {
            $collapsed = $collapsed || $value !== [];
            $keys = [];
            foreach ($value as $key => $child) {
                $keys[(string)$key] = \is_array($child) ? 'tree' : 'value';
            }

            return $keys;
        }

        $summarized = [];
        foreach ($value as $key => $child) {
            $summarized[$key] = $this->summarize($child, $depth + 1, $collapsed);
        }

        return $summarized;
    }
}

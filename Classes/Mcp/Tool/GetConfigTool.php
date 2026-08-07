<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use T3Boost\Mcp\Support\SecretMasker;
use T3Boost\Mcp\ToolInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Boost analog: get-config + list-available-config-keys, for
 * $GLOBALS['TYPO3_CONF_VARS'], feature toggles and extension configuration.
 */
final class GetConfigTool implements ToolInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SecretMasker $secretMasker,
    ) {
    }

    public function getName(): string
    {
        return 'get_config';
    }

    public function getDescription(): string
    {
        return 'Read TYPO3 system configuration ($GLOBALS[\'TYPO3_CONF_VARS\'], from settings.php / '
            . 'additional.php). Without arguments: top-level keys and configured feature toggles. With '
            . '"path" (slash-separated, e.g. "SYS/caching" or "MAIL"): that configuration subtree. With '
            . '"extension": the extension configuration of that extension key. Secrets are masked.';
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
            $value = $this->secretMasker->mask($value);
        } elseif ($this->secretMasker->isSecretKey(basename($path))) {
            $value = '***MASKED***';
        }

        return [
            'path' => $path,
            'value' => $value,
        ];
    }
}

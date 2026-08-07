<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Support;

/**
 * Masks secret-looking values before configuration leaves the MCP boundary.
 * Tool output ends up in AI conversation logs, so err on the side of masking.
 */
final class SecretMasker
{
    private const MASK = '***MASKED***';

    private const KEY_PATTERN = '/pass|secret|token|credential|encryption|private/i';

    private const EXACT_KEYS = ['key', 'apikey', 'api_key', 'authcode'];

    /**
     * @param array<array-key, mixed> $config
     * @return array<array-key, mixed>
     */
    public function mask(array $config): array
    {
        foreach ($config as $key => $value) {
            if (\is_array($value)) {
                $config[$key] = $this->mask($value);
                continue;
            }

            if (\is_string($value) && $value !== '' && $this->isSecretKey((string)$key)) {
                $config[$key] = self::MASK;
            }
        }

        return $config;
    }

    public function isSecretKey(string $key): bool
    {
        return \in_array(strtolower($key), self::EXACT_KEYS, true)
            || preg_match(self::KEY_PATTERN, $key) === 1;
    }
}

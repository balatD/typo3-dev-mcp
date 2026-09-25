<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Support;

use Mcp\Exception\ToolCallException;

/**
 * The JSON that carries one tool call between `devmcp:serve` and the
 * `devmcp:call` process running it.
 *
 * Results are decoded with objects preserved, so nested `{}` and `[]` survive;
 * only the top level is turned back into an array, because the SDK formats
 * arrays and objects differently. The `object` flag marks a result that was an
 * object to begin with.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class ToolCallEnvelope
{
    private const FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION
        | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $arguments
     */
    public static function arguments(array $arguments): string
    {
        return json_encode($arguments, self::FLAGS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function readArguments(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $arguments = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($arguments)) {
            throw new \UnexpectedValueException('Tool arguments must be a JSON object.');
        }

        return $arguments;
    }

    public static function result(mixed $result): string
    {
        $envelope = ['result' => $result];
        if (\is_object($result)) {
            $envelope['object'] = true;
        }

        return json_encode($envelope, self::FLAGS);
    }

    public static function error(string $message): string
    {
        return json_encode(['error' => $message], self::FLAGS);
    }

    /**
     * @throws ToolCallException when the tool reported an error
     * @throws \UnexpectedValueException when the output is not an envelope
     */
    public static function open(string $output): mixed
    {
        try {
            $envelope = json_decode($output, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('Tool output is not JSON: ' . $e->getMessage(), 0, $e);
        }

        if ($envelope instanceof \stdClass && \is_string($envelope->error ?? null)) {
            throw new ToolCallException($envelope->error);
        }
        if (!$envelope instanceof \stdClass || !property_exists($envelope, 'result')) {
            throw new \UnexpectedValueException('Tool output is not a result envelope.');
        }

        $result = $envelope->result;

        return $result instanceof \stdClass && !isset($envelope->object) ? (array)$result : $result;
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Support;

use BalatD\DevMcp\Mcp\Support\ToolCallEnvelope;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolCallEnvelopeTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function resultProvider(): iterable
    {
        yield 'nested empty object vs empty array' => [['object' => new \stdClass(), 'list' => [], 'map' => ['a' => []]]];
        yield 'empty array' => [[]];
        yield 'list' => [['a', 'b', 3]];
        yield 'non-list int keys' => [[3 => 'x', 7 => 'y']];
        yield 'slashes and unicode' => [['path' => 'EXT:foo/Bar.html', 'label' => 'Größe — ✓']];
        yield 'floats' => [['one' => 1.0, 'sum' => 0.1 + 0.2]];
        yield 'top-level stdClass' => [(object)['a' => []]];
        yield 'JsonSerializable' => [new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['serialized' => true, 'empty' => new \stdClass()];
            }
        }];
        yield 'string' => ['plain text'];
        yield 'int' => [42];
        yield 'bool' => [false];
        yield 'null' => [null];
    }

    #[Test]
    #[DataProvider('resultProvider')]
    public function aResultCrossesTheProcessBoundaryUnchanged(mixed $result): void
    {
        $opened = ToolCallEnvelope::open(ToolCallEnvelope::result($result));

        self::assertSame($this->asTheSdkSendsIt($result), $this->asTheSdkSendsIt($opened));
    }

    #[Test]
    public function argumentsKeepFloatsAndUnicode(): void
    {
        $arguments = ['limit' => 5.0, 'query' => 'SELECT "ä"', 'record' => ['CType' => 'list']];

        self::assertSame($arguments, ToolCallEnvelope::readArguments(ToolCallEnvelope::arguments($arguments)));
    }

    #[Test]
    public function emptyArgumentsReadAsAnEmptyArray(): void
    {
        self::assertSame([], ToolCallEnvelope::readArguments(''));
        self::assertSame([], ToolCallEnvelope::readArguments(ToolCallEnvelope::arguments([])));
    }

    #[Test]
    public function anErrorEnvelopeBecomesAToolCallException(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Table "nope" has no TCA schema.');

        ToolCallEnvelope::open(ToolCallEnvelope::error('Table "nope" has no TCA schema.'));
    }

    #[Test]
    public function anythingElseIsRejectedAsMalformed(): void
    {
        foreach (['', 'PHP Warning: oops', '{"unrelated":1}', '[1,2]'] as $output) {
            try {
                ToolCallEnvelope::open($output);
                self::fail('Accepted malformed output: ' . $output);
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Mirrors how the MCP SDK turns a tool's return value into `content` and
     * `structuredContent`: arrays are sent as they are, objects are JSON
     * round-tripped into associative arrays.
     *
     * @return array{content: string, structured: ?string}
     */
    private function asTheSdkSendsIt(mixed $value): array
    {
        $flags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

        $content = match (true) {
            $value === null => '(null)',
            \is_scalar($value) => var_export($value, true),
            default => json_encode($value, $flags),
        };

        $structured = match (true) {
            \is_array($value) => array_is_list($value) ? null : json_encode($value, $flags),
            \is_object($value) => json_encode(json_decode(json_encode($value, $flags), true), $flags),
            default => null,
        };

        return ['content' => $content, 'structured' => $structured];
    }
}

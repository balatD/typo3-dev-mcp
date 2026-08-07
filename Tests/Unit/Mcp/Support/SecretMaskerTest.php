<?php

declare(strict_types=1);

namespace T3Boost\Tests\Unit\Mcp\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use T3Boost\Mcp\Support\SecretMasker;

final class SecretMaskerTest extends TestCase
{
    /**
     * @return \Generator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function maskDataProvider(): \Generator
    {
        yield 'db credentials are masked, host is kept' => [
            ['host' => 'db', 'user' => 'root', 'password' => 'root'],
            ['host' => 'db', 'user' => 'root', 'password' => '***MASKED***'],
        ];

        yield 'encryptionKey is masked' => [
            ['SYS' => ['encryptionKey' => 'abc123', 'sitename' => 'Demo']],
            ['SYS' => ['encryptionKey' => '***MASKED***', 'sitename' => 'Demo']],
        ];

        yield 'exact key names are masked case-insensitively' => [
            ['apiKey' => 'x', 'authCode' => 'y', 'debug' => true],
            ['apiKey' => '***MASKED***', 'authCode' => '***MASKED***', 'debug' => true],
        ];

        yield 'empty and non-string secret values stay untouched' => [
            ['password' => '', 'secretEnabled' => true],
            ['password' => '', 'secretEnabled' => true],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('maskDataProvider')]
    public function maskReplacesSecretValues(array $input, array $expected): void
    {
        self::assertSame($expected, (new SecretMasker())->mask($input));
    }

    #[Test]
    public function isSecretKeyMatchesPatternsAndExactKeys(): void
    {
        $masker = new SecretMasker();

        self::assertTrue($masker->isSecretKey('password'));
        self::assertTrue($masker->isSecretKey('transport_smtp_password'));
        self::assertTrue($masker->isSecretKey('key'));
        self::assertFalse($masker->isSecretKey('sitename'));
        self::assertFalse($masker->isSecretKey('devIPmask'));
    }
}

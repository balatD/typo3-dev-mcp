<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BalatD\DevMcp\Mcp\Support\SecretMasker;
use BalatD\DevMcp\Mcp\Tool\GetConfigTool;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class GetConfigToolTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $backup = null;

    protected function setUp(): void
    {
        $this->backup = $GLOBALS['TYPO3_CONF_VARS'] ?? null;

        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'trustedHostsPattern' => 'example\\.test',
                'encryptionKey' => 'super-secret-value',
                'caching' => [
                    'cacheConfigurations' => [
                        'pages' => [
                            'backend' => 'Typo3DatabaseBackend',
                            'options' => ['compression' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->backup === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);

            return;
        }

        $GLOBALS['TYPO3_CONF_VARS'] = $this->backup;
    }

    private function tool(): GetConfigTool
    {
        return new GetConfigTool(
            $this->createMock(ExtensionConfiguration::class),
            new SecretMasker(),
        );
    }

    #[Test]
    public function shallowScalarsSurviveTheDepthLimit(): void
    {
        $result = $this->tool()->execute(['path' => 'SYS']);

        self::assertSame('example\\.test', $result['value']['trustedHostsPattern']);
    }

    #[Test]
    public function deepSubtreesCollapseToAKeyMap(): void
    {
        $result = $this->tool()->execute(['path' => 'SYS']);

        self::assertSame(['pages' => 'tree'], $result['value']['caching']['cacheConfigurations']);
        self::assertTrue($result['truncated']);
        self::assertStringContainsString('full', $result['hint']);
    }

    #[Test]
    public function anUncollapsedResultReportsNoTruncation(): void
    {
        $result = $this->tool()->execute(['path' => 'SYS/caching/cacheConfigurations/pages']);

        self::assertSame('Typo3DatabaseBackend', $result['value']['backend']);
        self::assertArrayNotHasKey('truncated', $result);
        self::assertArrayNotHasKey('hint', $result);
    }

    #[Test]
    public function fullReturnsTheUntrimmedSubtree(): void
    {
        $result = $this->tool()->execute(['path' => 'SYS', 'full' => true]);

        self::assertTrue($result['value']['caching']['cacheConfigurations']['pages']['options']['compression']);
        self::assertArrayNotHasKey('truncated', $result);
    }

    #[Test]
    public function secretsAreMaskedBeforeCollapsing(): void
    {
        foreach ([[], ['full' => true]] as $extra) {
            $result = $this->tool()->execute(['path' => 'SYS'] + $extra);

            self::assertSame('***MASKED***', $result['value']['encryptionKey']);
        }
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

final class SiteInfoToolTest extends AbstractToolTestCase
{
    protected function siteConfiguration(): string
    {
        return <<<'YAML'
            rootPageId: 1
            base: 'https://typo3-dev-mcp.test/'
            languages:
              - title: English
                enabled: true
                languageId: 0
                base: /
                locale: en_US.UTF-8
                navigationTitle: English
              - title: German
                enabled: true
                languageId: 1
                base: /de/
                locale: de_DE.UTF-8
                navigationTitle: Deutsch
                fallbackType: strict
            errorHandling:
              - errorCode: 404
                errorHandler: Page
                errorContentSource: 't3://page?uid=2'
            routes: []
            YAML;
    }

    #[Test]
    public function languagesCarryTheirConfiguredBaseAndFallback(): void
    {
        $german = $this->getTool('site_info')->execute([])['sites'][self::SITE_IDENTIFIER]['languages'][1];

        self::assertSame('https://typo3-dev-mcp.test/de/', $german['base']);
        self::assertSame('/de/', $german['configuredBase']);
        self::assertSame('Deutsch', $german['navigationTitle']);
        self::assertSame('strict', $german['fallbackType']);
    }

    #[Test]
    public function aPageErrorHandlerNamesThePageItPointsTo(): void
    {
        $handler = $this->getTool('site_info')->execute([])['sites'][self::SITE_IDENTIFIER]['errorHandling'][0];

        self::assertSame('t3://page?uid=2', $handler['errorContentSource']);
        self::assertSame(['uid' => 2, 'title' => 'Subpage', 'slug' => '/subpage', 'hidden' => false], $handler['errorPage']);
    }
}

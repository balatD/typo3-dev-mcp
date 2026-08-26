<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use BalatD\DevMcp\Mcp\ToolInterface;
use BalatD\DevMcp\Mcp\ToolRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Boots a TYPO3 instance with a site and a page, so the tools that report
 * resolved runtime state have something to resolve.
 *
 * The core extensions are not decoration: `backend_modules` needs EXT:backend,
 * `typoscript` needs EXT:frontend, `viewhelper_lookup` needs EXT:fluid, and
 * `list_commands` reports nothing without extensions that register commands.
 */
abstract class AbstractToolTestCase extends FunctionalTestCase
{
    protected const SITE_IDENTIFIER = 'main';

    protected const ROOT_PAGE_ID = 1;

    protected array $coreExtensionsToLoad = [
        'backend',
        'frontend',
        'fluid',
        'extbase',
        'fluid_styled_content',
        'tstemplate',
    ];

    protected array $testExtensionsToLoad = [
        'balatd/typo3-dev-mcp',
        'friendsoftypo3/content-blocks',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->writeSiteConfiguration();
    }

    /**
     * The site configuration is written by hand: core's SiteBasedTestTrait lives
     * in typo3/sysext and is not reachable from an extension's test suite.
     *
     * The destination comes from Environment rather than a hardcoded `config/`:
     * a functional test instance keeps its configuration under `typo3conf/`.
     */
    private function writeSiteConfiguration(): void
    {
        $path = Environment::getConfigPath() . '/sites/' . self::SITE_IDENTIFIER;
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            self::fail('Could not create site configuration directory ' . $path);
        }

        file_put_contents($path . '/config.yaml', <<<'YAML'
            rootPageId: 1
            base: 'https://typo3-dev-mcp.test/'
            websiteTitle: 'typo3-dev-mcp functional tests'
            languages:
              - title: English
                enabled: true
                languageId: 0
                base: /
                locale: en_US.UTF-8
                navigationTitle: English
                flag: gb
            errorHandling: []
            routes: []
            YAML);
    }

    /**
     * @return array<string, ToolInterface> keyed by tool name
     */
    final protected function getTools(): array
    {
        return $this->get(ToolRegistry::class)->all();
    }

    final protected function getTool(string $name): ToolInterface
    {
        $tool = $this->get(ToolRegistry::class)->get($name);
        self::assertInstanceOf(
            ToolInterface::class,
            $tool,
            sprintf('Tool "%s" is not registered. Registered: %s', $name, implode(', ', array_keys($this->getTools()))),
        );

        return $tool;
    }
}

<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use BalatD\DevMcp\Mcp\Tool\ContentBlocksTool;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;

// Loaded by TYPO3 in addition to Services.yaml — do not import the yaml here.
return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    // Container-wide autoconfiguration: any service in any extension that
    // implements ToolInterface is tagged and announced by the MCP server.
    $containerBuilder->registerForAutoconfiguration(ToolInterface::class)
        ->addTag('devmcp.tool');

    // Tools depending on optional packages are excluded from the Services.yaml
    // glob and registered here instead, so the container still compiles when
    // the package is absent. The Composer autoloader is complete before the
    // container is built, which makes class_exists() reliable at this point —
    // but the compiled container is cached, so installing the package later
    // requires a cache flush.
    if (class_exists(ContentBlockRegistry::class)) {
        $containerConfigurator->services()
            ->set(ContentBlocksTool::class)
            ->autowire()
            ->autoconfigure();
    }
};

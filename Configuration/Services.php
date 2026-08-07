<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use BalatD\DevMcp\Mcp\ToolInterface;

// Loaded by TYPO3 in addition to Services.yaml — do not import the yaml here.
return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    // Container-wide autoconfiguration: any service in any extension that
    // implements ToolInterface is tagged and announced by the MCP server.
    $containerBuilder->registerForAutoconfiguration(ToolInterface::class)
        ->addTag('devmcp.tool');
};

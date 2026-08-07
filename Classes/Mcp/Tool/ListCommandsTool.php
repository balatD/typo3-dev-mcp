<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Console\CommandRegistry;

/**
 * Boost analog: list-artisan-commands.
 */
final class ListCommandsTool implements ToolInterface
{
    public function __construct(
        private readonly CommandRegistry $commandRegistry,
    ) {
    }

    public function getName(): string
    {
        return 'list_commands';
    }

    public function getDescription(): string
    {
        return 'List all available TYPO3 console commands (vendor/bin/typo3) with description and '
            . 'synopsis. Use this to discover the right CLI command instead of guessing names.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $commands = [];
        foreach ($this->commandRegistry->getNames() as $name) {
            try {
                $command = $this->commandRegistry->get($name);
                $commands[$name] = array_filter([
                    'description' => $command->getDescription(),
                    'synopsis' => $command->getSynopsis(),
                    'aliases' => $command->getAliases() ?: null,
                    'hidden' => $command->isHidden() ?: null,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            } catch (\Throwable $e) {
                $commands[$name] = ['error' => 'Could not load command: ' . $e->getMessage()];
            }
        }
        ksort($commands);

        return [
            'commandCount' => \count($commands),
            'commands' => $commands,
        ];
    }
}

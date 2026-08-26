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
    ) {}

    public function getName(): string
    {
        return 'list_commands';
    }

    public function getDescription(): string
    {
        return 'The TYPO3 console commands registered in this installation (vendor/bin/typo3). '
            . 'No arguments: every command name with its description. "search" filters them; '
            . '"name" returns one command with synopsis, aliases and help.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Exact command name for full detail, e.g. "cache:flush"',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Substring matched against command name and description',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $wanted = trim((string)($arguments['name'] ?? ''));
        $search = strtolower(trim((string)($arguments['search'] ?? '')));

        if ($wanted !== '') {
            try {
                $command = $this->commandRegistry->get($wanted);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'No console command "' . $wanted . '": ' . $e->getMessage()
                    . ' Call list_commands with {"search": "..."} to find it.',
                );
            }

            return array_filter([
                'name' => $wanted,
                'description' => $command->getDescription(),
                'synopsis' => $command->getSynopsis(),
                'help' => $command->getProcessedHelp(),
                'aliases' => $command->getAliases() ?: null,
                'hidden' => $command->isHidden() ?: null,
            ], static fn(mixed $value): bool => $value !== null && $value !== '');
        }

        // the list is for discovery — a name and a description are enough to
        // pick one, and synopses across ~100 commands were most of a 10 KB payload
        $commands = [];
        foreach ($this->commandRegistry->getNames() as $name) {
            try {
                $description = $this->commandRegistry->get($name)->getDescription();
            } catch (\Throwable $e) {
                $description = 'Could not load command: ' . $e->getMessage();
            }

            if ($search !== '' && !str_contains(strtolower($name . ' ' . $description), $search)) {
                continue;
            }

            $commands[$name] = $description;
        }

        if ($commands === [] && $search !== '') {
            throw new \RuntimeException(
                'No console command matches "' . $search . '". Call list_commands without arguments to list them all.',
            );
        }

        ksort($commands);

        return [
            'commandCount' => \count($commands),
            'commands' => $commands,
            'hint' => 'Pass {"name": "<command>"} for its synopsis, arguments and help.',
        ];
    }
}

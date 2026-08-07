<?php

declare(strict_types=1);

namespace T3Boost\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `typo3 boost:install` — registers the MCP server with AI assistants
 * (.mcp.json, DDEV-aware) and installs composed AI guidelines.
 */
final class InstallCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>boost:install is not implemented yet.</error>');

        return Command::FAILURE;
    }
}

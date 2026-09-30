<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Command;

use BalatD\DevMcp\Install\Client;
use BalatD\DevMcp\Install\DdevDetector;
use BalatD\DevMcp\Install\GuidelineComposer;
use BalatD\DevMcp\Install\McpConfigWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 * `typo3 devmcp:install` — registers the MCP server with AI clients
 * (project-scoped config files, DDEV-aware) and installs composed AI guidelines.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class InstallCommand extends Command
{
    public function __construct(
        private readonly DdevDetector $ddevDetector,
        private readonly McpConfigWriter $mcpConfigWriter,
        private readonly GuidelineComposer $guidelineComposer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'client',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Client to register the MCP server with: ' . implode(', ', array_column(Client::cases(), 'value'))
                . ' (repeatable; default: the clients already set up in this project, else claude)',
            )
            ->addOption('skip-mcp-json', null, InputOption::VALUE_NONE, 'Do not register the MCP server with any client')
            ->addOption('skip-guidelines', null, InputOption::VALUE_NONE, 'Do not install AI guidelines');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectPath = Environment::getProjectPath();
        $viaDdev = $this->ddevDetector->isDdevProject();

        $io->title('typo3-dev-mcp install');

        $clients = $input->getOption('skip-mcp-json') ? [] : $this->selectClients($input, $io, $projectPath);
        foreach ($clients as $client) {
            $file = $this->mcpConfigWriter->register($projectPath, $viaDdev, $client);
            $io->writeln(sprintf(
                ' ✓ Registered MCP server "typo3-dev-mcp" for %s in %s (%s)',
                $client->label(),
                $file,
                $viaDdev ? 'via `ddev exec`' : 'local PHP',
            ));
        }

        if (!$input->getOption('skip-guidelines')) {
            foreach ($this->guidelineComposer->install($projectPath, $viaDdev) as $file) {
                $io->writeln(' ✓ ' . $file);
            }
        }

        $io->newLine();
        $io->writeln('Next steps:');
        foreach ($clients as $client) {
            $io->writeln(' - ' . match ($client) {
                Client::ClaudeCode => 'Claude Code: run /mcp, or restart it, to pick up the server.',
                Client::Codex => 'Codex: start `codex` in the project root and trust the project when asked'
                    . ' — Codex ignores .codex/config.toml in untrusted projects.',
                default => $client->label() . ': restart it to pick up the server.',
            });
        }
        $io->writeln(' - Re-run this command after TYPO3 upgrades to refresh the guidelines.');

        if ($this->ddevDetector->isInsideDdevContainer()) {
            $io->newLine();
            $io->writeln('<comment>Note: you ran this inside the DDEV container. The client config files were written to');
            $io->writeln('the project root, which is shared with the host — the registered command uses `ddev exec`');
            $io->writeln('so the host-side AI client starts the server inside the container.</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<Client>
     */
    private function selectClients(InputInterface $input, SymfonyStyle $io, string $projectPath): array
    {
        /** @var list<string> $ids */
        $ids = $input->getOption('client');

        if ($ids === []) {
            $defaults = array_values(array_filter(
                Client::cases(),
                static fn(Client $client): bool => is_file($projectPath . '/' . $client->configFile()),
            )) ?: [Client::ClaudeCode];
            if (!$input->isInteractive()) {
                return $defaults;
            }

            $choices = [];
            foreach (Client::cases() as $client) {
                $choices[$client->value] = $client->label();
            }
            /** @var list<string> $ids */
            $ids = $io->choice(
                'Register the MCP server with which clients? (comma-separated)',
                $choices,
                implode(',', array_column($defaults, 'value')),
                true,
            );
        }

        return array_map(
            static fn(string $id): Client => Client::tryFrom($id) ?? throw new InvalidOptionException(sprintf(
                'Unknown client "%s". Valid clients: %s.',
                $id,
                implode(', ', array_column(Client::cases(), 'value')),
            )),
            $ids,
        );
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Command;

use BalatD\DevMcp\Install\DdevDetector;
use BalatD\DevMcp\Install\GuidelineComposer;
use BalatD\DevMcp\Install\McpJsonWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 * `typo3 devmcp:install` — registers the MCP server with AI assistants
 * (.mcp.json, DDEV-aware) and installs composed AI guidelines.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class InstallCommand extends Command
{
    public function __construct(
        private readonly DdevDetector $ddevDetector,
        private readonly McpJsonWriter $mcpJsonWriter,
        private readonly GuidelineComposer $guidelineComposer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('skip-mcp-json', null, InputOption::VALUE_NONE, 'Do not write .mcp.json')
            ->addOption('skip-guidelines', null, InputOption::VALUE_NONE, 'Do not install AI guidelines');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectPath = Environment::getProjectPath();
        $viaDdev = $this->ddevDetector->isDdevProject();

        $io->title('typo3-dev-mcp install');

        if (!$input->getOption('skip-mcp-json')) {
            $file = $this->mcpJsonWriter->register($projectPath, $viaDdev);
            $io->writeln(sprintf(
                ' ✓ Registered MCP server "typo3-dev-mcp" in %s (%s)',
                $file,
                $viaDdev ? 'via `ddev exec`' : 'local PHP',
            ));
        }

        if (!$input->getOption('skip-guidelines')) {
            foreach ($this->guidelineComposer->install($projectPath) as $file) {
                $io->writeln(' ✓ ' . $file);
            }
        }

        $io->newLine();
        $io->writeln('Next steps:');
        $io->writeln(' - Restart your AI assistant (or run /mcp in Claude Code) to pick up the server.');
        $io->writeln(' - Re-run this command after TYPO3 upgrades to refresh the guidelines.');

        if ($this->ddevDetector->isInsideDdevContainer()) {
            $io->newLine();
            $io->writeln('<comment>Note: you ran this inside the DDEV container. The .mcp.json was written to the');
            $io->writeln('project root, which is shared with the host — the registered command uses `ddev exec`');
            $io->writeln('so the host-side AI client starts the server inside the container.</comment>');
        }

        return Command::SUCCESS;
    }
}

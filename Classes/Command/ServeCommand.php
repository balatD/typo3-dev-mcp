<?php

declare(strict_types=1);

namespace T3Boost\Command;

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use T3Boost\Mcp\ToolRegistry;

/**
 * `typo3 boost:mcp` — serves the MCP protocol on stdio.
 *
 * stdout is reserved for JSON-RPC frames; anything else (PHP notices,
 * deprecations, accidental echo) would corrupt the protocol, so all
 * error output is forced to stderr before the server starts.
 */
final class ServeCommand extends Command
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->enforceStdoutHygiene();

        $builder = Server::builder()
            ->setServerInfo('t3boost', '0.1.0');

        foreach ($this->toolRegistry->all() as $tool) {
            $builder = $builder->addTool(
                $tool->execute(...),
                $tool->getName(),
                $tool->getDescription(),
                $tool->getInputSchema(),
            );
        }

        $builder->build()->run(new StdioTransport());

        return Command::SUCCESS;
    }

    private function enforceStdoutHygiene(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 'stderr');
    }
}

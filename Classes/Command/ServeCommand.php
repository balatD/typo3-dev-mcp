<?php

declare(strict_types=1);

namespace T3Boost\Command;

use Composer\InstalledVersions;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use T3Boost\Mcp\SdkToolHandler;
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
    private const INSTRUCTIONS = 'Development helper for this TYPO3 installation. '
        . 'Call application_info once at the start of a session to learn the TYPO3/PHP versions and '
        . 'installed extensions, then prefer these tools over guessing: they reflect the live '
        . 'installation (TCA, database, sites, TypoScript, logs), not just the files on disk.';

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->enforceStdoutHygiene();

        $builder = Server::builder()
            ->setServerInfo(
                't3boost',
                InstalledVersions::getPrettyVersion('t3boost/t3boost') ?? 'dev',
                'TYPO3 development helper MCP server',
            )
            ->setInstructions(self::INSTRUCTIONS);

        foreach ($this->toolRegistry->all() as $tool) {
            $builder->add(
                new Tool(
                    name: $tool->getName(),
                    title: null,
                    inputSchema: $tool->getInputSchema(),
                    description: $tool->getDescription(),
                    annotations: new ToolAnnotations(readOnlyHint: $tool->isReadOnly()),
                ),
                new SdkToolHandler($tool),
            );
        }

        return $builder->build()->run(new StdioTransport());
    }

    private function enforceStdoutHygiene(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 'stderr');
    }
}

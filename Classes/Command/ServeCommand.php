<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Command;

use Composer\InstalledVersions;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use BalatD\DevMcp\Event\CollectToolsEvent;
use BalatD\DevMcp\Mcp\SdkToolHandler;
use BalatD\DevMcp\Mcp\ToolRegistry;

/**
 * `typo3 devmcp:serve` — serves the MCP protocol on stdio.
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
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->enforceStdoutHygiene();

        $builder = Server::builder()
            ->setServerInfo(
                'typo3-dev-mcp',
                InstalledVersions::getPrettyVersion('balatd/typo3-dev-mcp') ?? 'dev',
                'TYPO3 development helper MCP server',
            )
            ->setInstructions(self::INSTRUCTIONS);

        $collectEvent = new CollectToolsEvent($this->toolRegistry->all());
        $this->eventDispatcher->dispatch($collectEvent);

        foreach ($collectEvent->getTools() as $tool) {
            /** @var array{type: 'object', properties: array<string, mixed>, required: array<string>|null} $inputSchema */
            $inputSchema = $tool->getInputSchema();
            $builder->add(
                new Tool(
                    name: $tool->getName(),
                    title: null,
                    inputSchema: $inputSchema,
                    description: $tool->getDescription(),
                    annotations: new ToolAnnotations(readOnlyHint: $tool->isReadOnly()),
                ),
                new SdkToolHandler($tool, $this->eventDispatcher),
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

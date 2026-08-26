<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Command;

use BalatD\DevMcp\Event\CollectToolsEvent;
use BalatD\DevMcp\Mcp\SdkToolHandler;
use BalatD\DevMcp\Mcp\ToolRegistry;
use Composer\InstalledVersions;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `typo3 devmcp:serve` — serves the MCP protocol on stdio.
 *
 * stdout is reserved for JSON-RPC frames; anything else (PHP notices,
 * deprecations, accidental echo) would corrupt the protocol, so all
 * error output is forced to stderr before the server starts.
 */
final class ServeCommand extends Command
{
    /**
     * The cross-tool policy lives here, not repeated in 23 tool descriptions:
     * the instructions reach every MCP client once, whereas each description is
     * schema that is re-sent on every request.
     */
    private const INSTRUCTIONS = 'Development helper for this TYPO3 installation. These tools report the '
        . 'live state — TCA, database, sites, compiled TypoScript, resolved configuration, logs — not just '
        . 'the files on disk. Prefer them over guessing table columns, CTypes, ViewHelper arguments or '
        . 'configuration keys. For pure code work (renaming, refactoring, reading or explaining a file) '
        . 'they have nothing to add; use the normal file tools.';

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

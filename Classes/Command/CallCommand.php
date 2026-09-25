<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Command;

use BalatD\DevMcp\Event\AfterToolExecutionEvent;
use BalatD\DevMcp\Event\BeforeToolExecutionEvent;
use BalatD\DevMcp\Event\CollectToolsEvent;
use BalatD\DevMcp\Mcp\Support\ToolCallEnvelope;
use BalatD\DevMcp\Mcp\ToolInterface;
use BalatD\DevMcp\Mcp\ToolRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `typo3 devmcp:call <tool>` — runs one tool call for `devmcp:serve`.
 *
 * The server spawns this for every call, so the tool sees the installation as
 * it is now rather than as it was when the server booted. Arguments arrive as
 * JSON on stdin; stdout carries exactly one ToolCallEnvelope.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class CallCommand extends Command
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tool', InputArgument::REQUIRED, 'Name of the tool to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Same hygiene as devmcp:serve: TYPO3's bootstrap may have pointed
        // display_errors back at stdout, which belongs to the envelope.
        error_reporting(\E_ALL);
        ini_set('display_errors', 'stderr');

        try {
            $tool = $this->resolveTool((string)$input->getArgument('tool'));
            $arguments = ToolCallEnvelope::readArguments($this->readStdin($input));

            $beforeEvent = new BeforeToolExecutionEvent($tool, $arguments);
            $this->eventDispatcher->dispatch($beforeEvent);

            $result = $beforeEvent->hasResult()
                ? $beforeEvent->getResult()
                : $tool->execute($beforeEvent->getArguments());

            $afterEvent = new AfterToolExecutionEvent($tool, $beforeEvent->getArguments(), $result);
            $this->eventDispatcher->dispatch($afterEvent);
        } catch (\Throwable $e) {
            return $this->fail($output, $e->getMessage());
        }

        try {
            $envelope = ToolCallEnvelope::result($afterEvent->getResult());
        } catch (\JsonException $e) {
            return $this->fail($output, 'The tool result cannot be encoded as JSON: ' . $e->getMessage());
        }

        $output->writeln($envelope, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(ToolCallEnvelope::error($message), OutputInterface::OUTPUT_RAW);

        return Command::FAILURE;
    }

    private function resolveTool(string $name): ToolInterface
    {
        $collectEvent = new CollectToolsEvent($this->toolRegistry->all());
        $this->eventDispatcher->dispatch($collectEvent);

        return $collectEvent->getTools()[$name] ?? throw new \RuntimeException(
            'Tool "' . $name . '" is no longer available in this installation. '
            . 'Restart the MCP server to refresh the tool list.',
        );
    }

    private function readStdin(InputInterface $input): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        return (string)stream_get_contents($stream ?? \STDIN);
    }
}

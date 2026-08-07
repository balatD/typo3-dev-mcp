<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp;

use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use BalatD\DevMcp\Event\AfterToolExecutionEvent;
use BalatD\DevMcp\Event\BeforeToolExecutionEvent;

/**
 * Bridges a typo3-dev-mcp tool to the MCP SDK's explicit handler contract, which
 * passes the raw argument bag instead of reflection-mapping named parameters.
 *
 * Not a DI service — instantiated by the ServeCommand (excluded in Services.yaml).
 */
final class SdkToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly ToolInterface $tool,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            $beforeEvent = new BeforeToolExecutionEvent($this->tool, $arguments);
            $this->eventDispatcher->dispatch($beforeEvent);

            $result = $beforeEvent->hasResult()
                ? $beforeEvent->getResult()
                : $this->tool->execute($beforeEvent->getArguments());

            $afterEvent = new AfterToolExecutionEvent($this->tool, $beforeEvent->getArguments(), $result);
            $this->eventDispatcher->dispatch($afterEvent);

            return $afterEvent->getResult();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Only ToolCallException reaches the client as a readable isError
            // result — anything else degrades to an opaque internal error.
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}

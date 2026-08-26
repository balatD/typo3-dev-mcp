<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp;

use BalatD\DevMcp\Event\AfterToolExecutionEvent;
use BalatD\DevMcp\Event\BeforeToolExecutionEvent;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

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
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        // The SDK injects the session and request objects into the argument bag
        // after it has validated the bag against the announced input schema, so
        // they arrive as two keys no tool declares. Strip them here: ToolInterface
        // is implemented by third parties and its contract is the schema, not
        // whatever the SDK happens to append.
        unset($arguments['_session'], $arguments['_request']);

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

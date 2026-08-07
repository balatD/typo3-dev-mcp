<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp;

use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

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
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            return $this->tool->execute($arguments);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Only ToolCallException reaches the client as a readable isError
            // result — anything else degrades to an opaque internal error.
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}

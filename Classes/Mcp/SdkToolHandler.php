<?php

declare(strict_types=1);

namespace T3Boost\Mcp;

use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Bridges a T3Boost tool to the MCP SDK's explicit handler contract, which
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
        return $this->tool->execute($arguments);
    }
}

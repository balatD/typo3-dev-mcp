<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp;

use BalatD\DevMcp\Mcp\Support\ToolCallEnvelope;
use BalatD\DevMcp\Mcp\Support\Typo3Cli;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Bridges a tool announced by `devmcp:serve` to the MCP SDK and runs every
 * call in a fresh `typo3 devmcp:call` process.
 *
 * A long-running server keeps the TCA, listeners and backend modules it booted
 * with, so after a code or configuration change it would report the old state
 * even once caches are flushed. A process per call sees what TYPO3 itself sees.
 *
 * Not a DI service — instantiated by the ServeCommand (excluded in Services.yaml).
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class SdkToolHandler implements ToolHandlerInterface
{
    private const OUTPUT_TAIL_BYTES = 2048;

    public function __construct(
        private readonly string $toolName,
        private readonly Typo3Cli $cli,
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
            $process = $this->cli->run(['devmcp:call', $this->toolName], ToolCallEnvelope::arguments($arguments));
        } catch (ProcessTimedOutException $e) {
            throw new ToolCallException(
                \sprintf('Tool "%s" did not finish within %d seconds.', $this->toolName, $this->cli->getTimeoutSeconds()),
                0,
                $e,
            );
        } catch (\Throwable $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        $errorOutput = $process->getErrorOutput();
        if ($errorOutput !== '') {
            // Keep notices and deprecations visible in the client's server log,
            // as they were when tools ran inside this process.
            fwrite(\STDERR, $errorOutput);
        }

        try {
            return ToolCallEnvelope::open($process->getOutput());
        } catch (\UnexpectedValueException) {
            throw new ToolCallException(\sprintf(
                'Tool "%s" failed without a result (exit code %d): %s',
                $this->toolName,
                (int)$process->getExitCode(),
                substr(trim($errorOutput . "\n" . $process->getOutput()), -self::OUTPUT_TAIL_BYTES),
            ));
        }
    }
}

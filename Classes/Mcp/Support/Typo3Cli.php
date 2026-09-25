<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Support;

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

/**
 * Runs `typo3` console commands in a fresh PHP process.
 *
 * The entry script comes from typo3/cms-cli, which cms-core requires, so it is
 * found regardless of Composer's bin-dir or the working directory. Errors go
 * to stderr from the first line, before TYPO3's own error handling takes over,
 * because stdout carries the result.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class Typo3Cli
{
    /** @var list<string> */
    private readonly array $command;

    /**
     * @param ?list<string> $command the invocation prefix; null resolves typo3/cms-cli's script
     */
    public function __construct(
        ?array $command = null,
        private readonly int $timeoutSeconds = 120,
    ) {
        $this->command = $command ?? [
            \PHP_BINARY,
            '-d',
            'display_errors=stderr',
            InstalledVersions::getInstallPath('typo3/cms-cli') . '/typo3',
        ];
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, ?string $input = null): Process
    {
        $process = new Process([...$this->command, ...$arguments], null, null, $input, $this->timeoutSeconds);
        $process->run();

        return $process;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }
}

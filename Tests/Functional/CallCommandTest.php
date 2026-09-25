<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use BalatD\DevMcp\Command\CallCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;

final class CallCommandTest extends AbstractToolTestCase
{
    #[Test]
    public function theCommandIsRegisteredButHidden(): void
    {
        $command = $this->get(CommandRegistry::class)->get('devmcp:call');

        self::assertInstanceOf(CallCommand::class, $command);
        self::assertTrue($command->isHidden(), 'devmcp:call is plumbing for devmcp:serve, not a command for humans.');
    }

    #[Test]
    public function aToolRunsAgainstTheBootedInstallation(): void
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('devmcp:call'));
        $tester->setInputs(['{}']);

        // The command owns its process and reroutes error output away from stdout.
        $errorReporting = error_reporting();
        $displayErrors = (string)ini_get('display_errors');
        try {
            $tester->execute(['tool' => 'site_info']);
        } finally {
            error_reporting($errorReporting);
            ini_set('display_errors', $displayErrors);
        }

        $envelope = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, $envelope['result']['siteCount']);
        self::assertArrayHasKey(self::SITE_IDENTIFIER, $envelope['result']['sites']);
    }
}

<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp\Tool;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BalatD\DevMcp\Mcp\Tool\ListCommandsTool;
use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Console\CommandRegistry;

final class ListCommandsToolTest extends TestCase
{
    private function tool(): ListCommandsTool
    {
        $commands = [
            'cache:flush' => (new Command('cache:flush'))
                ->setDescription('Flush TYPO3 caches')
                ->setAliases(['cf']),
            'site:list' => (new Command('site:list'))->setDescription('List all sites'),
        ];
        $commands['cache:flush']->addArgument('group');

        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('getNames')->willReturn(array_keys($commands));
        $registry->method('get')->willReturnCallback(
            static function (string $name) use ($commands): Command {
                return $commands[$name] ?? throw new \RuntimeException('Unknown command "' . $name . '"');
            },
        );

        return new ListCommandsTool($registry);
    }

    #[Test]
    public function theListIsNamesAndDescriptionsOnly(): void
    {
        $result = $this->tool()->execute([]);

        self::assertSame(2, $result['commandCount']);
        self::assertSame('Flush TYPO3 caches', $result['commands']['cache:flush']);
        self::assertStringNotContainsString('[group]', (string)json_encode($result));
    }

    #[Test]
    public function searchFiltersByNameAndDescription(): void
    {
        self::assertSame(['cache:flush'], array_keys($this->tool()->execute(['search' => 'cache'])['commands']));
        self::assertSame(['site:list'], array_keys($this->tool()->execute(['search' => 'all sites'])['commands']));
    }

    #[Test]
    public function anUnmatchedSearchIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No console command matches "nonsense"/');

        $this->tool()->execute(['search' => 'nonsense']);
    }

    #[Test]
    public function nameReturnsTheSynopsisAndAliases(): void
    {
        $result = $this->tool()->execute(['name' => 'cache:flush']);

        self::assertSame('cache:flush', $result['name']);
        self::assertStringContainsString('[<group>]', $result['synopsis']);
        self::assertSame(['cf'], $result['aliases']);
    }

    #[Test]
    public function anUnknownNameIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No console command "nope"/');

        $this->tool()->execute(['name' => 'nope']);
    }
}

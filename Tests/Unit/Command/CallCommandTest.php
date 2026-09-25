<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Command;

use BalatD\DevMcp\Command\CallCommand;
use BalatD\DevMcp\Event\AfterToolExecutionEvent;
use BalatD\DevMcp\Event\BeforeToolExecutionEvent;
use BalatD\DevMcp\Event\CollectToolsEvent;
use BalatD\DevMcp\Mcp\ToolInterface;
use BalatD\DevMcp\Mcp\ToolRegistry;
use BalatD\DevMcp\Tests\Unit\Fixture\CallableTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CallCommandTest extends TestCase
{
    /**
     * @param list<ToolInterface> $tools
     * @param array<class-string, list<callable>> $listeners
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the decoded envelope
     */
    private function call(string $name, array $tools, array $listeners = [], array $arguments = []): array
    {
        $dispatcher = new class ($listeners) implements EventDispatcherInterface {
            /**
             * @param array<class-string, list<callable>> $listeners
             */
            public function __construct(private readonly array $listeners) {}

            public function dispatch(object $event): object
            {
                foreach ($this->listeners[$event::class] ?? [] as $listener) {
                    $listener($event);
                }

                return $event;
            }
        };

        $tester = new CommandTester(new CallCommand(new ToolRegistry($tools), $dispatcher));
        $tester->setInputs([json_encode($arguments, \JSON_THROW_ON_ERROR)]);
        $tester->execute(['tool' => $name]);

        return json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function executesToolWithArgumentsModifiedByBeforeEvent(): void
    {
        $tool = new CallableTool('echo', static fn(array $arguments): mixed => $arguments);

        $envelope = $this->call('echo', [$tool], [
            BeforeToolExecutionEvent::class => [
                static fn(BeforeToolExecutionEvent $event) => $event->setArguments(['limit' => 5]),
            ],
        ], ['limit' => 100]);

        self::assertSame(['result' => ['limit' => 5]], $envelope);
        self::assertSame(1, $tool->executions);
    }

    #[Test]
    public function beforeEventResultShortCircuitsExecution(): void
    {
        $tool = new CallableTool('never');

        $envelope = $this->call('never', [$tool], [
            BeforeToolExecutionEvent::class => [
                static fn(BeforeToolExecutionEvent $event) => $event->setResult(['cached' => true]),
            ],
        ]);

        self::assertSame(['result' => ['cached' => true]], $envelope);
        self::assertSame(0, $tool->executions);
    }

    #[Test]
    public function afterEventCanReplaceTheResult(): void
    {
        $envelope = $this->call('secret', [new CallableTool('secret', static fn(): array => ['value' => 'raw'])], [
            AfterToolExecutionEvent::class => [
                static fn(AfterToolExecutionEvent $event) => $event->setResult(['value' => 'masked']),
            ],
        ]);

        self::assertSame(['result' => ['value' => 'masked']], $envelope);
    }

    #[Test]
    public function toolExceptionsBecomeErrorEnvelopes(): void
    {
        $envelope = $this->call('broken', [new CallableTool('broken', static fn() => throw new \RuntimeException('kaputt'))]);

        self::assertSame(['error' => 'kaputt'], $envelope);
    }

    #[Test]
    public function listenerVetoesBecomeErrorEnvelopes(): void
    {
        $envelope = $this->call('vetoed', [new CallableTool('vetoed')], [
            BeforeToolExecutionEvent::class => [static fn() => throw new \RuntimeException('vetoed by policy')],
        ]);

        self::assertSame(['error' => 'vetoed by policy'], $envelope);
    }

    #[Test]
    public function aToolAddedByACollectToolsListenerCanBeCalled(): void
    {
        $envelope = $this->call('project_info', [], [
            CollectToolsEvent::class => [
                static fn(CollectToolsEvent $event) => $event->addTool(
                    new CallableTool('project_info', static fn(): array => ['deployTarget' => 'staging']),
                ),
            ],
        ]);

        self::assertSame(['result' => ['deployTarget' => 'staging']], $envelope);
    }

    #[Test]
    public function aToolThatIsGoneExplainsHowToRefreshTheList(): void
    {
        $envelope = $this->call('removed', []);

        self::assertStringContainsString('Tool "removed" is no longer available', $envelope['error']);
        self::assertStringContainsString('Restart the MCP server', $envelope['error']);
    }

    #[Test]
    public function resultTextIsWrittenRaw(): void
    {
        $envelope = $this->call('markup', [new CallableTool('markup', static fn(): string => '<info>kept</info>')]);

        self::assertSame(['result' => '<info>kept</info>'], $envelope);
    }
}

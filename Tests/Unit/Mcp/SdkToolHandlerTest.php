<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Mcp;

use BalatD\DevMcp\Event\AfterToolExecutionEvent;
use BalatD\DevMcp\Event\BeforeToolExecutionEvent;
use BalatD\DevMcp\Mcp\SdkToolHandler;
use BalatD\DevMcp\Tests\Unit\Fixture\CallableTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class SdkToolHandlerTest extends TestCase
{
    /**
     * @param array<class-string, list<callable>> $listeners
     */
    private function createDispatcher(array $listeners = []): EventDispatcherInterface
    {
        return new class ($listeners) implements EventDispatcherInterface {
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
    }

    private function createGateway(): ClientGateway
    {
        return (new \ReflectionClass(ClientGateway::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function executesToolWithArgumentsModifiedByBeforeEvent(): void
    {
        $tool = new CallableTool('echo', static fn(array $arguments): mixed => $arguments);
        $dispatcher = $this->createDispatcher([
            BeforeToolExecutionEvent::class => [
                static fn(BeforeToolExecutionEvent $event) => $event->setArguments(['limit' => 5]),
            ],
        ]);

        $result = (new SdkToolHandler($tool, $dispatcher))->execute(['limit' => 100], $this->createGateway());

        self::assertSame(['limit' => 5], $result);
        self::assertSame(1, $tool->executions);
    }

    #[Test]
    public function sdkInjectedSessionAndRequestKeysNeverReachTheTool(): void
    {
        $tool = new CallableTool('echo', static fn(array $arguments): mixed => $arguments);

        $seenByListener = null;
        $dispatcher = $this->createDispatcher([
            BeforeToolExecutionEvent::class => [
                static function (BeforeToolExecutionEvent $event) use (&$seenByListener): void {
                    $seenByListener = $event->getArguments();
                },
            ],
        ]);

        $result = (new SdkToolHandler($tool, $dispatcher))->execute([
            'limit' => 5,
            '_session' => new \stdClass(),
            '_request' => new \stdClass(),
        ], $this->createGateway());

        self::assertSame(['limit' => 5], $result);
        self::assertSame(['limit' => 5], $seenByListener);
    }

    #[Test]
    public function beforeEventResultShortCircuitsExecution(): void
    {
        $tool = new CallableTool('never');
        $dispatcher = $this->createDispatcher([
            BeforeToolExecutionEvent::class => [
                static fn(BeforeToolExecutionEvent $event) => $event->setResult(['cached' => true]),
            ],
        ]);

        $result = (new SdkToolHandler($tool, $dispatcher))->execute([], $this->createGateway());

        self::assertSame(['cached' => true], $result);
        self::assertSame(0, $tool->executions);
    }

    #[Test]
    public function afterEventCanReplaceTheResult(): void
    {
        $tool = new CallableTool('secret', static fn(): array => ['value' => 'raw']);
        $dispatcher = $this->createDispatcher([
            AfterToolExecutionEvent::class => [
                static fn(AfterToolExecutionEvent $event) => $event->setResult(['value' => 'masked']),
            ],
        ]);

        $result = (new SdkToolHandler($tool, $dispatcher))->execute([], $this->createGateway());

        self::assertSame(['value' => 'masked'], $result);
    }

    #[Test]
    public function throwablesBecomeToolCallExceptions(): void
    {
        $tool = new CallableTool('broken', static fn() => throw new \RuntimeException('kaputt'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('kaputt');

        (new SdkToolHandler($tool, $this->createDispatcher()))->execute([], $this->createGateway());
    }

    #[Test]
    public function listenerExceptionsBecomeToolCallExceptions(): void
    {
        $tool = new CallableTool('vetoed');
        $dispatcher = $this->createDispatcher([
            BeforeToolExecutionEvent::class => [
                static fn() => throw new \RuntimeException('vetoed by policy'),
            ],
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('vetoed by policy');

        (new SdkToolHandler($tool, $dispatcher))->execute([], $this->createGateway());
    }
}

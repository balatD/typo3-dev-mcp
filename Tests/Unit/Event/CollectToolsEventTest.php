<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BalatD\DevMcp\Event\CollectToolsEvent;
use BalatD\DevMcp\Tests\Unit\Fixture\CallableTool;

final class CollectToolsEventTest extends TestCase
{
    #[Test]
    public function toolsCanBeAddedRemovedAndReplaced(): void
    {
        $event = new CollectToolsEvent([
            'builtin' => new CallableTool('builtin'),
        ]);

        $event->addTool(new CallableTool('custom'));
        $event->removeTool('builtin');

        self::assertFalse($event->hasTool('builtin'));
        self::assertTrue($event->hasTool('custom'));
        self::assertSame(['custom'], array_keys($event->getTools()));

        $replacement = new CallableTool('custom');
        $event->addTool($replacement);
        self::assertSame($replacement, $event->getTools()['custom']);
    }
}

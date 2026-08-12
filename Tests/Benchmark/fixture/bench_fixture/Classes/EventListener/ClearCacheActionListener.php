<?php

declare(strict_types=1);

namespace BalatD\BenchFixture\EventListener;

use TYPO3\CMS\Backend\Backend\Event\ModifyClearCacheActionsEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Exists so `list_events` has a registered listener to report. This event has
 * no core listeners in a vanilla install, which keeps the fixture's listener
 * unambiguous in benchmark assertions.
 */
#[AsEventListener(identifier: 'bench-fixture/clear-cache-action')]
final class ClearCacheActionListener
{
    public function __invoke(ModifyClearCacheActionsEvent $event): void
    {
        $event->addCacheAction([
            'id' => 'benchFixture',
            'title' => 'Benchmark fixture cache',
            'description' => 'No-op action registered by the benchmark fixture.',
            'href' => '#',
            'iconIdentifier' => 'actions-system-cache-clear',
        ]);
    }
}

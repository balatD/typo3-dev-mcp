<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * TYPO3 has hundreds of PSR-14 events and no runtime listing, so both halves of
 * the question are guesswork today: which event to hook into, and who already
 * listens to it.
 *
 * ListenerProvider::getAllListenerDefinitions() is marked @internal for
 * debugging use — which is exactly this — and EXT:lowlevel's configuration
 * module injects it the same way.
 */
final class ListEventsTool implements ToolInterface
{
    private const LIST_LIMIT = 60;

    /** @var array<string, string>|null PSR-4 namespace prefix => package key */
    private ?array $namespaceMap = null;

    public function __construct(
        private readonly ListenerProvider $listenerProvider,
        private readonly PackageManager $packageManager,
    ) {
    }

    public function getName(): string
    {
        return 'list_events';
    }

    public function getDescription(): string
    {
        return 'List PSR-14 events of this installation and the listeners actually registered for them '
            . '(class, method, identifier). Pass "event" to filter by event class, "listener" to find what '
            . 'a class listens to, or "withListenersOnly" to see only events that are hooked. Use this '
            . 'before adding an event listener instead of guessing event class names.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event' => [
                    'type' => 'string',
                    'description' => 'Substring of the event class, e.g. "ModifyPageLinkConfiguration" or "Cache"',
                ],
                'listener' => [
                    'type' => 'string',
                    'description' => 'Substring of a listener class or identifier — finds what it listens to',
                ],
                'withListenersOnly' => [
                    'type' => 'boolean',
                    'description' => 'Only return events that have at least one listener (default false)',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $eventFilter = strtolower(trim((string)($arguments['event'] ?? '')));
        $listenerFilter = strtolower(trim((string)($arguments['listener'] ?? '')));
        $withListenersOnly = (bool)($arguments['withListenersOnly'] ?? false);

        $listeners = $this->collectListeners();
        $events = $listenerFilter !== '' ? [] : $this->collectEventClasses();

        // events with listeners are authoritative; the class scan only adds the unhooked ones
        $eventClasses = array_unique([...array_keys($listeners), ...$events]);
        sort($eventClasses);

        $results = [];
        $totalMatches = 0;

        foreach ($eventClasses as $eventClass) {
            $eventListeners = $listeners[$eventClass] ?? [];

            if ($withListenersOnly && $eventListeners === []) {
                continue;
            }
            if ($eventFilter !== '' && !str_contains(strtolower($eventClass), $eventFilter)) {
                continue;
            }
            if ($listenerFilter !== '' && !$this->matchesListener($eventListeners, $listenerFilter)) {
                continue;
            }

            ++$totalMatches;
            if (\count($results) >= self::LIST_LIMIT) {
                continue;
            }

            $results[$eventClass] = array_filter([
                'listenerCount' => \count($eventListeners),
                'listeners' => $eventListeners !== [] ? $eventListeners : null,
                'package' => $this->packageOfClass($eventClass),
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($results === [] && ($eventFilter !== '' || $listenerFilter !== '')) {
            throw new \RuntimeException(
                'No event matches this filter. Call list_events without arguments to browse, or use '
                . 'search_changelog / search_docs to find the event introduced for a feature.',
            );
        }

        return array_filter([
            'eventCount' => \count($results),
            'truncated' => $totalMatches > \count($results) ? $totalMatches : null,
            'events' => $results,
            'hint' => 'Events without listeners are extension points nobody uses yet. Register a listener '
                . 'with the #[AsEventListener] attribute on an invokable class.',
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function collectListeners(): array
    {
        try {
            $definitions = $this->listenerProvider->getAllListenerDefinitions();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Registered listeners could not be read (the core listener provider is @internal and may '
                . 'have drifted in this version): ' . $e->getMessage(),
            );
        }

        $listeners = [];
        foreach ($definitions as $eventClass => $eventListeners) {
            foreach ($eventListeners as $identifier => $listener) {
                $service = (string)($listener['service'] ?? $identifier);
                $method = $listener['method'] ?? null;

                $listeners[(string)$eventClass][] = array_filter([
                    'service' => $service,
                    // a null method means the listener is invokable
                    'method' => \is_string($method) ? $method : '__invoke',
                    'identifier' => (string)$identifier !== $service ? (string)$identifier : null,
                ], static fn (mixed $value): bool => $value !== null);
            }
        }

        return $listeners;
    }

    /**
     * Core registers no marker interface for events, so they are discovered by
     * convention: a class named *Event inside an \Event\ namespace.
     *
     * @return list<string>
     */
    private function collectEventClasses(): array
    {
        $events = [];

        foreach ($this->packageManager->getActivePackages() as $package) {
            $classesPath = $package->getPackagePath() . 'Classes/';
            if (!is_dir($classesPath)) {
                continue;
            }

            $namespace = array_search($package->getPackageKey(), $this->namespaceMap(), true);
            if (!\is_string($namespace)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($classesPath, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = substr($file->getPathname(), \strlen($classesPath), -4);
                if (!str_contains($relativePath, 'Event') || !str_ends_with($relativePath, 'Event')) {
                    continue;
                }

                $events[] = $namespace . str_replace('/', '\\', $relativePath);
            }
        }

        return $events;
    }

    /**
     * @return array<string, string> PSR-4 namespace prefix => package key
     */
    private function namespaceMap(): array
    {
        if ($this->namespaceMap !== null) {
            return $this->namespaceMap;
        }

        $map = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $autoload = $package->getValueFromComposerManifest('autoload');
            if (!\is_object($autoload)) {
                continue;
            }

            foreach ((array)($autoload->{'psr-4'} ?? []) as $namespace => $path) {
                if (\is_string($path) && str_contains($path, 'Classes/')) {
                    $map[(string)$namespace] = $package->getPackageKey();
                }
            }
        }

        return $this->namespaceMap = $map;
    }

    /**
     * @param list<array<string, string>> $eventListeners
     */
    private function matchesListener(array $eventListeners, string $filter): bool
    {
        foreach ($eventListeners as $listener) {
            if (str_contains(strtolower(implode(' ', $listener)), $filter)) {
                return true;
            }
        }

        return false;
    }

    private function packageOfClass(string $eventClass): ?string
    {
        foreach ($this->namespaceMap() as $namespace => $packageKey) {
            if (str_starts_with($eventClass, $namespace)) {
                return $packageKey;
            }
        }

        return null;
    }
}

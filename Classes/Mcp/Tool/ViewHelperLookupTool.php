<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use Composer\Autoload\ClassLoader;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Schema\ViewHelperFinder;
use TYPO3Fluid\Fluid\Schema\ViewHelperMetadata;

/**
 * ViewHelper names and — above all — argument names are what an AI gets wrong
 * most often in Fluid, because they live in PHP classes that the template never
 * references. This reads the real argument definitions of every ViewHelper
 * registered in this installation, core and extensions alike.
 *
 * Built on the same Fluid API as `fluid:schema:generate`.
 */
final class ViewHelperLookupTool implements ToolInterface
{
    private const SEARCH_LIMIT = 40;

    private const SUMMARY_LENGTH = 160;

    private const DOCUMENTATION_LENGTH = 1200;

    public function __construct(
        private readonly ClassLoader $classLoader,
        private readonly ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
    ) {
    }

    public function getName(): string
    {
        return 'viewhelper_lookup';
    }

    public function getDescription(): string
    {
        return 'Look up Fluid ViewHelpers available in this installation with their exact arguments '
            . '(name, type, required, default, description). Pass "name" for one ViewHelper in full detail '
            . '(e.g. "f:link.page"), "search" to find candidates, or nothing to list the registered Fluid '
            . 'namespaces. Always check here before writing Fluid instead of guessing argument names.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Exact ViewHelper tag, e.g. "f:link.page" or "f:format.date"',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Substring matched against tag name, PHP class and summary, e.g. "image"',
                ],
                'namespace' => [
                    'type' => 'string',
                    'description' => 'Restrict to one Fluid namespace alias, e.g. "f" or "core"',
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
        $name = trim((string)($arguments['name'] ?? ''));
        $search = strtolower(trim((string)($arguments['search'] ?? '')));
        $namespaceFilter = trim((string)($arguments['namespace'] ?? ''), ': ');

        $byTag = $this->collectViewHelpers();

        if ($name !== '') {
            return $this->describeSingle($byTag, $name);
        }

        if ($search === '' && $namespaceFilter === '') {
            return $this->listNamespaces($byTag);
        }

        return $this->searchViewHelpers($byTag, $search, $namespaceFilter);
    }

    /**
     * Maps every registered Fluid namespace alias to its ViewHelpers, keyed by
     * tag name. Aliases may bundle several PHP namespaces (e.g. "f" merges
     * Fluid standalone and EXT:fluid) and later ones override earlier ones.
     *
     * @return array<string, array<string, ViewHelperMetadata>>
     */
    private function collectViewHelpers(): array
    {
        // Collecting metadata means calling initializeArguments() on every
        // ViewHelper, and deprecated ones raise E_USER_DEPRECATED right there
        // (e.g. <f:cache.warmup> on Fluid 4). Unhandled, TYPO3's error handler
        // would write those into the project's deprecation log on every lookup,
        // making read_log_entries report deprecations the developer never
        // caused. Swallow them for the duration of the scan only.
        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);

        try {
            $finder = new ViewHelperFinder();
            $viewHelpers = $finder->findViewHelpersInComposerProject($this->classLoader);
            $namespaces = $this->viewHelperResolverFactory->create()->getNamespaces();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'ViewHelpers could not be collected (the Fluid schema API is @internal and may have drifted '
                . 'in this version): ' . $e->getMessage(),
            );
        } finally {
            restore_error_handler();
        }

        $byPhpNamespace = [];
        foreach ($viewHelpers as $viewHelper) {
            $byPhpNamespace[$viewHelper->namespace][] = $viewHelper;
        }

        $byTag = [];
        foreach ($namespaces as $alias => $phpNamespaces) {
            // a null entry means the alias is registered but deliberately empty
            foreach ($phpNamespaces ?? [] as $phpNamespace) {
                foreach ($byPhpNamespace[$phpNamespace] ?? [] as $viewHelper) {
                    $byTag[(string)$alias][$viewHelper->tagName] = $viewHelper;
                }
            }
        }

        foreach ($byTag as $alias => $viewHelpers) {
            ksort($viewHelpers);
            $byTag[$alias] = $viewHelpers;
        }

        return $byTag;
    }

    /**
     * @param array<string, array<string, ViewHelperMetadata>> $byTag
     * @return array<string, mixed>
     */
    private function listNamespaces(array $byTag): array
    {
        $namespaces = [];
        foreach ($byTag as $alias => $viewHelpers) {
            $namespaces[$alias] = \count($viewHelpers);
        }

        return [
            'namespaces' => $namespaces,
            'hint' => 'Counts are ViewHelpers per Fluid namespace alias. Pass {"search": "<word>"} to find '
                . 'one, or {"name": "f:link.page"} for the full argument list.',
        ];
    }

    /**
     * @param array<string, array<string, ViewHelperMetadata>> $byTag
     * @return array<string, mixed>
     */
    private function describeSingle(array $byTag, string $name): array
    {
        [$alias, $tagName] = $this->splitName($byTag, $name);

        $viewHelper = $byTag[$alias][$tagName] ?? null;
        if ($viewHelper === null) {
            throw new \RuntimeException(
                'No ViewHelper "' . $name . '". Use {"search": "' . ($tagName !== '' ? $tagName : $name)
                . '"} to find the correct tag name.',
            );
        }

        $viewHelperArguments = [];
        foreach ($viewHelper->argumentDefinitions as $argument) {
            $viewHelperArguments[$argument->getName()] = array_filter([
                'type' => $argument->getType(),
                'required' => $argument->isRequired() ?: null,
                'default' => $argument->getDefaultValue(),
                'description' => $argument->getDescription(),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return array_filter([
            'name' => $alias . ':' . $tagName,
            'class' => $viewHelper->className,
            'documentation' => mb_strimwidth(trim($viewHelper->documentation), 0, self::DOCUMENTATION_LENGTH, '…'),
            'arguments' => $viewHelperArguments,
            'allowsArbitraryArguments' => $viewHelper->allowsArbitraryArguments ?: null,
            'usage' => '<' . $alias . ':' . $tagName . ' … /> or {value -> ' . $alias . ':' . $tagName . '(…)}',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, array<string, ViewHelperMetadata>> $byTag
     * @return array<string, mixed>
     */
    private function searchViewHelpers(array $byTag, string $search, string $namespaceFilter): array
    {
        $matches = [];
        $totalMatches = 0;

        foreach ($byTag as $alias => $viewHelpers) {
            if ($namespaceFilter !== '' && $alias !== $namespaceFilter) {
                continue;
            }

            foreach ($viewHelpers as $tagName => $viewHelper) {
                $summary = $this->summarize($viewHelper->documentation);
                $haystack = strtolower($alias . ':' . $tagName . ' ' . $viewHelper->className . ' ' . $summary);

                if ($search !== '' && !str_contains($haystack, $search)) {
                    continue;
                }

                ++$totalMatches;
                if (\count($matches) >= self::SEARCH_LIMIT) {
                    continue;
                }

                $matches[$alias . ':' . $tagName] = array_filter([
                    'class' => $viewHelper->className,
                    'summary' => $summary,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }
        }

        if ($matches === []) {
            throw new \RuntimeException(
                'No ViewHelper matches "' . ($search !== '' ? $search : $namespaceFilter)
                . '". Call viewhelper_lookup without arguments to see the registered namespaces.',
            );
        }

        return array_filter([
            'resultCount' => \count($matches),
            'truncated' => $totalMatches > \count($matches) ? $totalMatches : null,
            'viewHelpers' => $matches,
            'hint' => 'Pass {"name": "<tag>"} for the full argument list of one of these.',
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, array<string, ViewHelperMetadata>> $byTag
     * @return array{0: string, 1: string}
     */
    private function splitName(array $byTag, string $name): array
    {
        $name = trim($name, '<>/ ');

        if (str_contains($name, ':')) {
            [$alias, $tagName] = explode(':', $name, 2);

            return [$alias, $tagName];
        }

        // an unprefixed tag is almost always meant as "f:"
        foreach (array_keys($byTag) as $alias) {
            if (isset($byTag[$alias][$name])) {
                return [$alias, $name];
            }
        }

        return ['f', $name];
    }

    private function summarize(string $documentation): string
    {
        $firstParagraph = trim(explode("\n\n", trim($documentation), 2)[0]);

        return mb_strimwidth(
            trim((string)preg_replace('/\s+/', ' ', $firstParagraph)),
            0,
            self::SUMMARY_LENGTH,
            '…',
        );
    }
}

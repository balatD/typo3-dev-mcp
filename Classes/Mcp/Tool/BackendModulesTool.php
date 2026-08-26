<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LabelTranslator;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;

/**
 * TYPO3 v14 renamed most backend modules (Web → Content, File → Media,
 * Admin Tools → Administration), so identifiers remembered from older versions
 * are actively wrong — and `debug:backend:modules` only exists on v14.
 *
 * ModuleProvider is public API and skips every access check when no backend
 * user is passed, which is what makes this work on the CLI.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class BackendModulesTool implements ToolInterface
{
    public function __construct(
        private readonly ModuleProvider $moduleProvider,
        private readonly LabelTranslator $labelTranslator,
    ) {}

    public function getName(): string
    {
        return 'backend_modules';
    }

    public function getDescription(): string
    {
        return 'Registered backend modules with identifier, parent, path, access level and routes. '
            . '"identifier" for one module in detail, "search" to find one. Identifiers changed in '
            . 'TYPO3 v14 — resolve them here rather than from memory.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'identifier' => [
                    'type' => 'string',
                    'description' => 'Exact module identifier, e.g. "web_layout" or "site_configuration"',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Substring matched against identifier, title and controller',
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
        $identifier = trim((string)($arguments['identifier'] ?? ''));
        $search = strtolower(trim((string)($arguments['search'] ?? '')));

        if ($identifier !== '') {
            // null user: report the registration, not what somebody may access
            $module = $this->moduleProvider->getModule($identifier, null, false);
            if ($module === null) {
                throw new \RuntimeException(
                    'No backend module "' . $identifier . '". Call backend_modules without arguments to '
                    . 'list them all — identifiers changed in TYPO3 v14.',
                );
            }

            return $this->describeModule($module, true);
        }

        $modules = [];
        foreach ($this->moduleProvider->getModules(null, false, false) as $module) {
            $haystack = strtolower($module->getIdentifier() . ' ' . $module->getTitle() . ' ' . $module->getPath());
            if ($search !== '' && !str_contains($haystack, $search)) {
                continue;
            }

            $modules[$module->getIdentifier()] = $this->describeModule($module, false);
        }

        if ($modules === [] && $search !== '') {
            throw new \RuntimeException(
                'No backend module matches "' . $search . '". Call backend_modules without arguments to list them all.',
            );
        }

        ksort($modules);

        return [
            'moduleCount' => \count($modules),
            'modules' => $modules,
            'hint' => 'Pass {"identifier": "<identifier>"} for routes, access and sub-modules of one module.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeModule(ModuleInterface $module, bool $detailed): array
    {
        $description = array_filter([
            'title' => $this->labelTranslator->translate($module->getTitle()),
            'path' => $module->getPath(),
            'parent' => $module->hasParentModule() ? $module->getParentIdentifier() : null,
            'access' => $module->getAccess(),
            'standalone' => $module->isStandalone() ?: null,
            'subModules' => array_keys($module->getSubModules()) ?: null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        if (!$detailed) {
            return $description;
        }

        $routes = [];
        foreach ($module->getDefaultRouteOptions() as $routeName => $options) {
            $routes[(string)$routeName] = array_filter([
                'target' => \is_array($options) ? ($options['target'] ?? null) : null,
                'path' => \is_array($options) ? ($options['path'] ?? null) : null,
            ], static fn(mixed $value): bool => $value !== null);
        }

        return array_filter([
            'identifier' => $module->getIdentifier(),
            ...$description,
            'shortDescription' => $this->labelTranslator->translate($module->getShortDescription()),
            'description' => $this->labelTranslator->translate($module->getDescription()),
            'iconIdentifier' => $module->getIconIdentifier(),
            'component' => $module->getComponent(),
            'navigationComponent' => $module->getNavigationComponent(),
            'workspaceAccess' => $module->getWorkspaceAccess(),
            'position' => $module->getPosition() ?: null,
            'aliases' => $module->getAliases() ?: null,
            'routes' => $routes !== [] ? $routes : null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }
}

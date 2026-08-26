<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LabelTranslator;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Set\SetDefinition;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Site sets (v13.1+) are the modern way to ship TypoScript, page TSconfig and
 * typed settings as composable bundles, but `site:sets:list` only prints names —
 * the settings definitions and the resolved dependency order are invisible from
 * the CLI.
 *
 * site_info lists which sets a site uses; this tool explains what they contain.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class SiteSetsTool implements ToolInterface
{
    public function __construct(
        private readonly SetRegistry $setRegistry,
        private readonly SiteFinder $siteFinder,
        private readonly LabelTranslator $labelTranslator,
    ) {}

    public function getName(): string
    {
        return 'site_sets';
    }

    public function getDescription(): string
    {
        return 'TYPO3 site sets: which are registered, their dependency-resolved order, the settings they '
            . 'define (key, type, default, category) and the TypoScript / page TSconfig they contribute. '
            . '"set" for one in detail, "site" for a site\'s sets plus effective settings. Broken sets are '
            . 'reported too — check here when a set seems to be ignored.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'set' => [
                    'type' => 'string',
                    'description' => 'Set name, e.g. "typo3/fluid-styled-content" — omit to list all sets',
                ],
                'site' => [
                    'type' => 'string',
                    'description' => 'Site identifier (from site_info) — returns that site\'s sets in '
                        . 'dependency order plus its effective settings',
                ],
                'includeSettings' => [
                    'type' => 'boolean',
                    'description' => 'Include settings definitions in the list view (default false; always '
                        . 'included when "set" is given)',
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
        $setName = trim((string)($arguments['set'] ?? ''));
        $siteIdentifier = trim((string)($arguments['site'] ?? ''));
        $includeSettings = (bool)($arguments['includeSettings'] ?? false);

        if ($setName !== '') {
            return $this->describeSingleSet($setName);
        }

        if ($siteIdentifier !== '') {
            return $this->describeSite($siteIdentifier, $includeSettings);
        }

        return $this->listAllSets($includeSettings);
    }

    /**
     * @return array<string, mixed>
     */
    private function listAllSets(bool $includeSettings): array
    {
        $sets = [];
        foreach ($this->allSets() as $set) {
            $sets[$set->name] = $this->describeSet($set, $includeSettings);
        }

        return array_filter([
            'setCount' => \count($sets),
            'sets' => $sets,
            'invalidSets' => $this->describeInvalidSets(),
            'hint' => 'Pass {"set": "<name>"} for the full settings definitions of one set, or '
                . '{"site": "<identifier>"} for the sets a site actually uses.',
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSingleSet(string $setName): array
    {
        $set = $this->setRegistry->getSet($setName);
        if ($set === null) {
            throw new \RuntimeException(
                'Unknown site set "' . $setName . '". Call site_sets without arguments to list all '
                . 'registered sets — the name is the "name" field of the set\'s config.yaml.',
            );
        }

        // getSets() returns the set plus every (transitive) dependency, already ordered
        $resolved = [];
        foreach ($this->setRegistry->getSets($setName) as $dependency) {
            $resolved[] = $dependency->name;
        }

        return [
            'set' => $this->describeSet($set, true),
            'resolvedOrder' => $resolved,
            'hint' => 'resolvedOrder is the load order including transitive dependencies; later entries '
                . 'override earlier ones.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSite(string $siteIdentifier, bool $includeSettings): array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            throw new \RuntimeException(
                'No site "' . $siteIdentifier . '". Use site_info to list the configured site identifiers.',
            );
        }

        $requested = $site->getSets();
        $resolved = [];
        foreach ($this->setRegistry->getSets(...$requested) as $set) {
            $resolved[$set->name] = $this->describeSet($set, $includeSettings);
        }

        return [
            'site' => $siteIdentifier,
            'requestedSets' => $requested,
            'resolvedSets' => $resolved,
            'effectiveSettings' => $this->effectiveSettings($site),
            'hint' => $requested === []
                ? 'This site uses no site sets — add them under "dependencies" in its config.yaml. '
                    . 'Call site_sets without arguments to see which sets are available.'
                : 'requestedSets is what config.yaml asks for, resolvedSets adds the transitive '
                    . 'dependencies in load order. effectiveSettings are the merged values actually in use.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveSettings(Site $site): array
    {
        try {
            return $site->getSettings()->getAllFlat();
        } catch (\Throwable $e) {
            return ['error' => 'Settings could not be resolved: ' . $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSet(SetDefinition $set, bool $includeSettings): array
    {
        $settings = [];
        if ($includeSettings) {
            foreach ($set->settingsDefinitions as $definition) {
                $settings[$definition->key] = $this->describeSettingDefinition($definition);
            }
        }

        return array_filter([
            'label' => $this->labelTranslator->translate($set->label),
            'dependencies' => $set->dependencies !== [] ? $set->dependencies : null,
            'optionalDependencies' => $set->optionalDependencies !== [] ? $set->optionalDependencies : null,
            'typoscript' => $set->typoscript,
            'pageTsConfig' => $set->pagets,
            // route enhancers in sets only exist since v14.1, so they are read
            // through toArray() rather than as a property
            'routeEnhancers' => $this->routeEnhancerIdentifiers($set),
            'settingsOverrides' => $set->settings !== [] ? $set->settings : null,
            'hidden' => $set->hidden ?: null,
            'settingsDefinitionCount' => \count($set->settingsDefinitions),
            'settingsDefinitions' => $settings !== [] ? $settings : null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Reads the public promoted properties rather than the serializer, because
     * v13 offers toArray() and v14 replaced it with jsonSerialize().
     *
     * @return array<string, mixed>
     */
    private function describeSettingDefinition(SettingDefinition $definition): array
    {
        return array_filter([
            'type' => $definition->type,
            'default' => $definition->default,
            'label' => $this->labelTranslator->translate($definition->label),
            'description' => $this->labelTranslator->translate($definition->description),
            'category' => $definition->category,
            'enum' => $definition->enum !== [] ? $this->translateEnum($definition->enum) : null,
            'readonly' => $definition->readonly ?: null,
            'tags' => $definition->tags !== [] ? $definition->tags : null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return list<SetDefinition>
     */
    private function allSets(): array
    {
        try {
            // @internal in core, but this is what `site:sets:list` itself uses
            return array_values($this->setRegistry->getAllSets());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Site sets could not be collected: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int|string, string|int|float|bool> $enum
     * @return array<int|string, string|int|float|bool>
     */
    private function translateEnum(array $enum): array
    {
        foreach ($enum as $value => $label) {
            if (\is_string($label)) {
                $enum[$value] = (string)$this->labelTranslator->translate($label);
            }
        }

        return $enum;
    }

    /**
     * @return list<string>|null identifiers only — the enhancer bodies belong to routing, not to sets
     */
    private function routeEnhancerIdentifiers(SetDefinition $set): ?array
    {
        $routeEnhancers = $set->toArray()['routeEnhancers'] ?? [];

        return \is_array($routeEnhancers) && $routeEnhancers !== []
            ? array_map(strval(...), array_keys($routeEnhancers))
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeInvalidSets(): ?array
    {
        $invalid = [];
        foreach ($this->setRegistry->getInvalidSets() as $name => $details) {
            $invalid[(string)$name] = [
                'error' => $details['error']->value,
                'context' => $details['context'],
            ];
        }

        return $invalid !== [] ? $invalid : null;
    }
}

<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use T3Boost\Mcp\Support\LabelTranslator;
use T3Boost\Mcp\ToolInterface;

/**
 * Lists the registered content element types (CTypes) — the vocabulary an AI
 * needs when creating content, templates or new content elements.
 */
final class ContentElementsTool implements ToolInterface
{
    public function __construct(
        private readonly LabelTranslator $labelTranslator,
    ) {
    }

    public function getName(): string
    {
        return 'content_elements';
    }

    public function getDescription(): string
    {
        return 'List all registered content element types (tt_content CTypes) with label and group, plus '
            . 'legacy list_type plugins where present (removed in TYPO3 v14). Use this before creating '
            . 'content elements or Fluid templates to see what exists and avoid inventing CTypes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        $result = [
            'contentElements' => $this->collectItems('CType'),
        ];

        // list_type was removed in v14; only report it where it still exists
        if (isset($GLOBALS['TCA']['tt_content']['columns']['list_type'])) {
            $plugins = $this->collectItems('list_type');
            if ($plugins !== []) {
                $result['legacyListTypePlugins'] = $plugins;
            }
        }

        $result['count'] = \count($result['contentElements']);

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectItems(string $column): array
    {
        $items = $GLOBALS['TCA']['tt_content']['columns'][$column]['config']['items'] ?? [];

        $collected = [];
        foreach ($items as $item) {
            // v12+ associative item arrays, with a defensive fallback for legacy numeric ones
            $value = \is_array($item) ? ($item['value'] ?? $item[1] ?? null) : null;
            if ($value === null || $value === '--div--' || $value === '') {
                continue;
            }

            $collected[] = array_filter([
                'value' => $value,
                'label' => $this->labelTranslator->translate($item['label'] ?? $item[0] ?? null),
                'group' => $item['group'] ?? null,
                'icon' => $item['icon'] ?? null,
            ], static fn (mixed $itemValue): bool => $itemValue !== null && $itemValue !== '');
        }

        return $collected;
    }
}

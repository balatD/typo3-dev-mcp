<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LabelTranslator;
use BalatD\DevMcp\Mcp\ToolInterface;

/**
 * No boost analog — TYPO3's semantic data model. The TCA is what an AI
 * actually needs to write correct queries, forms and extensions.
 */
final class TcaSchemaTool implements ToolInterface
{
    public function __construct(
        private readonly LabelTranslator $labelTranslator,
    ) {
    }

    public function getName(): string
    {
        return 'tca_schema';
    }

    public function getDescription(): string
    {
        return 'Inspect the loaded TCA (Table Configuration Array) — TYPO3\'s semantic data model. '
            . 'Without arguments: all TCA tables with their key ctrl settings. With "table": per-column '
            . 'summary (type, relations) plus record types and palettes. With "table" and "field": the '
            . 'complete column configuration. Prefer this over database_schema to understand relations, '
            . 'enable-fields and record types.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'TCA table name, e.g. "tt_content" or "pages"',
                ],
                'field' => [
                    'type' => 'string',
                    'description' => 'Column name within "table" to get the full configuration for',
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
        $tca = $GLOBALS['TCA'] ?? [];
        if ($tca === []) {
            throw new \RuntimeException('TCA is not loaded — this should not happen in CLI context.');
        }

        $table = $arguments['table'] ?? null;
        $field = $arguments['field'] ?? null;

        if (!\is_string($table) || $table === '') {
            return $this->listTables($tca);
        }

        if (!isset($tca[$table])) {
            throw new \RuntimeException(
                'Table "' . $table . '" has no TCA. Call tca_schema without arguments to list TCA tables.',
            );
        }

        if (\is_string($field) && $field !== '') {
            if (!isset($tca[$table]['columns'][$field])) {
                throw new \RuntimeException(
                    'Field "' . $field . '" does not exist in TCA of "' . $table . '".',
                );
            }

            return [
                'table' => $table,
                'field' => $field,
                'configuration' => $tca[$table]['columns'][$field],
            ];
        }

        return $this->describeTable($table, $tca[$table]);
    }

    /**
     * @param array<string, mixed> $tca
     * @return array<string, mixed>
     */
    private function listTables(array $tca): array
    {
        $tables = [];
        foreach ($tca as $tableName => $tableTca) {
            $ctrl = $tableTca['ctrl'] ?? [];
            $tables[$tableName] = array_filter([
                'title' => $this->labelTranslator->translate($ctrl['title'] ?? null),
                'labelField' => $ctrl['label'] ?? null,
                'typeField' => $ctrl['type'] ?? null,
                'sortby' => $ctrl['sortby'] ?? null,
                'softDelete' => isset($ctrl['delete']),
                'hiddenField' => $ctrl['enablecolumns']['disabled'] ?? null,
                'languageAware' => isset($ctrl['languageField']),
                'workspaceAware' => (bool)($ctrl['versioningWS'] ?? false),
                'columnCount' => \count($tableTca['columns'] ?? []),
            ], static fn (mixed $value): bool => $value !== null && $value !== false);
        }
        ksort($tables);

        return [
            'tableCount' => \count($tables),
            'tables' => $tables,
            'hint' => 'Pass {"table": "<name>"} for column details.',
        ];
    }

    /**
     * @param array<string, mixed> $tableTca
     * @return array<string, mixed>
     */
    private function describeTable(string $table, array $tableTca): array
    {
        $columns = [];
        foreach ($tableTca['columns'] ?? [] as $columnName => $columnTca) {
            $config = $columnTca['config'] ?? [];
            $columns[$columnName] = array_filter([
                'label' => $this->labelTranslator->translate($columnTca['label'] ?? null),
                'type' => $config['type'] ?? null,
                'renderType' => $config['renderType'] ?? null,
                'foreign_table' => $config['foreign_table'] ?? null,
                'MM' => $config['MM'] ?? null,
                'allowed' => $config['allowed'] ?? null,
                'required' => ($config['required'] ?? false) ?: null,
                'itemCount' => isset($config['items']) ? \count($config['items']) : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $types = array_keys($tableTca['types'] ?? []);
        $palettes = array_keys($tableTca['palettes'] ?? []);

        return [
            'table' => $table,
            'ctrl' => [
                'title' => $this->labelTranslator->translate($tableTca['ctrl']['title'] ?? null),
                'label' => $tableTca['ctrl']['label'] ?? null,
                'type' => $tableTca['ctrl']['type'] ?? null,
                'enablecolumns' => $tableTca['ctrl']['enablecolumns'] ?? [],
                'languageField' => $tableTca['ctrl']['languageField'] ?? null,
            ],
            'columns' => $columns,
            'types' => $types,
            'palettes' => $palettes,
            'hint' => 'Pass {"table": "' . $table . '", "field": "<column>"} for a full column configuration.',
        ];
    }
}

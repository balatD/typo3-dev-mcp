<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LabelTranslator;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * FlexForm data structures are the one part of the TCA that tca_schema cannot
 * show: the structure lives in a separate XML file (or string) selected at
 * runtime from the record's type, so the field names a plugin actually stores
 * are invisible until the DS is resolved.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class FlexFormSchemaTool implements ToolInterface
{
    private const DEFAULT_TABLE = 'tt_content';

    private const DEFAULT_FIELD = 'pi_flexform';

    public function __construct(
        private readonly FlexFormTools $flexFormTools,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly LabelTranslator $labelTranslator,
    ) {}

    public function getName(): string
    {
        return 'flexform_schema';
    }

    public function getDescription(): string
    {
        return 'Resolve the FlexForm data structure of a TCA field into sheets and fields with their TCA '
            . 'configuration — the field names a plugin actually stores in its FlexForm XML. Defaults to '
            . 'tt_content.pi_flexform; "type" selects the structure (the CType, or on TYPO3 v13 the '
            . 'list_type of the plugin).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Table holding the FlexForm field (default "' . self::DEFAULT_TABLE . '")',
                ],
                'field' => [
                    'type' => 'string',
                    'description' => 'FlexForm field name (default "' . self::DEFAULT_FIELD . '")',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Record type selecting the structure — a CType like "textmedia", or a '
                        . 'v13 plugin list_type. Omit for the default structure.',
                ],
                'record' => [
                    'type' => 'object',
                    'description' => 'Explicit column values to select the structure, e.g. '
                        . '{"CType": "list", "list_type": "myext_pi1"} — only needed when "type" alone is '
                        . 'not enough to identify it',
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
        $table = trim((string)($arguments['table'] ?? self::DEFAULT_TABLE)) ?: self::DEFAULT_TABLE;
        $field = trim((string)($arguments['field'] ?? self::DEFAULT_FIELD)) ?: self::DEFAULT_FIELD;
        $type = trim((string)($arguments['type'] ?? ''));
        $record = \is_array($arguments['record'] ?? null) ? $arguments['record'] : [];

        if (!$this->tcaSchemaFactory->has($table)) {
            throw new \RuntimeException(
                'Unknown table "' . $table . '". Call tca_schema without arguments to list the TCA tables.',
            );
        }

        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->hasField($field)) {
            throw new \RuntimeException(
                'Table "' . $table . '" has no field "' . $field . '". Call tca_schema with {"table": "'
                . $table . '"} to see its fields.',
            );
        }

        $fieldTca = ['config' => $schema->getField($field)->getConfiguration()];
        if (($fieldTca['config']['type'] ?? null) !== 'flex') {
            throw new \RuntimeException(
                'Field "' . $table . '.' . $field . '" is not of TCA type "flex" (it is "'
                . (string)($fieldTca['config']['type'] ?? 'unknown') . '"). Use tca_schema for regular fields.',
            );
        }

        $row = $record !== [] ? $record : $this->buildRow($schema, $fieldTca['config'], $type);
        $dataStructure = $this->resolveDataStructure($fieldTca, $table, $field, $row, $schema, $type);

        return array_filter([
            'table' => $table,
            'field' => $field,
            'type' => $type !== '' ? $type : null,
            'resolvedWith' => $row !== [] ? $row : null,
            'sheets' => $this->describeSheets($dataStructure),
            'hint' => 'Field names are the keys inside each sheet; FlexForm values are stored under '
                . 'data.<sheet>.lDEF.<field>.vDEF in the XML.',
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $fieldTca
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resolveDataStructure(
        array $fieldTca,
        string $table,
        string $field,
        array $row,
        TcaSchema $schema,
        string $type,
    ): array {
        // v14 requires the schema on both calls; v13 does not accept it at all
        $schemaAware = (new \ReflectionMethod(FlexFormTools::class, 'parseDataStructureByIdentifier'))
            ->getNumberOfParameters() > 1;

        try {
            if ($schemaAware) {
                $identifier = $this->flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $row, $schema);

                return $this->flexFormTools->parseDataStructureByIdentifier($identifier, $schema);
            }

            $identifier = $this->flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $row);

            return $this->flexFormTools->parseDataStructureByIdentifier($identifier);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'No FlexForm data structure could be resolved for ' . $table . '.' . $field
                . ($type !== '' ? ' with type "' . $type . '"' : ' without a type')
                . ': ' . $e->getMessage()
                . ' Pass "type" (or "record") to select a structure — content_elements lists the available CTypes.',
            );
        }
    }

    /**
     * Builds the minimal record needed to select a data structure.
     *
     * On v13 every column named in ds_pointerField must be present as a key —
     * FlexFormTools rejects the row otherwise, even for the default structure.
     * v14 removed ds_pointerField and looks at the record type alone.
     *
     * @param array<string, mixed> $fieldConfig
     * @return array<string, string>
     */
    private function buildRow(TcaSchema $schema, array $fieldConfig, string $type): array
    {
        $pointerFields = [];
        foreach (explode(',', (string)($fieldConfig['ds_pointerField'] ?? '')) as $pointerField) {
            $pointerField = trim($pointerField);
            if ($pointerField !== '') {
                $pointerFields[$pointerField] = '';
            }
        }

        if ($type === '' || !$schema->supportsSubSchema()) {
            return $pointerFields;
        }

        $typeField = $schema->getSubSchemaTypeInformation()->getFieldName();

        // A type that is no record type of its own is a v13 plugin key: those
        // structures are stored under list_type while CType stays "list".
        if (!$schema->hasSubSchema($type) && \array_key_exists('list_type', $pointerFields)) {
            return [...$pointerFields, $typeField => 'list', 'list_type' => $type];
        }

        return [...$pointerFields, $typeField => $type];
    }

    /**
     * @param array<string, mixed> $dataStructure
     * @return array<string, mixed>
     */
    private function describeSheets(array $dataStructure): array
    {
        $sheets = [];

        foreach ($dataStructure['sheets'] ?? [] as $sheetName => $sheet) {
            $root = \is_array($sheet) && \is_array($sheet['ROOT'] ?? null) ? $sheet['ROOT'] : [];

            $fields = [];
            foreach ($root['el'] ?? [] as $fieldName => $definition) {
                if (\is_array($definition)) {
                    $fields[(string)$fieldName] = $this->describeField($definition);
                }
            }

            $sheets[(string)$sheetName] = array_filter([
                'title' => $this->labelTranslator->translate(
                    isset($root['sheetTitle']) ? (string)$root['sheetTitle'] : null,
                ),
                'fieldCount' => \count($fields),
                'fields' => $fields,
            ], static fn(mixed $value): bool => $value !== null);
        }

        return $sheets;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function describeField(array $definition): array
    {
        $config = \is_array($definition['config'] ?? null) ? $definition['config'] : [];
        $items = \is_array($config['items'] ?? null) ? $config['items'] : null;

        return array_filter([
            'label' => $this->labelTranslator->translate(
                isset($definition['label']) ? (string)$definition['label'] : null,
            ),
            'type' => isset($config['type']) ? (string)$config['type'] : null,
            'renderType' => isset($config['renderType']) ? (string)$config['renderType'] : null,
            'default' => $config['default'] ?? null,
            'itemCount' => $items !== null ? \count($items) : null,
            'foreignTable' => isset($config['foreign_table']) ? (string)$config['foreign_table'] : null,
            'description' => $this->labelTranslator->translate(
                isset($definition['description']) ? (string)$definition['description'] : null,
            ),
        ], static fn(mixed $value): bool => $value !== null);
    }
}

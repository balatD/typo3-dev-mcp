<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LabelTranslator;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\Core\Schema\ActiveRelation;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * TYPO3's semantic data model, read through the official Schema API
 * (TYPO3\CMS\Core\Schema) instead of the raw $GLOBALS['TCA'] array:
 * capabilities, relations and record types come pre-resolved and identical on
 * v13 and v14. Core still marks that API @internal on 13.4 ("experimental until
 * TYPO3 v13 LTS"); the marker is gone in v14.
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class TcaSchemaTool implements ToolInterface
{
    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly LabelTranslator $labelTranslator,
    ) {}

    public function getName(): string
    {
        return 'tca_schema';
    }

    public function getDescription(): string
    {
        return 'TYPO3\'s semantic data model (TCA) via the official Schema API. No arguments: all tables '
            . 'with their key capabilities. "table": per-field summary (type, relations), record types '
            . 'and capabilities. "type": the fields of that record type\'s form, in form order. "field": '
            . 'the complete field configuration (within "type" if given).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Table (schema) name, e.g. "tt_content" or "pages"',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Record type, e.g. a CType like "textmedia" — its form fields with overrides applied',
                ],
                'field' => [
                    'type' => 'string',
                    'description' => 'Field name within "table" to get the full configuration for',
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
        $table = $arguments['table'] ?? null;
        $field = $arguments['field'] ?? null;

        if (!\is_string($table) || $table === '') {
            return $this->listSchemas();
        }

        if (!$this->tcaSchemaFactory->has($table)) {
            throw new \RuntimeException(
                'Table "' . $table . '" has no TCA schema. Call tca_schema without arguments to list tables.',
            );
        }

        $schema = $this->tcaSchemaFactory->get($table);

        $recordType = $arguments['type'] ?? null;
        if (\is_string($recordType) && $recordType !== '') {
            return $this->describeRecordType($schema, $table, $recordType, \is_string($field) ? $field : '');
        }

        if (\is_string($field) && $field !== '') {
            return $this->describeField($schema, $table, $field);
        }

        return $this->describeSchema($schema, $table);
    }

    /**
     * The Schema API builds a record type's sub-schema from its showitem, so
     * its fields come in form order with palettes expanded and columnsOverrides
     * and label overrides applied.
     *
     * @return array<string, mixed>
     */
    private function describeRecordType(TcaSchema $schema, string $table, string $recordType, string $field): array
    {
        if (!$schema->supportsSubSchema() || !$schema->hasSubSchema($recordType)) {
            $known = $schema->supportsSubSchema()
                ? implode(', ', array_map(static fn(TcaSchema $sub): string => $sub->getName(), iterator_to_array($schema->getSubSchemata(), false)))
                : 'none — "' . $table . '" has no record types';
            throw new \RuntimeException('Record type "' . $recordType . '" does not exist in "' . $table . '". Record types: ' . $known . '.');
        }

        $subSchema = $schema->getSubSchema($recordType);
        if ($field !== '') {
            return ['recordType' => $recordType] + $this->describeField($subSchema, $table, $field);
        }

        return array_filter([
            'table' => $table,
            'recordType' => $recordType,
            'recordTypeLabel' => $this->recordTypeLabel($schema, $recordType),
            'fields' => $this->summarizeFields($subSchema),
            'hint' => 'Pass {"table": "' . $table . '", "type": "' . $recordType . '", "field": "<name>"} for a field\'s '
                . 'configuration within this record type.',
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function recordTypeLabel(TcaSchema $schema, string $recordType): ?string
    {
        $typeField = $schema->getSubSchemaTypeInformation()->getFieldName();
        foreach ($schema->getField($typeField)->getConfiguration()['items'] ?? [] as $item) {
            if ((string)($item['value'] ?? '') === $recordType) {
                return $this->labelTranslator->translate($item['label'] ?? null);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function listSchemas(): array
    {
        $tables = [];
        foreach ($this->tcaSchemaFactory->all() as $schema) {
            $rawConfiguration = $schema->getRawConfiguration();
            $tables[$schema->getName()] = array_filter([
                'title' => $this->labelTranslator->translate($rawConfiguration['title'] ?? null),
                'labelField' => $rawConfiguration['label'] ?? null,
                'typeField' => $schema->supportsSubSchema()
                    ? $schema->getSubSchemaTypeInformation()->getFieldName()
                    : null,
                'softDelete' => $schema->hasCapability(TcaSchemaCapability::SoftDelete) ?: null,
                'languageAware' => $schema->isLanguageAware() ?: null,
                'workspaceAware' => $schema->isWorkspaceAware() ?: null,
                'fieldCount' => \count($schema->getFields()),
            ], static fn(mixed $value): bool => $value !== null);
        }
        ksort($tables);

        return [
            'tableCount' => \count($tables),
            'tables' => $tables,
            'hint' => 'Pass {"table": "<name>"} for field details.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSchema(TcaSchema $schema, string $table): array
    {
        $fields = $this->summarizeFields($schema);

        $capabilities = [];
        foreach (TcaSchemaCapability::cases() as $capability) {
            if ($schema->hasCapability($capability)) {
                $capabilities[] = $capability->name;
            }
        }

        $recordTypes = [];
        if ($schema->supportsSubSchema()) {
            $recordTypes = array_map(
                static fn(TcaSchema $subSchema): string => $subSchema->getName(),
                iterator_to_array($schema->getSubSchemata(), false),
            );
        }

        return array_filter([
            'table' => $table,
            'title' => $this->labelTranslator->translate($schema->getTitle()),
            'capabilities' => $capabilities,
            'typeField' => $schema->supportsSubSchema()
                ? $schema->getSubSchemaTypeInformation()->getFieldName()
                : null,
            'recordTypes' => $recordTypes ?: null,
            'fields' => $fields,
            'hint' => 'Pass {"table": "' . $table . '", "field": "<name>"} for a full field configuration.',
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function summarizeFields(TcaSchema $schema): array
    {
        $fields = [];
        foreach ($schema->getFields() as $schemaField) {
            $configuration = $schemaField->getConfiguration();
            $entry = array_filter([
                'label' => $this->labelTranslator->translate($schemaField->getLabel()),
                'type' => $schemaField->getType(),
                'renderType' => $configuration['renderType'] ?? null,
                'required' => $schemaField->isRequired() ?: null,
                'itemCount' => isset($configuration['items']) ? \count($configuration['items']) : null,
            ], static fn(mixed $value): bool => $value !== null && $value !== '');

            if ($schemaField instanceof RelationalFieldTypeInterface) {
                $entry['relationship'] = $schemaField->getRelationshipType()->name;
                $entry['relations'] = array_map(
                    static fn(ActiveRelation $relation): array => array_filter([
                        'table' => $relation->toTable(),
                        'field' => $relation->toField(),
                    ]),
                    $schemaField->getRelations(),
                );
            }

            $fields[$schemaField->getName()] = $entry;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeField(TcaSchema $schema, string $table, string $field): array
    {
        if (!$schema->hasField($field)) {
            throw new \RuntimeException(
                'Field "' . $field . '" does not exist in the schema of "' . $table . '".',
            );
        }

        $schemaField = $schema->getField($field);

        return [
            'table' => $table,
            'field' => $field,
            'label' => $this->labelTranslator->translate($schemaField->getLabel()),
            'type' => $schemaField->getType(),
            'required' => $schemaField->isRequired(),
            'nullable' => $schemaField->isNullable(),
            'configuration' => $schemaField->getConfiguration(),
        ];
    }
}

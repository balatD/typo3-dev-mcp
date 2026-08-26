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
            . 'and capabilities. "table" plus "field": the complete field configuration.';
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

        if (\is_string($field) && $field !== '') {
            return $this->describeField($schema, $table, $field);
        }

        return $this->describeSchema($schema, $table);
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

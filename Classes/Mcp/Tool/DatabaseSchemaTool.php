<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use T3Boost\Mcp\ToolInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Boost analog: database-schema.
 */
final class DatabaseSchemaTool implements ToolInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getName(): string
    {
        return 'database_schema';
    }

    public function getDescription(): string
    {
        return 'Inspect the live database schema. Without arguments: all table names with their column '
            . 'count. With "table": full column, index and foreign key details for that table. With '
            . '"filter": only tables whose name contains the given substring. Note that TYPO3 table '
            . 'structure is defined by TCA + ext_tables.sql — use tca_schema for the semantic model.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Exact table name to get full details for',
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'Substring to filter the table list by',
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
        $schemaManager = $this->connectionPool
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->createSchemaManager();

        $table = $arguments['table'] ?? null;
        if (\is_string($table) && $table !== '') {
            if (!$schemaManager->tablesExist([$table])) {
                throw new \RuntimeException(
                    'Table "' . $table . '" does not exist. Call database_schema without arguments to list tables.',
                );
            }

            return $this->describeTable($schemaManager, $table);
        }

        $filter = strtolower((string)($arguments['filter'] ?? ''));
        $tables = [];
        foreach ($schemaManager->listTableNames() as $tableName) {
            if ($filter !== '' && !str_contains(strtolower($tableName), $filter)) {
                continue;
            }
            $tables[$tableName] = \count($schemaManager->listTableColumns($tableName));
        }
        ksort($tables);

        return [
            'tableCount' => \count($tables),
            'tables' => $tables,
            'hint' => 'Pass {"table": "<name>"} for column details.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTable(object $schemaManager, string $table): array
    {
        $details = $schemaManager->introspectTable($table);

        $columns = [];
        foreach ($details->getColumns() as $column) {
            $columns[$column->getName()] = [
                'type' => strtolower(str_replace('Type', '', substr(strrchr($column->getType()::class, '\\') ?: '', 1))),
                'length' => $column->getLength(),
                'notnull' => $column->getNotnull(),
                'default' => $column->getDefault(),
                'autoincrement' => $column->getAutoincrement() ?: null,
            ];
        }

        $indexes = [];
        foreach ($details->getIndexes() as $index) {
            $indexes[$index->getName()] = [
                'columns' => $index->getColumns(),
                'unique' => $index->isUnique(),
                'primary' => $index->isPrimary(),
            ];
        }

        $foreignKeys = [];
        foreach ($details->getForeignKeys() as $foreignKey) {
            $foreignKeys[$foreignKey->getName()] = [
                'columns' => $foreignKey->getLocalColumns(),
                'references' => $foreignKey->getForeignTableName(),
                'referencedColumns' => $foreignKey->getForeignColumns(),
            ];
        }

        return [
            'table' => $table,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
        ];
    }
}

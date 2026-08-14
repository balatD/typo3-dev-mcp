<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\ToolInterface;
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
        return 'The live database schema. No arguments: all table names with their column count. '
            . '"table": columns, indexes and foreign keys. "filter": only tables matching a substring. '
            . 'For relations, enable-fields and record types use tca_schema instead.';
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
     * @param \Doctrine\DBAL\Schema\AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> $schemaManager
     * @return array<string, mixed>
     */
    private function describeTable(\Doctrine\DBAL\Schema\AbstractSchemaManager $schemaManager, string $table): array
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

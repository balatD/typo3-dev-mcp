<?php

declare(strict_types=1);

namespace T3Boost\Mcp\Tool;

use T3Boost\Mcp\ToolInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Boost analog: database-query. Read-only by default; write statements
 * require the developer to opt in via the T3BOOST_ALLOW_WRITE=1 env var.
 */
final class DatabaseQueryTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 100;

    private const READ_KEYWORDS = ['select', 'show', 'explain', 'describe', 'desc', 'with'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getName(): string
    {
        return 'database_query';
    }

    public function getDescription(): string
    {
        $writeEnabled = $this->isWriteAllowed();

        return 'Execute a single SQL query against the TYPO3 database and get the result rows. '
            . ($writeEnabled
                ? 'Write statements are ENABLED via T3BOOST_ALLOW_WRITE. '
                : 'Read-only: only SELECT/SHOW/EXPLAIN/DESCRIBE/WITH are accepted (set T3BOOST_ALLOW_WRITE=1 to allow writes). ')
            . 'Remember TYPO3 soft-delete semantics: filter deleted=0 (and hidden=0 for live records) yourself.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The SQL statement to execute',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default ' . self::DEFAULT_LIMIT . ')',
                    'minimum' => 1,
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return !$this->isWriteAllowed();
    }

    public function execute(array $arguments): mixed
    {
        $query = trim((string)($arguments['query'] ?? ''));
        $limit = max(1, (int)($arguments['limit'] ?? self::DEFAULT_LIMIT));

        if ($query === '') {
            throw new \RuntimeException('Argument "query" must not be empty.');
        }

        $query = rtrim($query, "; \t\n\r");
        if (str_contains($query, ';')) {
            throw new \RuntimeException('Only a single SQL statement is allowed per call.');
        }

        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

        if (!$this->isReadStatement($query)) {
            if (!$this->isWriteAllowed()) {
                throw new \RuntimeException(
                    'Write statements are disabled. Only SELECT/SHOW/EXPLAIN/DESCRIBE/WITH are allowed. '
                    . 'The developer can opt in by setting the environment variable T3BOOST_ALLOW_WRITE=1.',
                );
            }

            $affectedRows = $connection->executeStatement($query);

            return ['affectedRows' => $affectedRows];
        }

        $result = $connection->executeQuery($query);

        $rows = [];
        $truncated = false;
        foreach ($result->iterateAssociative() as $row) {
            if (\count($rows) >= $limit) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }

        return [
            'rowCount' => \count($rows),
            'truncated' => $truncated,
            'rows' => $rows,
        ];
    }

    private function isReadStatement(string $query): bool
    {
        if (preg_match('/^\(*\s*([a-z]+)/i', $query, $matches) !== 1) {
            return false;
        }

        return \in_array(strtolower($matches[1]), self::READ_KEYWORDS, true);
    }

    private function isWriteAllowed(): bool
    {
        return getenv('T3BOOST_ALLOW_WRITE') === '1';
    }
}

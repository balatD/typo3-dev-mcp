<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;

/**
 * Smoke matrix: every announced tool is executed against a booted TYPO3 and
 * asserted to return its documented top-level shape.
 *
 * This is deliberately shallow. It is not here to pin down payload contents —
 * those are tuned against the benchmark and change between minor releases. It
 * is here to catch the failure that actually happens: a core upgrade moves an
 * API a tool reads, and the tool throws on every call.
 */
final class ToolExecutionTest extends AbstractToolTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: list<string>}>
     */
    public static function toolExecutionProvider(): iterable
    {
        yield 'application_info' => ['application_info', [], ['typo3Version', 'phpVersion', 'applicationContext']];
        yield 'backend_modules' => ['backend_modules', [], ['moduleCount', 'modules']];
        yield 'content_elements' => ['content_elements', [], ['contentElements']];
        yield 'database_query' => ['database_query', ['query' => 'SELECT 1 AS one'], ['rowCount', 'rows']];
        yield 'database_schema' => ['database_schema', [], ['tableCount', 'tables']];
        yield 'database_schema (one table)' => ['database_schema', ['table' => 'pages'], ['table', 'columns', 'indexes']];
        yield 'flexform_schema' => ['flexform_schema', [], ['table', 'field', 'sheets']];
        yield 'get_config' => ['get_config', ['path' => 'SYS'], ['path', 'value']];
        yield 'get_config (scalar)' => ['get_config', ['path' => 'SYS/sitename'], ['path', 'value']];
        yield 'last_error' => ['last_error', [], ['error']];
        yield 'list_commands' => ['list_commands', [], ['commandCount', 'commands']];
        yield 'list_commands (one)' => ['list_commands', ['name' => 'cache:flush'], ['name', 'description']];
        yield 'list_events' => ['list_events', [], ['eventCount', 'events']];
        yield 'middleware_stack' => ['middleware_stack', [], ['stack', 'middlewareCount', 'middlewares']];
        yield 'page_tsconfig' => ['page_tsconfig', [], ['pageId', 'topLevelKeys']];
        yield 'page_tsconfig (page)' => ['page_tsconfig', ['pageId' => self::ROOT_PAGE_ID], ['pageId']];
        yield 'read_log_entries' => ['read_log_entries', [], ['source', 'entryCount', 'entries']];
        yield 'search_changelog' => ['search_changelog', ['query' => 'deprecated'], ['resultCount', 'results']];
        yield 'site_info' => ['site_info', [], ['siteCount', 'sites']];
        yield 'site_sets' => ['site_sets', [], ['setCount', 'sets']];
        yield 'tca_schema' => ['tca_schema', [], ['tableCount', 'tables']];
        yield 'tca_schema (one table)' => ['tca_schema', ['table' => 'pages'], ['table', 'fields']];
        yield 'typoscript' => ['typoscript', [], ['pageId', 'site', 'section']];
        yield 'typoscript (constants)' => ['typoscript', ['section' => 'constants'], ['pageId', 'section']];
        yield 'viewhelper_lookup' => ['viewhelper_lookup', [], []];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $expectedKeys
     */
    #[Test]
    #[DataProvider('toolExecutionProvider')]
    public function toolExecutesAndReturnsItsDocumentedShape(
        string $name,
        array $arguments,
        array $expectedKeys,
    ): void {
        $result = $this->getTool($name)->execute($arguments);

        self::assertIsArray($result, $name . ' must return a JSON-serializable array.');

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $result, sprintf('%s: expected top-level key "%s".', $name, $key));
        }

        self::assertNotFalse(
            json_encode($result),
            $name . ': result is not JSON-serializable, so it can never reach a client.',
        );
    }

    /**
     * `last_error` and `read_log_entries` have to work on an installation that
     * has never logged anything — the most common state, and the one where an
     * unguarded array access throws.
     */
    #[Test]
    public function logToolsHandleAnEmptyLog(): void
    {
        self::assertIsArray($this->getTool('last_error')->execute([]));
        self::assertIsArray($this->getTool('read_log_entries')->execute(['limit' => 5]));
    }

    #[Test]
    public function getConfigCollapsesDeepSubtreesAndMasksSecrets(): void
    {
        $result = $this->getTool('get_config')->execute(['path' => 'SYS']);

        self::assertArrayHasKey('truncated', $result);
        self::assertTrue($result['truncated'], 'SYS is deep enough that the depth-2 collapse must engage.');

        $encryptionKey = $this->getTool('get_config')->execute(['path' => 'SYS/encryptionKey']);
        self::assertNotSame(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null,
            $encryptionKey['value'],
            'The encryption key must never leave the server unmasked.',
        );
    }

    #[Test]
    public function databaseQueryRefusesWriteStatementsUnlessOptedIn(): void
    {
        self::assertFalse(getenv('DEV_MCP_ALLOW_WRITE'), 'This test asserts the default, opted-out behaviour.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Write statements are disabled');

        $this->getTool('database_query')->execute([
            'query' => "INSERT INTO pages (pid, title) VALUES (0, 'nope')",
        ]);
    }

    #[Test]
    public function databaseQueryRefusesMultipleStatements(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only a single SQL statement');

        $this->getTool('database_query')->execute(['query' => 'SELECT 1; SELECT 2']);
    }

    /**
     * search_docs has nothing to report without the network, so it must say so
     * rather than hang or surface a raw transport error — the documented
     * contract of DEV_MCP_NO_NETWORK, which FunctionalTests.xml sets suite-wide.
     */
    #[Test]
    public function searchDocsFailsExplicablyWhenOffline(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEV_MCP_NO_NETWORK/');

        $this->getTool('search_docs')->execute(['query' => 'TCA']);
    }

    /**
     * extension_info is the other network tool, but half its answer — the
     * locally installed version — needs no network. Offline it degrades to that
     * half instead of failing, which is the more useful behaviour and worth
     * pinning down.
     */
    #[Test]
    public function extensionInfoStillReportsTheLocalInstallWhenOffline(): void
    {
        $result = $this->getTool('extension_info')->execute(['key' => 'dev_mcp']);

        self::assertSame('dev_mcp', $result['extensionKey']);
        self::assertIsArray($result['installed'], 'The local half of the answer needs no network.');

        // The result is array_filter()ed, so the two remote halves are absent
        // rather than null — and "notes" is where the tool says why.
        self::assertArrayNotHasKey('ter', $result);
        self::assertArrayNotHasKey('packagist', $result);
        self::assertStringContainsString(
            'DEV_MCP_NO_NETWORK',
            implode(' ', $result['notes'] ?? []),
            'The offline reason must reach the caller, not be silently swallowed.',
        );
    }

    #[Test]
    public function flushCacheReportsWhatItFlushed(): void
    {
        $tool = $this->getTool('flush_cache');

        self::assertFalse($tool->isReadOnly(), 'flush_cache is the one tool that changes state.');
        self::assertSame(['flushed' => 'all'], $tool->execute([]));
    }

    /**
     * content_blocks is registered from Configuration/Services.php only when the
     * optional package is present. It is a dev dependency, so it is expected
     * here — but the container is cached, so a stale cache is a skip, not a fail.
     */
    #[Test]
    public function contentBlocksToolIsAnnouncedWhenThePackageIsInstalled(): void
    {
        if (!class_exists(ContentBlockRegistry::class)) {
            self::markTestSkipped('friendsoftypo3/content-blocks is not installed.');
        }

        $result = $this->getTool('content_blocks')->execute([]);

        // The tool being registered at all is the assertion that matters: it is
        // wired from Configuration/Services.php behind a class_exists() check
        // against a cached container. The test instance defines no blocks, so an
        // empty roster is the expected answer, not a failure.
        self::assertArrayHasKey('contentBlockCount', $result);
        if ($result['contentBlockCount'] > 0) {
            self::assertArrayHasKey('contentBlocks', $result);
        }
    }
}

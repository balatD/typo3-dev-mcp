<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the shape of what the server announces, not what the tools return.
 *
 * A malformed input schema is not a per-tool failure: MCP clients reject the
 * whole `tools/list` response, so one bad schema silently disables every tool.
 * That is what these assertions catch before a release does.
 */
final class ToolContractTest extends AbstractToolTestCase
{
    /**
     * Guards every other assertion in this class: they all iterate the registry,
     * and an empty registry would let each one pass while announcing nothing.
     */
    #[Test]
    public function theExpectedToolRosterIsRegistered(): void
    {
        self::assertSame([
            'application_info',
            'backend_modules',
            'content_blocks',
            'content_elements',
            'database_query',
            'database_schema',
            'extension_info',
            'flexform_schema',
            'flush_cache',
            'get_config',
            'last_error',
            'list_commands',
            'list_events',
            'middleware_stack',
            'page_tsconfig',
            'read_log_entries',
            'search_changelog',
            'search_docs',
            'site_info',
            'site_sets',
            'tca_schema',
            'typoscript',
            'viewhelper_lookup',
        ], $this->sortedToolNames());
    }

    #[Test]
    public function everyRegisteredToolHasAUsableName(): void
    {
        foreach ($this->getTools() as $key => $tool) {
            self::assertSame($key, $tool->getName(), 'Registry key and tool name disagree.');
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $tool->getName(),
                'Tool names are snake_case identifiers — they appear verbatim in the AI-facing tool list.',
            );
        }
    }

    #[Test]
    public function everyRegisteredToolHasADescription(): void
    {
        foreach ($this->getTools() as $name => $tool) {
            self::assertNotSame('', trim($tool->getDescription()), $name . ' has an empty description.');
        }
    }

    #[Test]
    public function everyInputSchemaIsAValidJsonSchemaObject(): void
    {
        foreach ($this->getTools() as $name => $tool) {
            $schema = $tool->getInputSchema();

            self::assertSame('object', $schema['type'] ?? null, $name . ': input schema must be type "object".');
            self::assertArrayHasKey('properties', $schema, $name . ': input schema must declare "properties".');

            $properties = $schema['properties'];
            self::assertTrue(
                \is_array($properties) || $properties instanceof \stdClass,
                $name . ': "properties" must be an object or array.',
            );

            // A required key that is not a declared property makes every call to
            // the tool fail validation inside the SDK, before it is ever executed.
            foreach ($schema['required'] ?? [] as $required) {
                self::assertArrayHasKey(
                    $required,
                    (array)$properties,
                    sprintf('%s: required argument "%s" is not declared in "properties".', $name, $required),
                );
            }
        }
    }

    #[Test]
    public function flushCacheIsTheOnlyToolThatIsNotReadOnly(): void
    {
        $writing = [];
        foreach ($this->getTools() as $name => $tool) {
            if (!$tool->isReadOnly()) {
                $writing[] = $name;
            }
        }

        // database_query flips to non-read-only only under DEV_MCP_ALLOW_WRITE,
        // which the suite never sets — see FunctionalTests.xml.
        self::assertSame(['flush_cache'], $writing);
    }

    /**
     * The composed guidelines are what the AI reads on every request. A tool
     * added without a line here is a tool the AI is never told about, and
     * nothing else in the build notices.
     */
    #[Test]
    public function everyToolIsDocumentedInTheComposedGuidelines(): void
    {
        $guidelines = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Resources/Private/Guidelines/general.md',
        );

        preg_match_all('/^- `([a-z0-9_]+)`/m', $guidelines, $matches);
        $documented = $matches[1];

        sort($documented);

        self::assertSame(
            $this->sortedToolNames(),
            $documented,
            'Resources/Private/Guidelines/general.md has drifted from the registered tools.',
        );
    }

    /**
     * @return list<string>
     */
    private function sortedToolNames(): array
    {
        $names = array_keys($this->getTools());
        sort($names);

        return $names;
    }
}

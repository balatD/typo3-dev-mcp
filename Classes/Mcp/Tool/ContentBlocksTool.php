<?php

declare(strict_types=1);

namespace BalatD\DevMcp\Mcp\Tool;

use BalatD\DevMcp\Mcp\Support\LabelTranslator;
use BalatD\DevMcp\Mcp\ToolInterface;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;

/**
 * Content Blocks define their fields in YAML and generate TCA, database columns
 * and a type name from it — so neither tca_schema nor content_elements show
 * where a field came from or what a block is called in its own vocabulary.
 *
 * Only registered when friendsoftypo3/content-blocks is installed
 * (see Configuration/Services.php).
 *
 * @internal Not covered by the backwards-compatibility promise: tool
 *           response payloads and these implementation classes may change
 *           in any minor release.
 */
final class ContentBlocksTool implements ToolInterface
{
    public function __construct(
        private readonly ContentBlockRegistry $contentBlockRegistry,
        private readonly LabelTranslator $labelTranslator,
    ) {}

    public function getName(): string
    {
        return 'content_blocks';
    }

    public function getDescription(): string
    {
        return 'Registered Content Blocks with vendor/name, generated type name, table, host extension '
            . 'and field definitions. "name" for one block in full detail. The YAML field identifiers '
            . 'here are what ends up in TCA and in the database.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Content Block name including vendor, e.g. "myvendor/teaser"',
                ],
                'typeName' => [
                    'type' => 'string',
                    'description' => 'Generated type name (the CType for content elements), e.g. "myvendor_teaser"',
                ],
                'table' => [
                    'type' => 'string',
                    'description' => 'Table to look "typeName" up in (default "tt_content")',
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
        $name = trim((string)($arguments['name'] ?? ''));
        $typeName = trim((string)($arguments['typeName'] ?? ''));
        $table = trim((string)($arguments['table'] ?? 'tt_content')) ?: 'tt_content';

        if ($name !== '') {
            if (!$this->contentBlockRegistry->hasContentBlock($name)) {
                throw new \RuntimeException(
                    'No Content Block "' . $name . '". Call content_blocks without arguments to list them — '
                    . 'the name always includes the vendor, e.g. "myvendor/teaser".',
                );
            }

            return $this->describeContentBlock($this->contentBlockRegistry->getContentBlock($name), true);
        }

        if ($typeName !== '') {
            $contentBlock = $this->contentBlockRegistry->getByTypeName($table, $typeName);
            if ($contentBlock === null) {
                throw new \RuntimeException(
                    'No Content Block with type name "' . $typeName . '" in table "' . $table . '". '
                    . 'Call content_blocks without arguments to list them, or content_elements for CTypes '
                    . 'that are not Content Blocks.',
                );
            }

            return $this->describeContentBlock($contentBlock, true);
        }

        $contentBlocks = [];
        foreach ($this->contentBlockRegistry->getAll() as $contentBlock) {
            $contentBlocks[$contentBlock->getName()] = $this->describeContentBlock($contentBlock, false);
        }

        if ($contentBlocks === []) {
            return [
                'contentBlockCount' => 0,
                'hint' => 'friendsoftypo3/content-blocks is installed but no Content Block is registered. '
                    . 'Blocks live in <extension>/ContentBlocks/ContentElements/<name>/.',
            ];
        }

        ksort($contentBlocks);

        return [
            'contentBlockCount' => \count($contentBlocks),
            'contentBlocks' => $contentBlocks,
            'hint' => 'Pass {"name": "<vendor/name>"} for the full field definitions of one block.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeContentBlock(LoadedContentBlock $contentBlock, bool $detailed): array
    {
        $yaml = $contentBlock->getYaml();

        $description = array_filter([
            'contentType' => $contentBlock->getContentType()->value,
            'table' => $yaml['table'] ?? null,
            'typeName' => $yaml['typeName'] ?? null,
            'title' => $this->labelTranslator->translate(
                isset($yaml['title']) ? (string)$yaml['title'] : null,
            ),
            'hostExtension' => $contentBlock->getHostExtension(),
            'fieldCount' => \is_array($yaml['fields'] ?? null) ? \count($yaml['fields']) : 0,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        if (!$detailed) {
            return $description;
        }

        return array_filter([
            'name' => $contentBlock->getName(),
            ...$description,
            'vendor' => $contentBlock->getVendor(),
            'package' => $contentBlock->getPackage(),
            'extPath' => $contentBlock->getExtPath(),
            'description' => $this->labelTranslator->translate(
                isset($yaml['description']) ? (string)$yaml['description'] : null,
            ),
            // fields are prefixed with the block name in the database unless disabled
            'prefixFields' => $contentBlock->prefixFields(),
            'prefixType' => $contentBlock->getPrefixType()->value,
            'fields' => $this->describeFields($yaml['fields'] ?? []),
            'hint' => 'Templates live in ' . $contentBlock->getExtPath() . '/templates/.',
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param mixed $fields the "fields" section of the block's YAML
     * @return array<string, mixed>
     */
    private function describeFields(mixed $fields): array
    {
        if (!\is_array($fields)) {
            return [];
        }

        $described = [];
        foreach ($fields as $field) {
            if (!\is_array($field)) {
                continue;
            }

            // reused core fields carry only useExistingField + identifier
            $identifier = (string)($field['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }

            $described[$identifier] = array_filter([
                'type' => $field['type'] ?? null,
                'label' => $this->labelTranslator->translate(
                    isset($field['label']) ? (string)$field['label'] : null,
                ),
                'useExistingField' => $field['useExistingField'] ?? null,
                'required' => $field['required'] ?? null,
                'default' => $field['default'] ?? null,
                // Collection and Palette nest further fields
                'fields' => isset($field['fields']) ? $this->describeFields($field['fields']) : null,
            ], static fn(mixed $value): bool => $value !== null && $value !== []);
        }

        return $described;
    }
}

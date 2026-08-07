# Content Blocks

This project uses `friendsoftypo3/content-blocks`.

- A content block lives in `ContentBlocks/ContentElements/<name>/` with `config.yaml` (v1.x: `EditorInterface.yaml`), templates and assets; check the existing blocks in this project and mirror their structure exactly.
- Prefer `useExistingField: true` for standard fields (`header`, `bodytext`, `image`, ...) over defining new ones — fewer columns, better reuse.
- Field identifiers are prefixed per content block automatically; access them in Fluid via the `{data}` object the content block template receives.
- Repeating structures use `Collection` fields; before reaching for a Collection consider whether existing relations (IRRE, categories) or container elements already solve it.
- After adding or changing a content block: `flush_cache` (system) and `typo3 extension:setup` when new DB columns are involved.
- Use the `content_elements` tool to see the CTypes that already exist before inventing a new block.

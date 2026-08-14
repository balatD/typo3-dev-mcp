# TYPO3 tools

This project runs TYPO3 {{typo3Version}} on PHP {{phpVersion}}.

The `typo3-dev-mcp` tools report the state of the running installation — resolved
configuration, the live data model, logs — which the files on disk do not always show.
All are read-only except `flush_cache`.

## Installation and code

- `application_info` — TYPO3 and PHP version, application context, database platform, active extensions.
- `extension_info` — installed version of an extension plus the latest TER and Packagist releases.
- `list_commands` — registered console commands.
- `backend_modules` — registered backend modules with identifiers, paths and routes.
- `list_events` — PSR-14 events and the listeners registered for them.
- `viewhelper_lookup` — Fluid ViewHelpers and their argument signatures.

## Data model

- `tca_schema` — TCA: tables, fields, relations, record types, capabilities.
- `database_schema` — physical schema: columns, indexes, foreign keys.
- `database_query` — one SQL query against the database.
- `content_elements` — registered `tt_content` CTypes.
- `content_blocks` — registered Content Blocks and their field definitions.
- `flexform_schema` — resolved FlexForm sheets and fields of a TCA field.

## Resolved configuration

These values are computed at runtime and have no CLI equivalent.

- `typoscript` — compiled frontend TypoScript for a page.
- `page_tsconfig` — resolved Page TSconfig for a page.
- `site_sets` — registered site sets, their resolved order and effective settings.
- `site_info` — configured sites with base URLs, root pages and languages.
- `get_config` — `TYPO3_CONF_VARS` and extension configuration.
- `middleware_stack` — resolved PSR-15 middleware order.

## Diagnostics and documentation

- `last_error` — the most recent error-level log entry, structured.
- `read_log_entries` — recent entries from the file log, deprecation log or `sys_log`.
- `search_changelog` — the core changelog shipped with the installed version.
- `search_docs` — docs.typo3.org, pinned to the installed major version.

## Cache

- `flush_cache` — all caches, or one group: `system` for configuration and DI, `pages` for content and rendering.

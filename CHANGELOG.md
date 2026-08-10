# Changelog

## 0.1.0-alpha.5 — 2026-08-10

### Added

Ten tools, taking the server from 14 to 24. All read-only, verified live on
TYPO3 13.4 and 14.3.

- Resolved configuration — the corners of TYPO3 that are backend-module-only,
  with no CLI equivalent in core:
  - `page_tsconfig`: resolved Page TSconfig for a page (`mod.*`, `TCEFORM`,
    `TCEMAIN`) with the same dot-path drilldown as `typoscript`.
  - `site_sets`: registered site sets, their dependency-resolved load order,
    settings definitions (type, default, category, enum) and a site's effective
    settings. Invalid sets are reported too.
  - `flexform_schema`: resolves FlexForm data structures to sheets and fields —
    the field names a plugin actually stores, which `tca_schema` cannot show.
  - `middleware_stack`: the PSR-15 stacks in execution order, with the
    declaring package and the before/after constraints.
- API discovery:
  - `viewhelper_lookup`: every available ViewHelper with its exact arguments,
    types, defaults and documentation — core, extensions and project alike.
  - `list_events`: PSR-14 events and the listeners actually registered for them.
  - `backend_modules`: registered backend modules with identifier, parent, path,
    access and routes (v14 renamed most of them).
- Documentation and ecosystem:
  - `search_docs`: searches docs.typo3.org pinned to the installed major
    version, returning excerpts and permalinks. Closes the gap listed under
    "known limitations" in alpha.1.
  - `extension_info`: an extension here versus in the TER and on Packagist,
    including which TYPO3 majors it supports.
- `content_blocks`: registered Content Blocks with type name, table, host
  extension and YAML field definitions. Only announced when
  `friendsoftypo3/content-blocks` is installed — the tool is registered
  conditionally so the container still compiles without it.
- `DEV_MCP_NO_NETWORK=1` keeps the server fully offline; the two network tools
  then fail with an explanatory message and nothing else is affected.

### Known limitations

Reading what core does not expose means resting on APIs core marks `@internal`.
These are the ones in use, all verified on 13.4 and 14.3, all wrapped so a
future signature change degrades to an explanatory error rather than a crash:

- `middleware_stack` — `MiddlewareStackResolver` (class); its return type
  already differs between v13 (`array`) and v14 (`ArrayObject`).
- `list_events` — `ListenerProvider::getAllListenerDefinitions()`, which core
  marks "only used for debugging purposes"; EXT:lowlevel consumes it the same way.
- `viewhelper_lookup` — the `TYPO3Fluid\Fluid\Schema` classes and
  `ViewHelperResolverFactoryInterface`, exactly as `fluid:schema:generate` uses them.
- `site_sets` — only `SetRegistry::getAllSets()`; `site:sets:list` uses it too.
  On v13 `SettingDefinition` is `@internal` as well, so its public properties are
  read directly instead of its serializer.
- `content_blocks` — `ContentBlockRegistry` and `LoadedContentBlock`, `@internal`
  in both content-blocks 1.6 and 2.4.

`page_tsconfig` deliberately uses the public `BackendUtility::getPagesTSconfig()`
rather than the `@internal` `PageTsConfigFactory`, and `flexform_schema`,
`backend_modules` and `extension_info` rest on public API only.

### Fixed

- `viewhelper_lookup` no longer pollutes the project's deprecation log.
  Collecting metadata calls `initializeArguments()` on every ViewHelper, and
  deprecated ones raise `E_USER_DEPRECATED` there (`<f:cache.warmup>` on
  Fluid 4), so every lookup used to append entries that `read_log_entries`
  would then report as the developer's own. Deprecations are now swallowed for
  the duration of the scan only.
- `LabelTranslator` now also resolves TYPO3 v14 Symfony Translation domain
  references (`backend.modules.layout:title`), not only `LLL:` paths. Labels in
  `tca_schema` and every other tool are readable again on v14.
- The README tool table lists `typoscript`, which was implemented and announced
  since alpha.1 but missing from the table.

## 0.1.0-alpha.4 — 2026-08-07

### Changed

- `tca_schema` is now built on the official Schema API
  (`TYPO3\CMS\Core\Schema\TcaSchemaFactory`, public since v13) instead of the raw
  `$GLOBALS['TCA']` array. Output got richer: schema capabilities, record types
  and pre-resolved relations (e.g. file fields → `sys_file_reference` with
  relationship type) — identical on v13 and v14.

## 0.1.0-alpha.3 — 2026-08-07

### Added

- First-class extensibility: any extension or sitepackage can ship MCP tools by
  implementing `ToolInterface` (auto-registered via container autoconfiguration,
  no manual tagging needed).
- PSR-14 events: `CollectToolsEvent` (add/remove/replace announced tools),
  `BeforeToolExecutionEvent` (adjust arguments, short-circuit or veto calls),
  `AfterToolExecutionEvent` (post-process results).

### Removed

- The `tinker` tool (arbitrary PHP execution in the booted TYPO3) — removed
  entirely for safety, together with its `allowTinker` extension configuration
  (`ext_conf_template.txt`) and the `DEV_MCP_ALLOW_TINKER` opt-in. The server
  now exposes no code-execution capability at all.

## 0.1.0-alpha.2 — 2026-08-07

### Changed

- CI now tests the full PHP 8.2–8.4 range on both TYPO3 13.4 and 14
  (TYPO3 14.3 accepts PHP `^8.2`, so all six combinations are covered).
- Installation goes through Packagist — no VCS repository entry needed anymore.

### Removed

- The content-blocks guideline; the ddev guideline remains and is only composed
  when DDEV is detected (`.ddev/config.yaml` or running inside the container).

## 0.1.0-alpha.1 — 2026-08-07

First public alpha. Verified live against TYPO3 13.4.33 and 14.3.5.

### Added

- MCP server on stdio via `vendor/bin/typo3 devmcp:serve`, built on the official
  PHP MCP SDK (`mcp/sdk`), announcing 14 development tools:
  `application_info`, `database_schema`, `database_query`, `site_info`,
  `tca_schema`, `content_elements`, `typoscript`, `get_config`, `list_commands`,
  `read_log_entries`, `last_error`, `search_changelog`, `get_url`, `flush_cache`
  — plus the opt-in `tinker`.
- `vendor/bin/typo3 devmcp:install`: registers the server in `.mcp.json`
  (DDEV auto-detected) and composes version-specific AI guidelines into
  `.ai/guidelines/typo3.md`, linked from `CLAUDE.md` / `AGENTS.md`.
- Safety model: read-only by default with MCP `readOnlyHint` annotations,
  secret masking, SQL write-guard (`DEV_MCP_ALLOW_WRITE=1` to lift),
  `tinker` double-gated behind Development context + `DEV_MCP_ALLOW_TINKER=1`.

### Known limitations

- `typoscript` builds on a core factory API marked `@internal` — it is verified
  on 13.4/14.3 but may need adjustments for future core versions.
- No functional test suite yet (unit tests + live smoke tests only).

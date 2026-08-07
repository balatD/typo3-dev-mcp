# Changelog

## Unreleased

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
- `search_docs` (querying docs.typo3.org) is not shipped yet; `search_changelog`
  covers the offline case.
- No functional test suite yet (unit tests + live smoke tests only).

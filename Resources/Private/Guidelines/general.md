# TYPO3 development guidelines

This project runs TYPO3 {{typo3Version}} on PHP {{phpVersion}}.

## Use the t3boost MCP tools instead of guessing

- Call `application_info` once at the start of a session: exact versions, active extensions, installed packages.
- Never invent table columns, CTypes or configuration keys. Read the real ones: `tca_schema` (semantic model, relations), `database_schema` (physical schema), `content_elements` (registered CTypes), `get_config` (TYPO3_CONF_VARS / extension configuration).
- Site identifiers, root pages, base URLs and languages come from `site_info`; build frontend links with `get_url`.
- When something fails, read the actual error before theorizing: `last_error`, then `read_log_entries` (sources: file, deprecations, syslog).
- Before using a core API that might have changed between versions, check `search_changelog` — it searches the changelog of the exact installed core version, including the migration path.
- After changing TCA, TypoScript, Services.yaml, site configuration or templates: `flush_cache` (group `system` for configuration/DI, `pages` for content/rendering).
- Discover CLI commands with `list_commands` instead of assuming Symfony/Laravel-style names.

## Conventions

- All database access goes through Doctrine DBAL (`ConnectionPool` / `QueryBuilder`) — never raw SQL string concatenation, never mysqli/PDO directly.
- Respect TYPO3 soft-delete/visibility semantics in custom queries: `deleted=0`, `hidden=0`, `starttime`/`endtime` (or use the QueryBuilder restrictions which apply them automatically).
- Register services via `Configuration/Services.yaml` with autowiring; get dependencies via constructor injection, not `GeneralUtility::makeInstance()` in new code (makeInstance only where DI is unavailable).
- Use PSR-14 events, not legacy hooks, for new extension points.
- Escape output: Fluid escapes by default — never `{variable -> f:format.raw()}` on user input.

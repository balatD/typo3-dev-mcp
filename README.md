# typo3-dev-mcp — AI development helper for TYPO3

`typo3-dev-mcp` gives Claude Code (and any MCP client) live insight into your TYPO3
v13/v14 installation: instead of guessing versions, TCA columns, CTypes or config keys
from files, the AI reads them from the running application. Inspired by
[laravel/boost](https://github.com/laravel/boost).

**Development-only tooling. Do not install or enable in production.**

> **Status: alpha** — verified live against TYPO3 13.4 and 14.3, but APIs and
> tool output formats may still change between releases.

## Installation

```bash
composer require --dev "balatd/typo3-dev-mcp:^0.1@alpha"
vendor/bin/typo3 devmcp:install        # or: ddev exec vendor/bin/typo3 devmcp:install
```

`devmcp:install`

- registers the MCP server in the project's `.mcp.json` — when DDEV is detected the
  server is started via `ddev exec vendor/bin/typo3 devmcp:serve`, otherwise via local PHP;
- composes version-specific AI guidelines into `.ai/guidelines/typo3.md` and links them
  from `CLAUDE.md` / `AGENTS.md` (between idempotent markers, existing content is kept).

Then restart your AI assistant (or run `/mcp` in Claude Code).

## Tools

| Tool | What it does |
|------|--------------|
| `application_info` | TYPO3/PHP version, context, DB platform, active extensions, composer packages |
| `database_schema` | Live schema: tables, columns, indexes, foreign keys |
| `database_query` | Run a single SQL query (read-only unless `DEV_MCP_ALLOW_WRITE=1`) |
| `site_info` | Sites, base URLs, root pages, languages, error handling |
| `tca_schema` | The TCA: tables, columns, types, relations, record types, palettes |
| `content_elements` | Registered CTypes (+ legacy `list_type` plugins where present) |
| `get_config` | `TYPO3_CONF_VARS` subtrees, feature toggles, extension configuration (secrets masked) |
| `list_commands` | All `vendor/bin/typo3` console commands with synopsis |
| `read_log_entries` | Recent log entries — file logs, deprecation log or `sys_log` |
| `last_error` | The most recent error-level log entry |
| `search_changelog` | Search the core changelog (Breaking/Deprecation/Feature/Important) of the installed version — offline and exact |
| `get_url` | Real routed frontend URL for a page + backend login URL |
| `flush_cache` | Flush all caches or a cache group |
| `tinker` | Execute PHP in the booted TYPO3 context — opt-in only (see below) |

### Safety model

- Everything is read-only by default; results carry MCP `readOnlyHint` annotations.
- Secret-looking configuration values (`password`, `encryptionKey`, tokens, …) are masked
  before they leave the server.
- `database_query` accepts only `SELECT`/`SHOW`/`EXPLAIN`/`DESCRIBE`/`WITH` unless the
  developer sets `DEV_MCP_ALLOW_WRITE=1`.
- `tinker` is double-gated: it only exists when the application context is `Development/*`
  **and** `DEV_MCP_ALLOW_TINKER=1` (or the `allowTinker` extension setting) is set.

## Requirements

- TYPO3 13.4 LTS or 14, Composer mode
- PHP 8.2+ (per your TYPO3 version's requirements)

## Development

The repository ships a DDEV harness with TYPO3 v13 and v14 side by side:

```bash
ddev start
ddev install-v13      # TYPO3 13.4 + this extension at /var/www/html/v13
ddev install-v14      # TYPO3 14 + this extension at /var/www/html/v14
```

Backends: `https://v13.typo3-dev-mcp.ddev.site/typo3/` /
`https://v14.typo3-dev-mcp.ddev.site/typo3/` (admin / `Joh316!!`).
Protocol smoke test without an MCP client:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"0"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | ddev exec -d /var/www/html/v13 vendor/bin/typo3 devmcp:serve
```

## License

MIT

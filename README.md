# typo3-dev-mcp — AI development helper for TYPO3

`typo3-dev-mcp` gives Claude Code (and any MCP client) live insight into your TYPO3
v13/v14 installation: instead of guessing versions, TCA columns, CTypes or config keys
from files, the AI reads them from the running application. Inspired by
[laravel/boost](https://github.com/laravel/boost).

**Development-only tooling. Do not install or enable in production.**

## Does it actually help?

Measured, not asserted. 170 benchmark runs on `claude-opus-5` against a real TYPO3 13.4
installation, comparing a plain Claude Code session against the same session after
`devmcp:install`:

| Task family | Baseline | With typo3-dev-mcp |
|---|---|---|
| **Live-state questions** (TCA, compiled TypoScript, FlexForm, site sets) | $0.208 · 10 turns | **$0.097 · 4 turns** |
| Code changes | $0.236 · 13 turns | $0.212 · 13 turns |
| Debugging a seeded fault | $0.164 · 8 turns · 7% hallucination | $0.205 · 9 turns · **0% hallucination** |
| Control (pure refactoring, no live state) | $0.107 · 5.5 turns | $0.088 · 4 turns |

**On questions about the running installation: 53% cheaper, 60% fewer turns.** The largest
single case was resolving `lib.contentElement.templateRootPaths` after site-set merging —
a value that exists in no single file — where the baseline burned **458,000 more tokens**
reconstructing it by reading across extensions.

Two things this benchmark does **not** show, stated plainly:

- **Task success was 100% in both arms, on all 17 tasks.** A competent agent with grep and
  a shell solved everything. This is a measure of efficiency and reliability, not
  capability — the tools make the agent faster and steadier, not smarter.
- The tool schemas cost **6,270 tokens on every request** (measured at 0.1.0-alpha.7),
  whether or not a tool is used. On short, non-TYPO3 work that is pure overhead.

Full methodology, per-task results, per-tool usage and limitations:
**[Tests/Benchmark/README.md](Tests/Benchmark/README.md)**.

## Installation

```bash
composer require --dev balatd/typo3-dev-mcp
vendor/bin/typo3 devmcp:install        # or: ddev exec vendor/bin/typo3 devmcp:install
```

Also published in the TER as `dev_mcp`, though Composer is the supported path for a
`--dev` dependency.

`devmcp:install`

- registers the MCP server in the project's `.mcp.json` — when DDEV is detected the
  server is started via `ddev exec vendor/bin/typo3 devmcp:serve`, otherwise via local PHP;
- writes a tool reference into `.ai/guidelines/typo3.md` and links it from `CLAUDE.md` /
  `AGENTS.md` (between idempotent markers, existing content is kept). It lists what each
  tool reports and nothing else — no framework advice.

Then restart your AI assistant (or run `/mcp` in Claude Code).

> **Claude Code** is supported out of the box via the project-scoped `.mcp.json`.
> Support for other AI CLIs (Codex, Gemini CLI, …) is coming. Meanwhile any MCP
> client can be pointed at `vendor/bin/typo3 devmcp:serve` manually — and the
> composed guidelines already land in `AGENTS.md`, which most agents read.

## Tools

### Application and data

| Tool | What it does |
|------|--------------|
| `application_info` | TYPO3/PHP version, context, DB platform, active extensions, composer packages |
| `database_schema` | Live schema: tables, columns, indexes, foreign keys |
| `database_query` | Run a single SQL query (read-only unless `DEV_MCP_ALLOW_WRITE=1`) |
| `site_info` | Sites, base URLs, root pages, languages, error handling |
| `tca_schema` | The TCA: tables, columns, types, relations, record types, palettes |
| `content_elements` | Registered CTypes (+ legacy `list_type` plugins where present) |
| `content_blocks` | Registered Content Blocks with type name, table and YAML field definitions |

### Resolved configuration

The backend-module-only corners of TYPO3 — core ships no CLI for any of these.

| Tool | What it does |
|------|--------------|
| `typoscript` | Compiled frontend TypoScript for a page (setup / constants / config) |
| `page_tsconfig` | Resolved Page TSconfig for a page — `mod.*`, `TCEFORM`, `TCEMAIN` |
| `site_sets` | Site sets, dependency order, settings definitions, effective site settings |
| `flexform_schema` | Resolved FlexForm data structures: sheets, fields, per-field TCA |
| `middleware_stack` | PSR-15 stacks in execution order with package and before/after |
| `get_config` | `TYPO3_CONF_VARS` subtrees, feature toggles, extension configuration (secrets masked) |

### API discovery

| Tool | What it does |
|------|--------------|
| `viewhelper_lookup` | Every available ViewHelper with its exact arguments, types and defaults |
| `list_events` | PSR-14 events and the listeners actually registered for them |
| `backend_modules` | Registered backend modules: identifier, parent, path, access, routes |
| `list_commands` | All `vendor/bin/typo3` console commands with synopsis |

### Documentation and diagnostics

| Tool | What it does |
|------|--------------|
| `search_docs` | Search docs.typo3.org, pinned to the installed major version |
| `search_changelog` | Search the core changelog (Breaking/Deprecation/Feature/Important) of the installed version — offline and exact |
| `extension_info` | An extension here vs. in the TER and on Packagist — including TYPO3 compatibility |
| `read_log_entries` | Recent log entries — file logs, deprecation log or `sys_log` |
| `last_error` | The most recent error-level log entry |
| `flush_cache` | Flush all caches or a cache group |

`content_blocks` is only announced when `friendsoftypo3/content-blocks` is installed.
Because the DI container is cached, installing that package later needs a
`vendor/bin/typo3 cache:flush` before the tool appears.

### Safety model

- Everything is read-only by default; results carry MCP `readOnlyHint` annotations.
  `flush_cache` is the only tool that changes state.
- Secret-looking configuration values (`password`, `encryptionKey`, tokens, …) are masked
  before they leave the server.
- `database_query` accepts only `SELECT`/`SHOW`/`EXPLAIN`/`DESCRIBE`/`WITH` unless the
  developer sets `DEV_MCP_ALLOW_WRITE=1`.
- `search_docs` and `extension_info` are the only tools that reach the network
  (docs.typo3.org, extensions.typo3.org, Packagist). They use TYPO3's own HTTP client, so
  proxy and TLS settings apply. Set `DEV_MCP_NO_NETWORK=1` to keep the server fully offline
  — those two tools then fail with an explanatory message and everything else is unaffected.
- There is deliberately **no code-execution tool**: nothing this server exposes can run
  arbitrary PHP.

## Extending

### Custom tools from your extension or sitepackage

Implement `BalatD\DevMcp\Mcp\ToolInterface` — that's it. With standard
autoconfiguration (`autoconfigure: true` in your `Services.yaml`, the default in
every modern extension) the service is tagged and announced automatically:

```php
final class ProjectInfoTool implements ToolInterface
{
    public function getName(): string
    {
        return 'project_info';
    }

    public function getDescription(): string
    {
        return 'Project-specific conventions the AI should know.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $arguments): mixed
    {
        return ['deployTarget' => 'staging.example.com'];
    }
}
```

Constructor injection works as in any TYPO3 service. Throw a
`\RuntimeException` with an actionable message on failure — it reaches the AI
as a readable error. After adding a tool, flush the DI cache
(`vendor/bin/typo3 cache:flush`) and restart the MCP server.

### PSR-14 events

| Event | Dispatched | Use it to |
|-------|------------|-----------|
| `CollectToolsEvent` | once at server start | add, remove or replace tools before they are announced |
| `BeforeToolExecutionEvent` | before every tool call | adjust arguments, short-circuit with your own result, or veto by throwing |
| `AfterToolExecutionEvent` | after every successful call | post-process results (extra masking, audit logging) |

```php
#[AsEventListener]
public function __invoke(BeforeToolExecutionEvent $event): void
{
    if ($event->getTool()->getName() === 'flush_cache') {
        throw new \RuntimeException('Cache flushing is disabled in this project.');
    }
}
```

## Requirements

- TYPO3 13.4 LTS or 14, Composer mode
- PHP 8.2, 8.3, 8.4 or 8.5 — every PHP/TYPO3 combination is tested in CI

## Versioning

Semantic versioning, with a deliberately narrow promise. **Covered** — break these and
the major version goes up:

- `ToolInterface`, the contract your own tools implement
- `CollectToolsEvent`, `BeforeToolExecutionEvent`, `AfterToolExecutionEvent` and their
  public methods
- The command names `devmcp:serve` and `devmcp:install`, and `devmcp:install`'s options
- The environment flags `DEV_MCP_ALLOW_WRITE` and `DEV_MCP_NO_NETWORK`

**Not covered** — these may change in any minor release:

- **The shape of what a tool returns.** Payloads are tuned against the benchmark, and
  three of the eight alphas shrank one. That work is not finished: a tool result stays in
  the conversation for every later turn, so an oversized response is paid repeatedly.
  Where a reduction has an escape hatch, it is documented (`{"full": true}` on
  `last_error` and `read_log_entries`, `get_config`'s drill-down hint).
- The concrete tool classes, `ToolRegistry`, `SdkToolHandler`, and everything under
  `Mcp\Support\` and `Install\`. All are marked `@internal`.
- The `mcp/sdk` constraint. It is a 0.x dependency and the constraint may widen in a minor
  release; `ToolInterface` carries no SDK types, so that stays invisible to your tools.

Two tools read core APIs that core itself marks `@internal` — `typoscript`
(`FrontendTypoScriptFactory`) and `list_events` (`ListenerProvider`) — because no public
equivalent exists. Both degrade to an explanatory error rather than a stack trace, but a
core *minor* can require a patch release here.

## Development

The repository ships a DDEV harness with TYPO3 v13 and v14 side by side:

```bash
ddev start
ddev install-v13      # TYPO3 13.4 + this extension at /var/www/html/v13
ddev install-v14      # TYPO3 14 + this extension at /var/www/html/v14
```

Backends: `https://v13.typo3-dev-mcp.ddev.site/typo3/` /
`https://v14.typo3-dev-mcp.ddev.site/typo3/` (admin / `Joh316!!`).

```bash
composer cgl                       # format; cgl:check for the CI dry-run
composer stan                      # PHPStan level 8
composer test:unit
composer test:functional           # boots TYPO3 per test; needs typo3DatabaseDriver=pdo_sqlite
```

The functional suite is a smoke matrix: it executes all 23 tools against a booted TYPO3
and asserts the announced roster, the input schemas and each documented top-level shape.
It deliberately does not pin payload contents — see [Versioning](#versioning).
Protocol smoke test without an MCP client:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"0"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | ddev exec -d /var/www/html/v13 vendor/bin/typo3 devmcp:serve
```

### Benchmark

`Tests/Benchmark/` holds the A/B harness behind the numbers above — a separate,
host-mounted TYPO3 project, a fixture extension seeding the live state the tasks
interrogate, 17 graded tasks, and a blind judge. See
[Tests/Benchmark/README.md](Tests/Benchmark/README.md).

```bash
composer bench:setup     # build the benchmark TYPO3 project (once)
composer bench:verify    # prove the two arms differ only as intended
composer bench:tax       # measure the fixed context cost of the tool schemas
composer bench           # run the sweep
```

## License

MIT

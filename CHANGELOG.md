# Changelog

## 0.1.0-alpha.8 — 2026-08-14

### Changed

- The composed AI guidelines are now a tool reference and nothing else: which
  tools exist, and what each one reports. The seven files of framework advice —
  `core-13`, `core-14`, `fluid`, `tca`, `extbase`, `testing`, `ddev` — are
  removed. They told a competent agent things it already knows (use PSR-14 events,
  don't hardcode fileadmin paths, escape output) and were paid for as context on
  every request. `.ai/guidelines/typo3.md` drops from 9,806 to 2,304 characters.

  Re-run `devmcp:install` to refresh the file; the old content stays in place
  until you do.

### Removed

- `GuidelineComposer::selectGuidelines()`. With one guideline file left there is
  nothing to select by TYPO3 version or DDEV presence, and `compose()` reads it
  directly. The `{{typo3Major}}` placeholder is gone with it; `{{typo3Version}}`
  and `{{phpVersion}}` still resolve.

## 0.1.0-alpha.7 — 2026-08-14

### Changed

The second pass over the benchmark findings, aimed at the one defect the 170-run
sweep exposed but alpha.6 did not fix.

- `get_config` returned whatever subtree you asked for, verbatim and unbounded.
  `{"path": "SYS"}` — the obvious call, and the one four of its six recorded uses
  made — cost 74 KB, more than the entire tool-schema tax several times over. It
  now collapses arrays below depth 2 to a `key => "tree"|"value"` map and reports
  `truncated` plus a drill-down hint (10 KB, −86%). Scalars are never collapsed,
  so `{"path": "SYS/trustedHostsPattern"}` and friends are unchanged, and
  `{"full": true}` returns the whole subtree. Secrets are masked before the
  collapse, not after.
- `list_commands` took no arguments and returned every command with its full
  synopsis — 13.6 KB for a call that exists to answer "what is this command
  called". The list is now names and descriptions only, `{"search": "cache"}`
  filters it (336 bytes), and `{"name": "cache:flush"}` returns one command with
  synopsis, aliases and help (4.6 KB unfiltered, −66%).
- Tool descriptions no longer repeat the usage policy that the guidelines file
  and the server `instructions` string already carry. The fixed schema tax went
  from 6,807 to 6,270 tokens per request — a real cut, but far short of the
  ~2,000 the benchmark notes projected, because descriptions are only about a
  third of a serialized schema. `Tests/Benchmark/results/tax-baseline.md` records
  the corrected arithmetic.

### Removed

- `get_url`, the one tool the benchmark evidence actually supports cutting: never
  called across 170 runs, ~200 bytes of payload, and `site_info` already returns
  every site's base URL. The five other never-called tools stay — `search_docs`
  and `extension_info` were disabled by the harness's `DEV_MCP_NO_NETWORK=1`,
  `read_log_entries` was simply covered by `last_error`, and neither
  `search_changelog` nor `middleware_stack` has an upgrade or PSR-15 task in the
  17-task set to call it. Zero calls there is a gap in the task set, not evidence.

Projects that want a smaller surface can drop tools without patching this
extension, via a `CollectToolsEvent` listener calling `$event->removeTool()`.

## 0.1.0-alpha.6 — 2026-08-12

### Changed

Tool responses got much smaller. Benchmarking the server against a plain Claude
Code session showed three tools dominating token cost, and almost all of it was
payload nobody reads. A tool result also stays in the conversation for every
later turn, so an oversized response is paid repeatedly, not once.

- `last_error` returned the raw log line — 16 KB for one Fluid exception, 96% of
  it stack trace. It now returns exception class, code, file, line, message,
  request URL and the first five frames (1.8 KB, −89%). Pass `{"full": true}`
  for the untouched entry.
- `read_log_entries` had the same problem multiplied by its default limit of 20:
  276 KB for a single call at `level: error`. Same structured output, same
  escape hatch (34 KB, −87%).
- `application_info` no longer lists every installed Composer package by default
  — 52% of its response, mostly transitive dependencies nothing asks about. It
  reports `composerPackageCount` instead; `{"packages": true}` restores the full
  list. `activeExtensions` is unchanged and still answers "is extension X
  installed", as does `extension_info` for a specific package.
- The composed guidelines no longer instruct an unconditional `application_info`
  call at the start of every session, and now state explicitly that pure code
  work — refactoring, reading, explaining a file — needs none of these tools.
  On a refactoring task that instruction alone had been costing 3.6× the
  baseline in tool calls the agent never used.

**Output shapes changed.** Anything parsing `last_error` or `read_log_entries`
now sees structured fields where a raw `message` string used to be, and
`application_info` no longer carries `composerPackages`. The `full` and
`packages` arguments return the previous payloads.

### Added

- `Tests/Benchmark/` — an A/B harness measuring this extension against a plain
  Claude Code session on a real TYPO3 13.4 install. It builds a separate,
  host-mounted benchmark project so the baseline arm keeps genuine file access,
  seeds a fixture extension providing the live state under test, and grades 17
  tasks against oracles that never consult the MCP, with a blind judge for the
  open-ended ones. Run via `composer bench:setup`, `bench:verify`, `bench:tax`,
  `bench`.

First full sweep, 170 runs on `claude-opus-5`: on questions about the running
installation (TCA, compiled TypoScript, FlexForms, site sets) the tools cut cost
53% and turns 60% — the widest case being `lib.contentElement.templateRootPaths`
after site-set merging, where the baseline spent 458k more tokens rebuilding a
value that exists in no single file. Task success was 100% in **both** arms, so
the gain is efficiency and fewer fabricated claims, not capability. The 23 tool
schemas cost ~6,800 tokens on every request whether used or not. Method,
per-task numbers and limitations: `Tests/Benchmark/results/final-report.md`.

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

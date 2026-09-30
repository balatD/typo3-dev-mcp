# Final benchmark report — 1.1.0 vs 1.0.0

Four sweeps of 17 tasks × 2 arms × 5 reps = **680 runs**, all on `claude-opus-5-5` (effort
medium), judged by `claude-opus-5`, under one harness:

| sweep | bench | typo3-dev-mcp | runs | spend |
|---|---|---|---|---|
| `20260925T100144Z` | TYPO3 13.4.35 | 1.0.0 (Packagist) | 170 | $15.23 |
| `20260925T133157Z` | TYPO3 13.4.35 | 1.1.0 (working copy) | 170 | $13.76 |
| `20260925T100144Z` | TYPO3 14.3.7 | 1.0.0 (Packagist) | 170 | $15.23 |
| `20260925T132710Z` | TYPO3 14.3.7 | 1.1.0 (working copy) | 170 | $12.99 |

All 680 usable: 0 voided by hygiene assertions. Two runs that hit the account's session limit
were re-run, together with the rest of their task and arm. Arm A (plain Claude Code with
Read/Grep/Glob/Bash) ran in every sweep, so each bench measured the same baseline twice — the
spread between those is the noise floor.

**This supersedes the 1.0.0 report from PR #2**, whose v13 figures are withdrawn: an
auto-memory note left over from August loaded into every v13 run of that sweep, in both arms,
and steered them away from the stale-state bug below. The harness now clears the bench's
auto-memory before each run and voids a run whose memory path does not match. It also keeps
the bench's git metadata outside the work tree, because both F3 faults could be read straight
off `git diff`. The August alpha.6 report is archived as
[final-report-alpha.6.md](final-report-alpha.6.md).

## Headline

Median cost per run, arm A (no MCP) → arm B (after `devmcp:install`), with median turns:

**TYPO3 13.4**

| Family | arm A | arm B, 1.0.0 | **arm B, 1.1.0** |
|---|---|---|---|
| F1 live-state | $0.074 · 5.5t | $0.044 · 4t (−41%) | **$0.032 · 4t (−59%)** |
| F2 code change | $0.100 · 7t | $0.161 · 13t (+61%) | **$0.104 · 8t (−3%)** |
| F3 debug | $0.103 · 6t | $0.099 · 8t (−4%) | $0.107 · 7t (+11%) |
| F4 control | $0.058 · 4t | $0.063 · 5t (+9%) | $0.062 · 5t (+22%) |

**TYPO3 14.3**

| Family | arm A | arm B, 1.0.0 | **arm B, 1.1.0** |
|---|---|---|---|
| F1 live-state | $0.072 · 5t | $0.048 · 4t (−34%) | **$0.035 · 4t (−53%)** |
| F2 code change | $0.091 · 6t | $0.170 · 13t (+87%) | **$0.106 · 7t (+10%)** |
| F3 debug | $0.098 · 6t | $0.092 · 8t (−6%) | **$0.073 · 7t (−29%)** |
| F4 control | $0.061 · 5t | $0.068 · 5t (+12%) | $0.062 · 6t (+13%) |

The percentages compare each arm B with the arm A of its own sweep; the arm-A column shows
the 1.0.0 sweep. Arm A's two measurements per bench differ by **4–12% at family level**, so
movements inside that band are noise: the F3 change on v13 and both F4 rows. The F1 and F2
changes are well outside it.

## Read this first: success is still a null result

**Every checker-graded run passed: 520 of 520**, 130 per sweep, both arms, both TYPO3
versions. The judge graded 90 of the 160 answers that need it — every one correct, with no
hallucinated claims in either arm. The other 70, mostly the 1.1.0 debugging runs, were not
graded before the account's usage limit; `bin/judge.sh` resumes them.

Every figure below is about efficiency.

## What 1.1.0 changed, and what it bought

### Stale state — the F2 regression is gone

In 1.0.0 `devmcp:serve` ran every call in the process it booted in. After an edit, `tca_schema`,
`list_events` and `backend_modules` kept the old state even after `flush_cache`, and
`flush_cache` itself skipped the dependency-injection caches. Agents that verified their edits
through the tools were told the new field or listener did not exist, retried, and fell back to
Bash. 1.1.0 runs each call in a fresh process and flushes like core's `cache:flush`.

| Task | v13 arm B 1.0.0 → 1.1.0 | v14 arm B 1.0.0 → 1.1.0 | arm A (1.1.0 sweep) |
|---|---|---|---|
| `f2-event-listener` | $0.161 → **$0.083** | $0.174 → **$0.080** | $0.098 / $0.093 |
| `f4-rename-method` | $0.152 → **$0.071** | $0.179 → **$0.080** | $0.068 / $0.093 |
| `f2-add-tca-field` | $0.216 → **$0.136** | $0.205 → **$0.133** | $0.110 / $0.102 |

`f2-event-listener` and `f4-rename-method` are now at or below the baseline.
`f2-add-tca-field` still costs ~$0.03 more than arm A: arm B verifies the new field against
the live installation (`database_query` for the column, `tca_schema` for the form) where
arm A trusts its edit. That verification now succeeds on the first attempt.

The call counts show the loops disappearing: `flush_cache` 27 → 16 calls (v13) and 27 → 15
(v14), `list_events` 19 → 9 and 20 → 5, `backend_modules` 12 → 5 and 10 → 5.

### Live state — arm B now wins all eight F1 tasks

Two F1 tasks were losses for arm B in 1.0.0 on both versions. Both were a tool that could not
answer the question in one call:

| Task | v13 arm B 1.0.0 → 1.1.0 | v14 arm B 1.0.0 → 1.1.0 | arm A (1.1.0 sweep) |
|---|---|---|---|
| `f1-custom-ctypes` | $0.191 → **$0.087** | $0.167 → **$0.101** | $0.156 / $0.139 |
| `f1-site-languages` | $0.047 → **$0.027** | $0.048 → **$0.029** | $0.035 / $0.040 |

- `tca_schema {"type": …}` returns one record type's form fields, so "which fields does this
  content element show" no longer means grepping TCA overrides and core palettes.
- `site_info` reports each language's configured base and fallbacks and resolves the 404
  handler's `t3://page` target, which removed a `database_query` round trip.

The other six F1 tasks were already wins and did not move. `f1-template-paths` remains the
largest: $0.223 → $0.030 on v13, with arm A reading 4.4× the tokens (304k vs 68k).

### Debugging

`flexform_schema {"uid": …}` compares a record's stored FlexForm values with its structure and
names the orphaned `settings.limit` directly. On v14, `f3-flexform-mismatch` went from $0.087
to $0.071 against arm A's $0.095, and F3 as a whole from −6% to −29%. On v13 the family
median moved within noise.

## Where arm B still costs more

- **`f3-missing-set-dependency`**: +$0.03 on both versions (arm A → arm B: v13 $0.133 →
  $0.165, v14 $0.127 → $0.158). The new not-found message does say "Loaded from site sets:
  none", but arm B keeps triangulating through `site_sets`, `site_info`, `database_query` and
  more `typoscript` paths before answering. The next candidate for tuning.
- **`f2-add-tca-field`**: +$0.03, the verification described above.
- **F4 control**: +13–22% on medians, about $0.01 per task. The MCP is irrelevant to these
  tasks and mostly unused; this is the per-request overhead below showing up on short work.
  A third of these runs also called `php` on a host without one and then spent turns
  finding DDEV. The guidelines now say to use `ddev exec`. A follow-up sweep
  (`20260930T080428Z`, v13, arm B only, F2 + F4 × 5 reps: 30 runs, all 25 checker-graded
  runs passed, the 5 `f4-explain-file` answers not judged):

  | | F4 `php` detours (runs / DDEV lookups) | F4 median | F2 median |
  |---|---|---|---|
  | arm A (1.1.0 sweep) | 9 of 15 / 5 | $0.051 · 4t | $0.106 · 7t |
  | arm B 1.1.0 | 5 of 15 / 2 | $0.062 · 5t (+22%) | $0.104 · 8t (−3%) |
  | arm B + DDEV hint | **0 of 15 / 0** | $0.053 · 5t (+5%) | $0.097 · 7t (−9%) |

  The detours are gone. The medians move within the noise floor. F4 total spend rose
  anyway ($0.93 → $1.00), because some `f4-rename-method` runs added a repo-wide grep; none
  of those runs called `php` or DDEV.

## Tool usage

15 of 22 tools were called in each 1.1.0 sweep. Never called in any of the four:
`extension_info`, `list_commands`, `middleware_stack`, `read_log_entries`, `search_changelog`,
`search_docs`, `viewhelper_lookup`. As before, this is a task-set gap — no task needs a
ViewHelper signature, a middleware order or a changelog entry — and not evidence that those
tools are dead weight.

Largest payloads in the 1.1.0 sweeps: `database_schema` for one table (~3.1k tokens),
`content_elements` (~1.0k), `tca_schema` (median ~830 tokens now that agents ask for one
record type instead of the whole table, which was ~2.8k).

## Cost model

In Claude Code, installing typo3-dev-mcp adds **~1,700 tokens to every request**: tool names,
the server's instructions and the guidelines. Claude Code loads tool schemas on demand, at the
price of one `ToolSearch` turn before the first use. A client that loads every schema up front
pays **6,409 tokens** per request (Opus 5.5; +131 over 1.0.0 for the new arguments). Details:
[tax-baseline.md](tax-baseline.md).

## Limitations

- **One project shape per version**: vanilla TYPO3 plus a fixture extension. Real projects
  hide more live state; this is a floor.
- **Two arms cannot separate tools from guidelines**: `devmcp:install` ships both.
- **n=5 per cell.** Family medians aggregate 15–40 runs and are firmer than per-task ones.
- **1.1.0 ran from the working copy** at commit `ffdd82e`, symlinked into both benches, not
  from a Packagist release. The released 1.1.0 contains the same code.
- **Interruptions**: both 1.1.0 sweeps were resumed into the same sweep id after a snapshot
  restore failed on an overloaded host, and the Mac slept over a weekend mid-sweep. Resumed
  tasks were re-run whole; no run was cut short by either.
- **Network isolation is inert**: `DEV_MCP_NO_NETWORK` set on the host does not reach the
  server inside DDEV. The network tools were available and unused.

## Reproducing

```bash
DEV_MCP_CONSTRAINT=1.0.0 bin/setup-bench.sh                 # v13 bench
BENCH_TYPO3=14 DEV_MCP_CONSTRAINT=1.0.0 bin/setup-bench.sh  # v14 bench
bin/verify-arms.sh && bin/oracle.sh                         # prefix BENCH_TYPO3=14 for v14
BENCH_MODEL=claude-opus-5-5 bin/run.sh --reps 5             # BENCH_SWEEP_ID=… resumes
bin/judge.sh
ddev exec -d /var/www/dev_mcp/Tests/Benchmark php bin/report.php --sweep <id>
```

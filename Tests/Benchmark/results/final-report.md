# Final benchmark report — 1.0.0

Two sweeps of 17 tasks × 2 arms × 5 reps = **340 runs**, `claude-opus-5-5` (effort medium),
`balatd/typo3-dev-mcp:1.0.0` installed from Packagist (mcp/sdk 0.8.1), 22 tools:

- **v13 bench:** TYPO3 13.4.35, 170 runs, $13.37 (arm A $7.05, arm B $6.32)
- **v14 bench:** TYPO3 14.3.7, 170 runs, $13.55 (arm A $6.61, arm B $6.94)

340 usable — 0 voided by hygiene assertions, 0 API errors. Judge `claude-opus-5`, as in
August, deliberately not the model under test. A 68-run pilot ($7.26) preceded the sweeps and
is not included. The previous report (August, alpha.6, `claude-opus-5`) is archived as
[final-report-alpha.6.md](final-report-alpha.6.md).

Arm A = plain Claude Code with Read/Grep/Glob/Bash. Arm B = after `typo3 devmcp:install`
(MCP server + `.ai/guidelines/typo3.md`). Skills and user settings disabled in both.

## Headline

**TYPO3 13.4**

| Family | Arm | Runs | Success | Halluc | Med cost | Med tokens | Med turns |
|---|---|---|---|---|---|---|---|
| **F1** live-state | a | 40 | 100% | — | $0.074 | 113,937 | 5 |
| **F1** live-state | **b** | 40 | 100% | — | **$0.038** | **93,884** | **4** |
| F2 code change | a | 15 | 100% | — | $0.106 | 144,391 | 7 |
| F2 code change | b | 15 | 100% | — | $0.109 | 171,655 | 7 |
| F3 debug | a | 15 | 100% | 0% | $0.073 | 89,766 | 4 |
| F3 debug | b | 15 | 100% | 0% | $0.089 | 122,357 | 7 |
| F4 control | a | 15 | 100% | 0% | $0.068 | 111,868 | 5 |
| F4 control | b | 15 | 100% | 0% | $0.066 | 117,929 | 5 |

**TYPO3 14.3**

| Family | Arm | Runs | Success | Halluc | Med cost | Med tokens | Med turns |
|---|---|---|---|---|---|---|---|
| **F1** live-state | a | 40 | 100% | — | $0.069 | 111,945 | 5 |
| **F1** live-state | **b** | 40 | 100% | — | **$0.045** | **71,853** | **4** |
| F2 code change | a | 15 | 100% | — | $0.096 | 134,376 | 6 |
| F2 code change | b | 15 | 100% | — | **$0.163** | **265,485** | **12** |
| F3 debug | a | 15 | 100% | 0% | $0.074 | 110,266 | 5 |
| F3 debug | b | 15 | 100% | 0% | $0.076 | 124,389 | 7 |
| F4 control | a | 15 | 100% | 0% | $0.061 | 108,306 | 5 |
| F4 control | b | 15 | 100% | 0% | $0.059 | 93,831 | 5 |

## Read this first: success is still a null result

**Every task, both arms, both TYPO3 versions: 100%.** All 80 judged answers were correct with
a score of 3/3 and **zero hallucinated claims in either arm**. The one accuracy-flavoured signal
from August — 1 of 15 arm-A F3 answers with a fabricated claim — did not reproduce on
Opus 5.5.

Everything below is a claim about **efficiency**, not capability or accuracy.

## Where it wins: live-state lookup

F1 is the family the tools exist for, and the effect holds on both versions:

| | v13 | v14 |
|---|---|---|
| median cost | $0.074 → **$0.038 (−48%)** | $0.069 → **$0.045 (−35%)** |
| median tokens | 114k → 94k (−18%) | 112k → 72k (−36%) |
| median turns | 5 → 4 | 5 → 4 |

The mechanism is the same as in August — targeted queries replace file archaeology:

| Task | v13 a → b | v14 a → b |
|---|---|---|
| `f1-template-paths` | $0.145 → **$0.034** (−145k tok) | $0.120 → **$0.030** (−115k tok) |
| `f1-page-tsconfig` | $0.090 → **$0.022** (−88k tok) | $0.070 → **$0.023** (−40k tok) |
| `f1-flexform-record` | $0.066 → $0.040 | $0.087 → $0.048 |

`f1-template-paths` remains the clearest case: the compiled
`lib.contentElement.templateRootPaths` after site-set merging exists in no single file, and
`typoscript` returns it directly.

Not uniform: `f1-custom-ctypes` costs more in arm B on both versions (+$0.028 / +$0.023), and
`f1-site-languages` on v13 (+$0.026).

## Where it costs: code changes on v14

On v14, arm B's F2 median is **+70%** ($0.096 → $0.163) with twice the turns (6 → 12), and
the F4 task `f4-rename-method` nearly doubles ($0.094 → $0.176). On v13 the same tasks are at
parity. Success is 100% in both arms either way — this is spend, not correctness.

The streams show why. On v14 arm B verifies every edit through the tools; on v13 it verifies
with Bash or not at all. Tool sequences, all five reps alike:

```
v14 f2-event-listener  Bash Bash Write ToolSearch flush_cache list_events list_events Bash …
v14 f4-rename-method   Bash Bash Bash ToolSearch flush_cache backend_modules flush_cache backend_modules …
v13 f2-event-listener  Bash Bash Write Bash Bash Bash
```

Two specifics are worth acting on:

- **`flush_cache` on a method rename.** Its description says editing PHP inside a class needs
  no flush; on v14 every `f4-rename-method` run flushed twice anyway.
- **`ToolSearch` round-trips.** Claude Code defers MCP tool schemas, so the first use of any
  tool in a session costs an extra turn to load it. That is paid on both versions and is
  outside this extension's control.

Why v14 and not v13 is **not established**: same tools, same guidelines, consistent across all
five reps on one version and absent on all five on the other. A plausible reading — untested —
is that the model trusts its prior knowledge of v14 less and checks the live install.

## Debugging and the control

- **F3 debug:** arm B costs more on v13 (+21%, 4 → 7 turns) and is at parity on v14 (+2%).
  Both arms diagnosed every fault correctly.
- **F4 control** (the MCP is irrelevant): parity on both versions ($0.068 → $0.066,
  $0.061 → $0.059). The tax is not showing up as extra spend here.

## Compared to August

August ran alpha.6 on `claude-opus-5`; this ran 1.0.0 on `claude-opus-5-5`. **Both the model
and the code changed**, so no difference below can be attributed to either alone.

| v13 | Aug a | Aug b | now a | now b |
|---|---|---|---|---|
| F1 median cost | $0.208 | $0.097 | $0.074 | $0.038 |
| F1 median turns | 10 | 4 | 5 | 4 |
| F3 median cost | $0.164 | $0.205 | $0.073 | $0.089 |
| F4 median cost | $0.107 | $0.088 | $0.068 | $0.066 |

The baseline got much stronger: arm A's F1 cost fell 64% and its turns halved. The MCP's lead
in turns narrowed accordingly (60% fewer → 20% fewer), while its relative cost saving on F1
held (−53% → −48%).

## Tool usage

**v13: 14 of 22 tools called. v14: 15 of 22.** Never called in either sweep:
`extension_info`, `list_commands`, `middleware_stack`, `read_log_entries`,
`search_changelog`, `search_docs`, `viewhelper_lookup`.

| Tool | v13 calls | v14 calls | med payload (v13) |
|---|---|---|---|
| `database_query` | 19 | 26 | 939 chars |
| `site_sets` | 17 | 20 | 2,445 chars |
| `typoscript` | 17 | 18 | 223 chars |
| `site_info` | 12 | 11 | 533 chars |
| `flexform_schema` | 10 | 11 | 871 chars |
| `get_config` | 10 | 10 | 42 chars |
| `flush_cache` | 4 | **25** | 17 chars |
| `tca_schema` | 5 | 14 | 6,498 chars |
| `list_events` | 0 | 12 | — |
| `backend_modules` | 1 | 12 | 586 chars |
| `content_elements` · `application_info` · `database_schema` · `page_tsconfig` · `last_error` | 5 each | 5 each | |

Tool output totalled ~68k tokens over 120 calls (v13) and ~72k over 184 calls (v14), across 85
arm-B runs each. The largest single payloads are `database_schema` for one table (~3.1k
tokens) and `tca_schema` for a whole table (~2.8k); on v14 most `tca_schema` calls asked for
one field (median 33 tokens).

**Correction to August's caveat.** August attributed the idle network tools to
`DEV_MCP_NO_NETWORK=1`. That flag is set on the host by `run.sh`, but `ddev exec` does not
forward host environment variables, so it never reached the server — verified during this
sweep. `search_docs` and `extension_info` were live in both sweeps and simply never chosen.
The other never-called tools are, as before, partly a task-set gap: no task needs a
ViewHelper signature, a middleware order or a changelog entry.

## Cost model

Fixed context tax: **6,278 tokens per request** on Opus 5.5 (6,269 on Sonnet 5), unchanged
since alpha.7 and identical on v14 — see [tax-baseline.md](tax-baseline.md). F4 parity says it
is not visible as extra spend on these task sizes: after the first request it is mostly cache
reads.

## Limitations

- **One project shape per version,** vanilla TYPO3 plus a fixture extension. Real projects
  with sitepackages and heavy TCA overrides hide more live state; this is a floor.
- **Two arms cannot separate tools from guidelines.** `devmcp:install` ships both.
- **n=5 per cell.** Family numbers aggregate 15–40 runs and are firmer than per-task ones.
- **The v14 bench is new** and needed a fixture change: v14 keeps a content type's FlexForm on
  the type itself, so the fixture now registers it per version (v13 unchanged; its 17 oracles
  regenerated byte-identical to August's).
- **One checker fix during the pilot:** `f1-trusted-hosts` rejected the correct answer
  "displayErrors: yes, `1`". The widened check still passes all ten August answers and still
  rejects "no" and "disabled"; the one affected pilot row was regraded.
- **The network flag is inert** (see above). Runs were not network-isolated.

## Reproducing

```bash
DEV_MCP_CONSTRAINT=1.0.0 bin/setup-bench.sh                 # v13 bench
BENCH_TYPO3=14 DEV_MCP_CONSTRAINT=1.0.0 bin/setup-bench.sh  # v14 bench
bin/verify-arms.sh && bin/oracle.sh                         # prefix BENCH_TYPO3=14 for v14
BENCH_MODEL=claude-opus-5-5 bin/run.sh --reps 5
bin/judge.sh
ddev exec -d /var/www/dev_mcp/Tests/Benchmark php bin/report.php
bin/tax.sh "$BENCH_ROOT/.mcp.json" 3                        # from the bench root, arm b
```

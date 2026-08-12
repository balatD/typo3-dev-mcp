# Benchmark: typo3-dev-mcp vs. a no-MCP baseline

Answers three questions with numbers instead of anecdote:

1. Does `devmcp:install` raise task success and lower hallucination on TYPO3 work?
2. What does it cost — the fixed context tax, plus per-task token spend?
3. Which tools earn their place, and which should be cut or paginated before 1.0?

## Latest results

170 runs, `claude-opus-5`, TYPO3 13.4.34. Full write-up:
**[results/final-report.md](results/final-report.md)** · cost model:
[results/tax-baseline.md](results/tax-baseline.md) · optimisation work:
[results/efficiency-findings.md](results/efficiency-findings.md)

| Family | Arm A (baseline) | Arm B (installed) |
|---|---|---|
| F1 live-state | $0.208 · 170k tok · 10 turns | **$0.097 · 99k tok · 4 turns** |
| F2 code change | $0.236 · 13 turns | $0.212 · 13 turns |
| F3 debug | $0.164 · 8 turns · 7% halluc | $0.205 · 9 turns · **0% halluc** |
| F4 control | $0.107 · 5.5 turns | $0.088 · 4 turns |

**Success was 100% in both arms on all 17 tasks.** The baseline solved everything; this
measures efficiency and reliability, not capability.

## Quick start

```bash
bin/setup-bench.sh          # build the benchmark TYPO3 project (~5 min, once)
bin/verify-arms.sh          # prove the two arms differ only as intended
bin/oracle.sh               # generate ground truth
bin/tax.sh                  # measurement 1 — fixed context tax
bin/run.sh --reps 5         # measurement 2 — the sweep
bin/judge.sh                # grade what checkers can't
ddev exec -d /var/www/dev_mcp/Tests/Benchmark php bin/report.php
```

`report.php` needs PHP, which is not installed on the host — run it in the container.

## The two arms

| Arm | State | Represents |
|-----|-------|------------|
| **a** | no `.mcp.json`, no `.ai/guidelines/`, no `CLAUDE.md` | before `devmcp:install` |
| **b** | whatever `typo3 devmcp:install` actually produces | after |

`switch-arm.sh b` runs the real install command rather than copying a snapshot of its
output, so the benchmark measures what ships.

**`b − a` is "what installing typo3-dev-mcp gets you", not "what the 24 tools get you."**
The installer ships two treatments at once: the tools *and* an 89-line
`.ai/guidelines/typo3.md`. Those guidelines are prompt engineering and may carry a real
share of the gain. Separating them needs a third arm (guidelines, no `.mcp.json`) — the
runner is arm-parameterised, so that is one `case` branch in `switch-arm.sh`, not a
rewrite. Worth adding if `b − a` turns out large.

## Why the benchmark is built this way

**The control arm is not blindfolded.** Arm A is a normal Claude Code session with
Read/Grep/Glob/Bash in a TYPO3 project. It can grep `Configuration/TCA/`, run
`vendor/bin/typo3 configuration:showactive`, and read `settings.php`. A control that
can't do those things measures "tools vs. nothing" and proves nothing.

**The benchmark target is host-mounted, deliberately.** The dev harness in `/.ddev`
keeps its v13/v14 installs in named Docker volumes. Claude Code runs on the host, so
against those volumes arm A would have no file access at all and could only reach the
project through `ddev exec cat` — crippling the control and inflating the result.
`setup-bench.sh` therefore builds a separate DDEV project whose docroot is a normal
host directory.

**The MCP is never its own oracle.** Ground truth comes from `vendor/bin/typo3`, direct
SQL, and reading config files. `bin/mcp-call.sh` exists for debugging only — using it
for oracles would make the benchmark circular.

**Ambient confounders are off.** This machine has ~40 `backend:typo3-*` skills, plus
auto-memory and local settings. `--disable-slash-commands` and
`--setting-sources project` remove them from both arms. Without the first flag arm A
silently gets `typo3-tca` and friends doing the MCP's job.

**`--setting-sources ""` would be a trap.** The empty value also suppresses `CLAUDE.md`
discovery, which strips the guidelines out of arm B while the run still reports as a
full install. `verify-arms.sh` exists to catch exactly this class of silent
invalidation; run it before every sweep.

**One run per arm is noise.** Default is 5 reps per (task × arm), reported as medians
with per-task pairing.

## Task format

One file per task, `tasks/<name>.task`, so prompt, ground truth and grading rule stay
together:

```
family: F1
grade: deterministic

--- prompt ---   text handed to the agent verbatim
--- oracle ---   bash producing ground truth; cwd is the bench project
--- check ---    bash; $RESPONSE_FILE and $ORACLE_FILE set; exit 0 = pass
--- setup ---    optional; applied after reset (seeded breakage for F3)
```

`check` may inspect the live install, not just the response text — F2 tasks assert the
TCA field resolves and the database column exists, because "I added it" is worth
nothing if TYPO3 never picked it up.

### Families

| | What it measures | Tasks |
|---|---|---|
| **F1** | live-state questions — where the MCP should dominate | 8 |
| **F2** | real code changes, graded against the live install | 3 |
| **F3** | seeded breakage, diagnosis only | 3 |
| **F4** | negative controls — MCP is irrelevant, so cost here is the tax showing up | 3 |

F1 tasks are built so the plausible-but-wrong answer is the one you get from reading
files alone: `trustedHostsPattern` is set in `additional.php` rather than
`settings.php`, `itemsPerPage` is overridden by the site so the set default is a decoy,
and the FlexForm's stored value differs from the data structure's default.

## Hygiene assertions

Checked automatically on every run; a violation voids the run rather than skewing the
aggregate.

- arm A must make zero `mcp__typo3-dev-mcp__` calls
- no run may invoke a `Skill` (proves `--disable-slash-commands` held)
- state is reset between every run: DB snapshot restore plus `git checkout`/`clean`

Runs are serialized. Reset restores a shared database, so concurrent runs would corrupt
each other. Budget ~12s per reset.

## Fixture

`fixture/bench_fixture/` seeds the live state the tasks interrogate — a custom CType
with three non-core `tt_content` columns, a two-sheet FlexForm plugin, a site set with
settings definitions, page TSconfig with `TCEFORM` overrides, a backend module, and a
PSR-14 listener. `seed-content.sh` adds a page tree, a second language, and content
records.

Edit the fixture, then `bin/setup-bench.sh --sync-fixture` — the bench project holds a
copy, because a symlink would have to resolve to different paths on the host and inside
the container.

`content_blocks` is not registered here (no `friendsoftypo3/content-blocks` installed),
so the surface is **23 tools, not 24**. Exclude it from attribution or add the package.

## Interpreting the output

- **Claim table** — per family: success, hallucination rate, median cost, and
  cost-per-success. Cheap and wrong is not a win.
- **Per-task paired** — arm b minus arm a, per task.
- **Tuning ledger** — per tool: calls, runs, and the success rate of runs that used it,
  plus a list of tools never called at all. A tool that never fires still costs its
  schema on every request; pair this with `results/tax-baseline.md`.

## Known limitation

A vanilla TYPO3 install is the **floor**, not the average. The gap between arms will be
wider on a project with heavy TCA overrides, a sitepackage, and real content, because
there is more hidden live state for the tools to reveal. Report the fixture's shape next
to any number, and frame the result as a conservative lower bound.

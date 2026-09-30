# Benchmark: typo3-dev-mcp vs. a no-MCP baseline

Answers three questions with numbers instead of anecdote:

1. Does `devmcp:install` raise task success and lower hallucination on TYPO3 work?
2. What does it cost — the fixed context tax, plus per-task token spend?
3. Which tools earn their place, and which should be cut or paginated before 1.0?

## Latest results

680 runs on `claude-opus-5-5`, TYPO3 13.4.35 and 14.3.7, 1.0.0 against 1.1.0 under one harness.
Full write-up: **[results/final-report.md](results/final-report.md)** · cost model:
[results/tax-baseline.md](results/tax-baseline.md) · the August alpha.6 sweep:
[results/final-report-alpha.6.md](results/final-report-alpha.6.md)

Median cost per run, arm A (baseline) → arm B (installed 1.1.0), and where 1.0.0 stood:

| Family | TYPO3 13.4 | TYPO3 14.3 | arm B on 1.0.0 |
|---|---|---|---|
| F1 live-state | $0.079 → **$0.032** (−59%) | $0.076 → **$0.035** (−53%) | −41% / −34% |
| F2 code change | $0.106 → $0.104 (−3%) | $0.096 → $0.106 (+10%) | +61% / +87% |
| F3 debug | $0.097 → $0.107 (+11%) | $0.102 → **$0.073** (−29%) | −4% / −6% |
| F4 control | $0.051 → $0.062 (+22%) | $0.055 → $0.062 (+13%) | +9% / +12% |

Every checker-graded run passed in both arms, and the 90 judged answers graded so far were all
correct with no hallucinations. Arm A's two runs per bench differ by 4–12%, so the F4 and
v13 F3 rows are within noise. 1.1.0's stale-state fix is the F2 row.

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

### TYPO3 v14 and released versions

Every script targets the v13 bench unless `BENCH_TYPO3=14` is set, which selects a
second DDEV project (`typo3-dev-mcp-bench-v14`) with its own `.bench-env.v14`,
`oracle/v14/` and `results/v14/`. The two benches share nothing, so their sweeps can run
in parallel. `DEV_MCP_CONSTRAINT=1.0.0` makes `setup-bench.sh` install that release from
Packagist instead of the working copy.

```bash
BENCH_TYPO3=14 DEV_MCP_CONSTRAINT=1.0.0 bin/setup-bench.sh
BENCH_TYPO3=14 bin/verify-arms.sh && BENCH_TYPO3=14 bin/oracle.sh
BENCH_TYPO3=14 bin/run.sh --reps 5 && BENCH_TYPO3=14 bin/judge.sh
ddev exec -d /var/www/dev_mcp/Tests/Benchmark env BENCH_TYPO3=14 php bin/report.php
```

Task files write the bench host as `{{SITE_HOST}}`; `task_section` fills it in.

## The two arms

| Arm | State | Represents |
|-----|-------|------------|
| **a** | no `.mcp.json`, no `.ai/guidelines/`, no `CLAUDE.md` | before `devmcp:install` |
| **b** | whatever `typo3 devmcp:install` actually produces | after |

`switch-arm.sh b` runs the real install command rather than copying a snapshot of its
output, so the benchmark measures what ships.

**`b − a` is "what installing typo3-dev-mcp gets you", not "what the 22 tools get you."**
The installer ships two treatments at once: the tools *and* a 47-line
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
the container. The sync commits to the bench's git, since every run resets with
`git checkout`.

`content_blocks` is not registered here (no `friendsoftypo3/content-blocks` installed),
so the surface is **22 tools, not 23**. Exclude it from attribution or add the package.

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

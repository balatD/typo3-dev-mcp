# Efficiency findings — what the pilot says to change

> **Outcome (implemented and measured).** All four changes shipped.
>
> | payload | before | after | |
> |---|---|---|---|
> | `read_log_entries` (19 error entries) | 276,270 chars | **34,492** | −87.5% |
> | `last_error` | 16,136 chars | **1,818** | −89% |
> | `application_info` | 8,346 chars | **2,706** | −68% |
> | schema tax | 6,620 tok | 6,807 tok | +187 |
>
> Behaviour, arm B medians (arm A unchanged as reference):
>
> | task | before | after | arm A |
> |---|---|---|---|
> | `f4-rename-method` | $0.469 · 19.5 turns | **$0.155 · 8 turns** | $0.132 · 7.5 |
> | `f3-broken-viewhelper` | $0.370 · 10.5 turns | **$0.215 · 9 turns** | $0.211 · 9.5 |
>
> Arm B is now at parity with the no-MCP baseline on both, where it was 3.6× and
> 1.75× more expensive. `application_info` went from 6 calls in 6 runs to **zero**.
> Diagnosis quality held: all 3 post-change `f3` runs judged correct, score 3, zero
> hallucinated claims — the trimmed `last_error` still names the offending arguments,
> the valid alternatives, and the file and line.
>
> The tax rose 187 tokens because three tools gained opt-in properties. That is the
> intended trade: the tax is mostly cache-reads after the first request, while payloads
> stay in context for the rest of the session.


Evidence: `results/tax-baseline.md` (schema cost) + `bin/tool-payloads.sh` over the
16-run pilot (27 MCP calls, 103,431 chars of tool output ≈ 42,740 tokens).

## Measured per-call payloads

| tool | calls | median chars | median tokens | note |
|---|---|---|---|---|
| `last_error` | 2 | 16,083 | **6,645** | one unparsed `message` field |
| `application_info` | 6 | 5,986 | **2,473** | called once per run, every run |
| `database_schema` | 4 | 7,337 | 3,032 | full column list |
| `viewhelper_lookup` | 2 | 1,561 | 645 | |
| `backend_modules` | 2 | 586 | 242 | called on a pure-refactor task |
| `site_info` | 2 | 533 | 220 | |
| `tca_schema` | 4 | 78 | 32 | efficient |
| `flush_cache` | 5 | 26 | 10 | efficient |

## Two different costs, and they behave differently

**Schema cost (6,620 tokens)** is paid on every request, but only the first request pays
creation price — later turns read it from cache at roughly a tenth of the cost. It is a
real floor, but its dollar impact is smaller than the raw number suggests.

**Payload cost is worse than it looks.** A tool result lands in the conversation and is
re-read as context on *every subsequent turn* for the rest of the session. A single
6,645-token `last_error` in turn 3 of an 11-turn run is carried through 8 more turns.
This is the lever with the most headroom.

---

## 1. `last_error` returns 16 KB for one error — ~95% of it noise

`Classes/Mcp/Tool/LastErrorTool.php:58` returns `$entries[0]` straight from `LogReader`.
The entry is one raw log line: exception class, code, file, line, the message, the full
stack trace, and the serialized request context blob.

Measured composition of one real call:

| field | chars |
|---|---|
| `message` | 15,966 |
| `timestamp` | 33 |
| `file` | 22 |
| `level` | 10 |

The agent needs exception class, code, file, line, message, and a few frames. That is
roughly 600–800 chars.

**Change:** parse the log line into structured fields and truncate the trace by default,
with `{"full": true}` to opt back in. This is the same discipline `SearchChangelogTool`
already applies with `DEFAULT_LIMIT = 10`.

**Estimated saving:** ~6,300 tokens per call, and it compounds across every later turn.
`read_log_entries` shares `LogReader` and almost certainly has the same problem at
`n` × the size — worth measuring with a task that exercises it.

## 2. `application_info` is mandated every session, and half of it is dependency noise

`ApplicationInfoTool.php:64-68` enumerates **every installed Composer package**, including
transitive dependencies. Measured on the bench project:

| key | chars | share |
|---|---|---|
| `composerPackages` | 4,349 | **52%** |
| `activeExtensions` | 1,332 | 16% |
| everything else (versions, context, db) | ~150 | 2% |

The guideline in `Resources/Private/Guidelines/general.md` says *"Call `application_info`
once at the start of a session"*, and the tool description repeats it
(`ApplicationInfoTool.php:34-35`). The pilot shows it working exactly as instructed:
**6 calls across 6 runs** — including on `f4-rename-method`, a pure refactor where none of
it was used.

**Change:** drop `composerPackages` from the default response and expose it behind
`{"packages": true}`. An agent that needs to know whether `friendsoftypo3/content-blocks`
is installed already has `activeExtensions`, and `extension_info` answers the specific
question.

**Estimated saving:** ~1,285 tokens on every session that follows the guideline.

## 3. The schema tax is flat, so only breadth-first trimming moves it

No tool exceeds 6.7% of the 6,620-token tax; the top five total 31%. Dropping tools will
not move this. Two options that will:

- **Trim descriptions and schema prose across all 23 tools.** Descriptions currently carry
  usage advice that duplicates the guidelines file — `application_info`'s "Call this once
  at the start of a session to ground yourself" is instruction, not interface. A 30% cut
  is worth roughly 2,000 tokens per request.
- **Merge tools that share a surface.** The three log tools (`last_error`,
  `read_log_entries`, plus the deprecation source) are one tool with a `source` argument.
  `search_docs` + `search_changelog` + `extension_info` are three variants of "look
  something up". Each merge removes a whole schema entry.

Re-measure with `bin/tax.sh` after any change — it is deterministic to the token.

## 4. Induced tool-calling on tasks with no MCP value

`f4-rename-method` is a pure refactor. Arm B cost **3.6× more** than baseline
(+$0.337, +350k tokens, 7.5 → 19.5 turns) and still called `application_info` and
`backend_modules`. The correct number of tool calls on that task is zero.

This is not a tool-payload problem, it is a prompt problem: the guidelines open with
"Use the typo3-dev-mcp MCP tools instead of guessing" and an unconditional
`application_info` instruction, with no counter-signal for tasks that need no live state.

**Change:** make the guideline conditional — call `application_info` when you need
version, extension or platform facts, not unconditionally — and add an explicit "if the
task is pure code refactoring or reading files, these tools have nothing to add" line.

**Caveat:** with two arms this cannot be attributed. The regression may come from the
guidelines, from the tool descriptions, or from mere tool availability. **Adding arm C
(guidelines, no `.mcp.json`) would settle it**, and it is the single highest-value change
to the benchmark itself.

---

## Rough headroom

For a session that follows the guidelines and hits one error:

| item | now | after | saving |
|---|---|---|---|
| schema, first request | 6,620 | ~4,600 | ~2,000 |
| `application_info` | 2,473 | ~1,190 | ~1,285 |
| `last_error` | 6,645 | ~330 | ~6,300 |
| **total** | **15,738** | **~6,120** | **~9,600** |

Roughly a 60% cut in tokens before any real work happens — and the `last_error` and
`application_info` savings compound, because those payloads otherwise sit in context for
the rest of the session.

## How to verify a change

```bash
bin/tax.sh                     # schema cost, deterministic to the token
bin/tool-payloads.sh           # per-call payload sizes from the last sweep
bin/run.sh --tasks f4-rename-method --reps 3   # the negative control, most sensitive
```

`f4-rename-method` is the sharpest regression detector: any change that reduces induced
tool-calling shows up there first, because the correct behaviour is to call nothing.

## Confidence

n=2 per cell, 4 of 17 tasks, one project. The payload sizes are measurements and are
solid — `last_error` really does return 16 KB. The cost deltas are directional only;
within-cell spread reached 2.3×. Do not put any of the cost numbers in a README yet.

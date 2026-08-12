# Pilot report

4 tasks × 2 arms × 2 reps = 16 runs · `claude-opus-5`, effort medium · TYPO3 13.4.34,
23 tools · all 16 runs passed hygiene assertions.

**This is a pilot, not a result.** n=2 per cell, 4 of 17 tasks. Read it as "the harness
works and here is the shape of the signal", not as a measurement.

## Headline

| Family | Arm | Success | Med cost | Med tokens | Med turns |
|---|---|---|---|---|---|
| F1 live-state | a | 100% | $0.258 | 207k | 11.0 |
| F1 live-state | **b** | 100% | **$0.250** | **155k** | **5.5** |
| F2 code change | a | 100% | **$0.242** | **214k** | **13.0** |
| F2 code change | b | 100% | $0.556 | 475k | 19.5 |
| F3 debug | a | 100% | **$0.211** | **124k** | **9.5** |
| F3 debug | b | 100% | $0.370 | 240k | 10.5 |
| F4 control | a | 100% | **$0.132** | **115k** | **7.5** |
| F4 control | b | 100% | $0.469 | 465k | 19.5 |

Paired per task (arm b − arm a):

| Task | Family | Δ cost | Δ tokens |
|---|---|---|---|
| f1-noncore-columns | F1 | **−$0.008** | **−51,607** |
| f3-broken-viewhelper | F3 | +$0.160 | +116,097 |
| f2-add-tca-field | F2 | +$0.314 | +261,431 |
| f4-rename-method | F4 | +$0.337 | +350,022 |

## What this says

**1. Success rate does not discriminate — at all.** Every task, both arms, 100%, and
zero hallucinations on the judged family. A competent baseline with Read/Grep/Bash
solves these. Either the F1 tasks need to get much harder, or the honest value
proposition for this MCP is *efficiency*, not correctness. That matters for how the
README should be written.

**2. The one real win is turns, not dollars.** On the live-state task arm B halved the
turn count (11 → 5.5) and used 25% fewer tokens for the same answer — two `database_schema`
calls replaced a file hunt. Cost came out flat because the 6,620-token tax eats the
saving. On a longer session where the tax amortises across many turns, this is where the
value would compound.

**3. The negative control is the damning one.** `f4-rename-method` is a pure refactor —
the MCP has nothing to offer. Arm B cost **3.6× more** (+$0.337, +350k tokens, 7.5 → 19.5
turns) and still called `backend_modules` on it. That is the tax plus induced tool-calling
on a task where the right number of tool calls is zero.

**4. Write tasks get worse, not better.** F2 cost 2.3× more in arm B, with up to 8 tool
calls. The guidelines tell the agent to verify TCA and flush caches after changes; that
appears to induce verification loops. **A 2-arm design cannot tell whether the tools or
the guidelines cause this** — which is exactly why the third arm is now worth adding.

**5. 15 of 23 tools were never called** and still cost their schema on every request.
Not conclusive at 4 tasks — the full 17-task set exercises far more of the surface — but
`tax-baseline.md` shows the tax is flat across tools, so a long tail of unused tools is
pure overhead.

## Variance warning

Within-cell spread is large at n=2:

| Task | arm b rep 1 | arm b rep 2 |
|---|---|---|
| f2-add-tca-field | $0.772 (8 calls) | $0.340 (3 calls) |
| f4-rename-method | $0.524 (3 calls) | $0.413 (1 call) |

A 2.3× spread inside one cell means **no single number here is trustworthy**. 5 reps is
the minimum for the real sweep, and medians must be reported with spread.

## Recommended changes before the full sweep

1. **Add arm C (guidelines, no `.mcp.json`).** The F2/F4 cost blowups are plausibly
   prompt-induced. Without arm C the write-path regression cannot be attributed, and it
   is the most actionable finding here. One `case` branch in `switch-arm.sh`.
2. **Make F1 harder.** Add tasks whose answer is not recoverable from files at all —
   compiled TypoScript after set merging, resolved FlexForm for a specific record,
   effective Page TSconfig at a given page. The current F1 set is greppable.
3. **Keep F4 and expand it.** It produced the sharpest result. Three more no-MCP-value
   tasks would firm up the tax-in-practice number.

## Provenance

An earlier 16-run pilot was discarded: the fixture's site set TypoScript was inert
because `typo3 setup --create-site` leaves a `sys_template` with `clear=3` and static
includes that override the set. Both arms then "failed" `f3-broken-viewhelper` with 5–7
hallucinations each — they were correctly diagnosing a fault the oracle did not know
about. Kept at `results/invalid/runs-broken-fixture.jsonl`. `verify-arms.sh` now asserts
fixture liveness before any sweep.

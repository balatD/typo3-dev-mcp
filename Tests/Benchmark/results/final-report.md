# Final benchmark report

17 tasks × 2 arms × 5 reps = **170 runs**, `claude-opus-5` (effort medium), TYPO3 13.4.34,
23 tools. 167 usable — 3 excluded as API transport errors, 0 voided by hygiene assertions.
Total spend $34.30 (arm A $19.33, arm B $14.97).

Arm A = plain Claude Code with Read/Grep/Glob/Bash. Arm B = after `typo3 devmcp:install`
(MCP server + `.ai/guidelines/typo3.md`). Skills and user settings disabled in both.

## Headline

| Family | Arm | Runs | Success | Halluc | Med cost | Med tokens | Med turns |
|---|---|---|---|---|---|---|---|
| **F1** live-state | a | 40 | 100% | — | $0.208 | 170,365 | 10.0 |
| **F1** live-state | **b** | 40 | 100% | — | **$0.097** | **98,965** | **4.0** |
| F2 code change | a | 15 | 100% | — | $0.236 | 210,728 | 13.0 |
| F2 code change | b | 15 | 100% | — | $0.212 | 199,001 | 13.0 |
| F3 debug | a | 15 | 100% | **7%** | $0.164 | 111,135 | 8.0 |
| F3 debug | b | 14 | 100% | **0%** | $0.205 | 189,049 | 9.0 |
| F4 control | a | 14 | 100% | 0% | $0.107 | 101,550 | 5.5 |
| F4 control | b | 14 | 100% | 0% | $0.088 | 97,479 | 4.0 |

## Read this first: success rate is a null result

**Every task, both arms, 100%.** A competent baseline with Read, Grep and Bash solved all 17
tasks, including resolving FlexForm values, compiled TypoScript and Page TSconfig. The MCP
does not make previously-impossible tasks possible on a project of this shape.

Anything this benchmark supports is a claim about **efficiency and reliability**, not
capability. Do not write "more accurate" anywhere.

## Where it wins: live-state lookup

F1 is the family the tools were built for, and the effect is large and consistent:

- **53% cheaper** ($0.208 → $0.097)
- **42% fewer tokens** (170k → 99k)
- **60% fewer turns** (10 → 4)

The mechanism is visible in the per-task numbers — targeted queries replace file archaeology:

| Task | arm a | arm b | Δ tokens |
|---|---|---|---|
| `f1-template-paths` | $0.684 | **$0.053** | **−458,075** |
| `f1-page-tsconfig` | $0.241 | **$0.076** | −128,617 |
| `f1-noncore-columns` | $0.281 | **$0.177** | −10,615 |
| `f1-flexform-record` | $0.146 | **$0.084** | −11,550 |

`f1-template-paths` is the clearest case: compiled `lib.contentElement.templateRootPaths`
after site-set merging is not in any single file. The baseline reads its way across
fluid_styled_content and the fixture set to reconstruct it — 458k more tokens and 13× the
cost — while `typoscript` returns the merged result directly.

Three F1 tasks went the other way, `f1-trusted-hosts` worst at +$0.192. The gains are not
uniform; the median is.

## Where it wins quietly: hallucination under uncertainty

F3 seeds a real fault and asks for a diagnosis. Both arms diagnosed correctly every time,
but the judge flagged **1 of 15 arm-A answers** as containing a fabricated claim about the
installation, against **0 of 14** for arm B.

One instance is not a rate — treat it as directional. It is the only accuracy-flavoured
signal in the whole sweep, and it is the failure mode the tools are designed to prevent.

## Where it costs: debugging spend, and a long tail

F3 arm B is **25% more expensive** and uses 70% more tokens. Having log and TypoScript tools
available invites more investigation, which produced cleaner answers but not faster ones.

F2 and F4 are essentially parity. Notably F4 — the negative control, where the MCP has
nothing to offer — is now *slightly cheaper* in arm B ($0.107 → $0.088). Before the payload
optimisations it was **3.6× more expensive**; see `efficiency-findings.md`.

## Tool usage: 17 of 23 tools earned a call

| Tool | Calls | Runs | Tasks |
|---|---|---|---|
| `site_sets` | 23 | 14 | 3 |
| `typoscript` | 21 | 14 | 3 |
| `flush_cache` | 15 | 15 | 4 |
| `flexform_schema` | 13 | 12 | 3 |
| `tca_schema` | 10 | 9 | 3 |
| `database_query` | 10 | 10 | 2 |
| `list_events` | 10 | 5 | 1 |
| `application_info` | 8 | 8 | 2 |
| `content_elements` · `database_schema` · `site_info` · `get_config` | 6 each | | |
| `page_tsconfig` · `last_error` · `viewhelper_lookup` | 5 each | | |
| `list_commands` · `backend_modules` | 1 each | | |

**Never called:** `extension_info`, `get_url`, `middleware_stack`, `read_log_entries`,
`search_changelog`, `search_docs`.

Two caveats on that list, both important:

1. `search_docs` and `extension_info` are **network tools, and the sweep ran with
   `DEV_MCP_NO_NETWORK=1`** to remove network variance. Their absence is a harness artifact,
   not evidence.
2. `read_log_entries` was unused because `last_error` covered every debugging task. That is a
   task-set gap, not proof the tool is dead weight.

So the honest unused set is `get_url` and `middleware_stack` — plus four tools this task set
never had a reason to exercise.

## Cost model

Fixed context tax: **6,807 tokens per request** (`tax-baseline.md`), flat across tools — the
largest is 6.7%, the top five 31%. Dropping tools will not move it; only trimming descriptions
across the board or merging tool surfaces will.

That tax is why F1's token saving (−42%) exceeds its cost saving (−53% is cost, but tokens
only fall 42%): the tax is mostly cache reads after the first request, so it dilutes rather
than dominates on multi-turn work.

## Limitations

- **One project, and a deliberately modest one.** A vanilla TYPO3 13 install plus a fixture
  extension. Real projects with large sitepackages and heavy TCA overrides have more hidden
  live state, so this is a floor, not an average.
- **Two arms cannot separate tools from guidelines.** `devmcp:install` ships both. Every
  number here is "what installing this gets you", never "what the tools do". A third arm
  (guidelines, no `.mcp.json`) would settle it and is one `case` branch in `switch-arm.sh`.
- **n=5 per cell.** Medians are reported for that reason; per-task spread reached 2.3× in
  earlier runs. Family-level numbers aggregate 14–40 runs and are firmer than per-task ones.
- **The harness needed four corrections during this work** — two grading false negatives, one
  silent judge truncation, one inert fixture. Each was caught because a whole cell failed at
  once, which is nearly always a broken task rather than a broken model. Findings that
  survived are the ones where failures were partial and arm-asymmetric.

## Reproducing

```bash
bin/setup-bench.sh && bin/verify-arms.sh && bin/oracle.sh
bin/tax.sh
bin/run.sh --reps 5
bin/judge.sh
ddev exec -d /var/www/dev_mcp/Tests/Benchmark php bin/report.php
```

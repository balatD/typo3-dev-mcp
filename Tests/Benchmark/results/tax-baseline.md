# Fixed context tax — baseline

Measured against `typo3-dev-mcp` at `0.1.0-alpha.5`, v13 harness (23 tools; `content_blocks`
is not registered there because `friendsoftypo3/content-blocks` is absent).

Reproduce with `Tests/Benchmark/bin/tax.sh` and `Tests/Benchmark/bin/schema-cost.sh 6620`.

## Headline

| | tokens |
|---|---|
| baseline prompt, no MCP | 9,250 |
| with typo3-dev-mcp attached | 15,870 |
| **fixed tax** | **6,620 (+72%)** |

Identical across 3 reps — zero variance. This is paid on **every request in every session**,
whether or not a tool is ever called. Measured with `--tools ""` so the built-in tool set is
excluded and the delta is attributable to the MCP server alone.

Total prompt size is `input + cache_creation + cache_read`, not `cache_creation` alone —
the latter collapses to 0 on a warm cache and would report a tax of zero.

## Per-tool attribution

23 tools serialize to 16,038 chars of JSON, calibrating to 2.42 chars/token. That ratio is
lower than prose (~4) because JSON schemas tokenize densely — every `"`, `{`, `:` is its own
token. It is not evidence of hidden framing overhead.

| tool | tokens | share |
|---|---|---|
| flexform_schema | 445 | 6.7% |
| search_docs | 432 | 6.5% |
| search_changelog | 402 | 6.1% |
| site_sets | 383 | 5.8% |
| read_log_entries | 376 | 5.7% |
| typoscript | 355 | 5.4% |
| list_events | 353 | 5.3% |
| viewhelper_lookup | 349 | 5.3% |
| page_tsconfig | 323 | 4.9% |
| get_config | 307 | 4.6% |
| middleware_stack | 299 | 4.5% |
| tca_schema | 296 | 4.5% |
| backend_modules | 291 | 4.4% |
| database_schema | 274 | 4.1% |
| extension_info | 266 | 4.0% |
| database_query | 260 | 3.9% |
| get_url | 235 | 3.5% |
| flush_cache | 196 | 3.0% |
| content_elements | 172 | 2.6% |
| application_info | 169 | 2.6% |
| site_info | 160 | 2.4% |
| last_error | 148 | 2.2% |
| list_commands | 130 | 2.0% |

## Reading

**There is no 80/20 cut here.** The distribution is flat — the largest tool is 6.7% and the
top five together are only 31%. Dropping a few tools barely moves the number. Reducing the tax
meaningfully requires either trimming `getDescription()` / `getInputSchema()` text across the
board, or reducing tool *count* substantially (e.g. merging the four search/lookup tools, or
the three log tools, behind one dispatching tool).

Whether 6,620 tokens is *worth paying* is the question the task sweep answers. This file is
only the denominator. Pair it with the F4 negative-control results: if arm B costs more on
tasks where the tools are irrelevant, this tax is what's showing up.

## Update — alpha.7, measured 2026-08-14

| | tokens |
|---|---|
| baseline prompt, no MCP | 9,989 |
| with typo3-dev-mcp attached | 16,259 |
| **fixed tax** | **6,270** |

22 tools (`get_url` deleted), all descriptions trimmed, cross-tool policy moved into the
server `instructions` string. Again zero variance across 3 reps. Reproduce with
`bin/schema-cost.sh 6270`.

The baseline prompt itself grew from 9,250 to 9,989 tokens between the two measurements —
the Claude Code system prompt is not a fixed quantity, so only the *delta* is comparable
across dates, never the `on` figure.

**6,807 → 6,270 is −537, not the ~2,000 predicted above.** The "30% cut" estimate treated
the whole serialized schema as prose; in reality descriptions are roughly a third of it,
and property names, types, enums and JSON structure are not compressible. Trimming every
description as far as it goes without losing argument semantics bought ~300 tokens, and
deleting a tool ~235. The flat-distribution conclusion is unchanged and now better
supported: meaningful reduction needs fewer *tools*, not shorter prose.

## Update — 1.0.0, measured 2026-09-24

Measured on the v13 bench with `balatd/typo3-dev-mcp:1.0.0` installed from Packagist (mcp/sdk
0.8.1), 22 tools, 3 reps each, zero variance:

| model | no MCP | with typo3-dev-mcp | **fixed tax** |
|---|---|---|---|
| `claude-sonnet-5` | 10,432 | 16,701 | **6,269** |
| `claude-opus-5-5` | 4,010 | 10,288 | **6,278** |

**Unchanged since alpha.7** (6,270): alpha.8 and 1.0.0 touched the guidelines and the SDK
bridge, not a single tool schema. The two models disagree by 9 tokens on the tax but by 6,400
on the baseline — the baseline is the model's own system prompt, so only the delta travels
across models, just as it only travels across dates.

The v14 bench serializes to the identical 14,727 chars, so the tax is the same there; no
tool's schema depends on the TYPO3 version.

Per tool, calibrated at 2.35 chars/token (`bin/schema-cost.sh 6269`):

| tool | tokens | share |
|---|---|---|
| read_log_entries | 433 | 6.9% |
| flexform_schema | 430 | 6.9% |
| search_docs | 412 | 6.6% |
| site_sets | 381 | 6.1% |
| search_changelog | 369 | 5.9% |
| typoscript | 350 | 5.6% |
| get_config | 336 | 5.4% |
| list_events | 322 | 5.1% |
| viewhelper_lookup | 321 | 5.1% |
| page_tsconfig | 298 | 4.8% |
| database_query | 273 | 4.4% |
| middleware_stack | 272 | 4.3% |
| tca_schema | 258 | 4.1% |
| backend_modules | 255 | 4.1% |
| list_commands | 242 | 3.9% |
| database_schema | 239 | 3.8% |
| last_error | 221 | 3.5% |
| extension_info | 221 | 3.5% |
| flush_cache | 211 | 3.4% |
| application_info | 183 | 2.9% |
| content_elements | 127 | 2.0% |
| site_info | 115 | 1.8% |

Still flat: the largest tool is 6.9%, the top five 32%.

The guidelines are a separate, smaller cost that this probe excludes by design
(`--setting-sources ""`): the 2,304-character `.ai/guidelines/typo3.md` that
`devmcp:install` links from `CLAUDE.md`, down from 9,806 before alpha.8.

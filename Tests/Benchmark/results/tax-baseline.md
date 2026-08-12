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

#!/usr/bin/env bash
#
# Per-tool payload cost: what each tool actually returns, joined to its schema cost.
#
# The schema cost (tax.sh) is what a tool costs when it is NOT used. This is what
# it costs when it IS. Together they give the full picture for the 1.0 cut list:
# a tool with a big schema that never fires is dead weight; a tool that fires
# often and returns a wall of text is a pagination candidate.
#
# Joins tool_use (id, name) to its tool_result (tool_use_id, content) across every
# stream in results/runs.
#
# Usage: tool-payloads.sh [chars-per-token]

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_env.sh"
RUNS_DIR="$RESULTS_DIR/runs"
CPT="${1:-2.42}"   # calibrated in results/tax-baseline.md

[[ -d "$RUNS_DIR" ]] || { echo "error: no runs in $RUNS_DIR" >&2; exit 1; }

# name<TAB>bytes, one line per completed MCP tool call.
jq -r -s '
    (map(select(.type == "assistant")
        | .message.content[]? | select(.type == "tool_use")
        | {key: .id, value: .name}) | from_entries) as $names
    | map(select(.type == "user")
        | .message.content[]? | select(.type == "tool_result")
        | select($names[.tool_use_id] != null)
        | [$names[.tool_use_id],
           (.content | if type == "array" then (map(.text // "") | join("")) else (. // "") end | length)]
        | @tsv)
    | .[]
' "$RUNS_DIR"/*.jsonl 2>/dev/null \
    | grep '^mcp__typo3-dev-mcp__' \
    | sed 's/^mcp__typo3-dev-mcp__//' \
    > /tmp/tool-payloads.tsv

if [[ ! -s /tmp/tool-payloads.tsv ]]; then
    echo "no MCP tool results found in the streams" >&2
    exit 1
fi

echo "Per-call payload size — ${CPT} chars/token"
echo
printf '%-24s %6s %10s %10s %10s %12s\n' TOOL CALLS 'MED CHARS' 'MAX CHARS' 'MED TOK' 'TOTAL TOK'
echo "--------------------------------------------------------------------------------------"

awk -F'\t' -v cpt="$CPT" '
{
    n[$1]++
    vals[$1] = vals[$1] " " $2
    total[$1] += $2
    if ($2 > max[$1]) max[$1] = $2
}
END {
    for (t in n) {
        c = split(vals[t], a, " ")
        # a[1] is the empty field before the first space
        m = 0
        delete s
        k = 0
        for (i = 1; i <= c; i++) if (a[i] != "") s[++k] = a[i] + 0
        for (i = 1; i < k; i++)
            for (j = i + 1; j <= k; j++)
                if (s[j] < s[i]) { tmp = s[i]; s[i] = s[j]; s[j] = tmp }
        med = (k % 2) ? s[int(k/2) + 1] : (s[k/2] + s[k/2 + 1]) / 2
        printf "%-24s %6d %10d %10d %10d %12d\n", t, n[t], med, max[t], med/cpt, total[t]/cpt
    }
}' /tmp/tool-payloads.tsv | sort -k6 -rn

echo
echo "Totals"
awk -F'\t' -v cpt="$CPT" '{s += $2; n++} END {
    printf "  %d calls, %d chars returned, ~%d tokens of tool output\n", n, s, s/cpt
}' /tmp/tool-payloads.tsv

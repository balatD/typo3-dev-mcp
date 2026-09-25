#!/usr/bin/env bash
#
# Per-tool breakdown of the fixed context tax measured by tax.sh.
#
# Talks raw JSON-RPC to devmcp:serve over stdio (no MCP client, no Claude
# session), then attributes the measured total tax to each tool in proportion
# to its serialized schema size. The chars-per-token ratio is calibrated from
# the real total rather than assumed, so the per-tool numbers add up to what
# tax.sh actually observed.
#
# Usage: schema-cost.sh [measured_total_tax_tokens]
#        schema-cost.sh 6620

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_env.sh"
load_bench_env
MEASURED_TAX="${1:-0}"
SERVE_CMD=(ddev -p "$PROJECT_NAME" exec vendor/bin/typo3 devmcp:serve)

raw="$(printf '%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"bench","version":"1"}}}' \
    '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
    '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
    | "${SERVE_CMD[@]}" 2>/dev/null)"

tools="$(echo "$raw" | jq -c 'select(.id == 2) | .result.tools' | head -1)"

if [[ -z "$tools" || "$tools" == "null" ]]; then
    echo "error: no tools/list response. Is the DDEV project running?" >&2
    exit 1
fi

# Serialized size of each tool entry, as the client would receive it.
sizes="$(echo "$tools" | jq -r '.[] | "\(.name)\t\(@json "\(.)" | length)"')"
total_chars="$(echo "$sizes" | awk -F'\t' '{s += $2} END {print s}')"
count="$(echo "$sizes" | wc -l | tr -d ' ')"

echo "Per-tool schema cost"
echo "tools: $count   serialized: $total_chars chars"
if (( MEASURED_TAX > 0 )); then
    printf 'calibrated against measured tax of %s tokens (%.2f chars/token)\n' \
        "$MEASURED_TAX" "$(echo "$total_chars $MEASURED_TAX" | awk '{print $1/$2}')"
fi
echo

printf '%-24s %8s %8s %6s\n' TOOL CHARS TOKENS 'SHARE'
echo "$sizes" | sort -t$'\t' -k2 -rn | awk -F'\t' -v total="$total_chars" -v tax="$MEASURED_TAX" '
{
    tokens = (tax > 0) ? int($2 * tax / total + 0.5) : 0
    printf "%-24s %8d %8d %5.1f%%\n", $1, $2, tokens, 100 * $2 / total
}'

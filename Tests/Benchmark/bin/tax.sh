#!/usr/bin/env bash
#
# Measurement 1: the fixed context tax of the MCP tool schemas.
#
# Sends the same trivial prompt twice — once with no MCP servers, once with
# typo3-dev-mcp attached — and diffs the total prompt size. The delta is what
# the tool schemas cost on EVERY request in EVERY session, used or not.
#
# Total prompt size is input + cache_creation + cache_read rather than
# cache_creation alone: cache_creation collapses to 0 on a warm cache, which
# would silently report a tax of zero on the second run.
#
# Usage: tax.sh [path/to/mcp.json] [reps]

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$BENCH_DIR/../.." && pwd)"

MCP_CONFIG="${1:-$REPO_ROOT/.mcp.json}"
REPS="${2:-3}"
MODEL="${BENCH_MODEL:-claude-sonnet-5}"
PROMPT='Reply with exactly: ok'

if [[ ! -f "$MCP_CONFIG" ]]; then
    echo "error: no MCP config at $MCP_CONFIG" >&2
    echo "hint: run 'vendor/bin/typo3 devmcp:install' first, or pass a path." >&2
    exit 1
fi

# $1 = label, $2... = extra claude flags. Echoes "total input_tokens tool_count".
probe() {
    local label="$1"; shift
    local json
    json="$(claude -p "$PROMPT" \
        --tools "" \
        --output-format json \
        --model "$MODEL" \
        --setting-sources "" \
        --disable-slash-commands \
        --no-session-persistence \
        --strict-mcp-config \
        "$@" 2>/dev/null)"

    echo "$json" | jq -r '
        .usage as $u
        | ($u.input_tokens + $u.cache_creation_input_tokens + $u.cache_read_input_tokens)
        | tostring'
}

echo "Fixed context tax — typo3-dev-mcp"
echo "model:  $MODEL"
echo "config: $MCP_CONFIG"
echo "reps:   $REPS"
echo

off_total=0
on_total=0

for ((i = 1; i <= REPS; i++)); do
    off="$(probe off)"
    on="$(probe on --mcp-config "$MCP_CONFIG")"
    printf 'rep %d  off=%8s  on=%8s  delta=%8s\n' "$i" "$off" "$on" "$((on - off))"
    off_total=$((off_total + off))
    on_total=$((on_total + on))
done

off_mean=$((off_total / REPS))
on_mean=$((on_total / REPS))
delta=$((on_mean - off_mean))

echo
printf 'mean without MCP : %s tokens\n' "$off_mean"
printf 'mean with MCP    : %s tokens\n' "$on_mean"
printf 'TAX              : %s tokens per request\n' "$delta"

if (( delta < 500 )); then
    echo
    echo "warning: delta is suspiciously small. Verify the server actually attached:"
    echo "  claude -p 'list your mcp tools' --strict-mcp-config --mcp-config $MCP_CONFIG"
fi

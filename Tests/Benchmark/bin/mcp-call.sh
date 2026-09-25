#!/usr/bin/env bash
#
# Calls one MCP tool on the bench project over raw stdio JSON-RPC.
#
# Debugging and harness verification only. NEVER use this to produce oracle
# values — grading MCP answers against MCP output is circular, and the whole
# benchmark depends on ground truth coming from an independent path
# (`vendor/bin/typo3`, direct SQL, or reading config files).
#
# Usage:
#   mcp-call.sh tools/list
#   mcp-call.sh tca_schema '{"table":"tt_content"}'

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_env.sh"
load_bench_env

TOOL="${1:?usage: mcp-call.sh <tool|tools/list> [json-args]}"
ARGS="${2:-{\}}"

if [[ "$TOOL" == "tools/list" ]]; then
    REQUEST='{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
else
    REQUEST="$(jq -nc --arg name "$TOOL" --argjson args "$ARGS" \
        '{jsonrpc:"2.0", id:2, method:"tools/call", params:{name:$name, arguments:$args}}')"
fi

printf '%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"bench","version":"1"}}}' \
    '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
    "$REQUEST" \
    | ddev -p "$PROJECT_NAME" exec vendor/bin/typo3 devmcp:serve 2>/dev/null \
    | jq -c 'select(.id == 2) | .result'

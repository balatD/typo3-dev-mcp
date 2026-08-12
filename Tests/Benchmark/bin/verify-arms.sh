#!/usr/bin/env bash
#
# Pre-flight: proves the two arms differ in exactly the intended way.
#
# Run before any sweep. Every failure here silently invalidates the whole
# benchmark rather than crashing it, so this is cheap insurance:
#
#   - arm A must have no guidelines and no MCP tools
#   - arm B must have both
#   - arm A must still be able to Read/Grep the project (a blindfolded control
#     arm makes the MCP look better than it is)
#
# The guidelines check exists because `--setting-sources ""` suppresses CLAUDE.md
# discovery. That flag looks like pure hygiene and quietly strips half of arm B's
# treatment.
#
# Usage: verify-arms.sh

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$BENCH_DIR/.bench-env"

MODEL="${BENCH_VERIFY_MODEL:-claude-sonnet-5}"
fail=0

ask() {
    local arm="$1" prompt="$2" tools="$3"
    local mcp=()
    [[ "$arm" == "b" ]] && mcp=(--mcp-config "$BENCH_ROOT/.mcp.json")

    ( cd "$BENCH_ROOT" && claude -p "$prompt" \
        --tools "$tools" \
        --output-format json \
        --model "$MODEL" \
        --setting-sources project \
        --disable-slash-commands \
        --permission-mode bypassPermissions \
        --no-session-persistence \
        --strict-mcp-config ${mcp[@]+"${mcp[@]}"} 2>/dev/null ) | jq -r '.result // ""'
}

check() {
    local label="$1" expected="$2" actual="$3"
    if [[ "$actual" == *"$expected"* ]]; then
        printf '  \033[32m✓\033[0m %s\n' "$label"
    else
        printf '  \033[31m✗\033[0m %s — expected %s, got: %s\n' "$label" "$expected" "${actual:0:80}"
        fail=1
    fi
}

# The fixture can be installed and still be inert: a legacy sys_template with
# clear=3 or static includes silently discards the site set's TypoScript, so the
# CType exists but none of its rendering does. A whole pilot was spent diagnosing
# that instead of the fault the task seeded, so it is checked first now.
echo "Fixture liveness"
fe="$(curl -sk "$SITE_URL/products" 2>/dev/null)"
check "frontend renders (HTTP 200)" "200" \
    "$(curl -sk -o /dev/null -w '%{http_code}' "$SITE_URL/products" 2>/dev/null)"
check "fixture template is live" "bench-card" "$fe"
check "fixture field renders" "A teaser on the card" "$fe"
check "set TypoScript reaches compiled setup" "bench_fixture" \
    "$("$BENCH_DIR/bin/mcp-call.sh" typoscript '{"pageId":2,"path":"lib.contentElement.templateRootPaths"}' 2>/dev/null | jq -r '.content[0].text' 2>/dev/null)"

echo
echo "Arm A (baseline)"
"$BENCH_DIR/bin/switch-arm.sh" a >/dev/null
check "no guidelines in context" "NO" \
    "$(ask a 'Reply YES or NO only: are TYPO3-specific guidelines present in your context?' '')"
check "no MCP tools available" "NO" \
    "$(ask a 'Reply YES or NO only: do you have any tool whose name starts with mcp__?' '')"
check "can read project files" "bench-fixture" \
    "$(ask a 'Read packages/bench_fixture/composer.json and reply with the value of its "name" field only.' 'Read,Glob,Grep')"

echo
echo "Arm B (after devmcp:install)"
"$BENCH_DIR/bin/switch-arm.sh" b >/dev/null
check "guidelines in context" "YES" \
    "$(ask b 'Reply YES or NO only: are TYPO3-specific guidelines present in your context?' '')"
check "MCP tools available" "YES" \
    "$(ask b 'Reply YES or NO only: do you have any tool whose name starts with mcp__?' '')"
check "MCP server answers" "13.4" \
    "$(ask b 'Use the application_info tool and reply with the TYPO3 version number only.' '')"

echo
if [[ $fail -eq 0 ]]; then
    echo "arms verified — safe to sweep"
else
    echo "ARM VERIFICATION FAILED — do not trust sweep results until fixed" >&2
fi
exit $fail

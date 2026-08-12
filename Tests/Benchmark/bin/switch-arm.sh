#!/usr/bin/env bash
#
# Puts the bench project into arm A or arm B state.
#
#   a  baseline — no .mcp.json, no .ai/guidelines, no CLAUDE.md/AGENTS.md
#   b  full     — whatever `typo3 devmcp:install` actually produces
#
# Arm B runs the real install command rather than copying a snapshot of its
# output, so the benchmark measures what ships. Both arms are applied to one
# install; runs are serialized anyway because state reset requires it.
#
# Usage: switch-arm.sh a|b

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARM="${1:-}"

if [[ ! -f "$BENCH_DIR/.bench-env" ]]; then
    echo "error: bench project not set up. Run bin/setup-bench.sh first." >&2
    exit 1
fi
# shellcheck disable=SC1091
source "$BENCH_DIR/.bench-env"

cd "$BENCH_ROOT"

strip_arm_state() {
    rm -f .mcp.json CLAUDE.md AGENTS.md
    rm -rf .ai
}

# devmcp:install writes inside the container; the host bind mount can lag by a
# few hundred milliseconds. Without this wait the verification below fails on a
# perfectly successful install.
wait_for_artefacts() {
    local i
    for ((i = 0; i < 50; i++)); do
        if [[ -f .mcp.json && -f .ai/guidelines/typo3.md && -f CLAUDE.md ]]; then
            return 0
        fi
        sleep 0.1
    done
    return 1
}

case "$ARM" in
    a)
        strip_arm_state
        ;;
    b)
        strip_arm_state
        ddev -p "$PROJECT_NAME" exec vendor/bin/typo3 devmcp:install </dev/null >/dev/null
        wait_for_artefacts || true
        ;;
    *)
        echo "usage: switch-arm.sh a|b" >&2
        exit 1
        ;;
esac

# Verify the arm is actually in the state we think it is. A silently failed
# install would make arm B identical to arm A and produce a null result that
# looks like a real finding.
present=0
[[ -f .mcp.json ]] && present=$((present + 1))
[[ -f .ai/guidelines/typo3.md ]] && present=$((present + 1))
[[ -f CLAUDE.md ]] && present=$((present + 1))

if [[ "$ARM" == "a" && $present -ne 0 ]]; then
    echo "error: arm A still has $present install artefact(s)" >&2
    exit 1
fi
if [[ "$ARM" == "b" && $present -ne 3 ]]; then
    echo "error: arm B has only $present/3 install artefacts — devmcp:install failed" >&2
    exit 1
fi

echo "arm $ARM ready ($present/3 install artefacts)"

#!/usr/bin/env bash
#
# Regenerates ground truth for every task.
#
# Oracles run against the clean baseline with the MCP server NOT involved —
# `vendor/bin/typo3`, direct SQL, and reading config files only. Grading MCP
# answers against MCP output would be circular and the benchmark would measure
# nothing.
#
# Usage: oracle.sh [task ...]

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TASKS_DIR="$BENCH_DIR/tasks"
OUT_DIR="$BENCH_DIR/oracle"

# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_task.sh"
# shellcheck disable=SC1091
source "$BENCH_DIR/.bench-env"

mkdir -p "$OUT_DIR"

if [[ $# -gt 0 ]]; then
    TASKS=("$@")
else
    TASKS=($(list_tasks))
fi

# Oracles describe the clean baseline. F3 tasks seed breakage at run time, so
# their oracle text is the written-down diagnosis rather than a live probe.
(
    cd "$BENCH_ROOT"
    git checkout -q -- . 2>/dev/null || true
    git clean -qfd 2>/dev/null || true
)

fail=0
for task in "${TASKS[@]}"; do
    file="$TASKS_DIR/${task}.task"
    [[ -f "$file" ]] || { echo "no such task: $task" >&2; fail=1; continue; }

    # Judged on output, not exit status: an oracle commonly ends in a grep that
    # legitimately matches nothing, and that must not count as a failure.
    ( cd "$BENCH_ROOT" && bash -c "$(task_section "$file" oracle)" ) > "$OUT_DIR/${task}.txt" 2>/dev/null || true

    if [[ -s "$OUT_DIR/${task}.txt" ]]; then
        printf '  \033[32m✓\033[0m %-28s %s bytes\n' "$task" "$(wc -c < "$OUT_DIR/${task}.txt" | tr -d ' ')"
    else
        printf '  \033[31m✗\033[0m %-28s oracle produced nothing\n' "$task"
        fail=1
    fi
done

exit $fail

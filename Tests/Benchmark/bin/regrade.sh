#!/usr/bin/env bash
#
# Re-applies deterministic checkers to saved responses, in place, without
# re-running the agents.
#
# Checker bugs are the sneakiest failure mode in this harness: a case-sensitive
# grep scored a correct answer as wrong purely because one arm phrased it as
# "Root page ID" and the other as "rootPageId". Fixing the checker and re-running
# 10 Opus sessions costs money for a grading fix that changes no agent behaviour.
#
# SAFETY: only tasks whose check reads the response text are re-gradable. F2-style
# checks interrogate the live installation ("does the DB column exist"), which is
# only meaningful immediately after that run — state has since been reset, so
# re-running them offline would produce confident nonsense. Those are skipped and
# reported.
#
# Usage: regrade.sh [task ...]

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_env.sh"
# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_task.sh"
load_bench_env

LEDGER="$RESULTS_DIR/runs.jsonl"
RUNS_DIR="$RESULTS_DIR/runs"

TASKS_DIR="$BENCH_DIR/tasks"
[[ -f "$LEDGER" ]] || { echo "error: no ledger" >&2; exit 1; }

only=""
[[ $# -gt 0 ]] && only="$*"

# A check is response-only if it never reaches for the live install.
is_regradable() {
    local body
    body="$(task_section "$TASKS_DIR/$1.task" check)"
    grep -q 'RESPONSE_FILE' <<< "$body" || return 1
    grep -qE 'ddev |mysql |packages/' <<< "$body" && return 1
    return 0
}

tmp="$LEDGER.regrade"
: > "$tmp"
changed=0
skipped=""

while IFS= read -r row; do
    task="$(echo "$row" | jq -r '.task')"
    arm="$(echo "$row" | jq -r '.arm')"
    rep="$(echo "$row" | jq -r '.rep')"
    grade="$(echo "$row" | jq -r '.grade')"

    if [[ "$grade" != "deterministic" ]] \
        || { [[ -n "$only" ]] && [[ " $only " != *" $task "* ]]; }; then
        echo "$row" >> "$tmp"
        continue
    fi

    if ! is_regradable "$task"; then
        [[ " $skipped " == *" $task "* ]] || skipped="$skipped $task"
        echo "$row" >> "$tmp"
        continue
    fi

    response_file="$RUNS_DIR/${task}__arm-${arm}__rep-${rep}.response.txt"
    if [[ ! -f "$response_file" ]]; then
        echo "$row" >> "$tmp"
        continue
    fi

    rc=0
    ( cd "$BENCH_ROOT" \
        && RESPONSE_FILE="$response_file" \
           ORACLE_FILE="$ORACLE_DIR/${task}.txt" \
           bash -c "$(task_section "$TASKS_DIR/$task.task" check)" ) >/dev/null 2>&1 || rc=$?

    new=$([[ $rc -eq 0 ]] && echo true || echo false)
    old="$(echo "$row" | jq -r '.checked|tostring')"

    if [[ "$new" != "$old" ]]; then
        printf '  %-26s arm-%s rep-%s  %s -> %s\n' "$task" "$arm" "$rep" "$old" "$new"
        changed=$((changed + 1))
    fi

    echo "$row" | jq -c --argjson checked "$new" '.checked = $checked' >> "$tmp"
done < "$LEDGER"

mv "$tmp" "$LEDGER"

echo
echo "regraded: $changed verdict(s) changed"
[[ -n "$skipped" ]] && echo "skipped (live-state checks, must be re-run):$skipped"
exit 0

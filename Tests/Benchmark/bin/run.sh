#!/usr/bin/env bash
#
# Sweep driver: runs every (task x arm x rep) combination and records metrics.
#
# Runs are serialized on purpose. State reset restores a DB snapshot and hard-
# resets the working tree, so two concurrent runs against one DDEV project would
# corrupt each other.
#
# Usage:
#   run.sh                                       full sweep
#   run.sh --tasks f1-trusted-hosts --reps 2
#   run.sh --dry-run                             print the plan, execute nothing

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TASKS_DIR="$BENCH_DIR/tasks"

# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_env.sh"
# shellcheck disable=SC1091
source "$BENCH_DIR/bin/_task.sh"

RUNS_DIR="$RESULTS_DIR/runs"
LEDGER="$RESULTS_DIR/runs.jsonl"

# Stamped once per sweep and written to every row. Without it a re-run of the
# same (task, arm, rep) after a code change collides with the previous ledger
# entry, and judge.sh silently reuses the old verdict for the new answer.
SWEEP_ID="$(date -u +%Y%m%dT%H%M%SZ)"

MODEL="${BENCH_MODEL:-claude-opus-5}"
EFFORT="${BENCH_EFFORT:-medium}"
MAX_USD="${BENCH_MAX_USD:-3}"
REPS=5
ARMS="a,b"
TASK_FILTER=""
DRY_RUN=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --tasks)   TASK_FILTER="$2"; shift 2 ;;
        --arms)    ARMS="$2"; shift 2 ;;
        --reps)    REPS="$2"; shift 2 ;;
        --model)   MODEL="$2"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        *) echo "unknown option: $1" >&2; exit 1 ;;
    esac
done

load_bench_env

mkdir -p "$RUNS_DIR" "$ORACLE_DIR"
say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [[ -n "$TASK_FILTER" ]]; then
    IFS=',' read -r -a TASKS <<< "$TASK_FILTER"
else
    TASKS=($(list_tasks))
fi
IFS=',' read -r -a ARM_LIST <<< "$ARMS"

say "tasks: ${#TASKS[@]}  arms: ${#ARM_LIST[@]}  reps: $REPS  = $(( ${#TASKS[@]} * ${#ARM_LIST[@]} * REPS )) runs"
say "model: $MODEL  effort: $EFFORT  budget/run: \$$MAX_USD  TYPO3: ${TYPO3_VERSION:-$BENCH_TYPO3}"

if [[ $DRY_RUN -eq 1 ]]; then
    printf '  %s\n' "${TASKS[@]}"
    exit 0
fi

# --- state reset ------------------------------------------------------------

# Run every ddev command from inside the project: `-p` is only accepted by some
# subcommands (`exec` has it, `snapshot restore` does not — it silently prints
# help and exits 0, which reads as a successful reset that never happened).
# </dev/null matters too: `snapshot restore` reads stdin and otherwise blocks.
bench_ddev() {
    ( cd "$BENCH_ROOT" && ddev "$@" </dev/null )
}

reset_state() {
    (
        cd "$BENCH_ROOT"
        git checkout -q -- . 2>/dev/null || true
        git clean -qfd 2>/dev/null || true
    )
    if ! bench_ddev snapshot restore bench-clean 2>&1 | grep -q 'was restored'; then
        echo "  !! snapshot restore failed — state is dirty, aborting" >&2
        exit 1
    fi
    bench_ddev exec vendor/bin/typo3 cache:flush >/dev/null 2>&1 || true
}

# --- one run ----------------------------------------------------------------

run_one() {
    local task="$1" arm="$2" rep="$3"
    local file="$TASKS_DIR/${task}.task"
    local stream="$RUNS_DIR/${task}__arm-${arm}__rep-${rep}.jsonl"

    reset_state
    "$BENCH_DIR/bin/switch-arm.sh" "$arm" >/dev/null

    # Seeded breakage, applied after reset so both arms face the same fault.
    if task_has_section "$file" setup; then
        ( cd "$BENCH_ROOT" && bash -c "$(task_section "$file" setup)" ) >/dev/null 2>&1 || true
    fi

    # --setting-sources must be "project", not "": the empty value also suppresses
    # CLAUDE.md discovery, which would silently strip the .ai/guidelines from arm B
    # and turn the whole benchmark into a tools-only comparison while still being
    # reported as "what devmcp:install gets you". "project" keeps the guidelines and
    # still excludes user and local settings — the bench project has no .claude/ of
    # its own, so nothing else leaks in.
    local mcp_flags=()
    [[ "$arm" == "b" ]] && mcp_flags=(--mcp-config "$BENCH_ROOT/.mcp.json")

    # bash 3.2 (macOS) treats "${arr[@]}" on an empty array as unbound under
    # `set -u`; the ${arr[@]+...} guard keeps arm A from dying here.
    ( cd "$BENCH_ROOT" && DEV_MCP_NO_NETWORK=1 claude -p "$(task_section "$file" prompt)" \
        --output-format stream-json --verbose \
        --model "$MODEL" \
        --effort "$EFFORT" \
        --strict-mcp-config ${mcp_flags[@]+"${mcp_flags[@]}"} \
        --setting-sources project \
        --disable-slash-commands \
        --permission-mode bypassPermissions \
        --no-session-persistence \
        --max-budget-usd "$MAX_USD" \
        > "$stream" 2>/dev/null ) || true

    record "$task" "$arm" "$rep" "$stream" "$file"
}

# --- metrics + hygiene ------------------------------------------------------

record() {
    local task="$1" arm="$2" rep="$3" stream="$4" file="$5"

    if [[ ! -s "$stream" ]]; then
        echo "  !! $task/arm-$arm/rep-$rep produced no output" >&2
        return
    fi

    local result response_file
    result="$(jq -c 'select(.type == "result")' "$stream" | tail -1)"
    [[ -z "$result" ]] && { echo "  !! $task/arm-$arm/rep-$rep has no result event" >&2; return; }

    response_file="$RUNS_DIR/${task}__arm-${arm}__rep-${rep}.response.txt"
    echo "$result" | jq -r '.result // ""' > "$response_file"

    local tools
    tools="$(jq -r 'select(.type == "assistant")
        | .message.content[]? | select(.type == "tool_use") | .name' "$stream" \
        | sort | uniq -c | awk '{printf "{\"name\":\"%s\",\"calls\":%d}\n", $2, $1}' \
        | jq -sc '.')"

    # Bytes each tool returned — the tuning signal. A tool that is rarely called
    # but returns a wall of text is a pagination candidate.
    local tool_bytes
    tool_bytes="$(jq -r 'select(.type == "user")
        | .message.content[]? | select(.type == "tool_result")
        | (.content | if type == "array" then (map(.text // "") | join("")) else (. // "") end)
        | length' "$stream" 2>/dev/null | awk '{s += $1} END {print s + 0}')"

    local mcp_calls skill_calls
    mcp_calls="$(echo "$tools" | jq '[.[] | select(.name | startswith("mcp__typo3-dev-mcp__")) | .calls] | add // 0')"
    skill_calls="$(echo "$tools" | jq '[.[] | select(.name == "Skill") | .calls] | add // 0')"

    # A violated assertion voids the run rather than silently skewing the aggregate.
    local hygiene="ok"
    if [[ "$arm" == "a" && "$mcp_calls" != "0" ]]; then
        hygiene="VOID:arm-a-used-mcp"
    elif [[ "$skill_calls" != "0" ]]; then
        hygiene="VOID:skill-invoked"
    fi

    # Deterministic checker. Exit 2 means "not deterministically gradable" —
    # the judge decides instead.
    local checked="null" grade
    grade="$(task_field "$file" grade)"
    if [[ "$grade" == "deterministic" ]]; then
        local rc=0
        ( cd "$BENCH_ROOT" \
            && RESPONSE_FILE="$response_file" \
               ORACLE_FILE="$ORACLE_DIR/${task}.txt" \
               bash -c "$(task_section "$file" check)" ) >/dev/null 2>&1 || rc=$?
        [[ $rc -eq 0 ]] && checked=true || checked=false
    fi

    jq -nc \
        --arg sweep "$SWEEP_ID" \
        --arg typo3 "${TYPO3_VERSION:-$BENCH_TYPO3}" \
        --arg task "$task" --arg arm "$arm" --argjson rep "$rep" \
        --arg family "$(task_field "$file" family)" \
        --arg grade "$grade" \
        --arg hygiene "$hygiene" \
        --argjson checked "$checked" \
        --argjson tools "$tools" \
        --argjson tool_bytes "${tool_bytes:-0}" \
        --argjson mcp_calls "${mcp_calls:-0}" \
        --arg response "$(cat "$response_file")" \
        --argjson result "$result" \
        '{
            sweep: $sweep, typo3: $typo3,
            task: $task, arm: $arm, rep: $rep, family: $family, grade: $grade,
            hygiene: $hygiene, checked: $checked,
            mcp_calls: $mcp_calls, tool_bytes: $tool_bytes, tools: $tools,
            cost_usd: $result.total_cost_usd,
            turns: $result.num_turns,
            duration_ms: $result.duration_ms,
            is_error: $result.is_error,
            input_tokens: ($result.usage.input_tokens
                + $result.usage.cache_creation_input_tokens
                + $result.usage.cache_read_input_tokens),
            output_tokens: $result.usage.output_tokens,
            response: $response
        }' >> "$LEDGER"

    printf '  %-26s arm-%s rep-%s  $%-7.4f %2sturns  mcp=%-3s check=%-5s %s\n' \
        "$task" "$arm" "$rep" \
        "$(echo "$result" | jq -r '.total_cost_usd')" \
        "$(echo "$result" | jq -r '.num_turns')" \
        "$mcp_calls" "$checked" "$hygiene"
}

# --- sweep ------------------------------------------------------------------

for task in "${TASKS[@]}"; do
    [[ -f "$TASKS_DIR/${task}.task" ]] || { echo "no such task: $task" >&2; continue; }
    say "task: $task"
    for arm in "${ARM_LIST[@]}"; do
        for ((rep = 1; rep <= REPS; rep++)); do
            run_one "$task" "$arm" "$rep"
        done
    done
done

reset_state
say "done — ledger: $LEDGER"
echo "Next: bin/judge.sh && bin/report.php"

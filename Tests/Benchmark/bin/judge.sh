#!/usr/bin/env bash
#
# Grades every run in the ledger that a deterministic checker could not settle.
#
# The judge never learns which arm produced a response — it sees only the task,
# the oracle, and the answer. Knowing the arm would let it grade the treatment
# rather than the text.
#
# Emits results/judged.jsonl: one object per run, keyed by task/arm/rep so
# report.php can join it back onto the ledger.
#
# Usage: judge.sh [--force]

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LEDGER="$BENCH_DIR/results/runs.jsonl"
JUDGED="$BENCH_DIR/results/judged.jsonl"
JUDGE_MODEL="${BENCH_JUDGE_MODEL:-claude-opus-5}"

[[ "${1:-}" == "--force" ]] && rm -f "$JUDGED"
[[ -f "$LEDGER" ]] || { echo "error: no ledger at $LEDGER" >&2; exit 1; }
touch "$JUDGED"

SCHEMA='{
  "type": "object",
  "properties": {
    "correct": {"type": "boolean"},
    "score": {"type": "integer", "minimum": 0, "maximum": 3},
    "hallucinated_claims": {"type": "array", "items": {"type": "string"}},
    "reasoning": {"type": "string"}
  },
  "required": ["correct", "score", "hallucinated_claims", "reasoning"]
}'

total=0
judged=0

while IFS= read -r row; do
    total=$((total + 1))

    task="$(echo "$row" | jq -r '.task')"
    arm="$(echo "$row" | jq -r '.arm')"
    rep="$(echo "$row" | jq -r '.rep')"
    grade="$(echo "$row" | jq -r '.grade')"
    hygiene="$(echo "$row" | jq -r '.hygiene')"
    response="$(echo "$row" | jq -r '.response')"

    # Deterministically graded and hygienic runs need no judge.
    [[ "$grade" == "deterministic" ]] && continue
    [[ "$hygiene" != "ok" ]] && continue

    # Includes the sweep stamp so a re-run after a code change is judged afresh
    # instead of inheriting the previous sweep's verdict.
    key="$(echo "$row" | jq -r '.sweep // "legacy"')__${task}__${arm}__${rep}"
    grep -qF "\"key\":\"$key\"" "$JUDGED" 2>/dev/null && continue

    oracle="$(cat "$BENCH_DIR/oracle/${task}.txt" 2>/dev/null || echo '(no oracle)')"
    prompt_text="$(bash -c "source '$BENCH_DIR/bin/_task.sh'; task_section '$BENCH_DIR/tasks/${task}.task' prompt")"

    judge_prompt=$(cat <<EOF
You are grading one answer produced by a coding agent working in a TYPO3 project.
You are NOT told which tools the agent had. Grade only the text.

## Task given to the agent
$prompt_text

## Ground truth (independently established, authoritative)
$oracle

## The agent's answer
$response

## How to grade
- correct: true only if the answer identifies the same substance as the ground truth.
  Different wording is fine. Missing the key fact, or naming the wrong file, field or
  event class, is not.
- score: 0 wrong or absent · 1 partially right, key detail wrong · 2 right but
  incomplete or hedged · 3 fully right and specific.
- hallucinated_claims: every concrete, checkable assertion the answer makes about
  this installation that contradicts the ground truth or refers to something that
  does not exist (a field, file, setting, event or table). Quote each briefly.
  Empty array if none. Vagueness is not a hallucination; a confident wrong specific is.
- reasoning: one or two sentences.
EOF
)

    # `|| true` is load-bearing: under `set -e` a single failed judge call (a 529,
    # a timeout) aborts the whole pass, and because verdicts are appended as they
    # are produced it looks like a completed run that simply judged fewer rows.
    # Re-running resumes from the dedupe key, so skipping one row is recoverable;
    # dying halfway silently is not.
    verdict="$(claude -p "$judge_prompt" \
        --tools "" \
        --output-format json \
        --model "$JUDGE_MODEL" \
        --setting-sources "" \
        --disable-slash-commands \
        --no-session-persistence \
        --strict-mcp-config \
        --json-schema "$SCHEMA" 2>/dev/null | jq -c '.result | fromjson? // empty' || true)"

    if [[ -z "$verdict" ]]; then
        echo "  !! judge returned nothing for $key" >&2
        continue
    fi

    jq -nc --arg key "$key" --arg task "$task" --arg arm "$arm" --argjson rep "$rep" \
        --argjson v "$verdict" \
        '{key: $key, task: $task, arm: $arm, rep: $rep} + $v' >> "$JUDGED"

    judged=$((judged + 1))
    printf '  %-26s arm-%s rep-%s  correct=%-5s score=%s halluc=%s\n' \
        "$task" "$arm" "$rep" \
        "$(echo "$verdict" | jq -r '.correct')" \
        "$(echo "$verdict" | jq -r '.score')" \
        "$(echo "$verdict" | jq -r '.hallucinated_claims | length')"
done < "$LEDGER"

echo
echo "judged $judged of $total ledger rows -> $JUDGED"

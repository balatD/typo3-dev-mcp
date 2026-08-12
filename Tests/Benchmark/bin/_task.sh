#!/usr/bin/env bash
#
# Parser for the .task file format. Sourced by run.sh, oracle.sh and judge.sh.
#
# A task is one file so a reviewer can read the prompt, the ground truth and the
# grading rule together. Splitting them across prompt.md/oracle.sh/check.sh made
# it too easy to change one and forget the others.
#
#   family: F1
#   grade: deterministic
#
#   --- prompt ---
#   free text handed to the agent verbatim
#
#   --- oracle ---
#   bash producing ground truth on stdout; cwd is the bench project
#
#   --- check ---
#   bash; $RESPONSE_FILE and $ORACLE_FILE are set, cwd is the bench project.
#   exit 0 = pass. May inspect the live install, not just the response text.
#
#   --- setup ---
#   optional bash applied after state reset (seeded breakage for F3)

task_field() {
    awk -v k="$2:" '$1 == k { sub(/^[^:]*:[ ]*/, ""); print; exit }' "$1"
}

task_section() {
    awk -v want="--- $2 ---" '
        $0 == want { inside = 1; next }
        /^--- .* ---$/ { inside = 0 }
        inside { print }
    ' "$1"
}

task_has_section() {
    grep -qF -- "--- $2 ---" "$1"
}

# macOS ships bash 3.2, which has no `mapfile`. Callers do `TASKS=($(list_tasks))`;
# task names never contain spaces, so word splitting is safe here.
list_tasks() {
    local f
    for f in "$TASKS_DIR"/*.task; do
        [ -f "$f" ] || continue
        basename "$f" .task
    done
}

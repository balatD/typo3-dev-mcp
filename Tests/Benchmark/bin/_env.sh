#!/usr/bin/env bash
#
# Resolves which bench project a script targets. Sourced by every bin/ script
# after BENCH_DIR is set.
#
#   BENCH_TYPO3=13 (default)  .bench-env      results/      oracle/
#   BENCH_TYPO3=14            .bench-env.v14  results/v14/  oracle/v14/
#
# v13 keeps the original paths so results recorded before the v14 bench existed
# stay where report.php and judge.sh expect them.

BENCH_TYPO3="${BENCH_TYPO3:-13}"
case "$BENCH_TYPO3" in
    13) BENCH_SUFFIX="" ;;
    14) BENCH_SUFFIX="v14" ;;
    *) echo "error: BENCH_TYPO3 must be 13 or 14, got '$BENCH_TYPO3'" >&2; exit 1 ;;
esac

BENCH_ENV_FILE="$BENCH_DIR/.bench-env${BENCH_SUFFIX:+.$BENCH_SUFFIX}"
RESULTS_DIR="$BENCH_DIR/results${BENCH_SUFFIX:+/$BENCH_SUFFIX}"
ORACLE_DIR="$BENCH_DIR/oracle${BENCH_SUFFIX:+/$BENCH_SUFFIX}"
export BENCH_TYPO3

# Exported so task sections, which run in `bash -c`, see the same values.
load_bench_env() {
    if [[ ! -f "$BENCH_ENV_FILE" ]]; then
        echo "error: no bench project for TYPO3 $BENCH_TYPO3." \
            "Run BENCH_TYPO3=$BENCH_TYPO3 bin/setup-bench.sh first." >&2
        exit 1
    fi
    set -a
    # shellcheck disable=SC1090
    source "$BENCH_ENV_FILE"
    set +a
    export SITE_HOST="${SITE_URL#https://}"
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Turns the run ledger into the two reports the benchmark exists to produce:
 *
 *   1. the claim table  — per family: success, hallucination rate, median cost,
 *                         and cost-per-success, paired across arms
 *   2. the tuning ledger — per tool: calls, bytes returned, and the success rate
 *                         of the runs that used it
 *
 * Medians rather than means throughout: agent runs have a long right tail, and
 * one runaway run would otherwise swallow the signal.
 *
 * Usage: report.php [--json]
 */

$benchDir = dirname(__DIR__);
$ledgerFile = $benchDir . '/results/runs.jsonl';
$judgedFile = $benchDir . '/results/judged.jsonl';

if (!is_file($ledgerFile)) {
    fwrite(STDERR, "error: no ledger at $ledgerFile — run bin/run.sh first\n");
    exit(1);
}

/** @return list<array<string, mixed>> */
function readJsonl(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function median(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);
    $mid = intdiv(count($values), 2);
    return count($values) % 2 === 0
        ? ($values[$mid - 1] + $values[$mid]) / 2
        : (float)$values[$mid];
}

$runs = readJsonl($ledgerFile);

// Default to the newest sweep. Mixing sweeps averages results from different
// versions of the code under test, which reads as noise rather than the change
// it actually is. Pass --all to aggregate everything.
$sweeps = array_values(array_unique(array_map(
    static fn(array $r): string => $r['sweep'] ?? 'legacy',
    $runs,
)));
sort($sweeps);
if (!in_array('--all', $argv, true) && count($sweeps) > 1) {
    $newest = end($sweeps);
    $runs = array_values(array_filter(
        $runs,
        static fn(array $r): bool => ($r['sweep'] ?? 'legacy') === $newest,
    ));
    fwrite(STDERR, sprintf(
        "note: %d sweeps in ledger; reporting only '%s' (%d runs). Pass --all to aggregate.\n\n",
        count($sweeps),
        $newest,
        count($runs),
    ));
}

$judged = [];
foreach (readJsonl($judgedFile) as $row) {
    $judged[$row['key']] = $row;
}

// --- resolve one success verdict per run ------------------------------------

$voided = 0;
$apiErrors = 0;
$rows = [];
foreach ($runs as $run) {
    if (($run['hygiene'] ?? 'ok') !== 'ok') {
        $voided++;
        continue;
    }

    // A transport failure (529 Overloaded and friends) measures Anthropic's
    // availability, not the arm. Counting it as a failed task would penalise
    // whichever arm happened to be running when it hit.
    if (($run['is_error'] ?? false) === true || str_starts_with((string)$run['response'], 'API Error')) {
        $apiErrors++;
        continue;
    }

    $key = sprintf('%s__%s__%s__%d', $run['sweep'] ?? 'legacy', $run['task'], $run['arm'], $run['rep']);
    $verdict = $judged[$key] ?? null;

    // A deterministic checker wins where it exists; the judge fills the rest.
    $success = $run['checked'] ?? null;
    if ($success === null && $verdict !== null) {
        $success = $verdict['correct'];
    }

    $run['success'] = $success;
    $run['hallucinations'] = $verdict !== null ? count($verdict['hallucinated_claims']) : null;
    $rows[] = $run;
}

if ($rows === []) {
    fwrite(STDERR, "error: every run was voided by a hygiene assertion\n");
    exit(1);
}

// --- claim table ------------------------------------------------------------

$byFamilyArm = [];
foreach ($rows as $r) {
    $byFamilyArm[$r['family']][$r['arm']][] = $r;
}
ksort($byFamilyArm);

$fmtPct = static fn(?float $v): string => $v === null ? '   —' : sprintf('%3.0f%%', $v * 100);

echo "\n";
echo "CLAIM TABLE\n";
echo str_repeat('=', 96) . "\n";
printf("%-8s %-5s %5s %8s %8s %9s %9s %9s %10s\n",
    'FAMILY', 'ARM', 'RUNS', 'SUCCESS', 'HALLUC', 'MED COST', 'MED TOK', 'MED TURNS', 'COST/WIN');
echo str_repeat('-', 96) . "\n";

foreach ($byFamilyArm as $family => $arms) {
    ksort($arms);
    foreach ($arms as $arm => $set) {
        $graded = array_filter($set, static fn($r) => $r['success'] !== null);
        $wins = array_filter($graded, static fn($r) => $r['success'] === true);
        $hall = array_filter($set, static fn($r) => $r['hallucinations'] !== null);

        $successRate = $graded === [] ? null : count($wins) / count($graded);
        $hallRate = $hall === []
            ? null
            : count(array_filter($hall, static fn($r) => $r['hallucinations'] > 0)) / count($hall);

        $costs = array_map(static fn($r) => (float)$r['cost_usd'], $set);
        $totalCost = array_sum($costs);
        $costPerWin = count($wins) > 0 ? $totalCost / count($wins) : null;

        printf("%-8s %-5s %5d %8s %8s %9s %9s %9.1f %10s\n",
            $family,
            'arm ' . $arm,
            count($set),
            $fmtPct($successRate),
            $fmtPct($hallRate),
            '$' . number_format(median($costs), 4),
            number_format(median(array_map(static fn($r) => (float)$r['input_tokens'], $set)), 0),
            median(array_map(static fn($r) => (float)$r['turns'], $set)),
            $costPerWin === null ? '—' : '$' . number_format($costPerWin, 3),
        );
    }
    echo "\n";
}

// --- paired per-task deltas -------------------------------------------------

$byTaskArm = [];
foreach ($rows as $r) {
    $byTaskArm[$r['task']][$r['arm']][] = $r;
}
ksort($byTaskArm);

echo "PER-TASK, PAIRED (arm b minus arm a)\n";
echo str_repeat('=', 96) . "\n";
printf("%-28s %-6s %14s %14s %12s %12s\n", 'TASK', 'FAM', 'SUCCESS a→b', 'COST a→b', 'Δ COST', 'Δ TOKENS');
echo str_repeat('-', 96) . "\n";

foreach ($byTaskArm as $task => $arms) {
    if (!isset($arms['a'], $arms['b'])) {
        continue;
    }

    $rate = static function (array $set): ?float {
        $graded = array_filter($set, static fn($r) => $r['success'] !== null);
        return $graded === []
            ? null
            : count(array_filter($graded, static fn($r) => $r['success'] === true)) / count($graded);
    };
    $medCost = static fn(array $s): float => median(array_map(static fn($r) => (float)$r['cost_usd'], $s));
    $medTok = static fn(array $s): float => median(array_map(static fn($r) => (float)$r['input_tokens'], $s));

    printf("%-28s %-6s %6s → %-5s %6s → %-5s %12s %12s\n",
        substr($task, 0, 28),
        $arms['a'][0]['family'],
        $fmtPct($rate($arms['a'])),
        $fmtPct($rate($arms['b'])),
        '$' . number_format($medCost($arms['a']), 3),
        '$' . number_format($medCost($arms['b']), 3),
        sprintf('%+.3f', $medCost($arms['b']) - $medCost($arms['a'])),
        sprintf('%+d', (int)($medTok($arms['b']) - $medTok($arms['a']))),
    );
}

// --- tuning ledger ----------------------------------------------------------

$tools = [];
foreach ($rows as $r) {
    if ($r['arm'] !== 'b') {
        continue;
    }
    foreach ($r['tools'] ?? [] as $tool) {
        $name = $tool['name'];
        if (!str_starts_with($name, 'mcp__typo3-dev-mcp__')) {
            continue;
        }
        $short = substr($name, strlen('mcp__typo3-dev-mcp__'));
        $tools[$short]['calls'] = ($tools[$short]['calls'] ?? 0) + $tool['calls'];
        $tools[$short]['runs'] = ($tools[$short]['runs'] ?? 0) + 1;
        $tools[$short]['wins'] = ($tools[$short]['wins'] ?? 0) + ($r['success'] === true ? 1 : 0);
        $tools[$short]['graded'] = ($tools[$short]['graded'] ?? 0) + ($r['success'] !== null ? 1 : 0);
        $tools[$short]['tasks'][$r['task']] = true;
    }
}
uasort($tools, static fn($a, $b) => $b['calls'] <=> $a['calls']);

$armBRuns = count(array_filter($rows, static fn($r) => $r['arm'] === 'b'));

echo "\n\nTUNING LEDGER — arm b only, $armBRuns runs\n";
echo str_repeat('=', 96) . "\n";
printf("%-24s %7s %7s %8s %10s %s\n", 'TOOL', 'CALLS', 'RUNS', 'TASKS', 'WIN RATE', 'USED IN');
echo str_repeat('-', 96) . "\n";

foreach ($tools as $name => $t) {
    printf("%-24s %7d %7d %8d %10s %s\n",
        $name,
        $t['calls'],
        $t['runs'],
        count($t['tasks']),
        ($t['graded'] ?? 0) > 0 ? sprintf('%3.0f%%', 100 * $t['wins'] / $t['graded']) : '—',
        implode(', ', array_slice(array_keys($t['tasks']), 0, 3)),
    );
}

// Tools that never fired still cost their schema on every request.
$allTools = [];
foreach (readJsonl($benchDir . '/results/tools.json') as $t) {
    $allTools[] = $t;
}
$unused = [];
$knownFile = $benchDir . '/results/tool-names.txt';
if (is_file($knownFile)) {
    foreach (file($knownFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $name) {
        if (!isset($tools[$name])) {
            $unused[] = $name;
        }
    }
}
if ($unused !== []) {
    echo "\nNEVER CALLED in this sweep (still paying schema cost every request):\n  "
        . implode("\n  ", $unused) . "\n";
}

if ($voided > 0) {
    echo "\n\033[33m$voided run(s) voided by hygiene assertions and excluded.\033[0m\n";
}
if ($apiErrors > 0) {
    echo "\033[33m$apiErrors run(s) excluded: API transport error, not a task failure.\033[0m\n";
}

echo "\nRuns: " . count($rows) . " usable"
    . ($voided > 0 ? " ($voided voided)" : '')
    . ". Pair with results/tax-baseline.md for the fixed context cost.\n";

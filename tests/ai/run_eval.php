<?php
/**
 * Deterministic AI safety/retrieval evaluation (no DB, no model, no network).
 *   php tests/ai/run_eval.php
 * Fixture packages below are TEST DATA ONLY and never shown to customers.
 * This verifies the retrieval, routing, guard, tool-validation and sanitising
 * layers. It does NOT measure model answer quality — see tests/ai/live_quality.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/ai/SafetyEval.php';

$r = SafetyEval::run(__DIR__ . '/eval_cases.json');
foreach ($r['rows'] as [$ok, $id, $d]) echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($id, 28) . $d . "\n";
echo "\n{$r['pass']} passed, {$r['fail']} failed\n";
echo "unauthorised information leaked: {$r['leaked']} (of {$r['leak_checks']} leakage checks)\n";

// Regression gate: the suite may not shrink and may not drop below the recorded baseline.
$bf = __DIR__ . '/baseline.json';
$base = is_file($bf) ? json_decode((string)file_get_contents($bf), true) : null;
$total = $r['pass'] + $r['fail']; $gate = true;
if (is_array($base)) {
    if ($total < (int)$base['min_cases']) { echo "GATE FAIL: {$total} cases, baseline requires at least {$base['min_cases']} (cases must not be removed to make the suite pass)\n"; $gate = false; }
    if ($r['pass'] < (int)$base['min_pass']) { echo "GATE FAIL: {$r['pass']} passing, baseline requires at least {$base['min_pass']}\n"; $gate = false; }
    if ($gate) echo "GATE OK: {$total} cases >= {$base['min_cases']}, {$r['pass']} passing >= {$base['min_pass']}\n";
} else { echo "GATE: no baseline.json (not enforced)\n"; }
if (in_array('--update-baseline', $argv ?? [], true)) {
    if ($r['fail'] === 0 && $gate) { $base = (array)$base; $base['min_cases'] = $total; $base['min_pass'] = $r['pass']; file_put_contents($bf, json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); echo "baseline raised to {$total}\n"; }
    else { echo "baseline NOT updated (suite is not fully passing)\n"; }
}
exit(($r['fail'] || $r['leaked'] || !$gate) ? 1 : 0);

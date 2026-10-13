<?php
/**
 * Deterministic AI safety/retrieval evaluation (no DB, no model, no network).
 *   php tests/ai/run_eval.php
 * Fixture packages below are TEST DATA ONLY and never shown to customers.
 * This verifies the retrieval, routing, guard, tool-validation and sanitising
 * layers. It does NOT measure model answer quality — see tests/ai/live_ollama.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/ai/SafetyEval.php';

$r = SafetyEval::run(__DIR__ . '/eval_cases.json');
foreach ($r['rows'] as [$ok, $id, $d]) echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($id, 28) . $d . "\n";
echo "\n{$r['pass']} passed, {$r['fail']} failed\n";
exit($r['fail'] ? 1 : 0);

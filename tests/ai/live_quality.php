<?php
/**
 * Live assistant quality run: asks the labelled questions in tests/ai/quality_cases.json to the REAL configured model
 * through the real SiteAssistant (real retrieval, tools, guard and verifier) and scores each answer with QualityEval.
 *
 *   php tests/ai/live_quality.php                run against the provider in .env (costs real API calls, ~26 requests)
 *   php tests/ai/live_quality.php --only=bn-budget-search,adv-dan-key
 *   php tests/ai/live_quality.php --require      exit 2 instead of 0 when no provider is configured
 *   php tests/ai/live_quality.php --stub         harness self-test with a canned reply. NOT a quality measurement.
 *
 * Without a configured provider it prints SKIPPED and measures nothing. Results are saved to tests/ai/results/ and
 * compared with the previous run of the same provider+model. Synthetic turns are kept out of the production metrics
 * and their telemetry/usage rows are removed afterwards. Needs a catalogue with at least one published package.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/AIClient.php';
require_once 'backend/ai/SiteAssistant.php';
require_once 'backend/ai/QualityEval.php';
require_once 'backend/ai/AITurnLog.php';

$opts = getopt('', ['stub', 'require', 'only:']);
$stub = isset($opts['stub']);
$doc = json_decode((string)file_get_contents(__DIR__ . '/quality_cases.json'), true);
$cases = $doc['cases'] ?? [];
if ($p = QualityEval::validateCases($cases)) { echo "Case file problems:\n  " . implode("\n  ", $p) . "\n"; exit(1); }
if (isset($opts['only'])) { $want = explode(',', $opts['only']); $cases = array_values(array_filter($cases, fn($c) => in_array($c['id'], $want, true))); }

$email = 'quality-eval@tms-testing.local';
if ($stub) {
    echo "STUB MODE: harness self-test with a canned English reply. This is NOT a quality measurement.\n\n";
    $ai = new AIClient($dbh, null, new GeminiProvider('stub', 'stub'));
    $ai->setProviderChain([new GeminiProvider('stub', 'stub')]);
    $ai->setTransport(fn($req, $p, $to) => [200, json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'Sure, here are some options for you.', 'suggestions' => []])]]]]]]), null]);
    $provider = 'stub'; $model = 'stub';
} else {
    $ai = new AIClient($dbh);
    $rp = (new ReflectionClass($ai))->getProperty('provider'); $rp->setAccessible(true); $pr = $rp->getValue($ai);
    if (!$pr->isConfigured()) {
        echo "SKIPPED: no AI provider is configured (set GEMINI_API_KEY, OPENAI_API_KEY or AI_PROVIDER=ollama in .env).\nNothing was measured; this is not a pass.\n";
        exit(isset($opts['require']) ? 2 : 0);
    }
    $provider = $pr->name(); $model = $pr->model();
    echo "Provider: $provider / $model  (real API calls)\n\n";
}

// A real published package for cases that need page context.
$pkgId = (int)$dbh->query('SELECT MIN(PackageId) FROM tbltourpackages WHERE Status = 1')->fetchColumn();
$catalogue = (int)$dbh->query('SELECT COUNT(*) FROM tbltourpackages WHERE Status = 1')->fetchColumn();

AITurnLog::$suppress = true;
$asst = new SiteAssistant($dbh, $ai);
$rows = []; $byCat = [];
foreach ($cases as $c) {
    if (($c['page'] ?? '') === '{{PKG}}' && !$pkgId) { $rows[] = [$c['id'], 'SKIP', ['no published package to use as page context']]; continue; }
    $dbh->prepare('DELETE FROM tbl_ai_usage_log WHERE user_email = ?')->execute([$email]);
    $lang = ($c['category'] ?? 'en') === 'bn' ? 'bn' : 'en';
    $page = ($c['page'] ?? '') === '{{PKG}}' ? 'package-details.php?pkgid=' . $pkgId : '';
    $t0 = microtime(true);
    $res = $asst->reply($c['prompt'], [], !empty($c['signed_in']) ? $email : 'guest', $page, $lang);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if (!$res['success']) { $s = ['pass' => false, 'failed' => ['assistant error: ' . ($res['error'] ?? '?')]]; }
    else $s = QualityEval::score($c, $res);
    $rows[] = [$c['id'], $s['pass'] ? 'PASS' : 'FAIL', $s['failed'], $ms, $c['category'] ?? 'en'];
    $byCat[$c['category'] ?? 'en'][] = $s['pass'];
    echo ($s['pass'] ? 'PASS ' : 'FAIL ') . str_pad($c['id'], 26) . str_pad($ms . 'ms', 8) . implode(' | ', $s['failed']) . "\n";
    if ($res['success'] && !$s['pass'] && !empty($c['show_reply'])) echo '     reply: ' . mb_substr($res['reply'], 0, 300) . "\n";
}
// Clean up everything the run wrote under the synthetic user.
$dbh->prepare('DELETE FROM tbl_ai_usage_log WHERE user_email = ?')->execute([$email]);
try { $dbh->prepare("DELETE FROM tbl_ai_telemetry WHERE user_email = ? AND feature = 'site_assistant'")->execute([$email]); } catch (Throwable $e) {}
$dbh->prepare("DELETE FROM tbl_site_chat_messages WHERE user_email = ?")->execute([$email]);

$pass = count(array_filter($rows, fn($r) => $r[1] === 'PASS')); $fail = count(array_filter($rows, fn($r) => $r[1] === 'FAIL')); $skip = count(array_filter($rows, fn($r) => $r[1] === 'SKIP'));
echo "\n";
foreach ($byCat as $cat => $v) echo str_pad($cat, 12) . array_sum($v) . '/' . count($v) . " passed\n";
echo "\n$pass passed, $fail failed, $skip skipped";
if ($skip) echo "  (skipped cases were NOT measured)";
echo "\n";
if ($stub) { echo "Stub run: nothing saved. Failures above are expected and show the checks detect a poor reply.\n"; exit(0); }

// Save and compare with the previous run of the same provider+model.
$dir = __DIR__ . '/results'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$prevFile = null; foreach (array_reverse(glob($dir . '/quality-*.json') ?: []) as $f) { $j = json_decode((string)file_get_contents($f), true); if (($j['provider'] ?? '') === $provider && ($j['model'] ?? '') === $model) { $prevFile = $j; break; } }
$out = ['at' => date('c'), 'provider' => $provider, 'model' => $model, 'catalogue_packages' => $catalogue, 'pass' => $pass, 'fail' => $fail, 'skipped' => $skip,
        'cases' => array_map(fn($r) => ['id' => $r[0], 'result' => $r[1], 'failed' => $r[2]], $rows)];
file_put_contents($dir . '/quality-' . date('Ymd-His') . '.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if ($prevFile) {
    $pm = []; foreach ($prevFile['cases'] as $c) $pm[$c['id']] = $c['result'];
    foreach ($out['cases'] as $c) { $o = $pm[$c['id']] ?? null; if ($o === 'PASS' && $c['result'] === 'FAIL') echo "REGRESSION since {$prevFile['at']}: {$c['id']}\n"; if ($o === 'FAIL' && $c['result'] === 'PASS') echo "fixed since {$prevFile['at']}: {$c['id']}\n"; }
} else echo "No earlier run for $provider/$model to compare with.\n";
echo "Saved to tests/ai/results/. These checks cover behaviour (language, tools, price grounding, hedging, refusals), not helpfulness or tone.\n";
exit($fail ? 1 : 0);

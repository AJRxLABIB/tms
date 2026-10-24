<?php
/**
 * scripts/ai_benchmark.php — compares the locally installed Ollama models on one fixed, labelled query set.
 *
 *   php scripts/ai_benchmark.php --dry-run             no runtime needed: validates the query set and shows the plan
 *   php scripts/ai_benchmark.php                       benchmarks every installed model (GET /api/tags)
 *   php scripts/ai_benchmark.php --models=qwen2.5:3b-instruct,llama3.2:3b --repeat=3
 *   php scripts/ai_benchmark.php --base-url=http://127.0.0.1:11434 --timeout=120
 *   php scripts/ai_benchmark.php --save                also store a summary row per model in tbl_ai_eval_runs (migration 035)
 *
 * It only talks to the Ollama runtime you point it at (default OLLAMA_BASE_URL or http://127.0.0.1:11434) and refuses a
 * non-local address, so no customer or test text can leave the machine. The prompts are synthetic. Results are written to
 * tests/ai/results/ (git-ignored) and a table is printed. It measures latency, reliability, JSON validity, price
 * grounding, Bengali reply rate and (from Ollama's own counters) tokens/second and memory. It does NOT measure helpfulness.
 * Not run against a live Ollama in the build environment: verify the first real run by reading the raw numbers.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../backend/ai/AIBenchmark.php';
require_once __DIR__ . '/../backend/ai/AIMetrics.php';
require_once __DIR__ . '/../backend/ai/PromptRedactor.php';

$o = getopt('', ['dry-run', 'models:', 'repeat:', 'base-url:', 'timeout:', 'save', 'help']);
if (isset($o['help'])) { echo "See the header of scripts/ai_benchmark.php\n"; exit(0); }
$dry     = isset($o['dry-run']);
$repeat  = max(1, min(10, (int)($o['repeat'] ?? 1)));
$timeout = max(10, min(600, (int)($o['timeout'] ?? 120)));
$base    = rtrim((string)($o['base-url'] ?? (getenv('OLLAMA_BASE_URL') ?: 'http://127.0.0.1:11434')), '/');
$cases   = AIBenchmark::cases();

// Case-set self-check (also what --dry-run reports).
$ids = array_column($cases, 'id'); $problems = [];
if (count($ids) !== count(array_unique($ids))) $problems[] = 'duplicate case ids';
foreach ($cases as $c) { if (empty($c['prompt']) || empty($c['checks'])) $problems[] = $c['id'] . ': missing prompt/checks'; }
if ($problems) { echo "Case set problems: " . implode('; ', $problems) . "\n"; exit(1); }

if (!PromptRedactor::isLocalEndpoint($base)) { echo "Refusing: $base is not localhost or a private-network address.\n"; exit(1); }

echo "Benchmark plan: " . count($cases) . " cases x $repeat repeat(s) per model, endpoint $base\n";
foreach ($cases as $c) echo '  - ' . str_pad($c['id'], 22) . '[' . $c['lang'] . '] ' . implode(',', array_map(fn($k, $v) => is_int($k) ? $v : $k, array_keys($c['checks']), $c['checks'])) . "\n";
if ($dry) { echo "\nDRY RUN: nothing was sent. Run without --dry-run on a machine with Ollama running.\n"; exit(0); }

/** Minimal JSON HTTP helper (curl). @return array{0:int,1:?array,2:?string} status, decoded body, error */
function ollama_http(string $method, string $url, ?array $payload, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $body = curl_exec($ch); $err = $body === false ? curl_error($ch) : null; $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, is_string($body) ? json_decode($body, true) : null, $err];
}
if (!function_exists('curl_init')) { echo "The PHP curl extension is required (enable extension=curl in php.ini).\n"; exit(1); }

[$code, $tags, $err] = ollama_http('GET', $base . '/api/tags', null, 10);
if ($code !== 200 || !is_array($tags)) { echo "Cannot reach Ollama at $base" . ($err ? " ($err)" : " (HTTP $code)") . ". Start it with `ollama serve`, or use --dry-run.\n"; exit(2); }
$installed = []; foreach ($tags['models'] ?? [] as $m) $installed[(string)$m['name']] = (int)($m['size'] ?? 0);
if (!$installed) { echo "Ollama is running but has no models. Pull one, e.g. `ollama pull qwen2.5:3b-instruct`.\n"; exit(2); }
$want = isset($o['models']) ? array_filter(array_map('trim', explode(',', (string)$o['models']))) : array_keys($installed);
$skipped = array_diff($want, array_keys($installed));
$want = array_values(array_intersect($want, array_keys($installed)));
foreach ($skipped as $s) echo "Skipping $s: not installed.\n";
if (!$want) { echo "No requested model is installed.\n"; exit(2); }
// Embedding-only models cannot chat; skip the common ones.
$want = array_values(array_filter($want, fn($m) => !preg_match('/embed|bge-|minilm|e5-/i', $m)));

$byModel = [];
foreach ($want as $model) {
    echo "\n== $model ==\n";
    $runs = [];
    // Warm-up call (not counted): the first request pays model load time and would distort latency.
    ollama_http('POST', $base . '/api/chat', ['model' => $model, 'stream' => false, 'messages' => [['role' => 'user', 'content' => 'Reply with {"reply":"ok"}']], 'options' => ['num_predict' => 16, 'temperature' => 0]], $timeout);
    for ($i = 0; $i < $repeat; $i++) foreach ($cases as $c) {
        $msg = AIBenchmark::messages($c);
        $t0 = microtime(true);
        [$code, $res, $err] = ollama_http('POST', $base . '/api/chat', ['model' => $model, 'stream' => false,
            'messages' => [['role' => 'system', 'content' => $msg['system']], ['role' => 'user', 'content' => $msg['user']]],
            'options' => ['temperature' => 0, 'num_predict' => 400]], $timeout);
        $ms = (microtime(true) - $t0) * 1000;
        $text = $code === 200 && is_array($res) ? (string)($res['message']['content'] ?? '') : null;
        if ($text === null || $text === '') { $runs[] = ['ok' => false, 'latency_ms' => null, 'score' => null]; echo '  FAIL ' . str_pad($c['id'], 22) . ($err ?: "HTTP $code / empty reply") . "\n"; continue; }
        $s = AIBenchmark::score($c, $text);
        $runs[] = ['ok' => true, 'latency_ms' => $ms, 'score' => $s, 'eval_count' => $res['eval_count'] ?? null, 'eval_duration_ns' => $res['eval_duration'] ?? null];
        echo '  ' . ($s['pass'] ? 'PASS ' : 'FAIL ') . str_pad($c['id'], 22) . sprintf('%6.0fms ', $ms) . implode(' | ', $s['failed']) . "\n";
    }
    $sum = AIBenchmark::summarize($runs);
    $sum['size_bytes'] = $installed[$model];
    // Memory actually in use right now (Ollama keeps the model loaded after the run).
    [$pc, $ps] = ollama_http('GET', $base . '/api/ps', null, 10);
    $sum['loaded_bytes'] = null;
    if ($pc === 200 && is_array($ps)) foreach ($ps['models'] ?? [] as $pm) if (($pm['name'] ?? '') === $model) $sum['loaded_bytes'] = (int)($pm['size'] ?? 0) ?: null;
    $byModel[$model] = $sum;
}

echo "\n" . AIBenchmark::table($byModel);
$hint = AIBenchmark::routingHint($byModel);
echo "\nRouting hint (from these measurements only): fast=" . ($hint['fast'] ?? 'none') . "  quality=" . ($hint['quality'] ?? 'none') . "\n" . $hint['note'] . "\n";
echo "Note: " . count($cases) . " synthetic cases is a small sample; treat differences of a few percent as noise and re-run with --repeat.\n";

$dir = __DIR__ . '/../tests/ai/results'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$file = $dir . '/benchmark-' . date('Ymd-His') . '.json';
file_put_contents($file, json_encode(['at' => date('c'), 'endpoint' => $base, 'cases' => count($cases), 'repeat' => $repeat, 'models' => $byModel, 'hint' => $hint], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved " . basename($file) . " in tests/ai/results/\n";

if (isset($o['save'])) {
    require __DIR__ . '/../backend/config.php';
    foreach ($byModel as $m => $s) {
        $passed = (int)round(($s['pass_rate'] ?? 0) * $s['requests']);
        EvalRunLog::save($dbh, 'benchmark', $passed, max(0, $s['requests'] - $passed), 0, $s, 'ollama', $m, 'ai_benchmark.php');
    }
    echo "Summary rows stored (tbl_ai_eval_runs).\n";
}
exit(0);

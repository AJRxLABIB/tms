<?php
/** AI provider failover + circuit breaker tests. Real AIClient and real providers; only the HTTP transport is scripted
 *  (clearly a test seam). Uses the real DB for breaker state and cleans up after itself.  php tests/ai/resilience_test.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/AIClient.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 74) . $d . "\n"; }
$dbh->exec("DELETE FROM tbl_app_meta WHERE k LIKE 'ai_cb_%'");
$dbh->exec("DELETE FROM tbl_ai_telemetry WHERE feature='resilience_test'");

$gem = new GeminiProvider('test-key', 'gemini-test');
$oai = new OpenAIProvider('test-key', 'gpt-test', 'http://openai.invalid/v1');
$oll = new OllamaProvider('llama-test', 'http://ollama.invalid');
$okG = json_encode(['candidates' => [['content' => ['parts' => [['text' => 'gemini answer']]]]], 'usageMetadata' => ['totalTokenCount' => 5]]);
$okO = json_encode(['choices' => [['message' => ['content' => 'openai answer'], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 7]]);
$okL = json_encode(['message' => ['content' => 'ollama answer'], 'prompt_eval_count' => 3, 'eval_count' => 4]);
$calls = [];
/** script: provider name => callable(callIndex): [http, body, curlErr] */
$mk = function (array $script) use (&$calls, $dbh, $gem, $oai, $oll): AIClient {
    $calls = [];
    $c = new AIClient($dbh, null, $gem);
    $c->setProviderChain([$gem, $oai, $oll]);
    $c->setTransport(function (array $req, AIProviderInterface $p, int $to) use (&$calls, $script) {
        $n = $p->name(); $calls[$n] = ($calls[$n] ?? 0) + 1;
        return isset($script[$n]) ? $script[$n]($calls[$n]) : [0, '', 'no route'];
    });
    return $c;
};
$ask = fn(AIClient $c, string $u = 'rt@example.test') => $c->call('sys', [['role' => 'user', 'content' => 'hi']], '', $u, 20, 5, 'resilience_test');
$reset = fn() => $dbh->exec("DELETE FROM tbl_app_meta WHERE k LIKE 'ai_cb_%'");

// 1. classification
t('classify: network error is unavailable', ProviderHealth::classify($gem->parseResponse(0, '', 'timeout')) === 'unavailable');
t('classify: 401 is config', ProviderHealth::classify($gem->parseResponse(401, '{}', null)) === 'config');
t('classify: OpenAI insufficient_quota is quota', ProviderHealth::classify($oai->parseResponse(429, json_encode(['error' => ['code' => 'insufficient_quota']]), null)) === 'quota');
t('classify: Gemini safety block is content', ProviderHealth::classify($gem->parseResponse(200, json_encode(['candidates' => [['finishReason' => 'SAFETY']]]), null)) === 'content');
t('classify: Ollama connection refused is unavailable', ProviderHealth::classify($oll->parseResponse(0, '', 'refused')) === 'unavailable');

// 2. primary healthy -> no fallback used
$reset(); $c = $mk(['gemini' => fn($n) => [200, $okG, null], 'openai' => fn($n) => [200, $okO, null]]);
$r = $ask($c);
t('healthy primary answers; fallback not touched', $r['success'] && $r['provider'] === 'gemini' && !$r['fallback_used'] && !isset($calls['openai']));

// 3. primary down (503 x retries) -> falls over to second provider
$reset(); $c = $mk(['gemini' => fn($n) => [503, '{}', null], 'openai' => fn($n) => [200, $okO, null]]);
$r = $ask($c);
t('503 on primary -> OpenAI answers, fallback_used=true', $r['success'] && $r['provider'] === 'openai' && $r['fallback_used'] === true && $r['content'] === 'openai answer');
t('primary was retried a bounded number of times (<=3)', ($calls['gemini'] ?? 0) >= 1 && $calls['gemini'] <= 3, 'calls=' . ($calls['gemini'] ?? 0));
t('telemetry records the provider that actually answered', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_telemetry WHERE feature='resilience_test' AND provider='openai' AND success=1")->fetchColumn() >= 1);
t('telemetry records the failed primary too', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_telemetry WHERE feature='resilience_test' AND provider='gemini' AND success=0")->fetchColumn() >= 1);

// 4. content refusal must NOT trigger failover
$reset(); $c = $mk(['gemini' => fn($n) => [200, json_encode(['candidates' => [['finishReason' => 'SAFETY']]]), null], 'openai' => fn($n) => [200, $okO, null]]);
$r = $ask($c);
t('safety refusal is returned, no other model is shopped', !$r['success'] && !isset($calls['openai']));

// 5. auth error -> immediate failover, and not retried
$reset(); $c = $mk(['gemini' => fn($n) => [401, '{}', null], 'openai' => fn($n) => [200, $okO, null]]);
$r = $ask($c);
t('401 on primary: one attempt only, then failover', $r['success'] && $calls['gemini'] === 1 && $r['provider'] === 'openai');
t('config failure opens the breaker immediately', ProviderHealth::isOpen($dbh, 'gemini'));
$c2 = $mk(['gemini' => fn($n) => [200, $okG, null], 'openai' => fn($n) => [200, $okO, null]]);
$r = $ask($c2);
t('open breaker: primary skipped with no network call', $r['provider'] === 'openai' && !isset($calls['gemini']));

// 6. breaker for transient failures opens after 3, and half-opens after the window
$reset();
for ($i = 0; $i < 2; $i++) ProviderHealth::recordFailure($dbh, 'gemini', 'unavailable');
t('two transient failures keep it closed', !ProviderHealth::isOpen($dbh, 'gemini'));
ProviderHealth::recordFailure($dbh, 'gemini', 'unavailable');
t('third transient failure opens it', ProviderHealth::isOpen($dbh, 'gemini'));
t('it closes again after the window (half-open trial allowed)', !ProviderHealth::isOpen($dbh, 'gemini', time() + 61));
$snap = ProviderHealth::snapshot($dbh, ['gemini', 'openai']);
t('snapshot reports open/healthy states', $snap['gemini']['status'] === 'open' && $snap['openai']['status'] === 'healthy');
ProviderHealth::recordFailure($dbh, 'gemini', 'content');
t('content failures never count against a provider', ProviderHealth::state($dbh, 'gemini')['fails'] === 3);
$c3 = $mk(['gemini' => fn($n) => [200, $okG, null]]);
ProviderHealth::recordSuccess($dbh, 'gemini');
t('success resets the breaker', !ProviderHealth::isOpen($dbh, 'gemini') && ProviderHealth::state($dbh, 'gemini')['fails'] === 0);

// 7. everything down -> truthful failure, bounded, no fabricated answer
$reset(); $c = $mk(['gemini' => fn($n) => [503, '{}', null], 'openai' => fn($n) => [503, '{}', null], 'ollama' => fn($n) => [0, '', 'refused']]);
$t0 = microtime(true); $r = $ask($c); $el = microtime(true) - $t0;
t('all providers down -> success=false with an honest error, empty content', !$r['success'] && $r['content'] === '' && $r['error'] !== '');
t('all-down case stays inside the overall deadline (<25s)', $el < 25, round($el, 1) . 's');

// 8. privacy gate on fallbacks
$chainFor = function (string $allow) {
    $code = 'require "backend/config.php"; require "backend/ai/AIClient.php"; echo implode(",", array_map(fn($p)=>$p->name(), AIClient::makeFallbackProviders(new GeminiProvider("k","m"))));';
    $env = 'AI_PROVIDER=gemini GEMINI_API_KEY=k OPENAI_API_KEY=k OLLAMA_MODEL=llama AI_FALLBACK_PROVIDERS=openai,ollama AI_FALLBACK_ALLOW_EXTERNAL=' . $allow;
    return trim((string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null | tail -1'));
};
t('cloud fallback is refused without the privacy opt-in; local ollama is allowed', str_ends_with($chainFor('0'), 'ollama') && !str_contains($chainFor('0'), 'openai'), $chainFor('0'));
t('cloud fallback is used once AI_FALLBACK_ALLOW_EXTERNAL=1', str_ends_with($chainFor('1'), 'openai,ollama'), $chainFor('1'));
t('external fallback ignored unless AI_FALLBACK_ALLOW_EXTERNAL=1 (constants default off)', !AI_FALLBACK_ALLOW_EXTERNAL && AI_FALLBACK_PROVIDERS === '');
t('no fallbacks configured by default', AIClient::makeFallbackProviders($gem) === []);

// 9. unconfigured everything -> same message as before
$c = new AIClient($dbh, null, new GeminiProvider('', 'm'));
$r = $c->call('s', [['role' => 'user', 'content' => 'hi']], '', 'rt@example.test', 10, 5, 'resilience_test');
t('no provider configured -> "not switched on" (unchanged behaviour)', !$r['success'] && stripos($r['error'], 'not switched on') !== false);

// 10. administrator switch: failover off -> only the primary is ever tried
require_once 'backend/ai/AIConfig.php';
$reset(); AIConfig::set($dbh, 'failover_enabled', 0, 'test-admin'); AIConfig::clearCache();
$c = $mk(['gemini' => fn($n) => [503, '{}', null], 'openai' => fn($n) => [200, $okO, null]]);
$r = $ask($c);
t('failover switched off by admin: primary outage is NOT rerouted to another provider', !$r['success'] && !isset($calls['openai']));
AIConfig::set($dbh, 'failover_enabled', 1, 'test-admin'); AIConfig::clearCache();
$reset(); $c = $mk(['gemini' => fn($n) => [503, '{}', null], 'openai' => fn($n) => [200, $okO, null]]);
$r = $ask($c);
t('failover switched back on: rerouting works again', $r['success'] && $r['provider'] === 'openai');
AIConfig::reset($dbh, 'failover_enabled', 'test-admin'); $dbh->exec("DELETE FROM tbl_ai_config_log WHERE changed_by='test-admin'"); AIConfig::clearCache();

$reset(); $dbh->exec("DELETE FROM tbl_ai_telemetry WHERE feature='resilience_test'"); $dbh->exec("DELETE FROM tbl_ai_usage_log WHERE user_email='rt@example.test'");
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);

<?php
/**
 * AIClient with AI_PROVIDER=auto and nothing usable (no Ollama, malformed Gemini key): it must pick the 'none' provider and
 * return the normal "not switched on" result WITHOUT any network call — never select the bad key.
 * Uses an in-memory SQLite database (needs pdo_sqlite) for the cache/rate-limit tables, so it needs no MySQL.
 *   php tests/ai/auto_provider_client_test.php
 */
declare(strict_types=1);
define('AI_PROVIDER', 'auto'); define('GEMINI_API_KEY', 'bad-key'); define('OLLAMA_MODEL', 'qwen2.5:3b-instruct');
define('OLLAMA_BASE_URL', 'http://127.0.0.1:1');   // nothing listens here: Ollama is "down"
define('AI_MAX_TOKENS', 2000); define('AI_CACHE_TTL', 60); define('AI_RATE_LIMIT', 30);
require_once __DIR__ . '/../../backend/ai/AIClient.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { echo "SKIP: pdo_sqlite not available\n"; exit(0); }
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok ? '' : "  -> $d") . "\n"; }

$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r = AIClient::resolution($db);
t('auto + no Ollama + malformed key -> none (invalid_key)', $r['provider'] === 'none' && $r['reason'] === 'invalid_key', json_encode($r));
$ai = new AIClient($db, new CacheService($db, false));
$res = $ai->call('system', [['role' => 'user', 'content' => 'hello']]);
t('call() returns a clean "not switched on" failure, no exception', $res['success'] === false && stripos((string)$res['error'], 'not switched on') !== false, json_encode($res));
t('the malformed key was never turned into a provider', $ai->lastProvider() === '');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

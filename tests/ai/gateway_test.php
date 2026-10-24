<?php
/**
 * Gateway tests: AIClient capability routing, context fitting, model-call records and structured output,
 * run against an in-memory SQLite database (a small shim rewrites the handful of MySQL-only phrases) and a fake HTTP
 * transport, so no MySQL, Ollama or network is needed.
 *   php tests/ai/gateway_test.php
 * This proves the gateway logic. It does NOT prove behaviour against a real Ollama runtime.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { echo "SKIP: pdo_sqlite is not available in this PHP.\n"; exit(0); }

require_once __DIR__ . '/../../backend/ai/AIClient.php';

/** Test-only: lets the production SQL (written for MySQL) run on SQLite. */
class GwShimPdo extends PDO {
    private function fix(string $q): string {
        $q = str_replace('DATE_SUB(NOW(), INTERVAL ? SECOND)', "datetime('now', '-' || ? || ' seconds')", $q);
        $q = str_replace('NOW()', "datetime('now')", $q);
        if (stripos($q, 'ON DUPLICATE KEY UPDATE') !== false) {
            $q = preg_replace('/^\s*INSERT INTO/i', 'INSERT OR REPLACE INTO', $q);
            $q = preg_replace('/\s*ON DUPLICATE KEY UPDATE.*$/is', '', $q);
        }
        return $q;
    }
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) { return parent::prepare($this->fix($query), $options); }
    #[\ReturnTypeWillChange]
    public function exec(string $statement) { return parent::exec($this->fix($statement)); }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, ...$args) { return parent::query($this->fix($query)); }
}

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($d !== '' && !$ok ? "   $d" : '') . "\n"; }

function freshDb(): PDO {
    $db = new GwShimPdo('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE tbl_app_meta (k TEXT PRIMARY KEY, v TEXT)");
    $db->exec("CREATE TABLE tbl_ai_usage_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_email TEXT, created_at TEXT)");
    $db->exec("CREATE TABLE tbl_ai_model_calls (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, capability TEXT, feature TEXT, provider TEXT, model TEXT, model_digest TEXT, model_size INTEGER, route_reason TEXT, success INTEGER, error_kind TEXT, latency_ms INTEGER, prompt_tokens INTEGER, completion_tokens INTEGER, context_dropped INTEGER)");
    return $db;
}

$tags = json_encode(['models' => [
    ['name' => 'qwen2.5:3b-instruct', 'digest' => 'digest-3b', 'size' => 1900000000],
    ['name' => 'qwen2.5:7b-instruct', 'digest' => 'digest-7b', 'size' => 4700000000],
]]);
$registry = new ModelRegistry('http://x', fn($u, $t) => [200, $tags]);

function chatReply(string $text): string { return json_encode(['message' => ['content' => $text], 'prompt_eval_count' => 11, 'eval_count' => 7]); }

function makeClient(PDO $db, array $cfg, $registry, array &$seen, array $replies): AIClient {
    $c = new AIClient($db, null, new OllamaProvider('qwen2.5:3b-instruct', 'http://x'));
    $c->setModelRouter(new ModelRouter($registry, $cfg));
    $i = 0;
    $c->setTransport(function (array $req, $prov, int $timeout) use (&$seen, $replies, &$i) {
        $seen[] = ['body' => json_decode($req['body'], true), 'timeout' => $timeout];
        $r = $replies[min($i, count($replies) - 1)]; $i++;
        return [200, chatReply($r), null];
    });
    return $c;
}

// 1. chat capability: routed model is used, context size is sent, a call record is written without prompt text
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct'], $registry, $seen, ['hello there']);
$registry->list(); // load, as a real request would have before recording
$r = $c->call('SYSTEM', [['role' => 'user', 'content' => 'SECRET-PROMPT-TEXT where is Sylhet?']], '', 'a@example.test', 22, 12, 'test_feature', null, 'chat');
t('capability call succeeds', !empty($r['success']) && $r['content'] === 'hello there');
t('request used the routed model', ($seen[0]['body']['model'] ?? '') === 'qwen2.5:3b-instruct');
t('request carries the context window size', ($seen[0]['body']['options']['num_ctx'] ?? 0) === 4096);
t('lastModel reports the model that answered', $c->lastModel() === 'qwen2.5:3b-instruct');
$row = $db->query('SELECT * FROM tbl_ai_model_calls')->fetch(PDO::FETCH_ASSOC);
t('a model-call record was written', $row !== false && (int)$row['success'] === 1 && $row['capability'] === 'chat' && $row['feature'] === 'test_feature');
t('record has model name, digest and size', $row['model'] === 'qwen2.5:3b-instruct' && $row['model_digest'] === 'digest-3b' && (int)$row['model_size'] === 1900000000);
t('record has token counts', (int)$row['prompt_tokens'] === 11 && (int)$row['completion_tokens'] === 7);
t('no prompt or answer text is stored anywhere in the record', !str_contains(json_encode($row), 'SECRET-PROMPT-TEXT') && !str_contains(json_encode($row), 'hello there'));

// 2. planning capability goes to the planning model and gets the longer time budget
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct', 'AI_MODEL_PLAN' => 'qwen2.5:7b-instruct'], $registry, $seen, ['plan']);
$c->call('S', [['role' => 'user', 'content' => 'plan 3 days']], '', 'a@example.test', 22, 12, 'plan', null, 'itinerary_plan');
t('itinerary_plan uses AI_MODEL_PLAN', ($seen[0]['body']['model'] ?? '') === 'qwen2.5:7b-instruct');
t('record shows the 7b digest', $db->query('SELECT model_digest FROM tbl_ai_model_calls')->fetchColumn() === 'digest-7b');

// 3. no capability: exactly the old behaviour (provider's own model, no gateway record, no num_ctx)
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:7b-instruct'], $registry, $seen, ['plain']);
$r = $c->call('S', [['role' => 'user', 'content' => 'hi']], '', 'a@example.test');
t('without a capability the provider model is used unchanged', ($seen[0]['body']['model'] ?? '') === 'qwen2.5:3b-instruct' && !isset($seen[0]['body']['options']['num_ctx']));
t('without a capability no gateway record is written', (int)$db->query('SELECT COUNT(*) FROM tbl_ai_model_calls')->fetchColumn() === 0);
$r = $c->call('S', [['role' => 'user', 'content' => 'hi']], '', 'a@example.test', 22, 12, '', null, 'not_a_capability');
t('an unknown capability is ignored, not an error', !empty($r['success']));

// 4. configured model not installed -> router falls back to the next installed model, recorded as fallback_model
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct', 'AI_MODEL_CHAT' => 'ghost:70b'], $registry, $seen, ['ok']);
$c->call('S', [['role' => 'user', 'content' => 'hi']], '', 'a@example.test', 22, 12, '', null, 'chat');
t('missing preferred model: next installed model answers', ($seen[0]['body']['model'] ?? '') === 'qwen2.5:3b-instruct');
t('fallback is recorded as fallback_model', $db->query('SELECT route_reason FROM tbl_ai_model_calls')->fetchColumn() === 'fallback_model');

// 5. nothing installed -> no HTTP call at all, clean failure for the caller's deterministic fallback
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'ghost:1'], $registry, $seen, ['never']);
$r = $c->call('S', [['role' => 'user', 'content' => 'hi']], '', 'a@example.test', 22, 12, '', null, 'chat');
t('no installed model: call fails cleanly without contacting the runtime', empty($r['success']) && $seen === [] && $r['error'] !== '');
$row = $db->query('SELECT * FROM tbl_ai_model_calls')->fetch(PDO::FETCH_ASSOC);
t('the failure is recorded with its reason', $row && (int)$row['success'] === 0 && $row['route_reason'] === 'no_model');

// 6. runtime unreachable
$db = freshDb(); $seen = [];
$downReg = new ModelRegistry('http://x', fn($u, $t) => [0, '']);
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct'], $downReg, $seen, ['never']);
$r = $c->call('S', [['role' => 'user', 'content' => 'hi']], '', 'a@example.test', 22, 12, '', null, 'chat');
t('runtime unreachable: no HTTP chat call, clean failure', empty($r['success']) && $seen === []);
t('recorded as runtime_unreachable', $db->query('SELECT route_reason FROM tbl_ai_model_calls')->fetchColumn() === 'runtime_unreachable');

// 7. context management: long history is trimmed; the system prompt and last message survive
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct', 'AI_CONTEXT_TOKENS' => '1500'], $registry, $seen, ['ok']);
$msgs = [];
for ($i = 1; $i <= 12; $i++) $msgs[] = ['role' => $i % 2 ? 'user' : 'assistant', 'content' => "old turn $i " . str_repeat('word ', 80)];
$msgs[] = ['role' => 'user', 'content' => 'FINAL QUESTION about Cox\'s Bazar'];
$r = $c->call('SYSTEM-PROMPT-MARKER', $msgs, '', 'a@example.test', 22, 12, '', null, 'chat');
$sent = $seen[0]['body']['messages'] ?? [];
t('long history is trimmed before sending', !empty($r['success']) && count($sent) < 14);
t('system prompt is always sent first and intact', ($sent[0]['role'] ?? '') === 'system' && $sent[0]['content'] === 'SYSTEM-PROMPT-MARKER');
t('last user message is always sent intact', str_contains(end($sent)['content'], 'FINAL QUESTION about Cox'));
t('trimming is recorded (context_dropped > 0)', (int)$db->query('SELECT context_dropped FROM tbl_ai_model_calls')->fetchColumn() > 0);

// 8. a single message that cannot fit is refused rather than cut
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct', 'AI_CONTEXT_TOKENS' => '1024'], $registry, $seen, ['never']);
$r = $c->call('S', [['role' => 'user', 'content' => str_repeat('a', 3900)]], '', 'a@example.test', 22, 12, '', null, 'chat');
$tooBig = empty($r['success']) && $seen === [];
t('a request too large for the window is refused, not silently cut', $tooBig);

// 9. structured output through the gateway
$schema = ['type' => 'object', 'required' => ['intent'], 'properties' => ['intent' => ['type' => 'string', 'enum' => ['search', 'book']]]];
$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct'], $registry, $seen, ['```json' . "\n" . '{"intent":"search"}' . "\n" . '```']);
$s = $c->callStructured('S', [['role' => 'user', 'content' => 'find beaches']], $schema, 'intent_classify', 'a@example.test', 'intent');
t('structured: valid answer parsed in one model call', $s['ok'] && $s['data'] === ['intent' => 'search'] && count($seen) === 1 && !$s['repaired']);
t('structured: result names the model that produced it', $s['model'] === 'qwen2.5:3b-instruct');

$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct'], $registry, $seen, ['{"intent":"buy"}', '{"intent":"book"}']);
$s = $c->callStructured('S', [['role' => 'user', 'content' => 'buy it']], $schema, 'intent_classify', 'a@example.test', 'intent');
t('structured: invalid answer repaired by exactly one extra model call', $s['ok'] && $s['data'] === ['intent' => 'book'] && count($seen) === 2 && $s['repaired']);
t('structured: the repair request names the validation problem, not secrets', str_contains(json_encode($seen[1]['body']['messages']), '$.intent'));

$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'qwen2.5:3b-instruct'], $registry, $seen, ['garbage', 'more garbage', 'even more garbage']);
$s = $c->callStructured('S', [['role' => 'user', 'content' => 'x']], $schema, 'intent_classify', 'a@example.test', 'intent');
t('structured: a model that keeps failing stops after one repair', !$s['ok'] && count($seen) === 2 && $s['error'] !== '');

$db = freshDb(); $seen = [];
$c = makeClient($db, ['OLLAMA_MODEL' => 'ghost:1'], $registry, $seen, ['never']);
$s = $c->callStructured('S', [['role' => 'user', 'content' => 'x']], $schema, 'intent_classify', 'a@example.test', 'intent');
t('structured: no model available -> ok=false, caller uses its deterministic path', !$s['ok'] && $s['error'] !== '' && $seen === []);

// 10. non-Ollama providers are never rerouted by capability
$db = freshDb(); $seen = [];
$other = new class implements AIProviderInterface {
    public function name(): string { return 'fake'; } public function model(): string { return 'fake-1'; } public function isConfigured(): bool { return true; }
    public function buildTextRequest(string $s, array $m, array $o): array { return ['url' => 'http://fake', 'headers' => [], 'body' => json_encode(['model' => 'fake-1', 'n' => count($m)])]; }
    public function buildImageRequest(string $s, string $u, string $i, string $m, array $o): array { throw new RuntimeException('no'); }
    public function parseResponse(int $c, string $b, ?string $e): array { return ['success' => true, 'content' => 'fake answer']; }
};
$c = new AIClient($db, null, $other);
$c->setModelRouter(new ModelRouter($registry, ['OLLAMA_MODEL' => 'ghost:1']));
$c->setTransport(function ($req, $p, $t) use (&$seen) { $seen[] = json_decode($req['body'], true); return [200, '{}', null]; });
$r = $c->call('S', [['role' => 'user', 'content' => 'hi']], '', 'a@example.test', 22, 12, '', null, 'chat');
t('a cloud provider is not subject to the local model router', !empty($r['success']) && ($seen[0]['model'] ?? '') === 'fake-1');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

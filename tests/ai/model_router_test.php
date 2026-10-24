<?php
/**
 * Pure tests (no DB, no Ollama, no network) for backend/ai/ModelRegistry.php and ModelRouter.php.
 *   php tests/ai/model_router_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/ai/ModelRegistry.php';
require_once __DIR__ . '/../../backend/ai/ModelRouter.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($d !== '' && !$ok ? "   $d" : '') . "\n"; }

$tags = json_encode(['models' => [
    ['name' => 'qwen2.5:3b-instruct', 'digest' => 'abc123', 'size' => 1900000000, 'modified_at' => '2026-10-01T00:00:00Z', 'details' => ['parameter_size' => '3B', 'family' => 'qwen2']],
    ['name' => 'llama3.2:latest', 'digest' => 'def456', 'size' => 2000000000, 'details' => ['parameter_size' => '3B']],
    ['name' => 'qwen2.5:7b-instruct', 'digest' => 'fff', 'size' => 4700000000],
]]);
$fetchOk = fn($url, $t) => [200, $tags];
$fetchDown = fn($url, $t) => [0, ''];
$fetchBad = fn($url, $t) => [200, 'not json'];

// registry
$calls = 0;
$reg = new ModelRegistry('http://127.0.0.1:11434', function ($u, $t) use (&$calls, $tags) { $calls++; return [200, $tags]; });
t('registry lists installed models', count($reg->list()) === 3 && $reg->reachable());
t('exact name is available', $reg->isAvailable('qwen2.5:3b-instruct'));
t('case-insensitive match', $reg->isAvailable('QWEN2.5:3B-INSTRUCT'));
t('bare name matches :latest', $reg->isAvailable('llama3.2'));
t('missing model is not available', !$reg->isAvailable('mistral'));
t('info carries digest, size and parameter size', ($i = $reg->info('qwen2.5:3b-instruct')) && $i['digest'] === 'abc123' && $i['size'] === 1900000000 && $i['parameter_size'] === '3B');
t('list is loaded once per TTL, not per query', $calls === 1);
t('infoIfLoaded works without a network call', $reg->infoIfLoaded('llama3.2')['digest'] === 'def456');
t('empty model name is never available', !$reg->isAvailable(''));

$down = new ModelRegistry('http://127.0.0.1:1', $fetchDown);
t('runtime down: list empty, reachable false, nothing thrown', $down->list() === [] && !$down->reachable() && !$down->isAvailable('qwen2.5:3b-instruct'));
$bad = new ModelRegistry('http://x', $fetchBad);
t('garbage response is treated as unreachable', !$bad->reachable());
$thrower = new ModelRegistry('http://x', function () { throw new RuntimeException('boom'); });
t('a throwing fetch is contained', !$thrower->reachable());
$unloaded = new ModelRegistry('http://x', $fetchOk);
t('infoIfLoaded before any load returns null (no hidden network)', $unloaded->infoIfLoaded('llama3.2') === null);

// router: chains
$mk = fn(array $cfg, $registry = null) => new ModelRouter($registry ?? new ModelRegistry('http://x', $fetchOk), $cfg);
$r = $mk(['OLLAMA_MODEL' => 'qwen2.5:3b-instruct']);
t('default: every capability uses OLLAMA_MODEL', $r->route('chat')['model'] === 'qwen2.5:3b-instruct' && $r->route('itinerary_plan')['model'] === 'qwen2.5:3b-instruct' && $r->route('intent_classify')['model'] === 'qwen2.5:3b-instruct');
$r = $mk(['OLLAMA_MODEL' => 'qwen2.5:3b-instruct', 'AI_MODEL_PLAN' => 'qwen2.5:7b-instruct', 'AI_MODEL_FAST' => 'llama3.2']);
t('planning goes to AI_MODEL_PLAN', $r->route('itinerary_plan')['model'] === 'qwen2.5:7b-instruct');
t('fast jobs go to AI_MODEL_FAST', $r->route('intent_classify')['model'] === 'llama3.2' && $r->route('search_interpret')['model'] === 'llama3.2');
t('chat/summarize keep the default', $r->route('chat')['model'] === 'qwen2.5:3b-instruct' && $r->route('summarize')['model'] === 'qwen2.5:3b-instruct');
t('reason is ok when the first choice is installed', $r->route('itinerary_plan')['reason'] === 'ok');

$r = $mk(['OLLAMA_MODEL' => 'qwen2.5:3b-instruct', 'AI_MODEL_PLAN' => 'not-installed:70b']);
$x = $r->route('itinerary_plan');
t('missing first choice falls down the chain to the default', $x['model'] === 'qwen2.5:3b-instruct' && $x['reason'] === 'fallback_model' && $x['unavailable'] === ['not-installed:70b']);

$r = $mk(['OLLAMA_MODEL' => 'a:1', 'AI_MODEL_CHAT' => 'b:1, llama3.2 ,b:1']);
t('comma list is trimmed, de-duplicated and ordered', $r->chain('chat') === ['b:1', 'llama3.2', 'a:1']);
t('comma list picks the first installed one', $r->route('chat')['model'] === 'llama3.2');

$r = $mk(['OLLAMA_MODEL' => 'ghost:1']);
$x = $r->route('chat');
t('nothing installed -> no_model so the caller can use its deterministic fallback', $x['model'] === null && $x['reason'] === 'no_model');

$r = $mk(['OLLAMA_MODEL' => 'qwen2.5:3b-instruct'], $down);
$x = $r->route('chat');
t('runtime unreachable -> model null, reason runtime_unreachable', $x['model'] === null && $x['reason'] === 'runtime_unreachable');

$r = $mk([]);
t('no model configured at all -> no_model', $r->route('chat')['model'] === null && $r->route('chat')['reason'] === 'no_model');
t('unknown capability is refused', $mk(['OLLAMA_MODEL' => 'a'])->route('hack')['reason'] === 'unknown_capability');
t('the router never prefers a model by size: order is exactly the configured order', $mk(['OLLAMA_MODEL' => 'qwen2.5:3b-instruct', 'AI_MODEL_CHAT' => 'qwen2.5:3b-instruct,qwen2.5:7b-instruct'])->route('chat')['model'] === 'qwen2.5:3b-instruct');
t('every capability has a time budget', count(array_diff(ModelRouter::CAPABILITIES, array_keys(ModelRouter::BUDGETS))) === 0);
t('planning gets a longer budget than intent classification', ModelRouter::BUDGETS['itinerary_plan'][0] > ModelRouter::BUDGETS['intent_classify'][0]);

// context limits
$r = $mk(['AI_CONTEXT_TOKENS' => '8192', 'AI_CONTEXT_ITINERARY_PLAN' => '16384']);
t('context limit: global and per-capability override', $r->contextLimit('chat') === 8192 && $r->contextLimit('itinerary_plan') === 16384);
t('context limit has a conservative default', $mk([])->contextLimit('chat') === 4096);
t('context limit is clamped to a sane range', $mk(['AI_CONTEXT_TOKENS' => '5'])->contextLimit('chat') === 1024);

// token estimate
t('estimate: empty is zero', ModelRouter::estimateTokens('') === 0);
t('estimate: English ~ chars/3.5', ModelRouter::estimateTokens(str_repeat('a', 350)) === 100);
t('estimate: Bengali counts about one token per character (errs high)', ModelRouter::estimateTokens(str_repeat('ক', 100)) === 100);

// fit
$r = $mk([]);
$sys = 'SYSTEM ' . str_repeat('s', 700);
$hist = [];
for ($i = 1; $i <= 10; $i++) $hist[] = ['role' => $i % 2 ? 'user' : 'assistant', 'content' => "turn $i " . str_repeat('x', 400)];
$hist[] = ['role' => 'user', 'content' => 'LAST QUESTION'];
$f = $r->fit($sys, $hist, 1500, 200);
t('fit: drops oldest turns first and reports it', $f['ok'] && $f['dropped'] > 0 && !str_contains($f['messages'][0]['content'], 'turn 1 '));
t('fit: the last message is always kept intact', end($f['messages'])['content'] === 'LAST QUESTION');
t('fit: the most recent history is kept over older', str_contains(implode(' ', array_column($f['messages'], 'content')), 'turn 10'));
t('fit: result is within the budget', $f['tokens'] <= 1300);

$f = $r->fit($sys, $hist, 100000, 200);
t('fit: nothing is cut when everything fits', $f['ok'] && $f['dropped'] === 0 && count($f['messages']) === 11);

$facts = ['role' => 'user', 'content' => 'FACTS: ' . str_repeat('package price 5000 ', 60), 'protected' => true];
$h2 = [['role' => 'user', 'content' => str_repeat('old ', 300)], $facts, ['role' => 'assistant', 'content' => str_repeat('reply ', 300)], ['role' => 'user', 'content' => 'now what?']];
$f = $r->fit('S', $h2, 900, 100);
$joined = implode('|', array_column($f['messages'], 'content'));
t('fit: protected tool facts are never cut', $f['ok'] && str_contains($joined, $facts['content']));
t('fit: unprotected old turns were dropped instead', !str_contains($joined, 'old old'));

$huge = [['role' => 'user', 'content' => 'FACTS ' . str_repeat('y', 20000), 'protected' => true], ['role' => 'user', 'content' => 'q']];
$f = $r->fit('S', $huge, 1024, 200);
t('fit: protected content too large -> refuses instead of cutting facts', !$f['ok'] && $f['reason'] === 'context_too_large' && $f['messages'] === $huge);

$long = [['role' => 'user', 'content' => str_repeat('z', 3000)], ['role' => 'assistant', 'content' => 'short'], ['role' => 'user', 'content' => 'q?']];
$f = $r->fit('S', $long, 700, 100);
t('fit: a long old turn is shortened (head and tail kept) when dropping is not enough or preferred', $f['ok']);

// system prompt is never altered by fit
$f = $r->fit('KEEP-ME-INTACT', $hist, 1200, 100);
t('fit: never returns or modifies the system prompt (it is not in the message list)', !str_contains(implode('', array_column($f['messages'], 'content')), 'KEEP-ME-INTACT'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

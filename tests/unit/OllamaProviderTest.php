<?php
/**
 * Standalone (no DB) test for OllamaProvider request building / response parsing.
 * Run: php tests/unit/OllamaProviderTest.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/ai/providers/OllamaProvider.php';

$fail = 0;
function check(string $name, bool $ok): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $name . "\n";
    if (!$ok) { $fail++; }
}

$p = new OllamaProvider('qwen2.5:3b-instruct', 'http://127.0.0.1:11434/');
check('name/model', $p->name() === 'ollama' && $p->model() === 'qwen2.5:3b-instruct');
check('configured', $p->isConfigured());
check('unconfigured when model empty', !(new OllamaProvider(''))->isConfigured());

$req = $p->buildTextRequest('SYS', [['role' => 'user', 'content' => 'হ্যালো'], ['role' => 'bogus', 'content' => 'x']], ['maxTokens' => 256]);
$body = json_decode($req['body'], true);
check('url has no double slash', $req['url'] === 'http://127.0.0.1:11434/api/chat');
check('stream disabled', $body['stream'] === false);
check('system first', $body['messages'][0] === ['role' => 'system', 'content' => 'SYS']);
check('unknown role coerced to user', $body['messages'][2]['role'] === 'user');
check('bengali preserved', $body['messages'][1]['content'] === 'হ্যালো');
check('num_predict honoured', $body['options']['num_predict'] === 256);
check('no auth header leaked', !preg_grep('/authorization/i', $req['headers']));

$ok = $p->parseResponse(200, json_encode(['message' => ['content' => 'Hi'], 'prompt_eval_count' => 10, 'eval_count' => 5]), null);
check('parse success + tokens', $ok['success'] && $ok['content'] === 'Hi' && $ok['totalTokens'] === 15);
check('empty content fails', !$p->parseResponse(200, json_encode(['message' => ['content' => '  ']]), null)['success']);
$off = $p->parseResponse(0, '', 'Connection refused');
check('offline -> not retriable, safe message', !$off['success'] && $off['retriable'] === false && strpos($off['error'], '127.0.0.1') === false);
check('404 model missing', !$p->parseResponse(404, '{"error":"model not found"}', null)['success']);
check('500 retriable', $p->parseResponse(500, '{}', null)['retriable'] === true);
check('garbage body does not crash', !$p->parseResponse(200, 'not json', null)['success']);
try { $p->buildImageRequest('', '', '', '', []); check('image throws', false); } catch (RuntimeException $e) { check('image throws', true); }

exit($fail ? 1 : 0);

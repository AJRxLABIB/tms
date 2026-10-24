<?php
/**
 * Pure tests for backend/ApiSupport.php — no database, no web server.
 *   php tests/api/contract_test.php
 * The idempotency store itself (INSERT IGNORE / replay) needs MySQL and is covered by the manual steps in TESTING.md;
 * here we cover everything that decides behaviour before and around it.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/ApiSupport.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}

// ── request ids
t('safe client id is accepted', ApiSupport::sanitizeRequestId('client-req-12345') === 'client-req-12345');
t('too short id rejected', ApiSupport::sanitizeRequestId('abc') === null);
t('id with spaces/newline (log injection) rejected', ApiSupport::sanitizeRequestId("abcdefgh\nX-Evil: 1") === null && ApiSupport::sanitizeRequestId('abc def ghi') === null);
t('id longer than 64 rejected', ApiSupport::sanitizeRequestId(str_repeat('a', 65)) === null);
t('null / empty id -> null', ApiSupport::sanitizeRequestId(null) === null && ApiSupport::sanitizeRequestId('') === null);
$g1 = ApiSupport::generateRequestId(); $g2 = ApiSupport::generateRequestId();
t('generated ids are unique and safe', $g1 !== $g2 && ApiSupport::sanitizeRequestId($g1) === $g1);
ApiSupport::reset();
t('begin() adopts a safe X-Request-Id', ApiSupport::begin(['HTTP_X_REQUEST_ID' => 'trace-abcdef01']) === 'trace-abcdef01' && ApiSupport::requestId() === 'trace-abcdef01');
t('begin() is stable once set', ApiSupport::begin(['HTTP_X_REQUEST_ID' => 'other-abcdef02']) === 'trace-abcdef01');
ApiSupport::reset();
t('begin() replaces an unsafe id', preg_match('/^req_[0-9a-f]{16}$/', ApiSupport::begin(['HTTP_X_REQUEST_ID' => "bad id\r\n"])) === 1);

// ── errors
ApiSupport::reset(); ApiSupport::begin(['HTTP_X_REQUEST_ID' => 'trace-errors-01']);
$expected = [400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
             409 => 'conflict', 413 => 'payload_too_large', 422 => 'unprocessable', 429 => 'rate_limited', 500 => 'server_error', 503 => 'server_error', 418 => 'error'];
$allOk = true; foreach ($expected as $st => $code) { if (ApiSupport::codeForStatus($st) !== $code) { $allOk = false; echo "  status $st -> " . ApiSupport::codeForStatus($st) . "\n"; } }
t('status -> code map', $allOk);
$env = ApiSupport::errorEnvelope(404, 'Package not found.');
t('envelope shape {error:{code,message,request_id}}', $env === ['error' => ['code' => 'not_found', 'message' => 'Package not found.', 'request_id' => 'trace-errors-01']]);
$leg = ApiSupport::legacyErrorBody(429, 'Slow down.');
t('legacy body keeps success:false and string error', $leg['success'] === false && $leg['error'] === 'Slow down.');
t('legacy body adds error_code, request_id, error_detail', $leg['error_code'] === 'rate_limited' && $leg['request_id'] === 'trace-errors-01' && $leg['error_detail']['message'] === 'Slow down.');
t('custom code overrides the default', ApiSupport::legacyErrorBody(422, 'x', 'idempotency_key_reused')['error_code'] === 'idempotency_key_reused');

// ── pagination
t('defaults', ApiSupport::pageParams([], 12, 50) === ['page' => 1, 'per_page' => 12, 'offset' => 0]);
t('limit is accepted as per_page alias', ApiSupport::pageParams(['limit' => '20'], 12, 50)['per_page'] === 20);
t('per_page wins over limit', ApiSupport::pageParams(['per_page' => '5', 'limit' => '20'], 12, 50)['per_page'] === 5);
t('per_page clamped to max', ApiSupport::pageParams(['per_page' => '9999'], 12, 50)['per_page'] === 50);
t('zero / negative / junk fall back safely', ApiSupport::pageParams(['page' => '-3', 'per_page' => '0'], 12, 50) === ['page' => 1, 'per_page' => 12, 'offset' => 0]
    && ApiSupport::pageParams(['page' => 'abc', 'per_page' => 'x'], 12, 50)['page'] === 1);
t('offset math', ApiSupport::pageParams(['page' => '3', 'per_page' => '10'], 12, 50)['offset'] === 20);
$m = ApiSupport::pageMeta(95, 2, 10, '/api/v1/', ['action' => 'packages', 'search' => 'cox', 'page' => '2', 'limit' => '10']);
t('meta numbers (95 items, 10/page => 10 pages)', $m['total'] === 95 && $m['page'] === 2 && $m['per_page'] === 10 && $m['total_pages'] === 10);
parse_str((string)parse_url($m['links']['next'], PHP_URL_QUERY), $q);
t('next link keeps filters and advances the page', $q['action'] === 'packages' && $q['search'] === 'cox' && $q['page'] === '3' && $q['per_page'] === '10' && !isset($q['limit']));
t('prev/self/first/last', str_contains((string)$m['links']['prev'], 'page=1') && str_contains($m['links']['self'], 'page=2') && str_contains($m['links']['first'], 'page=1') && str_contains($m['links']['last'], 'page=10'));
t('first page has no prev; last page has no next', ApiSupport::pageMeta(95, 1, 10, '/a', [])['links']['prev'] === null && ApiSupport::pageMeta(95, 10, 10, '/a', [])['links']['next'] === null);
$empty = ApiSupport::pageMeta(0, 1, 10, '/a', []);
t('empty result: total_pages 0, no next/prev, last link still valid', $empty['total_pages'] === 0 && $empty['links']['next'] === null && $empty['links']['prev'] === null && str_contains($empty['links']['last'], 'page=1'));
t('page beyond the end: prev points at the real last page', str_contains((string)ApiSupport::pageMeta(25, 9, 10, '/a', [])['links']['prev'], 'page=3'));
t('links are URL-encoded', str_contains(ApiSupport::pageMeta(5, 1, 2, '/a', ['search' => 'a&b=c d'])['links']['self'], 'search=a%26b%3Dc+d'));

// ── idempotency keys + request hash
t('uuid is a valid key', ApiSupport::validIdempotencyKey('123e4567-e89b-12d3-a456-426614174000'));
t('ulid / colon / dot keys valid', ApiSupport::validIdempotencyKey('01HZXK3F9Q:retry.1'));
t('short, long, spaced, injected keys invalid',
    !ApiSupport::validIdempotencyKey('short') && !ApiSupport::validIdempotencyKey(str_repeat('a', 129))
    && !ApiSupport::validIdempotencyKey('has space 123') && !ApiSupport::validIdempotencyKey("abc12345\r\nX: y") && !ApiSupport::validIdempotencyKey(null));
$h1 = ApiSupport::requestHash('book', ['package_id' => '5', 'persons' => '2', 'from_date' => '2031-01-01']);
$h2 = ApiSupport::requestHash('book', ['from_date' => '2031-01-01', 'persons' => '2', 'package_id' => '5']);
t('hash ignores field order', $h1 === $h2 && strlen($h1) === 64);
t('hash differs when a value changes', $h1 !== ApiSupport::requestHash('book', ['package_id' => '5', 'persons' => '3', 'from_date' => '2031-01-01']));
t('hash differs per endpoint', $h1 !== ApiSupport::requestHash('cancel_booking', ['package_id' => '5', 'persons' => '2', 'from_date' => '2031-01-01']));
t('hash ignores action and csrf_token', $h1 === ApiSupport::requestHash('book', ['action' => 'book', 'csrf_token' => 'zzz', 'package_id' => '5', 'persons' => '2', 'from_date' => '2031-01-01']));
t('int 5 and string "5" hash alike (form posts are strings)', ApiSupport::requestHash('b', ['a' => 5]) === ApiSupport::requestHash('b', ['a' => '5']));

// idempotencyBegin paths that never reach the database
$pdoUnused = (extension_loaded('pdo_sqlite')) ? new PDO('sqlite::memory:') : null;
$sent = [];
$respond = function (int $s, array $p) use (&$sent): void { $sent[] = [$s, $p]; };
if ($pdoUnused) {
    ApiSupport::reset(); ApiSupport::begin([]);
    ApiSupport::idempotencyBegin($pdoUnused, 'a@b.test', 'book', ['x' => 1], [], $respond);
    t('no Idempotency-Key header: no-op, nothing sent, not active', $sent === [] && !ApiSupport::idempotencyActive());
    ApiSupport::idempotencyBegin($pdoUnused, 'a@b.test', 'book', ['x' => 1], ['HTTP_IDEMPOTENCY_KEY' => 'bad key!'], $respond);
    t('malformed key => 400 invalid_idempotency_key', count($sent) === 1 && $sent[0][0] === 400 && $sent[0][1]['error_code'] === 'invalid_idempotency_key' && $sent[0][1]['success'] === false);
    $sent = [];
    $prevLog = ini_set('error_log', sys_get_temp_dir() . '/tms_contract_test.log');
    ApiSupport::idempotencyBegin($pdoUnused, 'a@b.test', 'book', ['x' => 1], ['HTTP_IDEMPOTENCY_KEY' => 'valid-key-0001'], $respond);   // table missing -> must not block
    ini_set('error_log', (string)$prevLog);
    t('store unavailable (no table): request is processed normally, nothing sent', $sent === [] && !ApiSupport::idempotencyActive());
    ApiSupport::idempotencyFinish(200, ['success' => true]);
    t('finish without an active key is a harmless no-op', true);
} else {
    echo "SKIP idempotencyBegin pure paths (pdo_sqlite not available in this PHP)\n";
}

// ── rate-limit headers
$rl = ApiSupport::rateLimitHeaders(120, 20, 60, 1000000020);
t('rate headers: limit/remaining', $rl['X-RateLimit-Limit'] === '120' && $rl['X-RateLimit-Remaining'] === '100' && !isset($rl['Retry-After']));
t('rate headers: reset is seconds left in the window', $rl['X-RateLimit-Reset'] === (string)(60 - (1000000020 % 60)));
$over = ApiSupport::rateLimitHeaders(120, 121, 60, 1000000000);
t('over the limit: remaining 0 and Retry-After set', $over['X-RateLimit-Remaining'] === '0' && isset($over['Retry-After']) && $over['Retry-After'] === $over['X-RateLimit-Reset']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

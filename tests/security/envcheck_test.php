<?php
/**
 * Pure tests (no database, no network, no .env) for backend/EnvCheck.php and the pure parts of
 * backend/TwoFactorRecovery.php.   php tests/security/envcheck_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/EnvCheck.php';
require_once __DIR__ . '/../../backend/TwoFactorRecovery.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 70) . $d . "\n"; }
function levelOf(array $res, string $key): string { foreach ($res as $r) if ($r['key'] === $key && $r['level'] !== 'PASS') return $r['level']; foreach ($res as $r) if ($r['key'] === $key) return 'PASS'; return 'NONE'; }

$goodProd = [
    'APP_ENV' => 'production', 'APP_URL' => 'https://tms.example-travel.com', 'APP_DEBUG' => 'false',
    'DB_HOST' => 'db.internal', 'DB_NAME' => 'tms', 'DB_USER' => 'tms_app', 'DB_PASS' => 'Zr7!pQ2vLw9#kXc4',
    'TMS_2FA_KEY' => bin2hex(random_bytes(16)), 'WALLET_ENCRYPTION_KEY' => bin2hex(random_bytes(32)),
    'TMS_AUTO_SCHEMA' => '0', 'PAYMENT_PROVIDER' => 'sslcommerz', 'AI_PROVIDER' => 'ollama',
    'OLLAMA_BASE_URL' => 'http://127.0.0.1:11434', 'HEALTH_TOKEN' => bin2hex(random_bytes(12)),
    'SMTP_HOST' => 'smtp.mail.test', 'TMS_BOOKING_HOLD_MINUTES' => '60',
];
$res = EnvCheck::run($goodProd); $sum = EnvCheck::summarize($res);
t('a correct production config has 0 failures', $sum['fail'] === 0, json_encode($sum));
t('a correct production config has 0 warnings', $sum['warn'] === 0, json_encode($sum));

$cases = [
    ['missing APP_URL fails in production',          ['APP_URL' => ''],                          'APP_URL',   'FAIL'],
    ['http APP_URL fails in production',             ['APP_URL' => 'http://tms.example.com'],    'APP_URL',   'FAIL'],
    ['localhost APP_URL fails in production',        ['APP_URL' => 'https://localhost'],         'APP_URL',   'FAIL'],
    ['APP_DEBUG=true fails in production',           ['APP_DEBUG' => 'true'],                    'APP_DEBUG', 'FAIL'],
    ['root DB user fails in production',             ['DB_USER' => 'root'],                      'DB_USER',   'FAIL'],
    ['empty DB password fails in production',        ['DB_PASS' => ''],                          'DB_PASS',   'FAIL'],
    ['placeholder DB password fails',                ['DB_PASS' => 'changeme'],                  'DB_PASS',   'FAIL'],
    ['<angle> placeholder DB password fails',        ['DB_PASS' => '<your-db-password>'],        'DB_PASS',   'FAIL'],
    ['short 2FA key fails',                          ['TMS_2FA_KEY' => 'short'],                 'TMS_2FA_KEY', 'FAIL'],
    ['wallet key wrong shape fails',                 ['WALLET_ENCRYPTION_KEY' => 'abc123'],      'WALLET_ENCRYPTION_KEY', 'FAIL'],
    ['sandbox payments forced on fails',             ['TMS_ALLOW_SANDBOX_PAYMENTS' => '1'],      'TMS_ALLOW_SANDBOX_PAYMENTS', 'FAIL'],
    ['auto schema left on only warns',               ['TMS_AUTO_SCHEMA' => ''],                  'TMS_AUTO_SCHEMA', 'WARN'],
    ['sandbox payment provider only warns',          ['PAYMENT_PROVIDER' => 'sandbox'],          'PAYMENT_PROVIDER', 'WARN'],
    ['external AI provider warns (data leaves)',     ['AI_PROVIDER' => 'gemini'],                'AI_PROVIDER', 'WARN'],
    ['public Ollama address warns',                  ['OLLAMA_BASE_URL' => 'http://203.0.113.9:11434'], 'OLLAMA_BASE_URL', 'WARN'],
    ['missing SMTP host warns',                      ['SMTP_HOST' => ''],                        'SMTP_HOST', 'WARN'],
    ['short health token warns',                     ['HEALTH_TOKEN' => 'abc'],                  'HEALTH_TOKEN', 'WARN'],
];
foreach ($cases as [$name, $over, $key, $want]) {
    $got = levelOf(EnvCheck::run(array_merge($goodProd, $over)), $key);
    t($name, $got === $want, "got $got");
}

// Development: same problems are only warnings.
$dev = array_merge($goodProd, ['APP_ENV' => 'development', 'APP_DEBUG' => 'true', 'DB_USER' => 'root', 'DB_PASS' => '']);
$d = EnvCheck::summarize(EnvCheck::run($dev));
t('in development nothing is a hard failure', $d['fail'] === 0 && $d['warn'] > 0, json_encode($d));

// Secrets are never echoed.
$secretEnv = array_merge($goodProd, ['DB_PASS' => 'SuperSecretValue-9f8e7d', 'TMS_2FA_KEY' => 'AnotherSecretValue-1a2b3c4d5e', 'GEMINI_API_KEY' => 'notAIzaButSecret-ZZZ', 'HEALTH_TOKEN' => 'tok-ABCDEF123456789']);
$text = json_encode(EnvCheck::run($secretEnv));
foreach (['SuperSecretValue-9f8e7d', 'AnotherSecretValue-1a2b3c4d5e', 'notAIzaButSecret-ZZZ', 'tok-ABCDEF123456789'] as $secret) {
    t('output never contains a secret value (' . substr($secret, 0, 6) . '…)', strpos($text, $secret) === false);
}
t('unset AI provider with no key defaults to local (self-hosted PASS)', levelOf(EnvCheck::run(array_merge($goodProd, ['AI_PROVIDER' => ''])), 'AI_PROVIDER') === 'PASS');
t('unset (auto) AI provider with a VALID-shaped Gemini key is reported as a possible external fallback', levelOf(EnvCheck::run(array_merge($goodProd, ['AI_PROVIDER' => '', 'GEMINI_API_KEY' => 'AIza' . str_repeat('a', 35)])), 'AI_PROVIDER') === 'WARN');

// ── 2FA recovery codes (pure parts) ──
$codes = TwoFactorRecovery::generate(8);
t('generates 8 distinct codes', count($codes) === 8 && count(array_unique($codes)) === 8);
$allOk = true; foreach ($codes as $c) { if (!preg_match('/^[' . TwoFactorRecovery::ALPHABET . ']{10}$/', $c)) $allOk = false; }
t('every code is 10 characters from the unambiguous alphabet', $allOk);
t('alphabet has no look-alike characters (0 O 1 I)', strpbrk(TwoFactorRecovery::ALPHABET, '01OI') === false);
$c0 = $codes[0]; $fmt = TwoFactorRecovery::format($c0);
t('format puts a dash in the middle', preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $fmt) === 1, $fmt);
t('normalize accepts lowercase, dash and spaces', TwoFactorRecovery::normalize(strtolower(substr($c0, 0, 5) . ' - ' . substr($c0, 5))) === $c0);
t('a recovery code is recognised as one', TwoFactorRecovery::looksLikeRecovery($fmt));
t('a 6-digit authenticator code is NOT a recovery code', !TwoFactorRecovery::looksLikeRecovery('123456'));
t('a 10-digit number is NOT a recovery code', !TwoFactorRecovery::looksLikeRecovery('1234567890'));
t('garbage/empty is NOT a recovery code', !TwoFactorRecovery::looksLikeRecovery('') && !TwoFactorRecovery::looksLikeRecovery('abc'));
$h = TwoFactorRecovery::hash($c0);
t('hash verifies the normalised code', password_verify(TwoFactorRecovery::normalize($fmt), $h));
t('hash rejects a different code', !password_verify($codes[1], $h));
t('hash does not contain the plain code', strpos($h, $c0) === false);
// A throwaway DB is not available here, so consume() against missing tables must fail closed, never throw.
$noDb = new class extends PDO { public function __construct() {} public function inTransaction(): bool { return false; } public function beginTransaction(): bool { throw new PDOException('no table'); } };
t('consume() fails closed (false) when the table is unavailable', TwoFactorRecovery::consume($noDb, 1, $fmt) === false);
t('remaining() is 0 when the table is unavailable', TwoFactorRecovery::remaining($noDb, 1) === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);

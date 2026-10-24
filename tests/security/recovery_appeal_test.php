<?php
/**
 * Pure tests (no database) for the table selection in backend/TwoFactorRecovery.php (admin vs customer codes) and the
 * customer-facing wording in backend/services/FraudCases.php.   php tests/security/recovery_appeal_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/TwoFactorRecovery.php';
require_once __DIR__ . '/../../backend/services/FraudCases.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 72) . $d . "\n"; }

// Table whitelist: only the two known tables can ever reach SQL.
t('default table is the customer table', TwoFactorRecovery::table(TwoFactorRecovery::TABLE_CUSTOMER) === 'tbl_2fa_recovery_codes');
t('admin table is selected only by its exact name', TwoFactorRecovery::table(TwoFactorRecovery::TABLE_ADMIN) === 'tbl_admin_2fa_recovery_codes');
t('unknown / injected table name falls back to the customer table', TwoFactorRecovery::table('admin; DROP TABLE admin') === 'tbl_2fa_recovery_codes');
t('empty table name falls back to the customer table', TwoFactorRecovery::table('') === 'tbl_2fa_recovery_codes');
t('admin and customer tables differ', TwoFactorRecovery::TABLE_ADMIN !== TwoFactorRecovery::TABLE_CUSTOMER);

// Admin login accepts a recovery code in the same field as a TOTP code: classification must not confuse them.
t('6-digit TOTP code is not treated as a recovery code', !TwoFactorRecovery::looksLikeRecovery('123456'));
$c = TwoFactorRecovery::generate(1)[0];
t('a generated code is recognised, with or without the dash', TwoFactorRecovery::looksLikeRecovery($c) && TwoFactorRecovery::looksLikeRecovery(TwoFactorRecovery::format($c)));
t('lower-case with spaces normalises to the same code', TwoFactorRecovery::normalize(strtolower(substr($c, 0, 5)) . ' - ' . substr($c, 5)) === $c);
t('hash verifies the normalised code and not another', password_verify($c, TwoFactorRecovery::hash($c)) && !password_verify(TwoFactorRecovery::generate(1)[0], TwoFactorRecovery::hash($c)));
t('a 10-digit number is not a recovery code', !TwoFactorRecovery::looksLikeRecovery('1234567890'));

// Customer wording never carries scores, signals or evidence.
$labels = [
    FraudCases::customerStatusLabel('confirmed', null), FraudCases::customerStatusLabel('open', null),
    FraudCases::customerStatusLabel('confirmed', 'open'), FraudCases::customerStatusLabel('confirmed', 'upheld'),
    FraudCases::customerStatusLabel('confirmed', 'dismissed'),
];
t('appeal status wording is distinct per outcome', count(array_unique($labels)) === 5);
$leak = false;
foreach ($labels as $l) if (preg_match('/score|signal|evidence|risk|fraud|coupon|loyalty|payment attempt/i', $l)) $leak = true;
t('customer wording leaks no score / signal / evidence terms', !$leak);
t('open appeal is shown as waiting', stripos(FraudCases::customerStatusLabel('confirmed', 'open'), 'waiting') !== false);

// Appeal length rule lives in fileAppeal before any DB access: short/long messages are refused without touching the database.
$noDb = new class extends PDO { public function __construct() {} public function prepare(string $q, array $o = []): PDOStatement|false { throw new RuntimeException('db touched'); } };
t('appeal under 10 characters is refused before any query', FraudCases::fileAppeal($noDb, 1, 'a@b.test', 'too short') !== null);
t('appeal over 2000 characters is refused before any query', FraudCases::fileAppeal($noDb, 1, 'a@b.test', str_repeat('x', 2001)) !== null);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

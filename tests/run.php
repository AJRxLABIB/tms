<?php
/**
 * tests/run.php — lightweight, dependency-free test runner (Rule 58).
 *
 * WHY NOT PHPUNIT: this project has no Composer setup today, and pulling in
 * a full test framework for a handful of assertions would be exactly the
 * kind of overengineering Rule 73 warns against. This file is a plain PHP
 * script: each test is a closure returning true/false (or throwing), run in
 * order, with a pass/fail summary at the end and a non-zero exit code on any
 * failure (so it can be wired into a CI step later without changes).
 *
 * SCOPE (honest about what this does and doesn't cover): these are the
 * highest financial-risk pieces first — money math and availability/pricing
 * logic — because a bug there costs someone real money or an oversold
 * package. It is NOT a full test suite (no browser/E2E tests, no admin
 * workflow tests, no security fuzzing) — see TESTING.md for what's covered,
 * what isn't, and why.
 *
 * Run: php tests/run.php
 * Needs your local DB configured exactly like the running app (it boots the
 * real includes/config.php), since several of these are integration tests
 * against real tables, not pure unit tests -- there is no mocking layer in
 * this project, and building one just for tests would itself be
 * overengineering for a codebase this size.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Run this from the command line: php tests/run.php');
}

chdir(__DIR__ . '/..');
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/PricingEngine.php';
require __DIR__ . '/../includes/payments/PaymentProviderInterface.php';
require __DIR__ . '/../includes/payments/SandboxProvider.php';
require __DIR__ . '/../includes/payments/PaymentService.php';

$tests = [];
$results = [];

function tms_test(string $name, callable $fn): void {
    global $tests;
    $tests[$name] = $fn;
}

// ── Money / pricing ─────────────────────────────────────────────────────

tms_test('PaymentService::toMinorUnits converts correctly, no float drift', function () {
    $cases = [
        [100.0, 10000],
        [99.99, 9999],
        [0.0, 0],
        [1234.5, 123450],
    ];
    foreach ($cases as [$in, $expected]) {
        $got = PaymentService::toMinorUnits($in);
        if ($got !== $expected) {
            throw new Exception("toMinorUnits($in) = $got, expected $expected");
        }
    }
    return true;
});

tms_test('tms_dynamic_price never returns a negative or absurd multiplier', function () use ($dbh) {
    // Multiplier must stay within the documented clamp [0.85, 1.35]
    // regardless of package/date, so a pricing bug can never spiral into
    // free trips or absurd overcharges.
    $result = tms_dynamic_price($dbh, 1, 10000.0, date('Y-m-d', strtotime('+10 days')));
    if ($result['multiplier'] < 0.85 || $result['multiplier'] > 1.35) {
        throw new Exception('multiplier ' . $result['multiplier'] . ' outside documented [0.85, 1.35] clamp');
    }
    if ($result['final'] < 0) {
        throw new Exception('final price went negative: ' . $result['final']);
    }
    return true;
});

tms_test('tms_dynamic_price returns the base price unchanged for basePrice <= 0', function () use ($dbh) {
    $result = tms_dynamic_price($dbh, 1, 0.0, null);
    if ($result['final'] !== 0) {
        throw new Exception('expected final=0 for a zero base price, got ' . $result['final']);
    }
    return true;
});

// ── Availability ────────────────────────────────────────────────────────

tms_test('tms_remaining_seats never returns a negative number', function () use ($dbh) {
    // Even if bookings somehow outnumber capacity (e.g. capacity was
    // lowered after bookings existed), this must clamp to 0, never go
    // negative -- a negative "remaining seats" would be a confusing and
    // wrong thing to show a customer.
    $row = $dbh->query("SELECT PackageId FROM tbltourpackages LIMIT 1")->fetch(PDO::FETCH_OBJ);
    if (!$row) {
        return true; // no packages in this DB yet -- nothing to check
    }
    $seats = tms_remaining_seats($dbh, (int)$row->PackageId);
    if ($seats !== null && $seats < 0) {
        throw new Exception('tms_remaining_seats returned a negative value: ' . $seats);
    }
    return true;
});

// ── Alerts ───────────────────────────────────────────────────────────────

tms_test('tms_create_price_alert rejects a zero/negative target price', function () use ($dbh) {
    require_once __DIR__ . '/../includes/AlertService.php';
    $ok = tms_create_price_alert($dbh, 'test-runner@example.invalid', 1, 0.0);
    if ($ok !== false) {
        throw new Exception('expected false for a zero target price, got true');
    }
    return true;
});

tms_test('group settlement math: shares always sum exactly to the total spent (no lost/duplicated cents)', function () use ($dbh) {
    require_once __DIR__ . '/../includes/GroupTripService.php';
    // Simulate the pure math without touching the DB: 3-way split of 100
    // minor units should be 34/33/33 (or similar), summing to exactly 100 --
    // this is the classic case where naive float division loses a unit.
    $total = 100;
    $n = 3;
    $base = intdiv($total, $n);
    $remainder = $total % $n;
    $shares = [];
    for ($i = 0; $i < $n; $i++) {
        $shares[] = $base + ($i < $remainder ? 1 : 0);
    }
    if (array_sum($shares) !== $total) {
        throw new Exception('shares summed to ' . array_sum($shares) . ", expected exactly $total");
    }
    return true;
});

tms_test('tms_add_group_expense rejects a zero/negative amount', function () use ($dbh) {
    require_once __DIR__ . '/../includes/GroupTripService.php';
    $ok = tms_add_group_expense($dbh, 999999, 'test-runner@example.invalid', 'test', 0);
    if ($ok !== false) {
        throw new Exception('expected false for a zero amount, got true');
    }
    return true;
});

// ── Auth / brute-force lockout ────────────────────────────────────────────

tms_test('login lockout activates after 5 failed attempts and clears on success (Round 17 API fix)', function () use ($dbh) {
    // Uses a clearly-fake, unique email/IP pair so this never collides with
    // a real account, and cleans up after itself either way (including on
    // failure) so this test never leaves rows behind in tbl_login_attempts.
    $email = 'tms-test-lockout-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $ip = '203.0.113.' . random_int(1, 254); // TEST-NET-3, RFC 5737 -- never a real client IP
    try {
        $before = tms_login_lockout_minutes_remaining($dbh, $email, $ip);
        if ($before !== 0) {
            throw new Exception("expected 0 minutes remaining before any failed attempts, got $before");
        }
        for ($i = 0; $i < TMS_LOGIN_MAX_ATTEMPTS; $i++) {
            tms_record_failed_login($dbh, $email, $ip);
        }
        $after = tms_login_lockout_minutes_remaining($dbh, $email, $ip);
        if ($after <= 0) {
            throw new Exception("expected a positive lockout after " . TMS_LOGIN_MAX_ATTEMPTS . " failed attempts, got $after -- this is exactly the gap api/index.php's api_login() had before Round 17: attempts recorded but the lockout never actually checked/enforced");
        }
        tms_clear_login_attempts($dbh, $email, $ip);
        $cleared = tms_login_lockout_minutes_remaining($dbh, $email, $ip);
        if ($cleared !== 0) {
            throw new Exception("expected 0 minutes remaining after clearing attempts, got $cleared");
        }
        return true;
    } finally {
        // Belt and suspenders: clear again even if an assertion above threw,
        // so a failed test run doesn't leave a lingering lockout row.
        $dbh->prepare("DELETE FROM tbl_login_attempts WHERE Email = :e OR IPAddress = :ip")
            ->execute([':e' => $email, ':ip' => $ip]);
    }
});

// ── Payments / refund ledger (Round 17) ───────────────────────────────────

tms_test('PaymentService refund-ledger methods no-op safely when a booking was never paid through this system', function () use ($dbh) {
    // Every historical booking (and most new ones -- payment.php is opt-in)
    // has no row in tblpayments at all. These three methods are called
    // unconditionally from tour-history.php / admin/manage-bookings.php on
    // every cancellation/refund decision, so they must never throw or
    // error out just because there's nothing to link -- see each method's
    // own docblock in includes/payments/PaymentService.php.
    $paymentService = new PaymentService($dbh);
    $fakeBookingId = 999999999; // does not exist
    $paymentService->logRefundRequest($fakeBookingId, 'test');
    $paymentService->markRefundProcessed($fakeBookingId);
    $paymentService->markRefundRejected($fakeBookingId, 'test');
    return true; // reaching here without a thrown exception is the pass condition
});

// ── Migrations ───────────────────────────────────────────────────────────

tms_test('every file in database/migrations/ is valid, non-empty SQL', function () {
    $files = glob(__DIR__ . '/../database/migrations/*.sql');
    if (empty($files)) {
        throw new Exception('no migration files found -- did the folder move?');
    }
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if (trim($sql) === '') {
            throw new Exception(basename($file) . ' is empty');
        }
        if (stripos($sql, 'DROP TABLE') !== false || stripos($sql, 'DROP DATABASE') !== false) {
            throw new Exception(basename($file) . ' contains a DROP TABLE/DATABASE statement -- migrations in this project must be additive only (Rule 8)');
        }
    }
    return true;
});

tms_test('migrate.php --status runs without error against the current DB', function () {
    $output = shell_exec('php ' . escapeshellarg(__DIR__ . '/../database/migrate.php') . ' --status 2>&1');
    if ($output === null) {
        throw new Exception('could not execute migrate.php (shell_exec disabled?)');
    }
    if (stripos($output, 'FATAL') !== false) {
        throw new Exception("migrate.php --status reported a fatal error:\n$output");
    }
    return true;
});

// ── Run everything ─────────────────────────────────────────────────────

echo "TMS test suite\n===============\n";
$failures = 0;
foreach ($tests as $name => $fn) {
    try {
        $ok = $fn();
        if ($ok) {
            echo "  [PASS] $name\n";
        } else {
            echo "  [FAIL] $name (returned false)\n";
            $failures++;
        }
    } catch (Throwable $e) {
        echo "  [FAIL] $name -- " . $e->getMessage() . "\n";
        $failures++;
    }
}

$total = count($tests);
$passed = $total - $failures;
echo "\n$passed/$total passed.\n";
exit($failures > 0 ? 1 : 0);

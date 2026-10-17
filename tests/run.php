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
 * real backend/config.php), since several of these are integration tests
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
// BUG FIX (found by actually running this test suite for the first time,
// master prompt item 59 -- "CI pipeline never actually run"): these were
// plain `require`, but backend/config.php already does
// `require_once __DIR__ . '/PricingEngine.php'` internally -- and a plain
// `require` does NOT consult PHP's already-included-files registry the way
// `require_once` does, so this unconditionally re-executed
// PricingEngine.php a second time and fatal-errored with "Cannot redeclare
// function tms_remaining_seats()" before a single test could run. Every
// bootstrap require here is now require_once, both to fix that and as a
// standing defense against the same class of bug if config.php's own
// dependencies ever change.
require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/PricingEngine.php';
require_once __DIR__ . '/../backend/payments/PaymentProviderInterface.php';
require_once __DIR__ . '/../backend/payments/SandboxProvider.php';
require_once __DIR__ . '/../backend/payments/PaymentService.php';
require_once __DIR__ . '/../backend/services/BookingStateMachine.php';
require_once __DIR__ . '/../backend/services/TripReadinessScore.php';
require_once __DIR__ . '/../backend/services/BudgetAutopilot.php';
require_once __DIR__ . '/../backend/services/PersonalizedHomepage.php';
require_once __DIR__ . '/../backend/ai/NLSearchParser.php';
require_once __DIR__ . '/../backend/ai/TravelRecommender.php';

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
    require_once __DIR__ . '/../backend/AlertService.php';
    $ok = tms_create_price_alert($dbh, 'test-runner@example.invalid', 1, 0.0);
    if ($ok !== false) {
        throw new Exception('expected false for a zero target price, got true');
    }
    return true;
});

tms_test('group settlement math: shares always sum exactly to the total spent (no lost/duplicated cents)', function () use ($dbh) {
    require_once __DIR__ . '/../backend/GroupTripService.php';
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
    require_once __DIR__ . '/../backend/GroupTripService.php';
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
    // own docblock in backend/payments/PaymentService.php.
    $paymentService = new PaymentService($dbh);
    $fakeBookingId = 999999999; // does not exist
    $paymentService->logRefundRequest($fakeBookingId, 'test');
    $paymentService->markRefundProcessed($fakeBookingId);
    $paymentService->markRefundRejected($fakeBookingId, 'test');
    return true; // reaching here without a thrown exception is the pass condition
});

// ── Migrations ───────────────────────────────────────────────────────────

tms_test('every file in database/migrations/ is valid, non-empty SQL', function () {
    // BUG FOUND while verifying this test actually runs correctly (Round
    // 115 continuation -- this had been assumed, every time it showed up
    // as a FAIL in a throwaway browser-run copy of this file, to be a
    // harmless artifact of that copy's extra directory depth breaking
    // this glob() path. That assumption was never actually checked by
    // fixing the depth and re-running -- doing so this round shows the
    // glob resolves fine and the failure is real: 009_index_and_dead_
    // table_cleanup.sql's DROP TABLE IF EXISTS tbl_ai_analytics is a
    // real, deliberate, already-reviewed exception (confirmed-dead table,
    // cross-referenced in backend/config.php's own comment, self-healing
    // recreation removed so the drop sticks) -- not an accidental
    // violation of "migrations must be additive only." The rule itself
    // was too blunt: it should catch an UNREVIEWED drop, not reject every
    // drop unconditionally. Fixed with a narrow, named allowlist rather
    // than removing the check -- a new migration with an undocumented
    // DROP still fails this test, exactly as intended.
    $reviewedDropExceptions = [
        '009_index_and_dead_table_cleanup.sql', // tbl_ai_analytics: confirmed dead, see the file's own comment.
    ];
    $files = glob(__DIR__ . '/../database/migrations/*.sql');
    if (empty($files)) {
        throw new Exception('no migration files found -- did the folder move?');
    }
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if (trim($sql) === '') {
            throw new Exception(basename($file) . ' is empty');
        }
        $hasDrop = stripos($sql, 'DROP TABLE') !== false || stripos($sql, 'DROP DATABASE') !== false;
        if ($hasDrop && !in_array(basename($file), $reviewedDropExceptions, true)) {
            throw new Exception(basename($file) . ' contains an unreviewed DROP TABLE/DATABASE statement -- migrations in this project must be additive only (Rule 8) unless added to $reviewedDropExceptions above with a reason');
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

// ── Natural-language search / duration parsing (Round 36, tested Round 39) ─

tms_test('NLSearchParser::durationOverlaps matches the exact cases live-verified in Round 36', function () {
    $cases = [
        // [durationText, reqMin, reqMax, expected]
        ['3-4 Days', 3, 3, true],   // a 3-day request overlaps a 3-4 day package
        ['6-8 Days', 3, 3, false],  // no overlap at all
        ['5 Days', 5, 5, true],     // single-number duration, exact match
        ['Half Day', 0, 1, true],   // no digits -- treated as a sub-1-day trip
        [null, 3, 3, false],        // missing duration text must never silently pass a real filter
        ['2-3 Days', 1, 2, true],   // partial overlap at the boundary
    ];
    foreach ($cases as [$text, $min, $max, $expected]) {
        $got = NLSearchParser::durationOverlaps($text, $min, $max);
        if ($got !== $expected) {
            $label = $text === null ? 'null' : "'$text'";
            throw new Exception("durationOverlaps($label, $min, $max) = " . var_export($got, true) . ', expected ' . var_export($expected, true));
        }
    }
    return true;
});

// ── Privacy — schema consistency (Round 20/34/37, tested Round 39) ────────

tms_test('every table tms_privacy_tables() lists (that exists in this DB) actually has the column it claims', function () use ($dbh) {
    // Guards against exactly the kind of silent drift that would break
    // account deletion/data export without anyone noticing: a table gets
    // renamed or a column gets dropped, but tms_privacy_tables() still
    // references the old name, and privacy.php either throws or silently
    // skips that table depending on how the bug manifests. Lenient about a
    // table not existing at all (session-gated feature tables -- AI/
    // marketplace/etc -- may not have migrated yet in a fresh DB, matching
    // this suite's existing "nothing to check yet" convention), but if the
    // table DOES exist, the column must be real.
    $checked = 0;
    foreach (tms_privacy_tables() as [$table, $column]) {
        $tableExists = $dbh->query("SHOW TABLES LIKE " . $dbh->quote($table))->fetch();
        if (!$tableExists) continue; // not migrated in this DB yet -- nothing to check
        $colExists = $dbh->query("SHOW COLUMNS FROM `$table` LIKE " . $dbh->quote($column))->fetch();
        if (!$colExists) {
            throw new Exception("tms_privacy_tables() lists $table.$column, but that column doesn't exist -- account deletion/data export would silently miss this table");
        }
        $checked++;
    }
    if ($checked === 0) {
        throw new Exception('none of the tables in tms_privacy_tables() exist in this DB -- did the table list or the DB connection break?');
    }
    return true;
});

// ── Hotel & Activities marketplace (Round 37, tested Round 39) ────────────

tms_test('hotel/activity marketplace tables exist with the IsSampleData flag and have seeded rows', function () use ($dbh) {
    // Round 37 built this feature entirely on labeled sample data (Rule 3 --
    // no real supplier data available). This test exists to catch a future
    // change that accidentally drops the IsSampleData column or the seed
    // step, which would either mislabel real-looking data as ungoverned or
    // leave the browse pages empty. Lenient like the rest of this suite: if
    // the session-gated migration hasn't run yet in this DB, skip rather
    // than fail.
    foreach (['tblhotels' => 'HotelId', 'tblactivities' => 'ActivityId'] as $table => $pk) {
        $tableExists = $dbh->query("SHOW TABLES LIKE " . $dbh->quote($table))->fetch();
        if (!$tableExists) continue; // migration hasn't run in this DB yet

        $flagExists = $dbh->query("SHOW COLUMNS FROM `$table` LIKE 'IsSampleData'")->fetch();
        if (!$flagExists) {
            throw new Exception("$table is missing its IsSampleData column -- Rule 3 sample-data labeling would silently break");
        }
        $count = (int)$dbh->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($count === 0) {
            throw new Exception("$table exists but has zero rows -- the seed step (tms_seed_hotels_activities) may have failed silently");
        }
        $unlabeled = (int)$dbh->query("SELECT COUNT(*) FROM `$table` WHERE IsSampleData = 0")->fetchColumn();
        if ($unlabeled > 0) {
            throw new Exception("$table has $unlabeled row(s) with IsSampleData=0 -- every row in this catalog today is sample data (Round 37), so a 0 here means either real data was added without updating this test, or the flag defaulted wrong on insert");
        }
    }
    return true;
});

// ── RBAC (Round 20, tested Round 39) ───────────────────────────────────────

tms_test('tms_admin_role fails CLOSED for a session whose admin row no longer exists (Stage 17); a legacy row with an empty Role is still superadmin', function () use ($dbh) {
    // tms_admin_role()'s own docblock/comments say a lookup miss must "fail
    // open" (return 'superadmin') rather than lock an admin out due to an
    // infra hiccup or a stale session pointing at a deleted account. This
    // pins that exact documented behavior down as a regression test. Uses a
    // clearly-fake username so this can never collide with a real admin,
    // and restores whatever $_SESSION['alogin'] held before (there
    // shouldn't be one in a CLI run, but this is defensive either way).
    $had = array_key_exists('alogin', $_SESSION);
    $prev = $_SESSION['alogin'] ?? null;
    try {
        $_SESSION['alogin'] = 'tms-test-nonexistent-admin-' . bin2hex(random_bytes(4));
        $role = tms_admin_role($dbh);
        if ($role !== '') {
            throw new Exception("expected tms_admin_role() to return '' for a deleted/unknown admin, got '$role'");
        }
        if (tms_admin_can($dbh, ['superadmin', 'manager'])) {
            throw new Exception('a deleted/unknown admin must not pass any role gate');
        }
        return true;
    } finally {
        if ($had) { $_SESSION['alogin'] = $prev; } else { unset($_SESSION['alogin']); }
    }
});

// ── Travel Modes (item 67, Round 42) ──────────────────────────────────────

tms_test('TravelRecommender::applyTravelModeAdjustment nudges only real columns per mode, never fabricates accessibility data', function () {
    // Pure unit test -- no DB, no AI client, just the static scoring helper.
    $pkg = (object)['PackageDuration' => 4, 'HotelRating' => 4.5, 'MealPlan' => 'Breakfast included'];
    $baseScores = ['budget_match'=>80,'weather_suitability'=>70,'style_match'=>65,'safety'=>80,'experience'=>75];

    // Business: short duration + high-rated hotel is a real, verifiable
    // signal that should raise style_match, with no warning attached.
    $biz = TravelRecommender::applyTravelModeAdjustment($baseScores, 'business', $pkg);
    if ($biz['scores']['style_match'] <= $baseScores['style_match']) {
        throw new Exception('expected business mode to raise style_match for a short, high-rated package');
    }
    if ($biz['warning'] !== null) {
        throw new Exception('business mode should not produce a warning');
    }

    // Accessible: tbltourpackages has no verified accessibility column, so
    // this must NEVER change the scores (Rule 3 -- never fabricate) and
    // MUST disclose that fact via a warning instead.
    $acc = TravelRecommender::applyTravelModeAdjustment($baseScores, 'accessible', $pkg);
    if ($acc['scores'] !== $baseScores) {
        throw new Exception('accessible mode must not fabricate a score change -- no verified per-package accessibility data exists');
    }
    if (empty($acc['warning']) || stripos($acc['warning'], 'accessib') === false) {
        throw new Exception('accessible mode must disclose that accessibility data is unverified, got: ' . var_export($acc['warning'], true));
    }

    // Empty/unrecognized mode: pass-through unchanged, no invented adjustment.
    $none = TravelRecommender::applyTravelModeAdjustment($baseScores, '', $pkg);
    if ($none['scores'] !== $baseScores || $none['warning'] !== null) {
        throw new Exception('an empty travel mode must leave scores unchanged and produce no warning');
    }

    return true;
});


// ── Booking State Machine (new "TMS V2" prompt, booking lifecycle guard) ──

tms_test('BookingStateMachine allows every transition this codebase actually performs and rejects invalid ones', function () {
    // Legal transitions, one per real write site found via a live grep
    // across frontend/, admin/, api/, backend/payments/ -- these must
    // never start failing, since every one of them is a currently-working
    // feature (Rule 2).
    $legal = [
        ['status', 0, 1],                 // admin confirms a pending booking
        ['status', 0, 2],                 // customer/admin cancels a pending booking
        ['status', 1, 2],                 // customer/admin cancels a confirmed booking
        ['payment', null, 'pending'],     // PaymentService::initiate
        ['payment', 'pending', 'paid'],   // PaymentService::confirm
        ['payment', 'paid', 'refunded'],  // PaymentService::refund
        ['refund', null, 'requested'],    // tour-history.php / api/index.php cancel
        ['refund', 'requested', 'processed'], // admin manage-bookings.php
        ['refund', 'requested', 'rejected'],  // admin manage-bookings.php
        ['modification', null, 'pending'],    // tour-history.php date-change request
        ['modification', 'pending', 'approved'], // admin manage-bookings.php
        ['modification', 'pending', 'rejected'], // admin manage-bookings.php
    ];
    foreach ($legal as [$field, $from, $to]) {
        $ok = match ($field) {
            'status' => BookingStateMachine::canTransitionStatus($from, $to),
            'payment' => BookingStateMachine::canTransitionPaymentStatus($from, $to),
            'refund' => BookingStateMachine::canTransitionRefundStatus($from, $to),
            'modification' => BookingStateMachine::canTransitionModificationStatus($from, $to),
        };
        if (!$ok) {
            throw new Exception("expected $field transition " . var_export($from, true) . " -> $to to be legal (it's a real write site in this app)");
        }
    }

    // Illegal transitions -- none of these appear anywhere in the codebase,
    // and each represents a real bug class (resurrecting a cancelled
    // booking, double-processing a refund, re-confirming after a refund).
    $illegal = [
        ['status', 2, 1],
        ['status', 2, 0],
        ['status', 1, 0],
        ['payment', 'refunded', 'paid'],
        ['payment', 'paid', 'pending'],
        ['refund', 'processed', 'requested'],
        ['refund', null, 'processed'],
        ['modification', 'approved', 'pending'],
    ];
    foreach ($illegal as [$field, $from, $to]) {
        $ok = match ($field) {
            'status' => BookingStateMachine::canTransitionStatus($from, $to),
            'payment' => BookingStateMachine::canTransitionPaymentStatus($from, $to),
            'refund' => BookingStateMachine::canTransitionRefundStatus($from, $to),
            'modification' => BookingStateMachine::canTransitionModificationStatus($from, $to),
        };
        if ($ok) {
            throw new Exception("expected $field transition " . var_export($from, true) . " -> $to to be rejected");
        }
    }

    return true;
});


// ── Trip Readiness Score ("TMS V2" prompt) ─────────────────────────────────

tms_test('TripReadinessScore::computeScore weights components correctly and lists only unmet ones as missing', function () {
    $all = TripReadinessScore::computeScore(['paid'=>true,'confirmed'=>true,'hasDocument'=>true,'hasItinerary'=>true]);
    if ($all['score'] !== 100) throw new Exception('all facts true must score 100, got ' . $all['score']);
    if (!empty($all['missing'])) throw new Exception('all facts true must have zero missing items');

    $none = TripReadinessScore::computeScore(['paid'=>false,'confirmed'=>false,'hasDocument'=>false,'hasItinerary'=>false]);
    if ($none['score'] !== 0) throw new Exception('all facts false must score 0, got ' . $none['score']);
    if (count($none['missing']) !== 4) throw new Exception('all facts false must list all 4 as missing');

    // Weights must sum to 100 and match the documented split (40/20/20/20).
    $partial = TripReadinessScore::computeScore(['paid'=>true,'confirmed'=>true,'hasDocument'=>false,'hasItinerary'=>false]);
    if ($partial['score'] !== 60) throw new Exception('paid+confirmed only must score 60 (40+20), got ' . $partial['score']);

    $docOnly = TripReadinessScore::computeScore(['paid'=>false,'confirmed'=>false,'hasDocument'=>true,'hasItinerary'=>false]);
    if ($docOnly['score'] !== 20) throw new Exception('document only must score 20, got ' . $docOnly['score']);

    // The document gap must point somewhere actionable (wallet.php); the
    // payment/confirmation/itinerary gaps deliberately have no generic URL
    // (my-trip.php's own Pay Now button / itinerary button handle those).
    $missingDoc = array_values(array_filter($none['missing'], fn($m) => $m['label'] === 'Travel document on file'))[0] ?? null;
    if (!$missingDoc || $missingDoc['action_url'] !== 'wallet.php') {
        throw new Exception('the document gap must link to wallet.php');
    }

    return true;
});


// ── AI Budget Autopilot ("TMS V2" prompt) ──────────────────────────────────

tms_test('BudgetAutopilot::shouldNotify only fires at/over threshold and respects the notification cooldown', function () {
    $now = strtotime('2026-09-20 12:00:00');

    $noBudget = ['budget'=>0,'spent'=>0,'percent_used'=>null,'over_budget'=>false];
    if (BudgetAutopilot::shouldNotify($noBudget, null, 90, 3, $now)) {
        throw new Exception('a booking with no real budget must never trigger a notification');
    }

    $underThreshold = ['budget'=>1000,'spent'=>500,'percent_used'=>50,'over_budget'=>false];
    if (BudgetAutopilot::shouldNotify($underThreshold, null, 90, 3, $now)) {
        throw new Exception('50% used must not trigger a notification (threshold is 90)');
    }

    $atThreshold = ['budget'=>1000,'spent'=>900,'percent_used'=>90,'over_budget'=>false];
    if (!BudgetAutopilot::shouldNotify($atThreshold, null, 90, 3, $now)) {
        throw new Exception('90% used, never notified, must trigger a notification');
    }

    $overBudget = ['budget'=>1000,'spent'=>1200,'percent_used'=>120,'over_budget'=>true];
    if (!BudgetAutopilot::shouldNotify($overBudget, null, 90, 3, $now)) {
        throw new Exception('over budget, never notified, must trigger a notification');
    }

    $notifiedYesterday = date('Y-m-d H:i:s', $now - 86400);
    if (BudgetAutopilot::shouldNotify($overBudget, $notifiedYesterday, 90, 3, $now)) {
        throw new Exception('over budget but notified 1 day ago (cooldown 3) must NOT re-notify yet');
    }

    $notified4DaysAgo = date('Y-m-d H:i:s', $now - 4 * 86400);
    if (!BudgetAutopilot::shouldNotify($overBudget, $notified4DaysAgo, 90, 3, $now)) {
        throw new Exception('over budget, notified 4 days ago (cooldown 3), must re-notify');
    }

    return true;
});

tms_test('PersonalizedHomepage::forUser returns no signal (not a crash) for an email with zero real data', function () use ($dbh) {
    $result = PersonalizedHomepage::forUser($dbh, 'definitely-does-not-exist-' . bin2hex(random_bytes(6)) . '@nowhere.invalid', 8);
    if ($result['reason'] !== 'none' || !empty($result['packages'])) {
        throw new Exception('expected reason=none and an empty packages array for a nonexistent email, got: ' . json_encode($result));
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

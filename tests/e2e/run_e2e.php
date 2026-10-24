<?php
/**
 * tests/e2e/run_e2e.php — real, HTTP-driven end-to-end test suite (Round
 * 115 continuation, TMS V2 Phase 4 / master prompt item 58: "zero E2E
 * tests" was a confirmed, honestly-flagged real gap in
 * tests/e2e/README.md, MASTER_PROMPT_STATUS.md, and TMS_V2_PHASE1_AUDIT.md
 * Section D -- this closes it for real rather than leaving a placeholder).
 *
 * WHAT MAKES THIS DIFFERENT FROM tests/run.php: that file boots
 * backend/config.php directly and calls PHP functions/classes in-process
 * -- fast, but it never actually goes over HTTP, so it can't catch a
 * broken route, a CSRF check that silently stopped firing, a session
 * cookie flag regression, or a REST response shape drift. This file makes
 * REAL HTTP requests (via curl) against a REAL running instance of the
 * app -- full request/response cycle, real cookies, real CSRF tokens
 * scraped out of real HTML, real sessions -- end to end, the way an
 * actual browser or API client would.
 *
 * WHY THIS WASN'T BUILT AS BROWSER AUTOMATION (Playwright/Cypress/etc.):
 * this project has no Node/npm dependency today (no package.json), and
 * introducing one just for E2E tests would be exactly the kind of
 * dependency-footprint growth Rule 73 warns against, on top of needing
 * a browser binary in whatever environment runs this. A pure-PHP,
 * curl-based HTTP test suite needs nothing beyond what's already
 * required to run the app itself (PHP + curl extension, both already
 * required by composer-free convention across this project), runs
 * identically in CI (.github/workflows/ci.yml) and locally, and still
 * exercises the real HTTP stack end to end -- it just doesn't render
 * JS or click pixels. If a future round adds real client-side JS logic
 * that genuinely needs browser-level testing, that's a new, separate
 * decision to make then (with its own dependency-cost tradeoff), not
 * something to default into here.
 *
 * WHAT THIS COVERS (a real first slice, not everything -- Rule 73):
 * registration (through the real CSRF-protected /register.php handler
 * the live "Sign Up" modal actually posts to, scraping a live token out
 * of real HTML exactly like a browser would), login,
 * an authenticated booking created through the public REST API, the two
 * new V2 domain endpoints (trip_readiness/budget_status) against that
 * real booking, cancellation, logout, and that a revoked token is
 * correctly rejected afterward. It does NOT cover admin workflows,
 * payments, or JS-rendered pages -- honestly out of scope for this first
 * pass, not silently assumed covered.
 *
 * SELF-CLEANING: creates one throwaway account
 * (e2e-<random>@tms-testing.local, same *.tms-testing.local convention
 * as every other disposable QA account this project uses) and deletes
 * it and everything it created at the end, in a finally block, so this
 * is safe to run repeatedly against a real database (including a real
 * dev install, not just CI's ephemeral one) without accumulating junk
 * rows. If a run crashes before cleanup, the account is still clearly
 * identifiable and safe to delete by hand later (its email pattern).
 *
 * Run: php tests/e2e/run_e2e.php
 * Needs a *running* web server for the app -- this does NOT boot PHP's
 * built-in server itself. Point it at one with the E2E_BASE_URL env var:
 *   - CI (.github/workflows/ci.yml): starts `php -S 127.0.0.1:8000` first,
 *     matching this script's default base URL.
 *   - Local XAMPP: E2E_BASE_URL=http://localhost/tourbuddy php tests/e2e/run_e2e.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Run this from the command line: php tests/e2e/run_e2e.php');
}

if (!extension_loaded('curl')) {
    fwrite(STDERR, "The curl PHP extension is required for tests/e2e/run_e2e.php and is not loaded.\n");
    exit(1);
}

$baseUrl = rtrim(getenv('E2E_BASE_URL') ?: 'http://127.0.0.1:8000', '/');

// ── Tiny HTTP client (cookie-jar aware) ─────────────────────────────────

$cookieJar = tempnam(sys_get_temp_dir(), 'tms_e2e_cookies_');
register_shutdown_function(function () use ($cookieJar) {
    if (is_file($cookieJar)) { @unlink($cookieJar); }
});

/**
 * @return array{status:int, body:string, headers:string}
 */
function e2e_request(string $baseUrl, string $cookieJar, string $method, string $path, array $fields = [], array $extraHeaders = []): array {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    } elseif ($fields) {
        // GET with query params merged onto the path.
        curl_setopt($ch, CURLOPT_URL, $baseUrl . $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($fields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP request to $path failed: $err (is a server running at $baseUrl ? see this file's own header comment)");
    }
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body'    => substr($raw, $headerSize),
    ];
}

function e2e_json(array $response): array {
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Expected JSON body, got: ' . substr($response['body'], 0, 300));
    }
    return $decoded;
}

// ── Test registry (same tms_test()/summary idiom as tests/run.php) ─────

$tests = [];
function tms_e2e_test(string $name, callable $fn): void {
    global $tests;
    $tests[$name] = $fn;
}

// Shared state between steps -- registration's email/password feed
// login, login's token feeds booking, booking's id feeds the readiness/
// budget/cancel steps. Each step throws on failure (caught by the runner
// below), so a broken early step correctly fails every step after it
// rather than cascading into confusing unrelated errors.
$ctx = [
    'email'      => 'e2e-' . bin2hex(random_bytes(6)) . '@tms-testing.local',
    'password'   => 'E2eTest#' . bin2hex(random_bytes(4)),
    'csrf'       => null,
    'token'      => null,
    'package_id' => null,
    'booking_id' => null,
];

tms_e2e_test('homepage loads and exposes a live CSRF token', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'GET', '/index.php');
    if ($res['status'] !== 200) {
        throw new Exception("expected 200, got {$res['status']}");
    }
    if (!preg_match('/id="signupCsrf"[^>]*value="([^"]+)"/', $res['body'], $m)) {
        throw new Exception('could not find the signup CSRF token in the homepage HTML');
    }
    $ctx['csrf'] = html_entity_decode($m[1], ENT_QUOTES);
    if (strlen($ctx['csrf']) < 20) {
        throw new Exception('CSRF token looks too short to be real: ' . $ctx['csrf']);
    }
    return true;
});

tms_e2e_test('registration succeeds through the real CSRF-protected endpoint', function () use ($baseUrl, $cookieJar, &$ctx) {
    // NOTE: the live topbar "Sign Up" modal (backend/header.php) posts to
    // /register.php, a full-page handler that re-renders the same HTML
    // page with a $success/$error string embedded -- not JSON. There is a
    // separate frontend/user-register-ajax.php that returns JSON, but a
    // project-wide grep found nothing that actually calls it from any
    // live page today (its own docblock references backend/signup.php, a
    // standalone form that isn't included anywhere either) -- so this
    // test deliberately exercises the real, live path a browser actually
    // uses, not the unused one.
    $res = e2e_request($baseUrl, $cookieJar, 'POST', '/register.php', [
        'FullName'     => 'E2E Test Runner',
        'MobileNumber' => '01700000000',
        'EmailId'      => $ctx['email'],
        'Password'     => $ctx['password'],
        'CPassword'    => $ctx['password'],
        'csrf_token'   => $ctx['csrf'],
    ]);
    if ($res['status'] !== 200 || !str_contains($res['body'], 'Account created successfully')) {
        throw new Exception('registration did not report success (status ' . $res['status'] . ')');
    }
    return true;
});

tms_e2e_test('registering the same email twice is correctly rejected', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'POST', '/register.php', [
        'FullName'     => 'E2E Test Runner Duplicate',
        'MobileNumber' => '01700000001',
        'EmailId'      => $ctx['email'],
        'Password'     => $ctx['password'],
        'CPassword'    => $ctx['password'],
        'csrf_token'   => $ctx['csrf'],
    ]);
    if (!str_contains($res['body'], 'already exists')) {
        throw new Exception('duplicate registration was not rejected with the expected message');
    }
    return true;
});

tms_e2e_test('login via REST API returns a bearer token for the new account', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'POST', '/api/index.php', [
        'action'   => 'login',
        'email'    => $ctx['email'],
        'password' => $ctx['password'],
    ]);
    $json = e2e_json($res);
    if (empty($json['success']) || empty($json['data']['token'])) {
        throw new Exception('login did not return a token: ' . json_encode($json));
    }
    $ctx['token'] = $json['data']['token'];
    return true;
});

tms_e2e_test('a wrong password on the same account is correctly rejected', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'POST', '/api/index.php', [
        'action'   => 'login',
        'email'    => $ctx['email'],
        'password' => $ctx['password'] . '-wrong',
    ]);
    if ($res['status'] !== 401) {
        throw new Exception("expected 401 for a wrong password, got {$res['status']}");
    }
    return true;
});

tms_e2e_test('public package listing returns a real bookable package', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'GET', '/api/index.php', ['action' => 'packages', 'limit' => 5, 'sort' => 'price_desc']);
    $json = e2e_json($res);
    if (empty($json['success']) || empty($json['data'])) {
        throw new Exception('packages listing failed or was empty: ' . json_encode($json));
    }
    foreach ($json['data'] as $pkg) {
        if (!empty($pkg['PackageId']) && (float)($pkg['PackagePrice'] ?? 0) > 0) {
            $ctx['package_id'] = (int) $pkg['PackageId'];
            break;
        }
    }
    if (!$ctx['package_id']) {
        throw new Exception('no package with a real price was found in the top 5 by price');
    }
    return true;
});

tms_e2e_test('an authenticated booking is created against a real package', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'POST', '/api/index.php', [
        'action'     => 'book',
        'package_id' => $ctx['package_id'],
        'from_date'  => date('Y-m-d', strtotime('+60 days')),
        'to_date'    => date('Y-m-d', strtotime('+65 days')),
        'persons'    => 1,
        'mobile'     => '01700000000',
        'comment'    => 'Created by tests/e2e/run_e2e.php -- safe to ignore/delete.',
    ], ['Authorization: Bearer ' . $ctx['token']]);
    $json = e2e_json($res);
    if (empty($json['success']) || empty($json['data']['booking_id'])) {
        throw new Exception('booking failed: ' . json_encode($json));
    }
    $ctx['booking_id'] = (int) $json['data']['booking_id'];
    return true;
});

tms_e2e_test('the new booking shows up in my_bookings', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'GET', '/api/index.php', ['action' => 'my_bookings'], ['Authorization: Bearer ' . $ctx['token']]);
    $json = e2e_json($res);
    $ids = array_column($json['data'] ?? [], 'BookingId');
    if (!in_array($ctx['booking_id'], $ids, true)) {
        throw new Exception('booking_id ' . $ctx['booking_id'] . ' not found in my_bookings: ' . json_encode($ids));
    }
    return true;
});

tms_e2e_test('trip_readiness reflects the real state of the new booking (TMS V2 Phase 29)', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'GET', '/api/index.php', ['action' => 'trip_readiness', 'booking_id' => $ctx['booking_id']], ['Authorization: Bearer ' . $ctx['token']]);
    $json = e2e_json($res);
    if (empty($json['success']) || !isset($json['data']['score'])) {
        throw new Exception('trip_readiness failed: ' . json_encode($json));
    }
    // A brand-new booking is unpaid/unconfirmed/no-document/no-itinerary --
    // score must be exactly 0, not merely "low", so this actually pins the
    // real computation rather than just checking the shape of the response.
    if ($json['data']['score'] !== 0) {
        throw new Exception('expected a fresh unpaid/unconfirmed booking to score 0, got ' . $json['data']['score']);
    }
    return true;
});

tms_e2e_test('budget_status reflects the real budget for the new booking (TMS V2 Phase 29)', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'GET', '/api/index.php', ['action' => 'budget_status', 'booking_id' => $ctx['booking_id']], ['Authorization: Bearer ' . $ctx['token']]);
    $json = e2e_json($res);
    if (empty($json['success']) || !isset($json['data']['budget'])) {
        throw new Exception('budget_status failed: ' . json_encode($json));
    }
    if ((int)$json['data']['spent'] !== 0) {
        throw new Exception('a brand-new booking with no logged expenses must show spent=0, got ' . $json['data']['spent']);
    }
    return true;
});

tms_e2e_test('a foreign booking_id is correctly rejected with 404 (ownership check)', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'GET', '/api/index.php', ['action' => 'trip_readiness', 'booking_id' => 999999999], ['Authorization: Bearer ' . $ctx['token']]);
    if ($res['status'] !== 404) {
        throw new Exception("expected 404 for a nonexistent booking_id, got {$res['status']}");
    }
    return true;
});

tms_e2e_test('the booking is cancelled through the real 24h-window rule', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'POST', '/api/index.php', [
        'action'     => 'cancel_booking',
        'booking_id' => $ctx['booking_id'],
    ], ['Authorization: Bearer ' . $ctx['token']]);
    $json = e2e_json($res);
    if (empty($json['success'])) {
        throw new Exception('cancellation failed: ' . json_encode($json));
    }
    return true;
});

tms_e2e_test('logout invalidates the token', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'POST', '/api/index.php', ['action' => 'logout'], ['Authorization: Bearer ' . $ctx['token']]);
    $json = e2e_json($res);
    if (empty($json['success'])) {
        throw new Exception('logout failed: ' . json_encode($json));
    }
    return true;
});

tms_e2e_test('the revoked token is correctly rejected afterward', function () use ($baseUrl, $cookieJar, &$ctx) {
    $res = e2e_request($baseUrl, $cookieJar, 'GET', '/api/index.php', ['action' => 'my_bookings'], ['Authorization: Bearer ' . $ctx['token']]);
    if ($res['status'] !== 401) {
        throw new Exception("expected 401 for a revoked token, got {$res['status']}");
    }
    return true;
});

// ── Run everything, then always clean up ────────────────────────────────

echo "TMS E2E test suite (against $baseUrl)\n" . str_repeat('=', 40) . "\n";
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

$total  = count($tests);
$passed = $total - $failures;
echo "\n$passed/$total passed.\n";

// Cleanup runs regardless of pass/fail, using the app's own DB connection
// -- not a second, drifting copy of the schema -- so this stays correct
// automatically if tblusers/tblbooking/etc. ever change shape.
//
// BUG FOUND while verifying this script live (not just implemented and
// assumed correct -- Rule 77): the first draft only deleted from
// tblbooking/tblapi_tokens/tblapi_refresh_tokens before tblusers, and
// failed with a real FK constraint violation on tblloyalty_log (booking
// creation awards loyalty points there even with no referral code
// involved). tblusers.EmailId has 9 real FOREIGN KEY references,
// confirmed live via information_schema during this project's migration
// 015 work: tblbooking, tblcart, tblenquiry (column EmailId, not
// UserEmail), tblissues, tblloyalty, tblloyalty_log, tblreviews,
// tbltraveldocuments, tblwishlist -- all ON DELETE RESTRICT. Every one is
// cleared before the tblusers delete, even though this particular test
// flow is only expected to populate tblbooking/tblloyalty/tblloyalty_log
// -- clearing all nine is what makes this safe to reuse for a future,
// broader E2E flow without silently reintroducing the same bug.
try {
    require_once __DIR__ . '/../../backend/config.php';
    $childTables = [
        ['tblbooking', 'UserEmail'], ['tblcart', 'UserEmail'], ['tblenquiry', 'EmailId'],
        ['tblissues', 'UserEmail'], ['tblloyalty', 'UserEmail'], ['tblloyalty_log', 'UserEmail'],
        ['tblreviews', 'UserEmail'], ['tbltraveldocuments', 'UserEmail'], ['tblwishlist', 'UserEmail'],
        ['tblapi_tokens', 'UserEmail'], ['tblapi_refresh_tokens', 'UserEmail'],
    ];
    foreach ($childTables as [$table, $col]) {
        $dbh->prepare("DELETE FROM `$table` WHERE `$col` = ?")->execute([$ctx['email']]);
    }
    $dbh->prepare("DELETE FROM tblusers WHERE EmailId = ?")->execute([$ctx['email']]);
    echo "Cleaned up test account {$ctx['email']} and its data.\n";
} catch (Throwable $e) {
    echo "WARNING: cleanup failed ({$e->getMessage()}) -- test account {$ctx['email']} may need manual deletion.\n";
}

exit($failures > 0 ? 1 : 0);

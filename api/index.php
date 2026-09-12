<?php
/**
 * TMS REST API — v1
 *
 * Public endpoints (no auth):
 *   GET  /api/?action=packages           – list packages (?search=, ?type=, ?page=, ?limit=, ?sort=)
 *   GET  /api/?action=package&id=N       – single package detail
 *   GET  /api/?action=types              – distinct package types
 *   POST /api/?action=check_email        – check if email is taken
 *   POST /api/?action=login              – { email, password } -> { token, user }
 *
 * Authenticated endpoints (Authorization: Bearer <token>, from /?action=login):
 *   GET  /api/?action=me                 – current user's profile
 *   GET  /api/?action=my_bookings        – current user's bookings
 *   POST /api/?action=book               – create a booking { package_id, from_date, to_date, persons, mobile, comment, coupon_code }
 *   POST /api/?action=cancel_booking     – cancel a booking { booking_id }
 *   POST /api/?action=logout             – invalidate the current token
 *
 * Responses always: { success: bool, data: ..., meta: {...} } or
 * { success: false, error: "..." }. See API_DOCUMENTATION.md for full
 * request/response examples, auth details, and error codes. A machine-
 * readable spec covering every endpoint above lives in api/openapi.yaml
 * (rendered at /api/docs.php).
 *
 * VERSIONING (Round 71, master prompt item 42): this file is also reachable,
 * unchanged, at the real path /api/v1/ -- api/v1/index.php contains nothing
 * but a one-line `require` of this same file, so the two paths can never
 * behave differently or drift apart, and api/ai.php / api/explore.php (the
 * two siblings that would have broken under a real directory restructure)
 * are completely untouched. Every response also still carries "version":"1"
 * in its meta, plus a new "versioned_path" boolean confirming which path was
 * actually hit. The legacy unversioned path keeps working exactly as before
 * -- "do not break existing consumers" -- and is expected to keep working
 * indefinitely; /v1/ is additive, not a replacement.
 *
 * RATE LIMITING (Round 71, master prompt item 42/44): every action is now
 * throttled to 120 requests/60s per IP (bucket "api_global"), with a
 * stricter 20/60s specifically on check_email (bucket "api_check_email",
 * since it has no other abuse protection and is a plain account-
 * enumeration oracle otherwise). Exceeding either returns 429. See
 * tms_api_rate_limit_check() below.
 */

session_start();
require_once dirname(__DIR__) . '/backend/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ─── Route ────────────────────────────────────────────────────────────────────
$action = tms_clean($_GET['action'] ?? $_POST['action'] ?? '');

// FEATURE (Round 71, master prompt item 42/44): a global per-IP request
// throttle, applied to every action, plus a stricter one on check_email
// specifically -- unlike login, it had zero abuse protection at all and
// is a plain account-enumeration oracle (any email in, "available: true/
// false" out) if hammered.
$tmsApiClientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
tms_api_rate_limit_check($dbh, 'api_global', $tmsApiClientIp, 120, 60);
if ($action === 'check_email') {
    tms_api_rate_limit_check($dbh, 'api_check_email', $tmsApiClientIp, 20, 60);
}

try {
    match ($action) {
        'packages'        => api_packages($dbh),
        'package'         => api_package($dbh),
        'types'           => api_types($dbh),
        'check_email'     => api_check_email($dbh),
        'login'           => api_login($dbh),
        'logout'          => api_logout($dbh),
        'me'              => api_me($dbh),
        'my_bookings'     => api_my_bookings($dbh),
        'book'            => api_book($dbh),
        'cancel_booking'  => api_cancel_booking($dbh),
        default           => api_error(404, 'Unknown action. Available: packages, package, types, check_email, login, logout, me, my_bookings, book, cancel_booking'),
    };
} catch (Throwable $e) {
    error_log('API error: ' . $e->getMessage());
    api_error(500, 'Internal server error.');
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function api_json(array $payload, int $status = 200): void {
    $payload['meta'] = ($payload['meta'] ?? []) + [
        'version' => '1',
        // Round 71: true when this request actually came in through the new
        // /api/v1/ path (api/v1/index.php just requires this same file --
        // see that file's own comment -- so both paths run identical code;
        // this flag is purely informational, for a client or a log grep to
        // confirm the versioned path is really being used).
        'versioned_path' => (bool) (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/v1/') !== false),
    ];
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function api_error(int $status, string $message): void {
    api_json(['success' => false, 'error' => $message], $status);
}

/**
 * Fixed-window per-(bucket,identifier) request counter (Round 71, master
 * prompt item 42/44). Deliberately SQL-clock-only -- WindowStart is computed
 * by MySQL's own NOW()/UNIX_TIMESTAMP(), never PHP's time() or date() --
 * because Round 66 found a real ~4 hour PHP/MySQL clock disagreement in
 * this environment; mixing the two clocks here would silently mis-bucket
 * requests the same way it silently broke login-lockout timing before.
 * Fails OPEN on any DB error (same philosophy as
 * tms_login_lockout_minutes_remaining()) -- a broken limiter must never be
 * able to take the whole API down.
 */
function tms_api_rate_limit_check(PDO $dbh, string $bucket, string $identifier, int $maxRequests, int $windowSeconds): void {
    try {
        $dbh->prepare(
            "INSERT INTO tbl_api_rate_limit (Bucket, Identifier, WindowStart, RequestCount)
             VALUES (:b, :i, FLOOR(UNIX_TIMESTAMP(NOW()) / :w), 1)
             ON DUPLICATE KEY UPDATE RequestCount = RequestCount + 1"
        )->execute([':b' => $bucket, ':i' => $identifier, ':w' => $windowSeconds]);

        $q = $dbh->prepare(
            "SELECT RequestCount FROM tbl_api_rate_limit
             WHERE Bucket = :b AND Identifier = :i AND WindowStart = FLOOR(UNIX_TIMESTAMP(NOW()) / :w)
             LIMIT 1"
        );
        $q->execute([':b' => $bucket, ':i' => $identifier, ':w' => $windowSeconds]);
        $count = (int) $q->fetchColumn();

        // Opportunistic cleanup (~1% of requests) so this table doesn't grow
        // unbounded -- same "self-maintaining, no cron required" philosophy
        // as the rest of this project's self-healing migrations.
        if (mt_rand(1, 100) === 1) {
            try {
                $dbh->prepare(
                    "DELETE FROM tbl_api_rate_limit WHERE WindowStart < FLOOR(UNIX_TIMESTAMP(NOW()) / :w) - 60"
                )->execute([':w' => $windowSeconds]);
            } catch (Exception $e) {}
        }

        if ($count > $maxRequests) {
            api_error(429, 'Rate limit exceeded for this endpoint. Please slow down and try again shortly.');
        }
    } catch (Exception $e) {
        error_log('[TMS API] Rate limit check failed (failing open): ' . $e->getMessage());
    }
}

/**
 * Resolves the current request's bearer token to a user email, or ends the
 * request with a 401. Tokens are stored hashed (SHA-256); this hashes the
 * incoming token the same way before the lookup, same principle as
 * tms_verify_password never comparing against a raw stored password.
 */
function api_auth_user(PDO $dbh): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        api_error(401, 'Missing or malformed Authorization header. Expected: Bearer <token>.');
    }
    $tokenHash = hash('sha256', $m[1]);
    $q = $dbh->prepare("SELECT UserEmail, ExpiresAt FROM tblapi_tokens WHERE TokenHash=:h LIMIT 1");
    $q->execute([':h' => $tokenHash]);
    $row = $q->fetch(PDO::FETCH_OBJ);
    if (!$row) {
        api_error(401, 'Invalid or revoked token.');
    }
    if ($row->ExpiresAt && strtotime($row->ExpiresAt) < time()) {
        api_error(401, 'Token expired. Please log in again.');
    }
    $dbh->prepare("UPDATE tblapi_tokens SET LastUsedAt=NOW() WHERE TokenHash=:h")->execute([':h' => $tokenHash]);
    return $row->UserEmail;
}

// ─── Handlers ────────────────────────────────────────────────────────────────

function api_packages(PDO $dbh): void {
    $search    = tms_clean($_GET['search'] ?? '');
    $type      = tms_clean($_GET['type']   ?? '');
    $page      = tms_int($_GET['page']     ?? 1, 1);
    $limit     = tms_int($_GET['limit']    ?? 12, 1, 50);
    $sortBy    = in_array($_GET['sort'] ?? '', ['price_asc','price_desc','name']) ? $_GET['sort'] : 'default';
    $offset    = ($page - 1) * $limit;

    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[]           = "(PackageName LIKE :s OR PackageLocation LIKE :s2)";
        $params[':s']      = "%$search%";
        $params[':s2']     = "%$search%";
    }
    if ($type !== '') {
        $where[]           = "PackageType = :t";
        $params[':t']      = $type;
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderSQL = match($sortBy) {
        'price_asc'  => 'ORDER BY PackagePrice ASC',
        'price_desc' => 'ORDER BY PackagePrice DESC',
        'name'       => 'ORDER BY PackageName ASC',
        default      => 'ORDER BY PackageId DESC',
    };

    $cq = $dbh->prepare("SELECT COUNT(*) FROM tbltourpackages $whereSQL");
    $cq->execute($params);
    $total = (int)$cq->fetchColumn();

    $dq = $dbh->prepare("SELECT PackageId,PackageName,PackageType,PackageLocation,
        PackagePrice,PackageImage,AvgRating FROM tbltourpackages $whereSQL $orderSQL
        LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) $dq->bindValue($k, $v, PDO::PARAM_STR);
    $dq->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $dq->bindValue(':off', $offset, PDO::PARAM_INT);
    $dq->execute();
    $rows = $dq->fetchAll(PDO::FETCH_ASSOC);

    api_json([
        'success' => true,
        'data'    => $rows,
        'meta'    => [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function api_package(PDO $dbh): void {
    $id = tms_int($_GET['id'] ?? 0, 1);
    if (!$id) { api_error(400, 'Missing package id.'); }

    $q = $dbh->prepare("SELECT p.*,
        (SELECT AVG(r.Rating) FROM tblreviews r WHERE r.PackageId=p.PackageId AND r.Status=1) as ReviewAvg,
        (SELECT COUNT(*)      FROM tblreviews r WHERE r.PackageId=p.PackageId AND r.Status=1) as ReviewCount
        FROM tbltourpackages p WHERE p.PackageId=:id LIMIT 1");
    $q->bindParam(':id', $id, PDO::PARAM_INT);
    $q->execute();
    $pkg = $q->fetch(PDO::FETCH_ASSOC);

    if (!$pkg) { api_error(404, 'Package not found.'); }
    api_json(['success' => true, 'data' => $pkg]);
}

function api_types(PDO $dbh): void {
    $q    = $dbh->query("SELECT DISTINCT PackageType FROM tbltourpackages ORDER BY PackageType");
    $data = $q->fetchAll(PDO::FETCH_COLUMN);
    api_json(['success' => true, 'data' => $data]);
}

function api_check_email(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $email = tms_validate_email($_POST['emailid'] ?? '');
    if (!$email) {
        api_json(['success' => true, 'data' => ['available' => false, 'message' => 'Invalid email format.']]);
    }
    $q = $dbh->prepare("SELECT COUNT(*) FROM tblusers WHERE EmailId=:e");
    $q->bindParam(':e', $email, PDO::PARAM_STR);
    $q->execute();
    $taken = (int)$q->fetchColumn() > 0;
    api_json([
        'success' => true,
        'data'    => [
            'available' => !$taken,
            'message'   => $taken ? 'This email is already registered.' : 'Email is available.',
        ],
    ]);
}

function api_login(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $email = tms_validate_email($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!$email || $pass === '') { api_error(400, 'email and password are required.'); }

    // SECURITY FIX: tbl_login_attempts / tms_login_lockout_minutes_remaining()
    // already implement a real brute-force lockout (5 failed attempts -> 15
    // minute lockout by email+IP) and login.php/admin/index.php both check it
    // -- but this endpoint only ever recorded failures below, it never
    // checked the lockout first. That meant the exact same account was fully
    // protected from password guessing on the website and completely
    // unprotected against the same attack through this REST API. Same fix
    // shape as login.php's Round-14 fix, same underlying helper, no new
    // mechanism introduced.
    $waitMinutes = tms_login_lockout_minutes_remaining($dbh, $email, $ip);
    if ($waitMinutes > 0) {
        api_error(429, "Too many failed sign-in attempts. Please try again in {$waitMinutes} minute" . ($waitMinutes === 1 ? '' : 's') . '.');
    }

    $q = $dbh->prepare("SELECT id, FullName, EmailId, Password FROM tblusers WHERE EmailId=:e LIMIT 1");
    $q->execute([':e' => $email]);
    $user = $q->fetch(PDO::FETCH_OBJ);

    if (!$user || !tms_verify_password($pass, $user->Password, (int)$user->id, $dbh)) {
        tms_record_failed_login($dbh, $email, $ip);
        api_error(401, 'Invalid email or password.');
    }
    tms_clear_login_attempts($dbh, $email, $ip);

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $device    = tms_clean($_POST['device'] ?? '');
    $expires   = date('Y-m-d H:i:s', strtotime('+30 days'));

    $dbh->prepare("INSERT INTO tblapi_tokens(UserEmail, TokenHash, DeviceLabel, ExpiresAt) VALUES(:e,:h,:d,:x)")
        ->execute([':e' => $user->EmailId, ':h' => $tokenHash, ':d' => $device ?: null, ':x' => $expires]);

    api_json(['success' => true, 'data' => [
        'token'      => $rawToken,
        'expires_at' => $expires,
        'user'       => ['id' => (int)$user->id, 'full_name' => $user->FullName, 'email' => $user->EmailId],
    ]]);
}

function api_logout(PDO $dbh): void {
    $email = api_auth_user($dbh);
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    preg_match('/^Bearer\s+(\S+)$/i', $header, $m);
    $dbh->prepare("DELETE FROM tblapi_tokens WHERE TokenHash=:h")->execute([':h' => hash('sha256', $m[1])]);
    api_json(['success' => true, 'data' => ['message' => 'Logged out.']]);
}

function api_me(PDO $dbh): void {
    $email = api_auth_user($dbh);
    $q = $dbh->prepare("SELECT id, FullName, EmailId, MobileNumber, RegDate FROM tblusers WHERE EmailId=:e LIMIT 1");
    $q->execute([':e' => $email]);
    $user = $q->fetch(PDO::FETCH_ASSOC);
    if (!$user) { api_error(404, 'User not found.'); }
    api_json(['success' => true, 'data' => $user]);
}

function api_my_bookings(PDO $dbh): void {
    $email = api_auth_user($dbh);
    $q = $dbh->prepare(
        "SELECT b.BookingId, b.PackageId, p.PackageName, p.PackageLocation, b.FromDate, b.ToDate,
                b.status, b.NoOfPersons, b.TotalCost, b.RegDate, b.VerificationCode
         FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
         WHERE b.UserEmail=:e ORDER BY b.BookingId DESC"
    );
    $q->execute([':e' => $email]);
    api_json(['success' => true, 'data' => $q->fetchAll(PDO::FETCH_ASSOC)]);
}

/**
 * Mirrors the booking-creation logic in package-details.php (persons,
 * mobile, coupon applied inline, real TotalCost stored, verification code
 * generated) — the Round 3 fix that made those fields actually get
 * populated. A REST client booking through here gets the same correct
 * behavior as the website, not a second, differently-buggy code path.
 */
function api_book(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $email = api_auth_user($dbh);

    $pid      = tms_int($_POST['package_id'] ?? 0, 1);
    $persons  = tms_int($_POST['persons'] ?? 1, 1, 20);
    $mobile   = tms_clean($_POST['mobile'] ?? '');
    $comment  = tms_clean($_POST['comment'] ?? '');
    $couponRaw = tms_clean($_POST['coupon_code'] ?? '');

    $from = DateTime::createFromFormat('Y-m-d', tms_clean($_POST['from_date'] ?? ''));
    $to   = DateTime::createFromFormat('Y-m-d', tms_clean($_POST['to_date'] ?? ''));
    $now  = new DateTime();

    if (!$pid) { api_error(400, 'package_id is required.'); }
    if (!$from || !$to) { api_error(400, 'from_date and to_date are required, format YYYY-MM-DD.'); }
    if ($from <= $now) { api_error(400, 'from_date must be in the future.'); }
    if ($to <= $from) { api_error(400, 'to_date must be after from_date.'); }

    $priceQ = $dbh->prepare("SELECT PackagePrice FROM tbltourpackages WHERE PackageId=:pid LIMIT 1");
    $priceQ->execute([':pid' => $pid]);
    $rawPrice = $priceQ->fetchColumn();
    if ($rawPrice === false) { api_error(404, 'Package not found.'); }
    if ($rawPrice === null || (float)$rawPrice <= 0) {
        api_error(422, "This package doesn't have a price set yet and can't be booked.");
    }
    $unitPrice = (float)$rawPrice;

    $subtotal = (float)$unitPrice * $persons;
    $discountAmt = 0.0;
    $couponCode  = null;
    $couponNote  = null;
    if ($couponRaw !== '') {
        $cq = $dbh->prepare("SELECT * FROM tblcoupons WHERE Code=:c AND (UserEmail=:e OR UserEmail IS NULL) AND IsUsed=0 AND ExpiryDate >= CURDATE() LIMIT 1");
        $cq->execute([':c' => $couponRaw, ':e' => $email]);
        $coupon = $cq->fetch(PDO::FETCH_OBJ);
        if ($coupon) {
            // Atomic claim — see package-details.php for why this isn't a
            // plain UPDATE after the SELECT.
            $claim = $dbh->prepare("UPDATE tblcoupons SET IsUsed=1 WHERE id=:id AND IsUsed=0");
            $claim->execute([':id' => $coupon->id]);
            if ($claim->rowCount() === 1) {
                $discountAmt = round($subtotal * ((float)$coupon->DiscountPercent / 100), 2);
                $couponCode  = $couponRaw;
            } else {
                $couponNote = 'Coupon code was just used by another request — booking created without a discount.';
            }
        } else {
            $couponNote = 'Coupon code was not valid or already used — booking created without a discount.';
        }
    }
    $total = max(0, $subtotal - $discountAmt);
    $verificationCode = strtoupper(bin2hex(random_bytes(5)));

    $q = $dbh->prepare(
        "INSERT INTO tblbooking(PackageId,UserEmail,FromDate,ToDate,Comment,status,NoOfPersons,MobileNumber,TotalCost,CouponCode,DiscountApplied,VerificationCode,BookingDate)
         VALUES(:pid,:email,:from,:to,:comment,0,:persons,:mobile,:total,:coupon,:discount,:vcode,:bdate)"
    );
    $q->execute([
        ':pid' => $pid, ':email' => $email,
        ':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d'), ':comment' => $comment,
        ':persons' => $persons, ':mobile' => $mobile ?: null, ':total' => $total,
        ':coupon' => $couponCode, ':discount' => $discountAmt, ':vcode' => $verificationCode,
        // BUG FIX: see checkout.php's identical comment — BookingDate was
        // never populated by any booking path, silently breaking several
        // downstream features that read it as the trip date.
        ':bdate' => $from->format('Y-m-d'),
    ]);
    $newId = (int)$dbh->lastInsertId();
    if (!$newId) { api_error(500, 'Could not create booking.'); }

    $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,100,100)
        ON DUPLICATE KEY UPDATE Points=Points+100, TotalEarned=TotalEarned+100")->execute([':e' => $email]);
    $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,100,:d)")
        ->execute([':e' => $email, ':d' => 'Booking #BK' . $newId . ' confirmed']);

    api_json(['success' => true, 'data' => [
        'booking_id'        => $newId,
        'total_cost'        => $total,
        'verification_code' => $verificationCode,
        'note'              => $couponNote,
    ]], 201);
}

function api_cancel_booking(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $email = api_auth_user($dbh);
    $bid   = tms_int($_POST['booking_id'] ?? 0, 1);
    if (!$bid) { api_error(400, 'booking_id is required.'); }

    $chk = $dbh->prepare("SELECT BookingId, FromDate, status FROM tblbooking WHERE BookingId=:bid AND UserEmail=:e AND status != 2 LIMIT 1");
    $chk->execute([':bid' => $bid, ':e' => $email]);
    $booking = $chk->fetch(PDO::FETCH_OBJ);
    if (!$booking) { api_error(404, 'Booking not found or already cancelled.'); }

    $fromDate = new DateTime($booking->FromDate);
    $today    = new DateTime();
    if (!($fromDate > $today && (int)$today->diff($fromDate)->days > 1)) {
        api_error(409, 'Cannot cancel a booking within 24 hours of departure.');
    }

    $wasConfirmed = ((int)$booking->status === 1);
    $dbh->prepare(
        "UPDATE tblbooking SET status=2, CancelledBy='u'" . ($wasConfirmed ? ", RefundStatus='requested'" : "") .
        " WHERE BookingId=:bid AND UserEmail=:e"
    )->execute([':bid' => $bid, ':e' => $email]);

    api_json(['success' => true, 'data' => ['message' => 'Booking cancelled.', 'refund_requested' => $wasConfirmed]]);
}

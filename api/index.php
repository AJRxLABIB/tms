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
 * Refresh (Round 115 continuation, master prompt item 42 -- no Authorization
 * header needed, the refresh token itself is the credential):
 *   POST /api/?action=refresh            – rotate a refresh token for a new access+refresh pair { refresh_token }
 *
 * Authenticated endpoints (Authorization: Bearer <token>, from /?action=login):
 *   GET  /api/?action=me                 – current user's profile
 *   GET  /api/?action=my_bookings        – current user's bookings
 *   POST /api/?action=book               – create a booking { package_id, from_date, to_date, persons, mobile, comment, coupon_code }
 *   POST /api/?action=cancel_booking     – cancel a booking { booking_id }
 *   POST /api/?action=logout             – invalidate the current token
 *   GET  /api/?action=sessions           – list this user's active API sessions/devices
 *   POST /api/?action=revoke_session     – revoke one session { session_id }
 *   POST /api/?action=revoke_all_sessions – revoke every session { keep_current? }
 *
 * V2 domain read endpoints (Round 115 continuation, "TMS V2" prompt Phase
 * 29 -- see api/v1/index.php's own docblock and TMS_V2_PHASE1_AUDIT.md
 * Section E for why this is additive query-param actions on the existing
 * v1 surface rather than a path-based /api/v2/ resource-hierarchy rewrite):
 *   GET  /api/?action=trip_readiness&booking_id=N  – readiness score for one of the caller's bookings
 *   GET  /api/?action=budget_status&booking_id=N   – real budget-vs-spend comparison + last autopilot alert, for one of the caller's bookings
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

require_once __DIR__ . '/../backend/session-boot.php';
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/services/TripReadinessScore.php';
require_once dirname(__DIR__) . '/backend/services/SupplierService.php';
require_once dirname(__DIR__) . '/backend/ai/ExpenseAnalyst.php';

require_once dirname(__DIR__) . '/backend/ApiSupport.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Correlation id: accepted from a safe X-Request-Id, otherwise generated; echoed as a header, in meta.request_id and in errors.
ApiSupport::begin($_SERVER);

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
        'refresh'         => api_refresh($dbh),
        'me'              => api_me($dbh),
        'my_bookings'     => api_my_bookings($dbh),
        'book'            => api_book($dbh),
        'cancel_booking'  => api_cancel_booking($dbh),
        'sessions'            => api_sessions($dbh),
        'revoke_session'      => api_revoke_session($dbh),
        'revoke_all_sessions' => api_revoke_all_sessions($dbh),
        'trip_readiness'      => api_trip_readiness($dbh),
        'budget_status'       => api_budget_status($dbh),
        default           => api_error(404, 'Unknown action. Available: packages, package, types, check_email, login, logout, refresh, me, my_bookings, book, cancel_booking, sessions, revoke_session, revoke_all_sessions, trip_readiness, budget_status'),
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
    $payload['meta']['request_id'] = ApiSupport::requestId();      // additive field
    ApiSupport::idempotencyFinish($status, $payload);              // no-op unless an Idempotency-Key request is in flight
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function api_error(int $status, string $message, ?string $code = null): void {
    // Legacy {"success":false,"error":"text"} is unchanged; error_code, request_id and error_detail are added.
    api_json(ApiSupport::legacyErrorBody($status, $message, $code), $status);
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

        // Standard rate-limit headers (the tighter of the buckets wins because later calls overwrite earlier ones only if stricter).
        if (!headers_sent()) {
            $remaining = max(0, $maxRequests - $count);
            if (!isset($GLOBALS['tms_api_rl_remaining']) || $remaining <= $GLOBALS['tms_api_rl_remaining']) {
                $GLOBALS['tms_api_rl_remaining'] = $remaining;
                foreach (ApiSupport::rateLimitHeaders($maxRequests, $count, $windowSeconds) as $hk => $hv) header("$hk: $hv");
            }
        }

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
function api_auth_user(PDO $dbh, ?string $requiredScope = null): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        api_error(401, 'Missing or malformed Authorization header. Expected: Bearer <token>.');
    }
    $tokenHash = hash('sha256', $m[1]);
    $q = $dbh->prepare("SELECT UserEmail, ExpiresAt, Scopes FROM tblapi_tokens WHERE TokenHash=:h LIMIT 1");
    $q->execute([':h' => $tokenHash]);
    $row = $q->fetch(PDO::FETCH_OBJ);
    if (!$row) {
        api_error(401, 'Invalid or revoked token.');
    }
    if ($row->ExpiresAt && strtotime($row->ExpiresAt) < time()) {
        api_error(401, 'Token expired. Please log in again.');
    }
    // FEATURE (Round 115 continuation, master prompt item 42 -- tracked as
    // "Phase 30" in AUDIT_REPORT_ROUND15.md): Scopes is new and nullable --
    // every token issued before this migration has Scopes=NULL, which is
    // treated as full legacy access so no pre-existing token or client is
    // affected. A token only becomes scope-restricted if the client
    // explicitly requested a scope at login (see api_login()).
    if ($requiredScope !== null && !tms_api_scope_allows($row->Scopes, $requiredScope)) {
        api_error(403, "This token's scope does not permit this action (requires '{$requiredScope}').");
    }
    $dbh->prepare("UPDATE tblapi_tokens SET LastUsedAt=NOW() WHERE TokenHash=:h")->execute([':h' => $tokenHash]);
    return $row->UserEmail;
}

/**
 * Known, checkable API scopes (Round 115 continuation, master prompt item
 * 42). Deliberately small and named after existing endpoints rather than
 * a generic verb set -- a new scoped endpoint later just adds one entry
 * here and passes it to api_auth_user(), nothing else changes.
 */
const TMS_API_KNOWN_SCOPES = ['profile:read', 'bookings:read', 'bookings:write'];

/**
 * Parses the optional `scope` login parameter (comma- or space-separated)
 * into a canonical comma-joined string, or null for "no restriction" --
 * today's behavior, unchanged, when the client sends nothing. Rejects an
 * unknown scope name outright (400) rather than silently ignoring it --
 * a typo'd scope the caller actually wanted enforced should never just
 * silently grant full access instead.
 */
function tms_api_parse_scope_param(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') { return null; }
    $parts = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\s]+/', $raw)))));
    foreach ($parts as $p) {
        if (!in_array($p, TMS_API_KNOWN_SCOPES, true)) {
            api_error(400, "Unknown scope '{$p}'. Known scopes: " . implode(', ', TMS_API_KNOWN_SCOPES) . '.');
        }
    }
    return $parts ? implode(',', $parts) : null;
}

/**
 * NULL/empty stored scopes = full (legacy) access -- every token issued
 * before this migration, and every new login that doesn't request a
 * scope, behaves exactly as it did before this feature existed.
 */
function tms_api_scope_allows(?string $tokenScopes, string $required): bool {
    if ($tokenScopes === null || trim($tokenScopes) === '') { return true; }
    $list = array_map('trim', explode(',', $tokenScopes));
    return in_array($required, $list, true);
}

// ─── Handlers ────────────────────────────────────────────────────────────────

function api_packages(PDO $dbh): void {
    $search    = tms_clean($_GET['search'] ?? '');
    $type      = tms_clean($_GET['type']   ?? '');
    $pp        = ApiSupport::pageParams($_GET, 12, 50);   // accepts page + (limit | per_page), clamped
    $page      = $pp['page'];
    $limit     = $pp['per_page'];
    $sortBy    = in_array($_GET['sort'] ?? '', ['price_asc','price_desc','name']) ? $_GET['sort'] : 'default';
    $offset    = ($page - 1) * $limit;

    $where  = [TMS_PKG_PUBLIC_SQL]; // published packages only
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
        'meta'    => ApiSupport::pageMeta($total, $page, $limit, strtok($_SERVER['REQUEST_URI'] ?? '/api/', '?'), $_GET),
    ]);
}

function api_package(PDO $dbh): void {
    $id = tms_int($_GET['id'] ?? 0, 1);
    if (!$id) { api_error(400, 'Missing package id.'); }

    $q = $dbh->prepare("SELECT p.*,
        (SELECT AVG(r.Rating) FROM tblreviews r WHERE r.PackageId=p.PackageId AND r.Status=1) as ReviewAvg,
        (SELECT COUNT(*)      FROM tblreviews r WHERE r.PackageId=p.PackageId AND r.Status=1) as ReviewCount
        FROM tbltourpackages p WHERE p.PackageId=:id AND p.Status=1 AND (p.ApprovalStatus IS NULL OR p.ApprovalStatus='approved') LIMIT 1");
    $q->bindParam(':id', $id, PDO::PARAM_INT);
    $q->execute();
    $pkg = $q->fetch(PDO::FETCH_ASSOC);

    if (!$pkg) { api_error(404, 'Package not found.'); }
    api_json(['success' => true, 'data' => $pkg]);
}

function api_types(PDO $dbh): void {
    $q    = $dbh->query("SELECT DISTINCT PackageType FROM tbltourpackages WHERE " . TMS_PKG_PUBLIC_SQL . " ORDER BY PackageType");
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

    $has2fa = tms_customer_2fa_columns_exist($dbh);
    $q = $dbh->prepare("SELECT id, FullName, EmailId, Password" . ($has2fa ? ", TwoFAEnabled, TwoFASecret" : "") . " FROM tblusers WHERE EmailId=:e LIMIT 1");
    $q->execute([':e' => $email]);
    $user = $q->fetch(PDO::FETCH_OBJ);

    if (!$user || !tms_verify_password($pass, $user->Password, (int)$user->id, $dbh)) {
        tms_record_failed_login($dbh, $email, $ip);
        api_error(401, 'Invalid email or password.');
    }
    // SECURITY FIX: the website asks for the authenticator code when TwoFAEnabled, but this endpoint issued a 30-day token on the
    // password alone, so 2FA could be bypassed through the REST API. Accounts with 2FA must now also send `totp_code`.
    if ($has2fa && !empty($user->TwoFAEnabled) && !empty($user->TwoFASecret)) {
        $tk = '2fa-user:' . strtolower($email);
        $wait = tms_login_lockout_minutes_remaining($dbh, $tk, $ip);
        if ($wait > 0) { api_error(429, "Too many failed attempts. Please try again in {$wait} minute" . ($wait === 1 ? '' : 's') . '.'); }
        $code = trim((string)($_POST['totp_code'] ?? ''));
        if ($code === '') { api_error(401, 'Two-factor code required. Send the 6-digit code from your authenticator app as totp_code.'); }
        if (!tms_totp_verify((string)$user->TwoFASecret, $code)) {
            tms_record_failed_login($dbh, $tk, $ip);
            api_error(401, 'Invalid or expired two-factor code.');
        }
        tms_clear_login_attempts($dbh, $tk, $ip);
    }
    tms_clear_login_attempts($dbh, $email, $ip);

    $device = tms_clean($_POST['device'] ?? '');
    // FEATURE (Round 115 continuation, master prompt item 42): optional
    // scope restriction, opt-in only -- omitting `scope` entirely keeps
    // exactly today's behavior (a full-access token), so no existing
    // client is affected by this parameter existing.
    $scopes = tms_api_parse_scope_param(tms_clean($_POST['scope'] ?? ''));
    $session = tms_api_issue_session($dbh, $user->EmailId, $device ?: null, $scopes);

    api_json(['success' => true, 'data' => [
        'token'              => $session['access_token'],
        'expires_at'         => $session['access_expires_at'],
        // FEATURE (Round 115 continuation): additive fields -- a client
        // written against the pre-existing docs that only reads `token`/
        // `expires_at`/`user` keeps working completely unchanged.
        'refresh_token'      => $session['refresh_token'],
        'refresh_expires_at' => $session['refresh_expires_at'],
        'scopes'             => $scopes,
        'user'               => ['id' => (int)$user->id, 'full_name' => $user->FullName, 'email' => $user->EmailId],
    ]]);
}

/**
 * Issues a brand-new access token + refresh token pair for a fresh login
 * (Round 115 continuation, master prompt item 42). api_refresh() has its
 * own, separate rotation logic below since a refresh must reuse the
 * existing FamilyId rather than start a new one.
 */
function tms_api_issue_session(PDO $dbh, string $email, ?string $device, ?string $scopes): array {
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $accessExpires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $dbh->prepare("INSERT INTO tblapi_tokens(UserEmail, TokenHash, DeviceLabel, ExpiresAt, Scopes) VALUES(:e,:h,:d,:x,:s)")
        ->execute([':e' => $email, ':h' => $tokenHash, ':d' => $device, ':x' => $accessExpires, ':s' => $scopes]);
    $apiTokenId = (int)$dbh->lastInsertId();

    $rawRefresh     = bin2hex(random_bytes(32));
    $refreshHash    = hash('sha256', $rawRefresh);
    $familyId       = bin2hex(random_bytes(16));
    $refreshExpires = date('Y-m-d H:i:s', strtotime('+90 days'));
    $dbh->prepare(
        "INSERT INTO tblapi_refresh_tokens(ApiTokenId, UserEmail, TokenHash, FamilyId, Scopes, ExpiresAt)
         VALUES(:aid,:e,:h,:fam,:s,:x)"
    )->execute([':aid' => $apiTokenId, ':e' => $email, ':h' => $refreshHash, ':fam' => $familyId, ':s' => $scopes, ':x' => $refreshExpires]);

    return [
        'access_token'       => $rawToken,
        'access_expires_at'  => $accessExpires,
        'refresh_token'      => $rawRefresh,
        'refresh_expires_at' => $refreshExpires,
    ];
}

/**
 * Exchanges a still-valid, not-yet-used refresh token for a brand-new
 * access + refresh token pair (rotation). No Authorization header here --
 * the refresh token in the POST body IS the credential, same principle as
 * a password-reset token.
 *
 * Reuse detection: a refresh token is single-use. If one that's already
 * been rotated away (RevokedAt IS NOT NULL) is presented again, that can
 * only mean it was replayed after already being exchanged once -- so the
 * entire family (every token descended from the same original login) is
 * revoked immediately, including the still-live access token, forcing a
 * fresh login. This is the standard refresh-token-theft mitigation, not
 * an invented mechanism.
 */
function api_refresh(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $raw = (string)($_POST['refresh_token'] ?? '');
    if ($raw === '') { api_error(400, 'refresh_token is required.'); }
    $hash = hash('sha256', $raw);

    $q = $dbh->prepare("SELECT * FROM tblapi_refresh_tokens WHERE TokenHash=:h LIMIT 1");
    $q->execute([':h' => $hash]);
    $row = $q->fetch(PDO::FETCH_OBJ);
    if (!$row) {
        api_error(401, 'Invalid refresh token.');
    }

    if ($row->RevokedAt !== null) {
        $dbh->prepare("UPDATE tblapi_refresh_tokens SET RevokedAt=NOW() WHERE FamilyId=:fam AND RevokedAt IS NULL")
            ->execute([':fam' => $row->FamilyId]);
        $dbh->prepare(
            "DELETE FROM tblapi_tokens WHERE id IN (SELECT ApiTokenId FROM tblapi_refresh_tokens WHERE FamilyId=:fam)"
        )->execute([':fam' => $row->FamilyId]);
        api_error(401, 'This refresh token was already used. All sessions from this login have been revoked for safety -- please log in again.');
    }

    if ($row->ExpiresAt && strtotime($row->ExpiresAt) < time()) {
        api_error(401, 'Refresh token expired. Please log in again.');
    }

    $deviceLabel = null;
    if ($row->ApiTokenId) {
        $dl = $dbh->prepare("SELECT DeviceLabel FROM tblapi_tokens WHERE id=:id");
        $dl->execute([':id' => $row->ApiTokenId]);
        $deviceLabel = $dl->fetchColumn();
        $deviceLabel = $deviceLabel !== false ? $deviceLabel : null;
        $dbh->prepare("DELETE FROM tblapi_tokens WHERE id=:id")->execute([':id' => $row->ApiTokenId]);
    }
    $dbh->prepare("UPDATE tblapi_refresh_tokens SET RevokedAt=NOW() WHERE id=:id")->execute([':id' => $row->id]);

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $accessExpires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $dbh->prepare("INSERT INTO tblapi_tokens(UserEmail, TokenHash, DeviceLabel, ExpiresAt, Scopes) VALUES(:e,:h,:d,:x,:s)")
        ->execute([':e' => $row->UserEmail, ':h' => $tokenHash, ':d' => $deviceLabel, ':x' => $accessExpires, ':s' => $row->Scopes]);
    $newApiTokenId = (int)$dbh->lastInsertId();

    $rawRefresh     = bin2hex(random_bytes(32));
    $refreshHash    = hash('sha256', $rawRefresh);
    $refreshExpires = date('Y-m-d H:i:s', strtotime('+90 days'));
    $dbh->prepare(
        "INSERT INTO tblapi_refresh_tokens(ApiTokenId, UserEmail, TokenHash, FamilyId, Scopes, ExpiresAt)
         VALUES(:aid,:e,:h,:fam,:s,:x)"
    )->execute([':aid' => $newApiTokenId, ':e' => $row->UserEmail, ':h' => $refreshHash, ':fam' => $row->FamilyId, ':s' => $row->Scopes, ':x' => $refreshExpires]);

    api_json(['success' => true, 'data' => [
        'token'              => $rawToken,
        'expires_at'         => $accessExpires,
        'refresh_token'      => $rawRefresh,
        'refresh_expires_at' => $refreshExpires,
        'scopes'             => $row->Scopes,
    ]]);
}

function api_logout(PDO $dbh): void {
    $email = api_auth_user($dbh);
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    preg_match('/^Bearer\s+(\S+)$/i', $header, $m);
    $tokenHash = hash('sha256', $m[1]);
    // FEATURE (Round 115 continuation): also revoke any refresh token tied
    // to this access token, so a website/app logout can't be silently
    // undone later by a leftover refresh token minting a fresh access
    // token. Must run before the DELETE below -- it depends on the
    // tblapi_tokens row the subquery reads still existing.
    $dbh->prepare(
        "UPDATE tblapi_refresh_tokens SET RevokedAt=NOW()
         WHERE RevokedAt IS NULL AND ApiTokenId=(SELECT id FROM tblapi_tokens WHERE TokenHash=:h LIMIT 1)"
    )->execute([':h' => $tokenHash]);
    $dbh->prepare("DELETE FROM tblapi_tokens WHERE TokenHash=:h")->execute([':h' => $tokenHash]);
    api_json(['success' => true, 'data' => ['message' => 'Logged out.']]);
}

/**
 * Lists the current user's active API sessions (Round 115 continuation,
 * master prompt item 42 -- device/session management for API tokens,
 * which AUDIT_REPORT_ROUND15.md's Section E.2 confirmed only existed at
 * the web-session layer before this, not here). Never returns the token
 * hash or raw token -- only what's needed to recognize and revoke one.
 */
function api_sessions(PDO $dbh): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    preg_match('/^Bearer\s+(\S+)$/i', $header, $m);
    $currentHash = isset($m[1]) ? hash('sha256', $m[1]) : '';
    $email = api_auth_user($dbh);

    $q = $dbh->prepare(
        "SELECT id, DeviceLabel, CreatedAt, LastUsedAt, ExpiresAt, TokenHash FROM tblapi_tokens
         WHERE UserEmail=:e ORDER BY COALESCE(LastUsedAt, CreatedAt) DESC"
    );
    $q->execute([':e' => $email]);
    $sessions = array_map(function ($r) use ($currentHash) {
        return [
            'id'           => (int)$r['id'],
            'device'       => $r['DeviceLabel'],
            'created_at'   => $r['CreatedAt'],
            'last_used_at' => $r['LastUsedAt'],
            'expires_at'   => $r['ExpiresAt'],
            'is_current'   => ($r['TokenHash'] === $currentHash),
        ];
    }, $q->fetchAll(PDO::FETCH_ASSOC));

    api_json(['success' => true, 'data' => $sessions]);
}

function api_revoke_session(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $email = api_auth_user($dbh);
    $sid = tms_int($_POST['session_id'] ?? 0, 1);
    if (!$sid) { api_error(400, 'session_id is required.'); }

    // Ownership-checked the same way BookingRepository::findActiveForUser()
    // guards booking cancellation -- a token can only ever revoke its own
    // user's sessions, never another account's.
    $chk = $dbh->prepare("SELECT id FROM tblapi_tokens WHERE id=:id AND UserEmail=:e LIMIT 1");
    $chk->execute([':id' => $sid, ':e' => $email]);
    if (!$chk->fetch()) { api_error(404, 'Session not found.'); }

    $dbh->prepare("UPDATE tblapi_refresh_tokens SET RevokedAt=NOW() WHERE RevokedAt IS NULL AND ApiTokenId=:id")->execute([':id' => $sid]);
    $dbh->prepare("DELETE FROM tblapi_tokens WHERE id=:id AND UserEmail=:e")->execute([':id' => $sid, ':e' => $email]);

    api_json(['success' => true, 'data' => ['message' => 'Session revoked.']]);
}

/**
 * Revokes every API session for the current user -- the API-token
 * equivalent of the web layer's existing "logout all devices" (SECURITY.md,
 * Phase 8). Defaults to revoking the CURRENT session too (matching a plain
 * "sign out everywhere" expectation); pass keep_current=1 to keep using
 * the token that made this request.
 */
function api_revoke_all_sessions(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    preg_match('/^Bearer\s+(\S+)$/i', $header, $m);
    $currentHash = isset($m[1]) ? hash('sha256', $m[1]) : '';
    $email = api_auth_user($dbh);
    $keepCurrent = !empty($_POST['keep_current']);

    // Runs before the DELETE below -- depends on tblapi_tokens still
    // holding every one of this user's current session rows.
    $dbh->prepare(
        "UPDATE tblapi_refresh_tokens SET RevokedAt=NOW()
         WHERE RevokedAt IS NULL AND ApiTokenId IN (SELECT id FROM tblapi_tokens WHERE UserEmail=:e)"
    )->execute([':e' => $email]);

    if ($keepCurrent && $currentHash !== '') {
        $dbh->prepare("DELETE FROM tblapi_tokens WHERE UserEmail=:e AND TokenHash != :h")->execute([':e' => $email, ':h' => $currentHash]);
    } else {
        $dbh->prepare("DELETE FROM tblapi_tokens WHERE UserEmail=:e")->execute([':e' => $email]);
    }

    api_json(['success' => true, 'data' => [
        'message' => $keepCurrent ? 'All other sessions revoked.' : 'All sessions revoked. You have been logged out everywhere, including this session.',
    ]]);
}

function api_me(PDO $dbh): void {
    $email = api_auth_user($dbh, 'profile:read');
    $q = $dbh->prepare("SELECT id, FullName, EmailId, MobileNumber, RegDate FROM tblusers WHERE EmailId=:e LIMIT 1");
    $q->execute([':e' => $email]);
    $user = $q->fetch(PDO::FETCH_ASSOC);
    if (!$user) { api_error(404, 'User not found.'); }
    api_json(['success' => true, 'data' => $user]);
}

function api_my_bookings(PDO $dbh): void {
    $email = api_auth_user($dbh, 'bookings:read');
    $q = $dbh->prepare(
        "SELECT b.BookingId, b.PackageId, p.PackageName, p.PackageLocation, b.FromDate, b.ToDate,
                b.status, b.NoOfPersons, b.TotalCost, b.RegDate, b.VerificationCode
         FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
         WHERE b.UserEmail=:e ORDER BY b.BookingId DESC"
        // Pagination is opt-in (?page= or ?per_page=) so existing clients still receive the full list unchanged.
        . (isset($_GET['page']) || isset($_GET['per_page']) ? ' LIMIT :lim OFFSET :off' : '')
    );
    $paged = isset($_GET['page']) || isset($_GET['per_page']);
    $pp = ApiSupport::pageParams($_GET, 20, 100);
    $q->bindValue(':e', $email, PDO::PARAM_STR);
    if ($paged) { $q->bindValue(':lim', $pp['per_page'], PDO::PARAM_INT); $q->bindValue(':off', $pp['offset'], PDO::PARAM_INT); }
    $q->execute();
    $out = ['success' => true, 'data' => $q->fetchAll(PDO::FETCH_ASSOC)];
    if ($paged) {
        $c = $dbh->prepare('SELECT COUNT(*) FROM tblbooking WHERE UserEmail=:e');
        $c->execute([':e' => $email]);
        $out['meta'] = ApiSupport::pageMeta((int)$c->fetchColumn(), $pp['page'], $pp['per_page'], strtok($_SERVER['REQUEST_URI'] ?? '/api/', '?'), $_GET);
    }
    api_json($out);
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
    $email = api_auth_user($dbh, 'bookings:write');

    // Optional Idempotency-Key header: a retried request (lost response, double tap) replays the first result instead of
    // creating a second booking. Without the header behaviour is exactly as before.
    ApiSupport::idempotencyBegin($dbh, $email, 'book', $_POST, $_SERVER, function (int $status, array $payload): void { api_json($payload, $status); });

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

    $priceQ = $dbh->prepare("SELECT PackagePrice FROM tbltourpackages WHERE PackageId=:pid AND " . TMS_PKG_PUBLIC_SQL . " LIMIT 1");
    $priceQ->execute([':pid' => $pid]);
    $rawPrice = $priceQ->fetchColumn();
    if ($rawPrice === false) { api_error(404, 'Package not found.'); } // also: unpublished / pending-review packages are not bookable
    if ($rawPrice === null || (float)$rawPrice <= 0) {
        api_error(422, "This package doesn't have a price set yet and can't be booked.");
    }
    // Round 84 (master prompt items 8/37 -- "never use floating-point
    // arithmetic for money"): PackagePrice is a whole-currency-unit INT
    // column -- (int) is exact for the same real values (float) was
    // carrying. The coupon discount math just below still uses
    // round(...,2) percentage arithmetic, unchanged here to stay mirrored
    // with package-details.php's identical, pre-existing coupon logic
    // (not touched this round -- Rule 73).
    // Same guards and same price as the website's Book Now (package-details.php): supplier blackout dates, the date-specific
    // dynamic price, and a LOCKED seat check inside one transaction (was: none of these, so the API could oversell, book blacked-out
    // dates and charge the raw list price).
    $blk = SupplierService::blackoutConflict($dbh, $pid, $from->format('Y-m-d'), $to->format('Y-m-d'));
    if ($blk !== null) { api_error(409, $blk); }
    $pricing   = tms_dynamic_price($dbh, $pid, (float)$rawPrice, $from->format('Y-m-d'));
    $unitPrice = (int)$pricing['final'];
    $subtotal  = $unitPrice * $persons;

    $dbh->beginTransaction();
    try {
    $seatsLeft = tms_remaining_seats_for_update($dbh, $pid);
    if ($seatsLeft !== null && $persons > $seatsLeft) {
        $dbh->rollBack();
        api_error(409, $seatsLeft === 0 ? 'Sorry, this package is sold out.' : "Only {$seatsLeft} seat" . ($seatsLeft === 1 ? '' : 's') . " left, but {$persons} requested.");
    }
    $discountAmt = 0;
    $couponCode  = null;
    $couponNote  = null;
    if ($couponRaw !== '') {
        $cq = $dbh->prepare("SELECT * FROM tblcoupons WHERE Code=:c AND (UserEmail=:e OR UserEmail IS NULL) AND IsUsed=0 AND (ExpiryDate IS NULL OR ExpiryDate >= CURDATE()) LIMIT 1");
        $cq->execute([':c' => $couponRaw, ':e' => $email]);
        $coupon = $cq->fetch(PDO::FETCH_OBJ);
        if ($coupon) {
            // Same rule as the website: a coupon with no owner is a site-wide promo and is NOT consumed (was: the first API booking
            // burned WELCOME10/SUMMER20 for every customer). Personal coupons are claimed atomically.
            $isGlobal = ($coupon->UserEmail === null || $coupon->UserEmail === '');
            $claimed  = true;
            if (!$isGlobal) {
                $claim = $dbh->prepare("UPDATE tblcoupons SET IsUsed=1 WHERE id=:id AND IsUsed=0");
                $claim->execute([':id' => $coupon->id]);
                $claimed = ($claim->rowCount() === 1);
            }
            if ($claimed) {
                $discountAmt = tms_coupon_discount($subtotal, (float)$coupon->DiscountPercent); // clamped, integer-exact
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
    // FEATURE (Round 115 continuation, master prompt item 37 -- Currency):
    // records what the customer was actually shown (source/rate/timestamp)
    // if they had a non-BDT display currency selected — see
    // tms_capture_booking_fx()'s own docblock. TotalCost itself is
    // untouched, still BDT.
    $fx = tms_capture_booking_fx((float)$total);
    // Defensive (Rule 8): migration 013 may not have run yet on this
    // deployment -- never let a pending migration break the public API's
    // booking endpoint.
    $hasBookingFx = tms_booking_fx_columns_exist($dbh);

    $q = $dbh->prepare(
        "INSERT INTO tblbooking(PackageId,UserEmail,FromDate,ToDate,Comment,status,NoOfPersons,MobileNumber,TotalCost,CouponCode,DiscountApplied,VerificationCode,BookingDate" . ($hasBookingFx ? ",DisplayCurrency,DisplayAmount,FxRate,FxRateSource,FxRateTimestamp" : "") . ")
         VALUES(:pid,:email,:from,:to,:comment,0,:persons,:mobile,:total,:coupon,:discount,:vcode,:bdate" . ($hasBookingFx ? ",:dcur,:damt,:frate,:fsrc,:fts" : "") . ")"
    );
    $q->execute(array_merge([
        ':pid' => $pid, ':email' => $email,
        ':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d'), ':comment' => $comment,
        ':persons' => $persons, ':mobile' => $mobile ?: null, ':total' => $total,
        ':coupon' => $couponCode, ':discount' => $discountAmt, ':vcode' => $verificationCode,
        // BUG FIX: see checkout.php's identical comment — BookingDate was
        // never populated by any booking path, silently breaking several
        // downstream features that read it as the trip date.
        ':bdate' => $from->format('Y-m-d'),
    ], $hasBookingFx ? [
        ':dcur' => $fx['currency'], ':damt' => $fx['converted_amount'],
        ':frate' => $fx['rate'], ':fsrc' => $fx['source'], ':fts' => $fx['rate_timestamp'],
    ] : []));
    $newId = (int)$dbh->lastInsertId();
    if (!$newId) { $dbh->rollBack(); api_error(500, 'Could not create booking.'); }

    tms_loyalty_award_booking($dbh, $email, $newId, 100);
    $dbh->commit();
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log('[TMS API] booking failed: ' . $e->getMessage());
        api_error(500, 'Could not create booking.');
    }

    api_json(['success' => true, 'data' => [
        'booking_id'        => $newId,
        'total_cost'        => $total,
        'verification_code' => $verificationCode,
        'note'              => $couponNote,
        // FEATURE (Round 115 continuation, master prompt item 37 --
        // Currency): additive -- null unless the caller's session had a
        // non-BDT display currency selected (rare for a pure API client,
        // common if the same session cookie carries over from the
        // website). A client ignoring these fields is unaffected.
        'display_currency'  => $fx['currency'],
        'display_amount'    => $fx['converted_amount'],
        'fx_rate'           => $fx['rate'],
    ]], 201);
}

function api_cancel_booking(PDO $dbh): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(405, 'POST required.'); }
    $email = api_auth_user($dbh, 'bookings:write');
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
        " WHERE BookingId=:bid AND UserEmail=:e AND status<>2"
    )->execute([':bid' => $bid, ':e' => $email]);
    tms_loyalty_reverse_booking($dbh, (int)$bid);

    api_json(['success' => true, 'data' => ['message' => 'Booking cancelled.', 'refund_requested' => $wasConfirmed]]);
}

/**
 * V2 domain endpoint (Round 115 continuation, "TMS V2" prompt Phase 29).
 * TripReadinessScore was built this session (frontend/my-trip.php) but had
 * no API exposure at all -- a real, scoped gap for any client other than
 * the website itself. Ownership is checked the same way api_cancel_booking
 * does it (UserEmail=:e in the WHERE, not a separate authorization step),
 * so this can never return another user's booking's readiness.
 */
function api_trip_readiness(PDO $dbh): void {
    $email = api_auth_user($dbh, 'bookings:read');
    // NOTE: tms_int($v, 1) would clamp a missing/zero value UP to 1 (its
    // min), making this required-check unreachable -- min=0 here instead,
    // so 0/negative/missing all correctly trigger the 400 below.
    $bid   = tms_int($_GET['booking_id'] ?? 0, 0);
    if ($bid < 1) { api_error(400, 'booking_id is required.'); }

    $q = $dbh->prepare("SELECT BookingId, PaymentStatus, status FROM tblbooking WHERE BookingId=:bid AND UserEmail=:e LIMIT 1");
    $q->execute([':bid' => $bid, ':e' => $email]);
    $booking = $q->fetch(PDO::FETCH_OBJ);
    if (!$booking) { api_error(404, 'Booking not found.'); }

    $readiness = TripReadinessScore::forBooking($dbh, $booking, $email);
    api_json(['success' => true, 'data' => $readiness]);
}

/**
 * V2 domain endpoint (Round 115 continuation, "TMS V2" prompt Phase 29).
 * Same gap as api_trip_readiness() above but for BudgetAutopilot /
 * ExpenseAnalyst::getBudgetComparison() -- also built this session, also
 * never exposed. Ownership is checked explicitly here (rather than relying
 * on getBudgetComparison()'s own UserEmail-scoped query silently returning
 * a zeroed-out comparison for a booking that isn't the caller's), so a
 * foreign or nonexistent booking_id gets a real 404 instead of a
 * misleadingly "empty" 200.
 */
function api_budget_status(PDO $dbh): void {
    $email = api_auth_user($dbh, 'bookings:read');
    // Same fix as api_trip_readiness() above -- min=0, not 1.
    $bid   = tms_int($_GET['booking_id'] ?? 0, 0);
    if ($bid < 1) { api_error(400, 'booking_id is required.'); }

    $chk = $dbh->prepare("SELECT BookingId FROM tblbooking WHERE BookingId=:bid AND UserEmail=:e LIMIT 1");
    $chk->execute([':bid' => $bid, ':e' => $email]);
    if (!$chk->fetchColumn()) { api_error(404, 'Booking not found.'); }

    $analyst    = new ExpenseAnalyst($dbh);
    $comparison = $analyst->getBudgetComparison($email, $bid);

    $alertQ = $dbh->prepare("SELECT LastNotifiedAt, LastPercentUsed FROM tblbudget_alerts WHERE BookingId=:bid LIMIT 1");
    $alertQ->execute([':bid' => $bid]);
    $alert = $alertQ->fetch(PDO::FETCH_ASSOC) ?: null;

    api_json(['success' => true, 'data' => $comparison + ['last_alert' => $alert]]);
}

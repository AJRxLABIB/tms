<?php
/**
 * TMS — Central Configuration
 * AI-Powered Travel Copilot Edition
 *
 * ✅ Configured for Google Gemini AI (FREE tier — 1,500 requests/day)
 *
 * HOW TO GET YOUR FREE GEMINI API KEY:
 * 1. Go to https://aistudio.google.com
 * 2. Sign in with your Google account
 * 3. Click "Get API Key" → "Create API Key"
 * 4. Copy the key and paste it below
 *
 * KEYS NOW LIVE IN .env (project root), NOT in this file.
 * Copy .env.example → .env and fill in your real keys there.
 * This file just reads them via tms_env() with safe fallbacks.
 */

require_once __DIR__ . '/env-loader.php';
tms_load_dotenv(__DIR__ . '/../.env');
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/totp.php';

// ─── AI Configuration (Google Gemini — FREE) ──────────────────────────────────
define('GEMINI_API_KEY',  tms_env('GEMINI_API_KEY', ''));
define('GEMINI_MODEL',    tms_env('GEMINI_MODEL', 'gemini-2.5-flash')); // free-tier model as of mid-2026 (gemini-1.5-flash is shut down)
define('AI_MAX_TOKENS',   (int) tms_env('AI_MAX_TOKENS', 2000));
define('AI_CACHE_TTL',    (int) tms_env('AI_CACHE_TTL', 3600));   // Cache AI responses for 1 hour
define('AI_RATE_LIMIT',   (int) tms_env('AI_RATE_LIMIT', 30));    // Max AI calls per user per hour

// ─── Backward-compat aliases (so existing code using ANTHROPIC_* still works) ─
define('ANTHROPIC_API_KEY', GEMINI_API_KEY);
define('ANTHROPIC_MODEL',   GEMINI_MODEL);

// Diagnostic only — a valid Google AI Studio key normally starts with "AIza".
// This does NOT block the app (some proxied/alternate key formats exist),
// it just logs so "AI features silently return 503" isn't a total mystery.
if (GEMINI_API_KEY !== '' && !str_starts_with(GEMINI_API_KEY, 'AIza')) {
    error_log('[TMS Config] GEMINI_API_KEY does not look like a standard Google AI Studio key '
        . '(those normally start with "AIza"). If AI-powered features (Explore Mode overview, '
        . 'AI Recommender, chatbots) are failing, regenerate a fresh key at '
        . 'https://aistudio.google.com and update .env.');
}

// ─── Weather Configuration (OpenWeatherMap — FREE, 1000 calls/day) ───────────
// Get your free key at: https://home.openweathermap.org/users/sign_up
// Dashboard → "API keys" tab → copy the default key → paste below
define('OPENWEATHER_API_KEY', tms_env('OPENWEATHER_API_KEY', ''));
define('WEATHER_CACHE_TTL',   (int) tms_env('WEATHER_CACHE_TTL', 1800)); // cache each destination's weather for 30 min

// ─── Explore Mode Configuration ────────────────────────────────────────────────
// Explore Mode (free-roam destination explorer, no booking required) works
// out-of-the-box using FREE, key-less APIs:
//   • Nominatim (OpenStreetMap)     — destination search / geocoding
//   • Open-Meteo                    — live weather, forecast & air quality
//   • Overpass API (OpenStreetMap)  — nearby hotels/restaurants/hospitals/etc.
//   • OSRM                          — driving/walking/cycling routes & ETAs
// These optional keys upgrade specific pieces (richer place data with ratings/
// photos, or transit directions) but are NOT required for the feature to run.
// (Explore Mode reuses the OPENWEATHER_API_KEY constant above if you set one.)
define('GOOGLE_PLACES_API_KEY', tms_env('GOOGLE_PLACES_API_KEY', ''));   // optional — enables ratings/photos/opening-hours for nearby places
define('GOOGLE_MAPS_API_KEY',   tms_env('GOOGLE_MAPS_API_KEY', ''));     // optional — enables Google Directions (incl. public transit) as an upgrade over OSRM
define('EXPLORE_CACHE_TTL_INFO',    60 * 60 * 24 * 14); // AI destination info cached 14 days
define('EXPLORE_CACHE_TTL_WEATHER', 60 * 20);            // weather cached 20 minutes
define('EXPLORE_CACHE_TTL_PLACES',  60 * 60 * 6);        // nearby places cached 6 hours

// ─── Database credentials ──────────────────────────────────────────────────────
define('DB_HOST', tms_env('DB_HOST', 'localhost'));
define('DB_USER', tms_env('DB_USER', 'root'));
define('DB_PASS', tms_env('DB_PASS', ''));
define('DB_NAME', tms_env('DB_NAME', 'tms'));

// ─── Application settings ─────────────────────────────────────────────────────
define('APP_NAME',    tms_env('APP_NAME', 'TMS AI Travel Copilot'));
define('APP_VERSION', '7.0');
define('APP_ENV',     tms_env('APP_ENV', 'local'));
define('APP_DEBUG',   (bool) tms_env('APP_DEBUG', true));
define('APP_URL',     tms_env('APP_URL', 'http://localhost/tms'));

// ─── Security settings ────────────────────────────────────────────────────────
define('PASSWORD_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_COST', 12);

// ─── PDO connection ───────────────────────────────────────────────────────────
try {
    $dbh = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true, // reuse connections across requests (PHP-FPM/mod_php) — cuts connection overhead
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    exit("Database connection failed. Please check your server configuration.");
}

// ─── Unified cache service (Phase 1d) ─────────────────────────────────────────
// Available everywhere $dbh is (i.e. everywhere this config.php is included),
// same as $dbh itself. See includes/CacheService.php for backend details.
require_once __DIR__ . '/CacheService.php';
require_once __DIR__ . '/PricingEngine.php';
$tmsCache = new CacheService($dbh);

// ─── Fatal-error safety net ────────────────────────────────────────────────────
// ROOT-CAUSE FIX: pages like destinations.php ran with error_reporting(0) and
// (on shared/XAMPP defaults) display_errors sometimes Off, so an uncaught
// fatal (e.g. a PDOException from a query touching a not-yet-migrated column)
// produced a completely blank white page with no clue what happened — exactly
// the "destinations.php Blank Page" bug from the audit. This prints a single,
// friendly, on-brand fallback page instead whenever a fatal happens before
// any real output has been sent, and always logs the real error server-side.
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('[TMS Fatal] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);

    // Don't clobber JSON API responses — those endpoints already catch
    // Throwable themselves and return a proper JSON error payload.
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type: application/json') !== false) return;
    }
    if (ob_get_level() > 0) { @ob_end_clean(); }
    if (headers_sent()) return; // something was already streamed; leave it as-is

    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>TMS — Temporarily Unavailable</title>'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<style>body{font-family:Poppins,Arial,sans-serif;background:#f5f8fb;color:#333;display:flex;'
       . 'align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px;}'
       . '.box{background:#fff;padding:40px;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:440px;}'
       . '.box i{font-size:40px;color:#e74c3c;margin-bottom:14px;display:block;}'
       . 'a{color:#3F84B1;font-weight:600;text-decoration:none;}</style></head><body>'
       . '<div class="box"><i>&#9888;</i><h2>Something went wrong</h2>'
       . '<p>This page hit an unexpected error. Our team has been notified. Please try again, '
       . 'or <a href="index.php">return to the homepage</a>.</p></div></body></html>';
});

// ─── Session init ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // SECURITY FIX (Section 13 audit): the session cookie had httponly and
    // samesite set, but no `secure` flag — meaning it would still be sent
    // over a plain, unencrypted HTTP connection if the site is ever served
    // that way, which is exactly what makes session hijacking via network
    // sniffing possible. Not hardcoded to true, though: this app runs on
    // localhost/XAMPP over plain HTTP during development, and a hardcoded
    // secure flag would silently break every login there (browsers refuse
    // to send `secure` cookies over HTTP at all) — so it's detected from
    // the actual request instead, covering both a direct HTTPS connection
    // and one terminated at a reverse proxy/load balancer upstream.
    $tmsIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $tmsIsHttps]);
    session_start();
}

// ─── Referral link capture ────────────────────────────────────────────────────
// A visitor may land on any page via a referral link (?ref=CODE) long before
// they actually sign up, so remember it in a 30-day cookie rather than only
// in the session, and pick it back up in the sign-up form later.
if (!empty($_GET['ref']) && preg_match('/^[A-Za-z0-9]{4,20}$/', $_GET['ref'])) {
    setcookie('tms_ref', $_GET['ref'], time() + 30 * 86400, '/', '', false, true);
    $_COOKIE['tms_ref'] = $_GET['ref']; // available immediately on this same request too
}

// ─── Core table setup (CREATE TABLE IF NOT EXISTS calls — safe/idempotent,
//     so gating them once-per-session is just a perf optimisation) ─────────────
if (empty($_SESSION['tms_db_init'])) {
    _tms_run_core_migrations($dbh);
    $_SESSION['tms_db_init'] = true;
}

if (empty($_SESSION['tms_ai_tables'])) {
    _tms_run_ai_migrations($dbh);
    $_SESSION['tms_ai_tables'] = true;
}

if (empty($_SESSION['tms_explore_tables'])) {
    _tms_run_explore_migrations($dbh);
    $_SESSION['tms_explore_tables'] = true;
}

if (empty($_SESSION['tms_api_tables'])) {
    _tms_run_api_migrations($dbh);
    $_SESSION['tms_api_tables'] = true;
}

// ─── Schema-drift repair (NOT session-gated — see note below) ─────────────────
// ROOT-CAUSE FIX (audit finding, July 2026):
// Several tables (tbltourpackages, tblusers, tblbooking, tblissues, tblpages)
// already existed in the original tms.sql install with an OLDER, narrower set
// of columns. `CREATE TABLE IF NOT EXISTS` above is therefore a silent no-op
// for them — MySQL skips the whole statement because the table name already
// exists, so newer columns declared only inside those CREATE TABLE strings
// (e.g. tbltourpackages.PackageDescription/PackageDuration/MapLat/MapLng/
// AvgRating/Status, tblusers.Language) were NEVER actually added to a real
// install. That silently broke: the 400+ package seeder (its INSERT referenced
// PackageDescription, which didn't exist -> every insert threw -> the whole
// seed transaction rolled back -> the catalog stayed at the original 9 demo
// rows, which is why Bangladesh/Cox's Bazar packages could never be found),
// and language.php's "UPDATE tblusers SET Language=..." (silently failed ->
// language selection never persisted).
//
// This previously ran once-per-*session*, which made it worse: a browser that
// already had a session cookie from before these columns were added to this
// file would NEVER re-run the repair, so the bug could reappear/persist even
// after the code was "fixed", until the developer cleared cookies. We now run
// a single cheap information_schema lookup on every request (a few ms) and
// only issue ALTER TABLE when a column is actually missing, so this is fully
// self-healing regardless of session/deploy history.
_tms_repair_schema_drift($dbh);

require_once __DIR__ . '/log-activity.php';
require_once __DIR__ . '/i18n.php';

// ══════════════════════════════════════════════════════════════════════════════
// SCHEMA-DRIFT REPAIR
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Ensure every column in $colDefs (colName => "colName TYPE ...") exists on
 * $table, using a single information_schema query for the whole table rather
 * than one query per column. Missing columns are added with ALTER TABLE.
 * Failures are logged (never silently swallowed) so schema problems are
 * actually visible in the PHP error log instead of disappearing.
 */
function tms_ensure_columns(PDO $dbh, string $table, array $colDefs): void {
    try {
        $stmt = $dbh->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $existing = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        error_log("[TMS Schema] Could not inspect columns for `$table`: " . $e->getMessage());
        return;
    }

    foreach ($colDefs as $colName => $colDef) {
        if (in_array(strtolower($colName), $existing, true)) continue;
        try {
            $dbh->exec("ALTER TABLE `$table` ADD COLUMN $colDef");
            error_log("[TMS Schema] Added missing column `$table`.`$colName`.");
        } catch (Exception $e) {
            error_log("[TMS Schema] FAILED adding `$table`.`$colName`: " . $e->getMessage());
        }
    }
}

/**
 * Repairs the specific "phantom column" drift discovered in the July 2026
 * audit: tables that pre-existed from tms.sql (with a narrower schema) so
 * later `CREATE TABLE IF NOT EXISTS` calls with more columns silently never
 * applied. See the long comment where this is called from for the full story.
 */
function _tms_repair_schema_drift(PDO $dbh): void {
    tms_ensure_columns($dbh, 'tbltourpackages', [
        'PackageDescription' => 'PackageDescription MEDIUMTEXT DEFAULT NULL',
        'PackageDuration'    => 'PackageDuration VARCHAR(100) DEFAULT NULL',
        'MapLat'             => 'MapLat VARCHAR(20) DEFAULT NULL',
        'MapLng'             => 'MapLng VARCHAR(20) DEFAULT NULL',
        'AvgRating'          => 'AvgRating DECIMAL(3,1) DEFAULT 0.0',
        'Status'             => 'Status INT(1) DEFAULT 1',
        'Country'            => 'Country VARCHAR(100) DEFAULT NULL',
        'Region'             => 'Region VARCHAR(150) DEFAULT NULL',
        'IsFeatured'         => 'IsFeatured TINYINT(1) DEFAULT 0',
        'ViewCount'          => 'ViewCount INT DEFAULT 0',
        'PackageMeta'        => 'PackageMeta LONGTEXT DEFAULT NULL',
        // Added to genuinely close the "Hotels/Food/Transport comparison"
        // and "remaining seats" pricing-factor gaps documented earlier as
        // schema limitations. Package-level attributes rather than a
        // separate hotels table — this catalog treats "package" as the
        // sellable unit (one hotel/meal-plan/transport combo per package),
        // which matches how the packages are actually authored today,
        // rather than introducing a many-to-many hotel entity nothing else
        // in the app is built to use.
        'HotelName'          => 'HotelName VARCHAR(150) DEFAULT NULL',
        'HotelRating'        => 'HotelRating DECIMAL(2,1) DEFAULT NULL',
        'MealPlan'           => 'MealPlan VARCHAR(100) DEFAULT NULL',
        'TransportMode'      => 'TransportMode VARCHAR(100) DEFAULT NULL',
        // Nullable on purpose: NULL means "capacity not tracked for this
        // package" (today's default, unlimited-looking behavior preserved
        // exactly), a number means "genuinely limited, track it." Never
        // defaults to a made-up number.
        'SeatsAvailable'     => 'SeatsAvailable INT DEFAULT NULL',
    ]);
    tms_ensure_columns($dbh, 'tblusers', [
        'Language' => "Language VARCHAR(10) DEFAULT 'en'",
        'ReferralCode' => 'ReferralCode VARCHAR(12) DEFAULT NULL',
        'ReferredBy'   => 'ReferredBy VARCHAR(100) DEFAULT NULL',
    ]);
    tms_ensure_columns($dbh, 'tblbooking', [
        'MobileNumber'    => 'MobileNumber VARCHAR(20) DEFAULT NULL',
        'NoOfPersons'     => 'NoOfPersons INT DEFAULT 1',
        'BookingDate'     => 'BookingDate TIMESTAMP NULL DEFAULT NULL',
        'TotalCost'       => 'TotalCost INT DEFAULT NULL',
        'CouponCode'      => 'CouponCode VARCHAR(50) DEFAULT NULL',
        'DiscountApplied' => 'DiscountApplied INT DEFAULT 0',
        'CancelledBy'     => 'CancelledBy VARCHAR(10) DEFAULT NULL',
        // Round 2 — booking modification requests, refund tracking, and a
        // per-booking verification code (used for the QR e-ticket code).
        'RequestedFromDate'  => 'RequestedFromDate VARCHAR(20) DEFAULT NULL',
        'RequestedToDate'    => 'RequestedToDate VARCHAR(20) DEFAULT NULL',
        'ModificationStatus' => "ModificationStatus VARCHAR(20) DEFAULT NULL",
        'ModificationNote'   => 'ModificationNote VARCHAR(255) DEFAULT NULL',
        'RefundStatus'       => 'RefundStatus VARCHAR(20) DEFAULT NULL',
        'RefundNote'         => 'RefundNote VARCHAR(255) DEFAULT NULL',
        'VerificationCode'   => 'VerificationCode VARCHAR(20) DEFAULT NULL',
    ]);
    // DATA-REPAIR FIX (customer-facing audit): BookingDate was added as a
    // self-healing column above, but nothing that creates a booking ever
    // actually set it (fixed at the source in checkout.php, api/index.php,
    // and package-details.php) — so every booking made before this fix has
    // BookingDate = NULL. That silently broke: the "Live Crowd Insights"
    // seasonal prediction (always fell back to generic AI guessing instead
    // of this platform's real booking history), the AI Trip Copilot's
    // "you have an upcoming trip" detection, the "Early Planner" achievement
    // (could never be earned), and blank dates in travel-memory.php /
    // expense-tracker.php. One-time backfill, same self-healing style as
    // the schema checks above: safe to run on every request (it only
    // touches rows still NULL, so it's a no-op once caught up) and safe to
    // run on a database that already has this fixed (nothing left to touch).
    try {
        $dbh->exec("UPDATE tblbooking SET BookingDate = FromDate WHERE BookingDate IS NULL AND FromDate IS NOT NULL AND FromDate != ''");
    } catch (Exception $e) {
        error_log('[TMS Schema] BookingDate backfill failed: ' . $e->getMessage());
    }

    // A unique index, not just a column — two bookings sharing a
    // VerificationCode would mean one person's QR/e-ticket code could
    // "verify" someone else's booking. 5 random bytes makes a real
    // collision astronomically unlikely, but a cheap DB-level guarantee is
    // better than relying on that alone. MySQL's UNIQUE index allows
    // unlimited NULLs, so every pre-existing booking (made before this
    // column existed) is unaffected.
    try {
        $idxCheck = $dbh->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblbooking' AND INDEX_NAME = 'uq_booking_verification_code'"
        );
        $idxCheck->execute();
        if ((int)$idxCheck->fetchColumn() === 0) {
            $dbh->exec("ALTER TABLE `tblbooking` ADD UNIQUE KEY `uq_booking_verification_code` (`VerificationCode`)");
        }
    } catch (Exception $e) {
        error_log('[TMS Schema] Could not add unique index on tblbooking.VerificationCode: ' . $e->getMessage());
    }
    tms_ensure_columns($dbh, 'tblcart', [
        'DynamicPrice' => 'DynamicPrice INT DEFAULT NULL',
    ]);
    tms_ensure_columns($dbh, 'tblissues', [
        'BookingId'    => 'BookingId INT(11) DEFAULT NULL',
        'IssueDetails' => 'IssueDetails TEXT DEFAULT NULL',
        'status'       => 'status INT(1) DEFAULT 0',
        'AdminReply'   => 'AdminReply TEXT DEFAULT NULL',
    ]);
    // Section 14 audit (admin Translation Management): tbl_translation_cache
    // only ever stored an md5 hash of the source text, never the text
    // itself — fine for the lookup tms_translate_batch() needs, useless for
    // a human trying to review/fix a bad AI translation, since there was no
    // way to know what English string a cached row even corresponded to.
    tms_ensure_columns($dbh, 'tbl_translation_cache', [
        'source_text' => 'source_text MEDIUMTEXT DEFAULT NULL',
    ]);
    // Feature Phase 2g: AI Expense Tracker — self-migrating table, same
    // idiom as every other table in this project.
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tblexpenses (
                ExpenseId INT(11) NOT NULL AUTO_INCREMENT,
                UserEmail VARCHAR(100) NOT NULL,
                BookingId INT(11) DEFAULT NULL,
                Category VARCHAR(30) NOT NULL DEFAULT 'other',
                Amount INT(11) NOT NULL,
                Description VARCHAR(255) DEFAULT NULL,
                ExpenseDate DATE NOT NULL,
                CreatedAt TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (ExpenseId),
                INDEX idx_user (UserEmail),
                INDEX idx_booking (BookingId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[TMS] tblexpenses migration failed: ' . $e->getMessage());
    }
    // Feature Phase 2h: Gamification / Achievements
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tblachievements (
                id INT(11) NOT NULL AUTO_INCREMENT,
                UserEmail VARCHAR(100) NOT NULL,
                Code VARCHAR(50) NOT NULL,
                UnlockedAt TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_achievement (UserEmail, Code),
                INDEX idx_user (UserEmail)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[TMS] tblachievements migration failed: ' . $e->getMessage());
    }
    // Feature Phase 2l: Digital Travel Wallet (documents). Files themselves
    // live in uploads/wallet/ (protected by .htaccess — see that directory),
    // this table only tracks metadata + ownership.
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tbltraveldocuments (
                DocId INT(11) NOT NULL AUTO_INCREMENT,
                UserEmail VARCHAR(100) NOT NULL,
                DocType VARCHAR(30) NOT NULL DEFAULT 'other',
                OriginalFilename VARCHAR(255) NOT NULL,
                StoredFilename VARCHAR(255) NOT NULL,
                MimeType VARCHAR(100) DEFAULT NULL,
                FileSize INT(11) DEFAULT NULL,
                Notes VARCHAR(255) DEFAULT NULL,
                UploadedAt TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (DocId),
                INDEX idx_user (UserEmail)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[TMS] tbltraveldocuments migration failed: ' . $e->getMessage());
    }
    tms_ensure_columns($dbh, 'tblpages', [
        'UpdatedAt' => 'UpdatedAt TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
    ]);
    tms_ensure_columns($dbh, 'tblwishlist', [
        'PriceAtAdd' => 'PriceAtAdd INT DEFAULT NULL',
    ]);
    // Feature Phase 2c: persist the issues/highlights the AI was already
    // being asked for (and silently discarding), plus lightweight fake-review
    // flagging fields.
    tms_ensure_columns($dbh, 'tbl_review_sentiment', [
        'issues'            => 'issues TEXT DEFAULT NULL',
        'highlights'        => 'highlights TEXT DEFAULT NULL',
        'is_suspicious'     => 'is_suspicious TINYINT(1) DEFAULT 0',
        'suspicious_reason' => 'suspicious_reason VARCHAR(255) DEFAULT NULL',
    ]);
    // RBAC (Phase 1b): existing installs have exactly one flat admin role.
    // Default every pre-existing admin row to 'superadmin' so nobody already
    // using this app loses access the moment this column appears — only
    // admins created after this point (via manage-admins.php) can be
    // assigned the more restricted 'manager' role.
    tms_ensure_columns($dbh, 'admin', [
        'Role' => "Role VARCHAR(20) DEFAULT 'superadmin'",
        'TwoFASecret'  => 'TwoFASecret VARCHAR(64) DEFAULT NULL',
        'TwoFAEnabled' => 'TwoFAEnabled TINYINT(1) DEFAULT 0',
    ]);
    try {
        $dbh->exec("UPDATE admin SET Role='superadmin' WHERE Role IS NULL OR Role=''");
    } catch (Exception $e) { /* column may not exist yet on first-ever run — fine, next request repairs it */ }
    try {
        $dbh->exec("ALTER TABLE admin ADD UNIQUE KEY uq_admin_username (UserName)");
    } catch (Exception $e) { /* already exists, or (rare) pre-existing duplicate usernames — non-fatal either way */ }

    // Backfill PriceAtAdd for wishlist rows added before this column existed,
    // so price-drop comparisons don't show a false 100% drop for old entries.
    try {
        $dbh->exec(
            "UPDATE tblwishlist w JOIN tbltourpackages p ON p.PackageId = w.PackageId
             SET w.PriceAtAdd = p.PackagePrice WHERE w.PriceAtAdd IS NULL"
        );
    } catch (Exception $e) {
        error_log('[TMS Schema] Could not backfill tblwishlist.PriceAtAdd: ' . $e->getMessage());
    }

    try {
        $dbh->exec("CREATE INDEX idx_pkg_country ON tbltourpackages(Country)");
    } catch (Exception $e) { /* already exists — fine */ }
    try {
        $dbh->exec("CREATE INDEX idx_pkg_region ON tbltourpackages(Region)");
    } catch (Exception $e) { /* already exists — fine */ }
    try {
        $dbh->exec("CREATE INDEX idx_pkg_featured ON tbltourpackages(IsFeatured)");
    } catch (Exception $e) { /* already exists — fine */ }
    try {
        $dbh->exec("CREATE INDEX idx_pkg_viewcount ON tbltourpackages(ViewCount)");
    } catch (Exception $e) { /* already exists — fine */ }

    // Now that the columns the seeder needs are guaranteed to exist, (re)try it.
    tms_seed_tourism_packages($dbh);
    tms_backfill_package_images($dbh);
    tms_backfill_realistic_pricing($dbh);
}

/**
 * ROOT-CAUSE FIX: data/packages_seed.json (the 404-package realistic
 * Bangladesh-focused catalog referenced throughout the project's docs)
 * stores PackagePrice as a small USD-scale number (e.g. 220) matching its
 * own `meta.budget_estimate` field ("$25-40/day" for a 3-4 day trip). The
 * currency-symbol fix in an earlier audit round (tms_currency()) correctly
 * switched every price display from "$" to "৳", but never touched the
 * underlying *numbers* — so a Cox's Bazar beach package has been rendering
 * as "৳ 220" (about $1.80), which fails the "use accurate, believable
 * data" and "use realistic prices" requirements just as badly as showing
 * the wrong currency symbol would have. Not a live FX-API rate (overkill
 * for demo/academic content) — a fixed reference rate, applied once at
 * seed time, rounded to a clean number the way a real listed price would be.
 *
 * (Deliberately a local constant inside the function, not a top-level
 * define(): _tms_repair_schema_drift() — which reaches this via
 * tms_seed_tourism_packages() — is called near the top of this file, before
 * a file-scope define() placed down here would actually have executed.
 * Function *declarations* hoist across the whole file regardless of order;
 * define() does not — it runs only when execution reaches it. Keeping the
 * rate local to the function sidesteps that ordering trap entirely.)
 */
function tms_usd_to_bdt(int $usdAmount): int {
    $rate = 122; // approximate reference USD->BDT rate for demo/academic content
    return (int) (round(($usdAmount * $rate) / 100) * 100); // nearest ৳100
}

/**
 * Converts a "$25-40/day" style range (as stored in packages_seed.json's
 * meta.budget_estimate / meta.luxury_estimate) into a Taka range using the
 * same reference rate as tms_usd_to_bdt(), e.g. "৳3,050-4,880/day". Any
 * trailing text such as " (budget)" is left untouched.
 */
function tms_convert_usd_range_to_bdt(string $range): string {
    return preg_replace_callback('/\$(\d+)\s*-\s*(\d+)/', function (array $m): string {
        $lo = tms_usd_to_bdt((int)$m[1]);
        $hi = tms_usd_to_bdt((int)$m[2]);
        return '৳' . number_format($lo) . '-' . number_format($hi);
    }, $range);
}

/**
 * Companion to tms_seed_tourism_packages(): fixes prices for installs that
 * already ran the seeder before this conversion existed. Matches rows back
 * to the seed file by PackageName (same key the seeder dedupes on) and only
 * overwrites a price that still exactly equals the original raw USD-scale
 * number — an admin who has since hand-edited a package's price is left
 * alone, since we can't tell "still the untouched seed value" apart from
 * "admin coincidentally re-typed the same number" any other way, and the
 * safe default is to never clobber a deliberate edit.
 */
function tms_backfill_realistic_pricing(PDO $dbh): void {
    try {
        $jsonPath = __DIR__ . '/../data/packages_seed.json';
        if (!file_exists($jsonPath)) return;
        $rows = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($rows)) return;

        $sel = $dbh->prepare("SELECT PackageId, PackagePrice FROM tbltourpackages WHERE PackageName=:n LIMIT 1");
        $upd = $dbh->prepare("UPDATE tbltourpackages SET PackagePrice=:p WHERE PackageId=:id");
        $n = 0;
        $dbh->beginTransaction();
        foreach ($rows as $row) {
            $originalUsd = (int)($row['PackagePrice'] ?? 0);
            if ($originalUsd <= 0) continue;
            $sel->execute([':n' => $row['PackageName']]);
            $existing = $sel->fetch(PDO::FETCH_OBJ);
            if (!$existing) continue;
            if ((int)$existing->PackagePrice !== $originalUsd) continue; // already converted or admin-edited
            $bdt = tms_usd_to_bdt($originalUsd);
            $upd->execute([':p' => $bdt, ':id' => $existing->PackageId]);
            $n++;
        }
        $dbh->commit();
        if ($n > 0) error_log("[TMS Pricing] Converted $n seeded package price(s) from USD-scale to realistic BDT.");
    } catch (Exception $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log('[TMS Pricing] Backfill failed: ' . $e->getMessage());
        return;
    }

    // Second, independent pass: fix the budget_estimate/luxury_estimate "$"
    // ranges inside PackageMeta for rows seeded before this fix existed.
    // Kept separate from the price backfill above so a problem in one never
    // blocks the other.
    try {
        $metaSel = $dbh->prepare("SELECT PackageId, PackageMeta FROM tbltourpackages WHERE PackageMeta LIKE '%\$%'");
        $metaSel->execute();
        $metaRows = $metaSel->fetchAll(PDO::FETCH_OBJ);
        if ($metaRows) {
            $metaUpd = $dbh->prepare("UPDATE tbltourpackages SET PackageMeta=:m WHERE PackageId=:id");
            $mFixed = 0;
            $dbh->beginTransaction();
            foreach ($metaRows as $row) {
                $meta = json_decode($row->PackageMeta, true);
                if (!is_array($meta)) continue;
                $changed = false;
                foreach (['budget_estimate', 'luxury_estimate'] as $field) {
                    if (!empty($meta[$field]) && strpos($meta[$field], '$') !== false) {
                        $meta[$field] = tms_convert_usd_range_to_bdt((string)$meta[$field]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $metaUpd->execute([':m' => json_encode($meta), ':id' => $row->PackageId]);
                    $mFixed++;
                }
            }
            $dbh->commit();
            if ($mFixed > 0) error_log("[TMS Pricing] Converted $mFixed package(s)' budget/luxury estimate ranges from USD to BDT.");
        }
    } catch (Exception $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log('[TMS Pricing] Meta-range backfill failed: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// MIGRATIONS
// ══════════════════════════════════════════════════════════════════════════════

function _tms_run_core_migrations(PDO $dbh): void {
    $tables = [
"CREATE TABLE IF NOT EXISTS `tbl_translation_cache` (
  `source_hash` char(32) NOT NULL, `lang` varchar(5) NOT NULL,
  `translated` mediumtext DEFAULT NULL, `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`source_hash`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserName` varchar(100) DEFAULT NULL,
  `Password` varchar(200) DEFAULT NULL, `updationDate` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblusers` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `FullName` varchar(100) DEFAULT NULL,
  `MobileNumber` varchar(20) DEFAULT NULL, `EmailId` varchar(100) DEFAULT NULL,
  `Password` varchar(200) DEFAULT NULL, `Language` varchar(10) DEFAULT 'en',
  `RegDate` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_email` (`EmailId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbltourpackages` (
  `PackageId` int(11) NOT NULL AUTO_INCREMENT, `PackageName` varchar(200) DEFAULT NULL,
  `PackageType` varchar(150) DEFAULT NULL, `PackageDescription` mediumtext DEFAULT NULL,
  `PackageDetails` mediumtext DEFAULT NULL, `PackagePrice` int(11) DEFAULT NULL,
  `PackageLocation` varchar(200) DEFAULT NULL, `PackageDuration` varchar(100) DEFAULT NULL,
  `PackageFetures` varchar(255) DEFAULT NULL, `PackageImage` varchar(200) DEFAULT NULL,
  `MapLat` varchar(20) DEFAULT NULL, `MapLng` varchar(20) DEFAULT NULL,
  `AvgRating` decimal(3,1) DEFAULT 0.0, `Status` int(1) DEFAULT 1,
  PRIMARY KEY (`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblbooking` (
  `BookingId` int(11) NOT NULL AUTO_INCREMENT, `PackageId` int(11) DEFAULT NULL,
  `UserEmail` varchar(100) DEFAULT NULL, `MobileNumber` varchar(20) DEFAULT NULL,
  `NoOfPersons` int(11) DEFAULT NULL, `BookingDate` date DEFAULT NULL,
  `TotalCost` int(11) DEFAULT NULL, `CouponCode` varchar(50) DEFAULT NULL,
  `DiscountApplied` int(11) DEFAULT 0, `status` int(1) DEFAULT 0,
  `CancelledBy` varchar(10) DEFAULT NULL,
  `RegDate` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`BookingId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblenquiry` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `FullName` varchar(200) DEFAULT NULL,
  `EmailId` varchar(100) DEFAULT NULL, `MobileNumber` varchar(20) DEFAULT NULL,
  `Subject` varchar(300) DEFAULT NULL, `Description` mediumtext DEFAULT NULL,
  `status` int(1) DEFAULT 0, `PostingDate` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblissues` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `BookingId` int(11) DEFAULT NULL, `IssueDetails` mediumtext DEFAULT NULL,
  `status` int(1) DEFAULT 0, `AdminReply` mediumtext DEFAULT NULL,
  `PostingDate` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblreviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `PackageId` int(11) DEFAULT NULL,
  `UserEmail` varchar(100) DEFAULT NULL, `UserName` varchar(100) DEFAULT NULL,
  `Rating` int(1) DEFAULT NULL, `ReviewText` mediumtext DEFAULT NULL,
  `ReviewImage` varchar(200) DEFAULT NULL, `Status` int(1) DEFAULT 1,
  `PostingDate` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblwishlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `PackageId` int(11) DEFAULT NULL, `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `unique_wish` (`UserEmail`,`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblrecentlyviewed` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `PackageId` int(11) DEFAULT NULL, `ViewedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `unique_recent` (`UserEmail`,`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblcoupons` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `Code` varchar(50) DEFAULT NULL,
  `DiscountPercent` int(11) DEFAULT 10, `UserEmail` varchar(100) DEFAULT NULL,
  `ExpiryDate` date DEFAULT NULL, `IsUsed` tinyint(1) DEFAULT 0,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_coupon_code` (`Code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblweathercache` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `CacheKey` varchar(100) DEFAULT NULL,
  `Payload` longtext DEFAULT NULL, `FetchedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_weather_key` (`CacheKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblpages` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `type` varchar(100) DEFAULT NULL,
  `detail` mediumtext DEFAULT NULL,
  `UpdatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_page_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblblog` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `Title` varchar(300) DEFAULT NULL,
  `Content` mediumtext DEFAULT NULL, `Tag` varchar(100) DEFAULT NULL,
  `CoverImage` varchar(200) DEFAULT NULL, `AuthorId` int(11) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblemergencycontacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `Country` varchar(100) DEFAULT NULL,
  `PoliceNumber` varchar(20) DEFAULT NULL, `AmbulanceNumber` varchar(20) DEFAULT NULL,
  `FireNumber` varchar(20) DEFAULT NULL, `TouristHelpline` varchar(20) DEFAULT NULL,
  `Embassy` varchar(200) DEFAULT NULL, `SafetyTips` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblbudgetplans` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `PlanName` varchar(200) DEFAULT NULL, `Destination` varchar(200) DEFAULT NULL,
  `TotalBudget` int(11) DEFAULT NULL, `Accommodation` int(11) DEFAULT NULL,
  `Food` int(11) DEFAULT NULL, `Transport` int(11) DEFAULT NULL,
  `Activities` int(11) DEFAULT NULL, `Miscellaneous` int(11) DEFAULT NULL,
  `Duration` int(11) DEFAULT NULL, `Travelers` int(11) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblcustomtrips` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `TripName` varchar(200) DEFAULT NULL, `Destination` varchar(200) DEFAULT NULL,
  `StartDate` date DEFAULT NULL, `EndDate` date DEFAULT NULL,
  `Budget` int(11) DEFAULT NULL, `TravelersCount` int(11) DEFAULT NULL,
  `Accommodation` varchar(100) DEFAULT NULL, `Transport` varchar(100) DEFAULT NULL,
  `Activities` mediumtext DEFAULT NULL, `MealPlan` varchar(100) DEFAULT NULL,
  `SpecialRequests` mediumtext DEFAULT NULL, `Status` varchar(50) DEFAULT 'Pending',
  `AdminNote` mediumtext DEFAULT NULL, `AiItinerary` longtext DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblactivity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `Actor` varchar(150) DEFAULT NULL,
  `Action` varchar(150) DEFAULT NULL, `Details` text DEFAULT NULL,
  `IPAddress` varchar(45) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblloyalty` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `Points` int(11) DEFAULT 0, `TotalEarned` int(11) DEFAULT 0,
  `UpdatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_loyalty_email` (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// CRITICAL FIX: referenced by 8+ INSERT/SELECT statements across checkout.php,
// package-details.php, register.php, user-register-ajax.php, reviews.php,
// loyalty.php, and loyalty-history.php — but never actually created
// anywhere. None of those INSERTs were wrapped in try/catch, and PDO is
// configured with ERRMODE_EXCEPTION (see the PDO constructor above), so
// every checkout/booking was throwing an UNCAUGHT fatal error on this
// INSERT — after the real booking had already been created and loyalty
// points already awarded, but before the cart was cleared or the success
// page was shown. Customers were left on a broken page after a booking
// that had, in fact, gone through.
"CREATE TABLE IF NOT EXISTS `tblloyalty_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `Points` int(11) DEFAULT 0, `Description` varchar(200) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_loyalty_log_email (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `Message` varchar(500) DEFAULT NULL, `Link` varchar(200) DEFAULT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_notif_email (`UserEmail`), INDEX idx_notif_read (`UserEmail`,`IsRead`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_chatbot_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `Label` varchar(100) DEFAULT NULL,
  `ClickedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_click_label (`Label`), INDEX idx_click_time (`ClickedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `Email` varchar(100) DEFAULT NULL,
  `IPAddress` varchar(45) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_login_att_email (`Email`,`CreatedAt`), INDEX idx_login_att_ip (`IPAddress`,`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblcart` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `PackageId` int(11) DEFAULT NULL, `FromDate` date DEFAULT NULL, `ToDate` date DEFAULT NULL,
  `NoOfPersons` int(11) DEFAULT 1, `Comment` mediumtext DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_cart_email (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $sql) {
        try { $dbh->exec($sql); } catch (Exception $e) {}
    }

    // Seed admin
    try {
        if ((int)$dbh->query("SELECT COUNT(*) FROM admin")->fetchColumn() === 0) {
            $hash = password_hash('Test@12345', PASSWORD_BCRYPT, ['cost' => 12]);
            $dbh->exec("INSERT INTO admin (UserName, Password) VALUES ('admin', '$hash')");
        }
    } catch (Exception $e) {}

    // Seed default static pages (About/Contact) so page.php never renders blank
    try {
        if ((int)$dbh->query("SELECT COUNT(*) FROM tblpages")->fetchColumn() === 0) {
            $stmt = $dbh->prepare("INSERT INTO tblpages(type, detail) VALUES(:type, :detail)");
            $stmt->execute([
                ':type'   => 'aboutus',
                ':detail' => 'TMS (Tourism Management System) helps travelers discover, plan and book unforgettable trips. '
                            . 'We combine curated tour packages, AI-powered trip planning and real-time travel information '
                            . 'to make every journey simple and memorable.',
            ]);
            $stmt->execute([
                ':type'   => 'contact',
                ':detail' => 'Have a question or need help planning your trip? Call our toll-free line at +880 1759-443004 '
                            . 'or use the enquiry form below and our team will get back to you shortly.',
            ]);
        }
    } catch (Exception $e) {}

    // Seed coupons
    try {
        if ((int)$dbh->query("SELECT COUNT(*) FROM tblcoupons")->fetchColumn() === 0) {
            $dbh->exec("INSERT IGNORE INTO tblcoupons(Code,DiscountPercent,ExpiryDate) VALUES
                ('WELCOME10',10,DATE_ADD(CURDATE(),INTERVAL 90 DAY)),
                ('SUMMER20',20,DATE_ADD(CURDATE(),INTERVAL 60 DAY)),
                ('TMS2026',15,DATE_ADD(CURDATE(),INTERVAL 120 DAY))");
        }
    } catch (Exception $e) {}

    // Indexes
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_pkg_status ON tbltourpackages(Status)",
        "CREATE INDEX IF NOT EXISTS idx_booking_email ON tblbooking(UserEmail)",
        "CREATE INDEX IF NOT EXISTS idx_reviews_pkg ON tblreviews(PackageId)",
        // PERFORMANCE FIX (Section 9 audit): admin/manage-bookings.php filters
        // by status (the pending/confirmed/cancelled stat strip + status
        // dropdown) and joins on PackageId, with neither column indexed —
        // every one of those queries was a full table scan of tblbooking,
        // and that table only ever grows.
        "CREATE INDEX IF NOT EXISTS idx_booking_status ON tblbooking(status)",
        "CREATE INDEX IF NOT EXISTS idx_booking_package ON tblbooking(PackageId)",
    ] as $idx) { try { $dbh->exec($idx); } catch (Exception $e) {} }
}

function _tms_run_ai_migrations(PDO $dbh): void {
    $aiTables = [
"CREATE TABLE IF NOT EXISTS `tbl_ai_usage_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_usage_email (`user_email`), INDEX idx_usage_time (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_ai_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `metric_key` varchar(150) DEFAULT NULL,
  `metric_value` longtext DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_metric_key` (`metric_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_ai_itineraries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `itinerary_json` longtext DEFAULT NULL,
  `packing_list` text DEFAULT NULL,
  `ai_version` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_itin_booking (`booking_id`), INDEX idx_itin_email (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Referenced by includes/ai/PackageGenerator.php (savePackage()/
// getUserPackages()) but never actually created — both call sites already
// had try/catch (unlike the tblloyalty_log case in Round 21), so this
// wasn't crashing anything, just silently discarding every AI-generated
// package instead of persisting it, and "my saved AI packages" was always
// empty as a result.
"CREATE TABLE IF NOT EXISTS `tbl_ai_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `package_json` longtext DEFAULT NULL,
  `match_score` int(11) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_ai_pkg_email (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Referenced by ai-recommend.php with NO try/catch at all — every
// logged-in user using the AI recommendation feature was hitting an
// uncaught fatal error the moment it tried to save their search
// preference, right after recommendations had already been computed and
// displayed.
"CREATE TABLE IF NOT EXISTS `tblrecommendations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `UserEmail` varchar(100) DEFAULT NULL,
  `Budget` varchar(50) DEFAULT NULL,
  `TravelType` varchar(50) DEFAULT NULL,
  `Duration` varchar(50) DEFAULT NULL,
  `Climate` varchar(50) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_reco_email (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Tourist <-> Agency live chat (reviewer ask: "Chat Between Tourist &
// Agency... real-time implementation... Typing indicator, Read receipts").
// One continuous thread per customer (UserEmail is the thread key) rather
// than per-booking, matching how a live-chat widget actually behaves —
// customers often have pre-sales questions before any booking exists.
// True WebSockets aren't available on this stack (no persistent-connection
// server), so this is powered by short-interval polling from
// api/chat.php — a standard, honest substitute that delivers messages
// within ~3 seconds, not a fake "real-time" label over something that
// doesn't actually work.
"CREATE TABLE IF NOT EXISTS `tblchat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `UserEmail` varchar(100) DEFAULT NULL,
  `SenderRole` enum('customer','admin') DEFAULT 'customer',
  `SenderName` varchar(100) DEFAULT NULL,
  `Message` text DEFAULT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_chat_email (`UserEmail`), INDEX idx_chat_created (`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// One row per customer thread — tracks last-seen/typing state for both
// sides so read receipts and a typing indicator are real, not decorative.
"CREATE TABLE IF NOT EXISTS `tblchat_threads` (
  `UserEmail` varchar(100) NOT NULL,
  `CustomerLastSeen` timestamp NULL DEFAULT NULL,
  `AdminLastSeen` timestamp NULL DEFAULT NULL,
  `CustomerTypingAt` timestamp NULL DEFAULT NULL,
  `AdminTypingAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_copilot_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `role` enum('user','assistant') NOT NULL,
  `message` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_cop_booking (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_travel_memories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `journal_text` longtext DEFAULT NULL,
  `social_captions` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_review_sentiment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `review_id` int(11) DEFAULT NULL,
  `sentiment` enum('positive','neutral','negative') DEFAULT NULL,
  `score` decimal(4,3) DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `analysed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_review_sentiment` (`review_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_user_profile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `interests` json DEFAULT NULL,
  `budget_range` varchar(50) DEFAULT NULL,
  `preferred_climate` varchar(50) DEFAULT NULL,
  `travel_style` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_profile_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_safety_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `destination` varchar(200) DEFAULT NULL,
  `alert_type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `severity` enum('info','warning','critical') DEFAULT 'info',
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($aiTables as $sql) {
        try { $dbh->exec($sql); } catch (Exception $e) {}
    }

    // Add AiItinerary column to custom trips if missing
    try { $dbh->exec("ALTER TABLE tblcustomtrips ADD COLUMN AiItinerary longtext DEFAULT NULL"); } catch (Exception $e) {}

    // NOTE: tbltourpackages column repair (Country/Region/IsFeatured/ViewCount/
    // PackageMeta/PackageDescription/PackageDuration/MapLat/MapLng/AvgRating/
    // Status) and the 400+ package seed used to happen here, gated behind a
    // once-per-session flag. That's what caused the "packages missing" bug —
    // see _tms_repair_schema_drift() for the full explanation. Both now run
    // unconditionally (and safely/idempotently) from there instead.
    try { $dbh->exec("CREATE INDEX idx_pkg_type ON tbltourpackages(PackageType)"); } catch (Exception $e) {}
    try { $dbh->exec("CREATE INDEX idx_wishlist_lookup ON tblwishlist(UserEmail, PackageId)"); } catch (Exception $e) {}
}

function _tms_run_explore_migrations(PDO $dbh): void {
    $exploreTables = [
"CREATE TABLE IF NOT EXISTS `tbl_explore_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `place_key` varchar(190) DEFAULT NULL,
  `display_name` varchar(300) DEFAULT NULL,
  `country` varchar(150) DEFAULT NULL,
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `info_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_explore_place` (`place_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_explore_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `display_name` varchar(300) DEFAULT NULL,
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_exphist_email (`user_email`), INDEX idx_exphist_time (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_explore_chat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(100) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `destination` varchar(300) DEFAULT NULL,
  `role` enum('user','assistant') NOT NULL,
  `message` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), INDEX idx_expchat_session (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_explore_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(190) DEFAULT NULL,
  `cache_value` longtext DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_explore_cache_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($exploreTables as $sql) {
        try { $dbh->exec($sql); } catch (Exception $e) {}
    }
}

/**
 * Bearer-token table for the REST API (Phase 8). Tokens are stored as a
 * SHA-256 hash, not the raw value — same principle as password storage,
 * so a database read alone can't be replayed as a live credential. The raw
 * token is only ever shown once, in the login response.
 */
function _tms_run_api_migrations(PDO $dbh): void {
    $sql = "CREATE TABLE IF NOT EXISTS `tblapi_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `UserEmail` varchar(100) DEFAULT NULL,
        `TokenHash` varchar(64) DEFAULT NULL,
        `DeviceLabel` varchar(100) DEFAULT NULL,
        `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
        `LastUsedAt` timestamp NULL DEFAULT NULL,
        `ExpiresAt` datetime DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_api_token_hash` (`TokenHash`),
        INDEX `idx_api_token_email` (`UserEmail`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $dbh->exec($sql); } catch (Exception $e) {}
}

/**
 * Loads data/packages_seed.json (generated by data/generate_packages.php) and inserts
 * any destinations not already present (matched by PackageName) so re-running never
 * creates duplicates. Safe to call on every request — it's a no-op once seeded.
 */
/**
 * ROOT-CAUSE-ADJACENT IMPROVEMENT: every one of the 404 seeded packages was
 * previously assigned the same generic "Image Not Available" placeholder
 * icon (there were no real photo files bundled with the seed data). That's
 * safe (never a broken image) but not what was asked for ("beautiful natural
 * tourism images ... beach, mountains, waterfalls..."). This maps each
 * PackageType to a real, freely-licensed Unsplash photo so package cards
 * actually look like the destination instead of a gray icon. If a specific
 * photo URL is ever unreachable, the existing onerror handlers on every
 * <img> already fall back to the local placeholder — this only adds a
 * better default, it can't introduce a broken-image regression.
 */
function tms_category_image(string $packageType): string {
    static $map = [
        'Beach'              => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=800&q=80',
        'Island'             => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=800&q=80',
        'Island/Wildlife'    => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=800&q=80',
        'Mountain'           => 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=800&q=80',
        'Nature'             => 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=800&q=80',
        'Nature/Culture'     => 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=800&q=80',
        'Nature/Religious'   => 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=800&q=80',
        'Border/Nature'      => 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=800&q=80',
        'Wetland'            => 'https://images.unsplash.com/photo-1500534623283-312aade485b7?w=800&q=80',
        'Wildlife'           => 'https://images.unsplash.com/photo-1516426122078-c23e76319801?w=800&q=80',
        'Heritage'           => 'https://images.unsplash.com/photo-1548013146-72479768bada?w=800&q=80',
        'Heritage/Religious' => 'https://images.unsplash.com/photo-1548013146-72479768bada?w=800&q=80',
        'Religious'          => 'https://images.unsplash.com/photo-1601999209039-84f3fa8ba9fd?w=800&q=80',
        'Adventure'          => 'https://images.unsplash.com/photo-1519681393784-d120267933ba?w=800&q=80',
        'Scenic Drive'       => 'https://images.unsplash.com/photo-1469854523086-cc02fe5d8800?w=800&q=80',
        'City'               => 'https://images.unsplash.com/photo-1519501025264-65ba15a82390?w=800&q=80',
        'Couple Package'     => 'https://images.unsplash.com/photo-1523906834658-6e24ef2386f9?w=800&q=80',
        'Family'             => 'https://images.unsplash.com/photo-1500463959177-e0869687df26?w=800&q=80',
        'Family Package'     => 'https://images.unsplash.com/photo-1500463959177-e0869687df26?w=800&q=80',
        'Group'               => 'https://images.unsplash.com/photo-1527201987695-2731edcd48a5?w=800&q=80',
        'Group Package'      => 'https://images.unsplash.com/photo-1527201987695-2731edcd48a5?w=800&q=80',
        'Domestic Packages'  => 'https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=800&q=80',
    ];
    return $map[$packageType] ?? 'https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=800&q=80';
}

/**
 * Richer, keyword-based image resolver used to backfill packages with no
 * real uploaded photo. tms_category_image() alone only has ~15 buckets,
 * which meant e.g. all ~97 "Nature"-type packages shared a single photo.
 * This scans the package name/location for a specific destination keyword
 * first (waterfall, temple, desert, lighthouse, etc.) for a much closer
 * thematic match, then picks a specific photo from a pool of several for
 * that theme (deterministic per PackageId, so it's stable across reloads
 * but varies package-to-package instead of repeating one photo per theme).
 */
/** Shared category-pool lookup used by both tms_destination_image() (a single
 *  photo, for list/card thumbnails) and tms_destination_gallery() (the whole
 *  matched pool, for the package-details gallery/slider) — kept as one
 *  function so the ~25-category keyword map only has to be maintained once. */
function _tms_destination_photo_pool(string $name, string $location, string $type): array {
    $haystack = strtolower($name . ' ' . $location . ' ' . $type);

    static $pools = [
        'waterfall'   => ['1432405972618-c60b0225b8f9','1502786129293-79981df4e689','1444491741275-3747c53c99b4'],
        'forest'      => ['1441974231531-c6227db76b6e','1518495973542-4542c06a5843','1448375240586-882707db888b'],
        'mountain'    => ['1506905925346-21bda4d32df4','1519681393784-d120267933ba','1464822759023-fed622ff2c3b'],
        'desert'      => ['1509316785289-025f5b846b35','1451337516015-6b6e9a44a8a3','1473580044384-7ba9967e16a0'],
        'lighthouse'  => ['1520454974749-611b7248ffdb','1466921583968-f262bb8d4d8f'],
        'lake'        => ['1439066615861-d1af74d74000','1439066615861-d1af74d74000'],
        'river'       => ['1502786129293-79981df4e689','1500534623283-312aade485b7'],
        'glacier'     => ['1541519227354-08fa5d50c44d','1531366936337-7c912a4589a7'],
        'canyon'      => ['1474044159687-1ee9f3a51722','1509316785289-025f5b846b35'],
        'volcano'     => ['1553095066-5014bc7b7f2d','1464822759023-fed622ff2c3b'],
        'temple'      => ['1555400038-63f5ba517a47','1548013146-72479768bada','1601999209039-84f3fa8ba9fd'],
        'religious'   => ['1601999209039-84f3fa8ba9fd','1548013146-72479768bada'],
        'historic'    => ['1539037116277-4db20889f2d4','1548013146-72479768bada','1520454974749-611b7248ffdb'],
        'national park' => ['1425913397330-cf8af2ff2a3f','1441974231531-c6227db76b6e'],
        'wildlife'    => ['1516426122078-c23e76319801','1441974231531-c6227db76b6e'],
        'wetland'     => ['1500534623283-312aade485b7','1502786129293-79981df4e689'],
        'beach'       => ['1507525428034-b723cf961d3e','1519046904884-53103b34b206','1473116763249-2faaef81ccda'],
        'island'      => ['1544551763-46a013bb70d5','1507525428034-b723cf961d3e'],
        'city'        => ['1519501025264-65ba15a82390','1477587458883-47145ed94245','1512453979798-5ea266f8880c'],
        'trek'        => ['1551632811-561732d1e306','1519681393784-d120267933ba'],
        'adventure'   => ['1519681393784-d120267933ba','1551632811-561732d1e306'],
        'scenic drive'=> ['1469854523086-cc02fe5d8800'],
    ];
    static $keywordToPool = [
        'waterfall' => 'waterfall', 'falls' => 'waterfall',
        'forest' => 'forest', 'jungle' => 'forest', 'sundarban' => 'forest',
        'mountain' => 'mountain', 'hill' => 'mountain', 'peak' => 'mountain', 'himalay' => 'mountain', 'everest' => 'mountain',
        'desert' => 'desert', 'dune' => 'desert', 'sahara' => 'desert',
        'lighthouse' => 'lighthouse',
        'lake' => 'lake',
        'river' => 'river',
        'glacier' => 'glacier',
        'canyon' => 'canyon', 'gorge' => 'canyon',
        'volcano' => 'volcano', 'crater' => 'volcano',
        'temple' => 'temple', 'pagoda' => 'temple', 'shrine' => 'temple', 'monastery' => 'temple',
        'religious' => 'religious', 'mosque' => 'religious', 'church' => 'religious',
        'heritage' => 'historic', 'historic' => 'historic', 'fort' => 'historic', 'palace' => 'historic', 'ruins' => 'historic', 'castle' => 'historic',
        'national park' => 'national park', 'sanctuary' => 'national park', 'reserve' => 'national park',
        'wildlife' => 'wildlife', 'safari' => 'wildlife', 'tiger' => 'wildlife',
        'wetland' => 'wetland', 'backwater' => 'wetland', 'marsh' => 'wetland', 'mangrove' => 'wetland',
        'beach' => 'beach', 'coast' => 'beach', 'bay' => 'beach', "cox's bazar" => 'beach', 'sea' => 'beach',
        'island' => 'island',
        'city' => 'city', 'skyline' => 'city', 'downtown' => 'city', 'capital' => 'city',
        'trek' => 'trek', 'hiking' => 'trek', 'climb' => 'trek',
        'adventure' => 'adventure',
        'scenic drive' => 'scenic drive',
    ];

    $poolKey = null;
    foreach ($keywordToPool as $kw => $pk) {
        if (strpos($haystack, $kw) !== false) { $poolKey = $pk; break; }
    }
    if ($poolKey === null) {
        // Fall back to the coarse PackageType-based bucket name (lowercased,
        // first segment before any "/").
        $typeKey = strtolower(explode('/', $type)[0] ?? '');
        $poolKey = $keywordToPool[$typeKey] ?? ($pools[$typeKey] ?? null ? $typeKey : 'city');
    }
    return $pools[$poolKey] ?? $pools['city'];
}

function tms_destination_image(string $name, string $location, string $type, int $packageId): string {
    $pool  = _tms_destination_photo_pool($name, $location, $type);
    $photo = $pool[$packageId % count($pool)];
    return "https://images.unsplash.com/photo-{$photo}?w=800&q=80";
}

/**
 * Returns every photo in the matched category as full Unsplash URLs, for
 * the package-details "Large gallery / Image slider" (Section 6) — the
 * project previously showed exactly one hero image per package with no
 * gallery of any kind. Reuses the same curated, already-in-use photo pools
 * as tms_destination_image() rather than introducing new/unvetted images.
 */
function tms_destination_gallery(string $name, string $location, string $type, string $w = '1200'): array {
    $pool = _tms_destination_photo_pool($name, $location, $type);
    $urls = [];
    foreach (array_unique($pool) as $photo) {
        $urls[] = "https://images.unsplash.com/photo-{$photo}?w={$w}&q=80";
    }
    return $urls;
}

/**
 * ROOT-CAUSE FIX (Section 8 audit): every image upload path in this project
 * (admin/add-package.php, edit-package.php, add-blog.php, edit-blog.php,
 * change-image.php, reviews.php, wallet.php) called move_uploaded_file()
 * straight through with zero resizing or re-encoding — an admin uploading a
 * 12MP phone photo serves that exact multi-MB file on every package card,
 * site-wide, forever. "Compression" was simply never implemented for
 * locally-uploaded images (the Unsplash-sourced destination photos already
 * use ?w=800&q=80 URL params, which is a form of compression, but that's a
 * separate image source entirely).
 *
 * Resizes down to a sane max dimension and re-encodes at a reasonable
 * quality using GD, in place, after move_uploaded_file() has already
 * succeeded. If the GD extension isn't available on this server, this is a
 * silent no-op — the original uploaded file is left exactly as it was
 * rather than the upload failing, matching the rest of the app's
 * "degrade gracefully, never break the core action" pattern (see
 * tms_get_weather()'s fallback chain for the same philosophy).
 */
function tms_compress_uploaded_image(string $absolutePath, int $maxDimension = 1600, int $jpegQuality = 82): void {
    if (!extension_loaded('gd') || !is_file($absolutePath)) return;

    try {
        $info = @getimagesize($absolutePath);
        if (!$info) return;
        [$width, $height, $type] = $info;
        if ($width <= 0 || $height <= 0) return;

        // Already small enough — don't re-encode (re-encoding a JPEG that's
        // already the right size just loses quality for no size benefit).
        if ($width <= $maxDimension && $height <= $maxDimension) return;

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolutePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($absolutePath),
            IMAGETYPE_GIF  => @imagecreatefromgif($absolutePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            default        => false,
        };
        if (!$src) return;

        $ratio  = min($maxDimension / $width, $maxDimension / $height);
        $newW   = max(1, (int)round($width  * $ratio));
        $newH   = max(1, (int)round($height * $ratio));
        $dst    = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG/GIF instead of flattening it to black.
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

        switch ($type) {
            case IMAGETYPE_JPEG: imagejpeg($dst, $absolutePath, $jpegQuality); break;
            case IMAGETYPE_PNG:  imagepng($dst, $absolutePath, 6); break;
            case IMAGETYPE_GIF:  imagegif($dst, $absolutePath); break;
            case IMAGETYPE_WEBP: if (function_exists('imagewebp')) imagewebp($dst, $absolutePath, $jpegQuality); break;
        }

        imagedestroy($src);
        imagedestroy($dst);
    } catch (Exception $e) {
        error_log('[TMS Images] Compression failed for ' . $absolutePath . ': ' . $e->getMessage());
        // Original uploaded file is untouched — safe to just move on.
    }
}

/** Backfills packages that have no real image file on disk with a themed photo (covers both the generic placeholder and the original demo packages whose uploaded files are missing). */
function tms_backfill_package_images(PDO $dbh): void {
    try {
        $rows = $dbh->query("SELECT PackageId, PackageName, PackageLocation, PackageType, PackageImage FROM tbltourpackages")
                    ->fetchAll(PDO::FETCH_OBJ);
        if (!$rows) return;

        $upd = $dbh->prepare("UPDATE tbltourpackages SET PackageImage=:img WHERE PackageId=:id");
        $n = 0;
        foreach ($rows as $r) {
            $img = trim((string)$r->PackageImage);
            // Already a working absolute URL (our themed photos, or an admin-supplied
            // external link) — nothing to do.
            if (preg_match('#^https?://#i', $img)) continue;
            // A local filename that genuinely exists on disk — a real admin-uploaded
            // photo — leave it alone, don't overwrite real content.
            if ($img !== '' && $img !== 'images/no-image-placeholder.jpg'
                && file_exists(__DIR__ . '/../admin/pacakgeimages/' . $img)) {
                continue;
            }
            $new = tms_destination_image(
                (string)($r->PackageName ?? ''),
                (string)($r->PackageLocation ?? ''),
                (string)($r->PackageType ?? ''),
                (int)$r->PackageId
            );
            $upd->execute([':img' => $new, ':id' => $r->PackageId]);
            $n++;
        }
        if ($n > 0) error_log("[TMS Images] Backfilled themed images for $n package(s) with no real photo on disk.");
    } catch (Exception $e) {
        error_log('[TMS Images] Backfill failed: ' . $e->getMessage());
    }
}

function tms_seed_tourism_packages(PDO $dbh): void {
    try {
        $jsonPath = __DIR__ . '/../data/packages_seed.json';
        if (!file_exists($jsonPath)) return;

        $rows = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($rows)) return;

        // ROOT-CAUSE FIX: this used to bail out permanently once the table had
        // >= 100 rows. That threshold was meant to mean "already seeded", but
        // if the seed transaction ever rolled back partway (as it always did
        // before the schema-drift fix, since PackageDescription didn't exist
        // yet) the table could sit forever below 100 real seeded rows while
        // still legitimately containing an admin's own custom packages above
        // that count — or, worse, get stuck never reaching 100 and never
        // being recognised as "done". We instead compare against the actual
        // seed file size and rely on the per-row PackageName dedupe below,
        // which makes re-running this function always safe (it just tops up
        // whatever is missing rather than being an all-or-nothing gate).
        $already = (int)$dbh->query("SELECT COUNT(*) FROM tbltourpackages")->fetchColumn();
        if ($already >= count($rows) + 9 /* +9 = original tms.sql demo rows */) {
            return; // fully seeded already, nothing to do
        }

        $checkStmt = $dbh->prepare("SELECT PackageId FROM tbltourpackages WHERE PackageName=:n LIMIT 1");
        $insStmt = $dbh->prepare("INSERT INTO tbltourpackages
            (PackageName, PackageType, PackageDescription, PackageDetails, PackagePrice,
             PackageLocation, PackageDuration, PackageFetures, PackageImage, MapLat, MapLng,
             AvgRating, Status, Country, Region, IsFeatured, PackageMeta)
            VALUES (:name,:type,:desc,:details,:price,:loc,:dur,:features,:img,:lat,:lng,
                    :rating,1,:country,:region,:featured,:meta)");

        $dbh->beginTransaction();
        $i = 0;
        foreach ($rows as $row) {
            $checkStmt->execute([':n' => $row['PackageName']]);
            if ($checkStmt->fetch()) continue; // skip duplicates

            // Same USD-scale problem as PackagePrice, in a different spot:
            // meta.budget_estimate / meta.luxury_estimate are literal
            // "$25-40/day" strings that package-details.php renders
            // as-is — the only remaining place raw "$" prices were still
            // reaching the page after the PackagePrice fix above.
            $rowMeta = $row['meta'] ?? [];
            if (isset($rowMeta['budget_estimate'])) {
                $rowMeta['budget_estimate'] = tms_convert_usd_range_to_bdt((string)$rowMeta['budget_estimate']);
            }
            if (isset($rowMeta['luxury_estimate'])) {
                $rowMeta['luxury_estimate'] = tms_convert_usd_range_to_bdt((string)$rowMeta['luxury_estimate']);
            }

            $insStmt->execute([
                ':name'     => $row['PackageName'],
                ':type'     => $row['PackageType'],
                ':desc'     => $row['PackageDescription'],
                ':details'  => $row['PackageDetails'],
                ':price'    => tms_usd_to_bdt((int)$row['PackagePrice']),
                ':loc'      => $row['PackageLocation'],
                ':dur'      => $row['PackageDuration'],
                ':features' => $row['PackageFetures'],
                ':img'      => tms_destination_image(
                                    (string)($row['PackageName'] ?? ''),
                                    (string)($row['PackageLocation'] ?? ''),
                                    (string)($row['PackageType'] ?? ''),
                                    (int)crc32(($row['PackageName'] ?? '') . ($row['PackageLocation'] ?? ''))
                                ),
                ':lat'      => $row['MapLat'],
                ':lng'      => $row['MapLng'],
                ':rating'   => round(mt_rand(38, 50) / 10, 1),
                ':country'  => $row['Country'],
                ':region'   => $row['Region'],
                ':featured' => ($row['Country'] === 'Bangladesh' && mt_rand(0, 4) === 0) ? 1 : 0,
                ':meta'     => json_encode($rowMeta),
            ]);
            $i++;
        }
        $dbh->commit();
        if ($i > 0) error_log("[TMS Seed] Inserted $i new tour package(s) from packages_seed.json.");
    } catch (Exception $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log('[TMS Seed] Package seed FAILED and was rolled back: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// SECURITY HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function tms_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function tms_csrf_field(): void {
    echo '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(tms_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function tms_csrf_validate(): void {
    // SECURITY HARDENING (Phase 1): this used to be soft-only — it logged a
    // mismatch but let every POST through regardless, which meant CSRF
    // protection was effectively decorative everywhere it was called. As of
    // this pass, every form that reaches a tms_csrf_validate() call has been
    // audited to confirm it actually sends the tms_csrf_field()/csrf_token
    // value (see admin/index.php, issuetickets.php, admin/change-image.php,
    // admin/edit-package.php, admin/manage-coupons.php,
    // admin/manage-emergency.php, compare.php, ai-recommend.php,
    // budget-planner.php, custom-trip.php, includes/write-us.php, and the
    // live email-availability check in includes/header.php), so it is now
    // safe to actually enforce the check instead of only logging it.
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        error_log('[TMS] CSRF token missing/mismatch for ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        exit('Invalid or expired form submission. Please refresh the page and try again.');
    }
}

function tms_hash_password(string $plain): string {
    return password_hash($plain, PASSWORD_ALGO, ['cost' => PASSWORD_COST]);
}

function tms_verify_password(string $plain, string $stored, ?int $recordId, PDO $dbh, string $table = 'tblusers', string $pkCol = 'id'): bool {
    if (password_verify($plain, $stored)) {
        if ($recordId !== null && password_needs_rehash($stored, PASSWORD_ALGO, ['cost' => PASSWORD_COST])) {
            $dbh->prepare("UPDATE `$table` SET Password=? WHERE `$pkCol`=?")
                ->execute([tms_hash_password($plain), $recordId]);
        }
        return true;
    }
    if (strlen($stored) === 32 && $stored === md5($plain) && $recordId !== null) {
        $dbh->prepare("UPDATE `$table` SET Password=? WHERE `$pkCol`=?")
            ->execute([tms_hash_password($plain), $recordId]);
        return true;
    }
    return false;
}

/**
 * Given whatever tms_package_image_src() resolved to, returns a ready-to-
 * echo ' srcset="..." sizes="..."' string when that source is one of the
 * Unsplash-hosted fallback photos (which support arbitrary ?w= params, so
 * real width variants can be built), or an empty string when it's a local
 * upload (a single fixed-size file — nothing to offer multiple widths of,
 * so no srcset is honestly better than a fake/broken one).
 */
function tms_responsive_img_attrs(string $resolvedSrc): string {
    if (strpos($resolvedSrc, 'images.unsplash.com/photo-') === false) {
        return '';
    }
    $base = preg_replace('/\?.*$/', '', $resolvedSrc); // strip existing ?w=..&q=.. query
    $srcset = htmlspecialchars(
        "{$base}?w=400&q=80 400w, {$base}?w=800&q=80 800w, {$base}?w=1200&q=80 1200w",
        ENT_QUOTES, 'UTF-8'
    );
    return ' srcset="' . $srcset . '" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px"';
}

/**
 * Resolves PackageImage to the correct <img src>. Locally-uploaded filenames
 * live under admin/pacakgeimages/ and need that prefix; the themed Unsplash
 * fallback photos from tms_destination_image() are already full URLs and must
 * NOT get that prefix (doing so — the original bug here — produced an
 * invalid path like "admin/pacakgeimages/https://images.unsplash.com/...",
 * which just re-triggered the broken-image fallback).
 */
function tms_package_image_src(?string $packageImage): string {
    $img = trim((string)$packageImage);
    if ($img === '') return 'images/no-image-placeholder.jpg';
    if (preg_match('#^https?://#i', $img)) return $img;
    return 'admin/pacakgeimages/' . $img;
}

function tms_clean(string $val): string {
    return trim(strip_tags($val));
}

/**
 * The 8 currencies the "See Price In" selector supports, and how to display
 * each one. Symbol-before-amount for all of these; JPY conventionally has no
 * decimal places (it's not a subdivided currency), everything else uses 2.
 */
const TMS_CURRENCIES = [
    'BDT' => ['symbol' => '৳',  'decimals' => 0],
    'USD' => ['symbol' => '$',  'decimals' => 2],
    'EUR' => ['symbol' => '€',  'decimals' => 2],
    'GBP' => ['symbol' => '£',  'decimals' => 2],
    'JPY' => ['symbol' => '¥',  'decimals' => 0],
    'CAD' => ['symbol' => 'C$', 'decimals' => 2],
    'AUD' => ['symbol' => 'A$', 'decimals' => 2],
    'INR' => ['symbol' => '₹',  'decimals' => 2],
];

/** The visitor's chosen display currency (see set-currency.php), defaulting to BDT. */
function tms_active_currency(): string {
    $c = strtoupper((string)($_SESSION['currency'] ?? 'BDT'));
    return array_key_exists($c, TMS_CURRENCIES) ? $c : 'BDT';
}

/**
 * Formats a BDT amount (all prices are stored in BDT) as Bangladeshi Taka,
 * OR converts and formats it in whatever currency the visitor picked via the
 * "See Price In" selector (see tms_active_currency() / set-currency.php).
 *
 * BUG FIX (Task 5): this used to always print "৳ <amount>" no matter what
 * currency was selected — there was no conversion step at all, so choosing
 * EUR never changed anything shown on screen. It now reads the visitor's
 * chosen currency from the session, converts using the same live-rate feed
 * tms_get_fx_rates() already fetches for the per-package estimate widget,
 * and formats with the correct symbol. Every call site across the app
 * (package cards, package details, cart, checkout, wishlist, compare,
 * budget planner, etc.) already goes through this one function, so fixing
 * it here fixes the price shown everywhere at once — no per-page changes
 * needed. Falls back to BDT (never a blank/broken price) if the live-rate
 * API is unreachable.
 */
function tms_currency(float $amount): string {
    $code = tms_active_currency();
    $meta = TMS_CURRENCIES[$code];

    if ($code !== 'BDT') {
        global $tmsCache;
        $fx = tms_get_fx_rates($tmsCache instanceof CacheService ? $tmsCache : null);
        if ($fx['ok'] && !empty($fx['rates'][$code])) {
            $amount = $amount * (float)$fx['rates'][$code];
        } else {
            $code = 'BDT';
            $meta = TMS_CURRENCIES['BDT'];
        }
    }

    return $meta['symbol'] . ' ' . number_format($amount, $meta['decimals']);
}

/**
 * ROOT-CAUSE FIX: normalizes "smart"/curly quote variants (as typed by iOS/
 * Word/Android autocorrect, e.g. U+2019 ' ) down to a plain ASCII apostrophe.
 * Search terms like "Cox's Bazar" typed with a curly apostrophe would not
 * LIKE-match data stored with a straight apostrophe (or vice versa) — this
 * makes search apostrophe-insensitive regardless of which side used which
 * character.
 */
function tms_normalize_quotes(string $val): string {
    $map = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201B}" => "'", "\u{2032}" => "'",
        "\u{FF07}" => "'", '´' => "'", '`' => "'",
        "\u{201C}" => '"', "\u{201D}" => '"',
    ];
    return strtr($val, $map);
}

function tms_validate_email(string $email): string {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) ?: '';
}

function tms_int(mixed $val, int $min = 0, int $max = PHP_INT_MAX): int {
    return max($min, min($max, (int)$val));
}

function tms_require_user(): void {
    if (empty($_SESSION['login'])) {
        $_SESSION['login_error'] = 'Please sign in to continue — this page requires an account.';
        // Remember where the visitor was actually headed (Batch 1: whole-app
        // login gate) so the sign-in modal can send them back there instead
        // of always landing on the homepage after they authenticate.
        $_SESSION['post_login_redirect'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: index.php');
        exit;
    }
}

/**
 * Record that the current logged-in user viewed a package, for the
 * "Recently Viewed" feature. Deliberately does nothing for guests — there's
 * no reliable, privacy-safe identity to key on without a login (a cookie/IP
 * based version would leak history across shared devices), so this is
 * scoped to signed-in users only, same as Wishlist.
 * Keeps at most $keep rows per user by trimming the oldest after each view.
 */
function tms_track_recently_viewed(PDO $dbh, int $packageId, int $keep = 12): void {
    if (empty($_SESSION['login']) || $packageId <= 0) return;
    $email = $_SESSION['login'];
    try {
        $dbh->prepare(
            "INSERT INTO tblrecentlyviewed (UserEmail, PackageId, ViewedAt) VALUES (:e, :p, NOW())
             ON DUPLICATE KEY UPDATE ViewedAt = NOW()"
        )->execute([':e' => $email, ':p' => $packageId]);

        // Trim to the most recent $keep — cheap since it only runs after an
        // insert/update, not on every page load elsewhere.
        $ids = $dbh->prepare("SELECT id FROM tblrecentlyviewed WHERE UserEmail=:e ORDER BY ViewedAt DESC LIMIT 1000 OFFSET :k");
        $ids->bindValue(':e', $email, PDO::PARAM_STR);
        $ids->bindValue(':k', $keep, PDO::PARAM_INT);
        $ids->execute();
        $stale = $ids->fetchAll(PDO::FETCH_COLUMN);
        if ($stale) {
            $in = implode(',', array_map('intval', $stale));
            $dbh->exec("DELETE FROM tblrecentlyviewed WHERE id IN ($in)");
        }
    } catch (Exception $e) {
        error_log('[TMS RecentlyViewed] track failed: ' . $e->getMessage());
    }
}

function tms_require_admin(): void {
    if (empty($_SESSION['alogin'])) {
        header('Location: index.php');
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// RBAC (Phase 1b) — two roles: 'superadmin' (full access, the historical
// default so no existing installation loses access) and 'manager'
// (operational access — packages, bookings, users, content, communication —
// but not revenue/export data or the ability to create other admins).
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Returns the logged-in admin's role ('superadmin' or 'manager'), looked up
 * fresh from the DB each call (cheap single-row lookup by primary-key-backed
 * username) rather than trusted from the session, so a role change takes
 * effect on the admin's very next page load instead of only their next login.
 */
function tms_admin_role(PDO $dbh): string {
    if (empty($_SESSION['alogin'])) return '';
    try {
        $stmt = $dbh->prepare("SELECT Role FROM admin WHERE UserName = :u LIMIT 1");
        $stmt->execute([':u' => $_SESSION['alogin']]);
        $role = $stmt->fetchColumn();
        return $role ?: 'superadmin'; // fail open to the historical behavior, not locked-out
    } catch (Exception $e) {
        error_log('[TMS RBAC] Role lookup failed: ' . $e->getMessage());
        return 'superadmin'; // fail open — an infra hiccup should never lock the admin out
    }
}

/**
 * Gate a page to specific admin roles. Call tms_require_admin() first (or
 * rely on this calling it), then this. Shows a friendly 403 page instead of
 * a blank screen or silent redirect, since a manager clicking a link they
 * can't see (if the sidebar somehow still shows it) deserves an explanation.
 */
function tms_admin_require_role(PDO $dbh, array $allowedRoles): void {
    tms_require_admin();
    $role = tms_admin_role($dbh);
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access Restricted</title>'
           . '<style>body{font-family:Inter,Segoe UI,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;'
           . 'align-items:center;justify-content:center;min-height:100vh;margin:0;}'
           . '.box{text-align:center;max-width:420px;padding:32px;}'
           . '.box i{font-size:40px;color:#f87171;margin-bottom:16px;display:block;}'
           . '.box h2{margin:0 0 10px;font-size:20px;}'
           . '.box p{color:#94a3b8;font-size:14px;margin:0 0 20px;}'
           . '.box a{color:#60a5fa;text-decoration:none;font-weight:600;}</style></head><body>'
           . '<div class="box"><i class="fa fa-lock">&#128274;</i><h2>Access Restricted</h2>'
           . '<p>Your admin role (' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . ') doesn\'t include '
           . 'permission to view this page. Contact a super-admin if you need access.</p>'
           . '<a href="dashboard.php">&#8592; Back to Dashboard</a></div></body></html>';
        exit;
    }
}

/**
 * Create an in-app notification for a user (shown via the bell icon in the
 * topbar). Used e.g. when an admin confirms/cancels a booking, so the user
 * finds out next time they visit the site, without needing a real SMTP
 * server configured (this project has none set up).
 */
function tms_notify(PDO $dbh, string $userEmail, string $message, string $link = ''): void {
    try {
        $dbh->prepare("INSERT INTO tbl_notifications(UserEmail, Message, Link) VALUES(:e, :m, :l)")
            ->execute([':e' => $userEmail, ':m' => $message, ':l' => $link]);
    } catch (Exception $e) {
        error_log('[TMS Notify] Failed to create notification: ' . $e->getMessage());
    }
}

/**
 * Notify every registered user at once (e.g. "New offer" announcements —
 * a new coupon was published). Uses a single INSERT...SELECT instead of
 * looping every user in PHP, so it stays cheap even as the user base grows.
 */
function tms_notify_all(PDO $dbh, string $message, string $link = ''): void {
    try {
        $stmt = $dbh->prepare(
            "INSERT INTO tbl_notifications(UserEmail, Message, Link)
             SELECT EmailId, :m, :l FROM tblusers WHERE EmailId IS NOT NULL AND EmailId != ''"
        );
        $stmt->execute([':m' => $message, ':l' => $link]);
    } catch (Exception $e) {
        error_log('[TMS Notify] Broadcast failed: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// LOGIN BRUTE-FORCE PROTECTION
// ══════════════════════════════════════════════════════════════════════════════

const TMS_LOGIN_MAX_ATTEMPTS  = 5;
const TMS_LOGIN_LOCKOUT_MINS  = 15;

/**
 * Returns the number of minutes remaining before this email/IP may try
 * logging in again, or 0 if not currently locked out.
 */
function tms_login_lockout_minutes_remaining(PDO $dbh, string $email, string $ip): int {
    try {
        $stmt = $dbh->prepare(
            "SELECT COUNT(*), MAX(CreatedAt) FROM tbl_login_attempts
             WHERE (Email = :email OR IPAddress = :ip) AND CreatedAt > DATE_SUB(NOW(), INTERVAL :mins MINUTE)"
        );
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':mins', TMS_LOGIN_LOCKOUT_MINS, PDO::PARAM_INT);
        $stmt->execute();
        [$count, $lastAttempt] = $stmt->fetch(PDO::FETCH_NUM);

        if ((int)$count < TMS_LOGIN_MAX_ATTEMPTS || !$lastAttempt) {
            return 0;
        }
        $unlockAt = (new DateTime($lastAttempt))->modify('+' . TMS_LOGIN_LOCKOUT_MINS . ' minutes');
        $remaining = (new DateTime())->diff($unlockAt);
        $minsLeft = (int)ceil(($remaining->days * 1440 + $remaining->h * 60 + $remaining->i));
        return $unlockAt > new DateTime() ? max(1, $minsLeft) : 0;
    } catch (Exception $e) {
        error_log('[TMS Login] Lockout check failed: ' . $e->getMessage());
        return 0; // fail open — never lock people out due to an infra error
    }
}

function tms_record_failed_login(PDO $dbh, string $email, string $ip): void {
    try {
        $dbh->prepare("INSERT INTO tbl_login_attempts(Email, IPAddress) VALUES(:e, :ip)")
            ->execute([':e' => $email, ':ip' => $ip]);
    } catch (Exception $e) {
        error_log('[TMS Login] Could not record failed attempt: ' . $e->getMessage());
    }
}

function tms_clear_login_attempts(PDO $dbh, string $email, string $ip): void {
    try {
        $dbh->prepare("DELETE FROM tbl_login_attempts WHERE Email = :e OR IPAddress = :ip")
            ->execute([':e' => $email, ':ip' => $ip]);
    } catch (Exception $e) {
        error_log('[TMS Login] Could not clear attempts: ' . $e->getMessage());
    }
}

/** Generates a short, unique, user-friendly referral code like "TMS4F9A2C". */
function tms_generate_referral_code(PDO $dbh): string {
    do {
        $code = 'TMS' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $dbh->prepare("SELECT id FROM tblusers WHERE ReferralCode = :c LIMIT 1");
        $stmt->execute([':c' => $code]);
    } while ($stmt->fetch());
    return $code;
}

// ══════════════════════════════════════════════════════════════════════════════
// WEATHER (OpenWeatherMap) — real live weather + 5-day forecast, DB-cached
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Fetch live weather + forecast for a lat/lng. Returns an array with keys:
 * ok(bool), city, temp, feels_like, humidity, wind_speed, condition, icon,
 * forecast(array of daily summaries), error(string, only if !ok).
 * Never throws — always returns a usable array so the page can render gracefully.
 */
function tms_get_weather(PDO $dbh, float $lat, float $lng, string $label = ''): array {
    if (!$lat && !$lng) {
        return ['ok' => false, 'error' => 'no_coords'];
    }

    $cacheKey = 'owm_' . round($lat, 3) . '_' . round($lng, 3);

    // Try cache first (Phase 1d: shared CacheService instead of this
    // function's own tblweathercache table — see includes/CacheService.php)
    global $tmsCache;
    if (isset($tmsCache)) {
        $cachedData = $tmsCache->get($cacheKey);
        if (is_array($cachedData)) return $cachedData;
    }

    // ROOT-CAUSE FIX: this function used to require a paid/registered
    // OpenWeatherMap API key and simply gave up — falling back to fake
    // seasonal-average weather — the instant that key was empty. That means
    // "Integrate a real weather API" was only ever actually true for a
    // deployer who went and signed up for OpenWeatherMap; everyone else
    // silently saw simulated data with no indication it wasn't live. The
    // project already has a proven, free, no-signup weather integration
    // (Open-Meteo, in includes/ExploreService.php) — reuse that as the
    // fallback here instead of giving up, so the Weather page shows real
    // live data out of the box regardless of whether an OWM key exists.
    if (empty(OPENWEATHER_API_KEY)) {
        return tms_get_weather_openmeteo($dbh, $lat, $lng, $label, $cacheKey, $tmsCache ?? null);
    }

    $result = ['ok' => false, 'error' => 'fetch_failed'];

    try {
        $curBase = 'https://api.openweathermap.org/data/2.5/weather';
        $curUrl  = $curBase . '?lat=' . urlencode($lat) . '&lon=' . urlencode($lng)
                 . '&units=metric&appid=' . urlencode(OPENWEATHER_API_KEY);

        $fcBase = 'https://api.openweathermap.org/data/2.5/forecast';
        $fcUrl  = $fcBase . '?lat=' . urlencode($lat) . '&lon=' . urlencode($lng)
                . '&units=metric&appid=' . urlencode(OPENWEATHER_API_KEY);

        $curJson = _tms_http_get($curUrl);
        $cur = $curJson ? json_decode($curJson, true) : null;

        if (!$cur || !isset($cur['main'])) {
            // Live OWM call failed even though a key is configured (bad key,
            // rate limit, network blip, etc.) — fall back to the free
            // Open-Meteo path rather than giving up straight to simulated
            // seasonal averages, so a temporary OWM problem doesn't take
            // real weather away entirely.
            return tms_get_weather_openmeteo($dbh, $lat, $lng, $label, $cacheKey, $tmsCache ?? null);
        }

        $forecast = [];
        $fcJson = _tms_http_get($fcUrl);
        $fc = $fcJson ? json_decode($fcJson, true) : null;
        if ($fc && !empty($fc['list'])) {
            $byDay = [];
            foreach ($fc['list'] as $slot) {
                $day = date('Y-m-d', $slot['dt']);
                $byDay[$day][] = $slot;
            }
            $i = 0;
            foreach ($byDay as $day => $slots) {
                if ($i === 0 && $day === date('Y-m-d')) { continue; } // skip partial today
                $temps = array_column(array_column($slots, 'main'), 'temp');
                $midSlot = $slots[intval(count($slots) / 2)];
                $forecast[] = [
                    'date'      => $day,
                    'day_label' => date('D', strtotime($day)),
                    'temp_max'  => round(max($temps)),
                    'temp_min'  => round(min($temps)),
                    'condition' => $midSlot['weather'][0]['main'] ?? '',
                    'icon'      => $midSlot['weather'][0]['icon'] ?? '01d',
                ];
                $i++;
                if (count($forecast) >= 5) break;
            }
        }

        // sys.sunrise/sunset are UTC unix timestamps; sys.timezone-less
        // builds still give a top-level 'timezone' offset (seconds from UTC)
        // we can apply with gmdate() to show the destination's local time
        // rather than the server's.
        $tzOffset = (int)($cur['timezone'] ?? 0);
        $sunrise  = isset($cur['sys']['sunrise']) ? gmdate('h:i A', $cur['sys']['sunrise'] + $tzOffset) : null;
        $sunset   = isset($cur['sys']['sunset'])  ? gmdate('h:i A', $cur['sys']['sunset']  + $tzOffset) : null;

        $result = [
            'ok'         => true,
            'source'     => 'openweathermap',
            'city'       => $cur['name'] ?: $label,
            'temp'       => round($cur['main']['temp']),
            'feels_like' => round($cur['main']['feels_like']),
            'humidity'   => $cur['main']['humidity'],
            'wind_speed' => round($cur['wind']['speed'] ?? 0, 1),
            'condition'  => $cur['weather'][0]['main'] ?? 'Clear',
            'description'=> ucfirst($cur['weather'][0]['description'] ?? ''),
            'icon'       => $cur['weather'][0]['icon'] ?? '01d',
            'sunrise'    => $sunrise,
            'sunset'     => $sunset,
            'forecast'   => $forecast,
            'fetched_at' => date('c'),
        ];

        // Save to cache (Phase 1d: shared CacheService)
        if (isset($tmsCache)) {
            $tmsCache->set($cacheKey, $result, WEATHER_CACHE_TTL);
        }

    } catch (Exception $e) {
        // Even an unexpected exception from the OWM path falls back to the
        // free API rather than giving up on real weather entirely.
        return tms_get_weather_openmeteo($dbh, $lat, $lng, $label, $cacheKey, $tmsCache ?? null);
    }

    return $result;
}

/**
 * Free, no-signup-required weather via Open-Meteo — same provider and
 * request shape already proven in includes/ExploreService.php, adapted to
 * return the field names weather.php / tms_get_weather()'s callers expect.
 * Icons come back as Font-Awesome class names (e.g. "fa-cloud-sun") rather
 * than OpenWeatherMap's icon codes (e.g. "01d") — callers must check
 * `source` before deciding whether to render an <img> (OWM) or an <i> (this).
 */
function tms_get_weather_openmeteo(PDO $dbh, float $lat, float $lng, string $label, string $cacheKey, $tmsCache): array {
    $result = ['ok' => false, 'error' => 'fetch_failed'];
    try {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude'      => $lat,
            'longitude'     => $lng,
            'current'       => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,is_day',
            'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min,sunrise,sunset',
            'forecast_days' => 6,
            'timezone'      => 'auto',
        ]);
        $json = _tms_http_get($url);
        $w = $json ? json_decode($json, true) : null;
        if (!$w || !isset($w['current'])) {
            return $result;
        }

        $code    = (int)($w['current']['weather_code'] ?? 0);
        $isDay   = (bool)($w['current']['is_day'] ?? 1);

        $forecast = [];
        if (!empty($w['daily']['time'])) {
            foreach ($w['daily']['time'] as $i => $date) {
                if ($i === 0) continue; // skip today, matches the OWM path (starts from tomorrow)
                $forecast[] = [
                    'date'      => $date,
                    'day_label' => date('D', strtotime($date)),
                    'temp_max'  => isset($w['daily']['temperature_2m_max'][$i]) ? round($w['daily']['temperature_2m_max'][$i]) : null,
                    'temp_min'  => isset($w['daily']['temperature_2m_min'][$i]) ? round($w['daily']['temperature_2m_min'][$i]) : null,
                    'condition' => tms_openmeteo_code_to_text((int)($w['daily']['weather_code'][$i] ?? 0)),
                    'icon'      => tms_openmeteo_code_to_icon((int)($w['daily']['weather_code'][$i] ?? 0), true),
                ];
                if (count($forecast) >= 5) break;
            }
        }

        $result = [
            'ok'         => true,
            'source'     => 'open-meteo',
            'city'       => $label,
            'temp'       => isset($w['current']['temperature_2m']) ? round($w['current']['temperature_2m']) : null,
            'feels_like' => isset($w['current']['apparent_temperature']) ? round($w['current']['apparent_temperature']) : null,
            'humidity'   => $w['current']['relative_humidity_2m'] ?? null,
            'wind_speed' => isset($w['current']['wind_speed_10m']) ? round($w['current']['wind_speed_10m'] / 3.6, 1) : null, // km/h -> m/s to match OWM units
            'condition'  => tms_openmeteo_code_to_text($code),
            'description'=> tms_openmeteo_code_to_text($code),
            'icon'       => tms_openmeteo_code_to_icon($code, $isDay),
            'sunrise'    => isset($w['daily']['sunrise'][0]) ? date('h:i A', strtotime($w['daily']['sunrise'][0])) : null,
            'sunset'     => isset($w['daily']['sunset'][0])  ? date('h:i A', strtotime($w['daily']['sunset'][0]))  : null,
            'forecast'   => $forecast,
            'fetched_at' => date('c'),
        ];

        if ($tmsCache) {
            $tmsCache->set($cacheKey, $result, WEATHER_CACHE_TTL);
        }
    } catch (Exception $e) {
        $result = ['ok' => false, 'error' => 'exception'];
    }
    return $result;
}

/** WMO weather code -> human text (Open-Meteo uses WMO codes, not OWM's condition strings). */
function tms_openmeteo_code_to_text(int $code): string {
    $map = [
        0=>'Clear sky',1=>'Mainly clear',2=>'Partly cloudy',3=>'Overcast',
        45=>'Fog',48=>'Depositing rime fog',
        51=>'Light drizzle',53=>'Moderate drizzle',55=>'Dense drizzle',
        56=>'Light freezing drizzle',57=>'Dense freezing drizzle',
        61=>'Slight rain',63=>'Moderate rain',65=>'Heavy rain',
        66=>'Light freezing rain',67=>'Heavy freezing rain',
        71=>'Slight snow fall',73=>'Moderate snow fall',75=>'Heavy snow fall',77=>'Snow grains',
        80=>'Slight rain showers',81=>'Moderate rain showers',82=>'Violent rain showers',
        85=>'Slight snow showers',86=>'Heavy snow showers',
        95=>'Thunderstorm',96=>'Thunderstorm with hail',99=>'Thunderstorm with heavy hail',
    ];
    return $map[$code] ?? 'Clear';
}

/** WMO weather code -> Font Awesome icon class (matches ExploreService.php's mapping). */
function tms_openmeteo_code_to_icon(int $code, bool $isDay): string {
    if (in_array($code, [0, 1], true)) return $isDay ? 'fa-sun' : 'fa-moon-o';
    if ($code === 2) return $isDay ? 'fa-cloud' : 'fa-cloud';
    if ($code === 3) return 'fa-cloud';
    if (in_array($code, [45, 48], true)) return 'fa-align-justify'; // fog — no dedicated FA glyph in the FA4 set this project uses
    if (in_array($code, [51,53,55,56,57,61,63,65,66,67,80,81,82], true)) return 'fa-tint';
    if (in_array($code, [71,73,75,77,85,86], true)) return 'fa-snowflake-o';
    if (in_array($code, [95,96,99], true)) return 'fa-bolt';
    return 'fa-cloud';
}

/** Small cURL GET wrapper with a strict timeout so a slow/dead API never hangs the page. */
function _tms_http_get(string $url): ?string {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res !== false ? $res : null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    return ($err === 0 && $res !== false) ? $res : null;
}

/**
 * Live currency conversion for the Currency Converter widget (reviewer
 * ask #21). All prices in this app are stored/displayed in BDT
 * (see tms_currency()), so this fetches BDT-based rates from
 * open.er-api.com — a free, no-signup, no-API-key endpoint (166
 * currencies, daily updates; attribution required per their terms, see
 * the widget's footer link). Cached for 12h via the same CacheService
 * already used for weather, so normal traffic never re-hits the API.
 * Returns ['ok'=>false] on any failure — callers must handle that
 * gracefully rather than showing a broken/blank converter.
 */
function tms_get_fx_rates(?CacheService $tmsCache = null): array {
    $cacheKey = 'fx:bdt_rates';
    if ($tmsCache) {
        $cached = $tmsCache->get($cacheKey);
        if ($cached !== null) return $cached;
    }
    $raw = _tms_http_get('https://open.er-api.com/v6/latest/BDT');
    if ($raw === null) return ['ok' => false];
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['result'] ?? '') !== 'success' || empty($data['rates'])) {
        return ['ok' => false];
    }
    $result = [
        'ok'         => true,
        'base'       => 'BDT',
        'rates'      => $data['rates'],
        'updated'    => $data['time_last_update_utc'] ?? null,
    ];
    if ($tmsCache) $tmsCache->set($cacheKey, $result, 12 * 3600);
    return $result;
}


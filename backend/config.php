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

// ─── AI Configuration ──────────────────────────────────────────────────────
// Round 34 (item 21, "AI Provider Abstraction"): AIClient.php used to talk
// to Google Gemini directly with no seam to swap or add a vendor. It now
// delegates to a small provider object (includes/ai/providers/) chosen by
// AI_PROVIDER. Default stays 'gemini' -- an existing .env with only
// GEMINI_API_KEY set (and no AI_PROVIDER line at all) behaves exactly as
// before this round.
define('AI_PROVIDER',     strtolower(trim((string) tms_env('AI_PROVIDER', 'gemini'))));

define('GEMINI_API_KEY',  tms_env('GEMINI_API_KEY', ''));
define('GEMINI_MODEL',    tms_env('GEMINI_MODEL', 'gemini-2.5-flash')); // free-tier model as of mid-2026 (gemini-1.5-flash is shut down)

// OpenAI (or any OpenAI-Chat-Completions-compatible endpoint -- point
// OPENAI_BASE_URL at a compatible provider instead of api.openai.com if
// needed). Implemented against OpenAI's long-documented Chat Completions
// shape; this session's web access was blocked when trying to re-verify it
// against current docs, and there is no OPENAI_API_KEY configured in this
// environment to test against a real account -- verify against
// https://platform.openai.com/docs/api-reference/chat before relying on
// this in production. See includes/ai/providers/OpenAIProvider.php and
// SESSION_SUMMARY_INDEX.md #34.
define('OPENAI_API_KEY',  tms_env('OPENAI_API_KEY', ''));
define('OPENAI_MODEL',    tms_env('OPENAI_MODEL', 'gpt-4o-mini'));
define('OPENAI_BASE_URL', rtrim((string) tms_env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'));

// Self-hosted inference (AI_PROVIDER=ollama). Keep the runtime on localhost/private network.
define('OLLAMA_BASE_URL', rtrim((string) tms_env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'), '/'));
define('OLLAMA_MODEL',    tms_env('OLLAMA_MODEL', 'qwen2.5:3b-instruct'));
// Optional semantic re-ranking of assistant search results (see backend/ai/rag/EmbeddingReranker.php).
// Empty = off (BM25 only). Example: AI_EMBED_MODEL=nomic-embed-text after `ollama pull nomic-embed-text`.
define('AI_EMBED_MODEL',  trim((string) tms_env('AI_EMBED_MODEL', '')));
// Provider failover (off by default). Comma list tried in order when AI_PROVIDER fails, e.g. AI_FALLBACK_PROVIDERS=ollama
// (local, no data leaves the machine). Another CLOUD vendor is used only if AI_FALLBACK_ALLOW_EXTERNAL=1 (privacy opt-in).
define('AI_FALLBACK_PROVIDERS', trim((string) tms_env('AI_FALLBACK_PROVIDERS', '')));
define('AI_FALLBACK_ALLOW_EXTERNAL', in_array(strtolower(trim((string) tms_env('AI_FALLBACK_ALLOW_EXTERNAL', '0'))), ['1', 'true', 'yes'], true));

define('AI_MAX_TOKENS',   (int) tms_env('AI_MAX_TOKENS', 32768));
define('AI_CACHE_TTL',    (int) tms_env('AI_CACHE_TTL', 3600));   // Cache AI responses for 1 hour
define('AI_RATE_LIMIT',   (int) tms_env('AI_RATE_LIMIT', 30));    // Max AI calls per user per hour

// ─── Commercial guard rails ──────────────────────────────────────────────────
// The largest discount any single coupon may apply. Loyalty redemption used to
// be uncapped (floor(points/500)*10 with no ceiling), so 5,000 points minted a
// 100%-off coupon and 6,000 points produced 120%. Applied both when a coupon is
// created and again when it is redeemed, so an existing bad row can't be used.
define('TMS_MAX_LOYALTY_DISCOUNT', (int) tms_env('TMS_MAX_LOYALTY_DISCOUNT', 40));

// ─── Backward-compat aliases (so existing code using ANTHROPIC_* still works) ─
define('ANTHROPIC_API_KEY', GEMINI_API_KEY);
define('ANTHROPIC_MODEL',   GEMINI_MODEL);

// Diagnostic only — gated to whichever provider AI_PROVIDER actually
// selects (Round 34), so switching to OpenAI no longer logs a misleading
// "GEMINI_API_KEY is empty" warning about a provider the site isn't using.
if (AI_PROVIDER === 'openai') {
    if (OPENAI_API_KEY === '') {
        error_log('[TMS Config] AI_PROVIDER=openai but OPENAI_API_KEY is empty — every AI '
            . 'feature (chatbot, Explore overview, AI Recommender, planner, packing assistant) '
            . 'will return its non-AI fallback. Set OPENAI_API_KEY in .env. See .env.example.');
    }
} else {
    // A valid Google AI Studio key normally starts with "AIza". This does
    // NOT block the app (some proxied/alternate key formats exist), it just
    // logs so "AI features silently return 503" isn't a total mystery.
    if (GEMINI_API_KEY === '') {
        // FIX (Round 14): the diagnostic below only fired for a MALFORMED key, so the
        // far more common "no .env at all" case logged nothing and every AI feature
        // silently degraded to canned fallback text with no clue why.
        error_log('[TMS Config] GEMINI_API_KEY is empty — every AI feature (chatbot, Explore '
            . 'overview, AI Recommender, planner, packing assistant) will return its non-AI '
            . 'fallback. Create a .env file in the project root containing GEMINI_API_KEY=... '
            . '(get a free key at https://aistudio.google.com). See .env.example.');
    }
    if (GEMINI_API_KEY !== '' && !str_starts_with(GEMINI_API_KEY, 'AIza')) {
        error_log('[TMS Config] GEMINI_API_KEY does not look like a standard Google AI Studio key '
            . '(those normally start with "AIza"). If AI-powered features (Explore Mode overview, '
            . 'AI Recommender, chatbots) are failing, regenerate a fresh key at '
            . 'https://aistudio.google.com and update .env.');
    }
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

// ─── Debug/error-display wiring (Round 115 security hardening) ────────────────
// APP_DEBUG was previously DEFINED here but never actually CONSULTED anywhere
// in the codebase (confirmed via a project-wide grep during the Round 115
// audit) -- so setting it to false in a production .env silently did nothing.
// This wires it to the one thing its own .env/.env.example comment already
// documents: whether raw PHP errors/warnings get printed into the page (and
// into JSON API responses, which breaks them). It only ever NARROWS exposure
// -- APP_DEBUG=false forces error display off -- and when APP_DEBUG=true (as
// this local environment's .env has it, correctly, for active development)
// it deliberately leaves display_errors/display_startup_errors exactly as
// php.ini already has them, so this change has zero effect on the current
// local dev experience. Errors are still always logged server-side either
// way (log_errors is forced on), and the fatal-error shutdown handler a few
// lines below -- which already catches fatals and shows a friendly page --
// is completely unaffected by this setting.
if (!APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

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
       . '<style>body{font-family:Poppins,Arial,sans-serif;background:#F7F8F5;color:#17231e;display:flex;'
       . 'align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px;}'
       . '.box{background:#fff;padding:40px;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:440px;}'
       . '.box i{font-size:40px;color:#e74c3c;margin-bottom:14px;display:block;}'
       . 'a{color:#176B50;font-weight:600;text-decoration:underline;}</style></head><body>'
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

// ─── PERF: schema fingerprint gate ───────────────────────────────────────────
// Every request used to run the schema-drift repair and the package seed/price
// backfill (hundreds of queries: ~400 per-package SELECTs plus information_schema
// lookups), and every NEW session (every crawler hit) re-ran all CREATE TABLE
// migrations. Now one SELECT compares a fingerprint of this file + the seed file
// with the value stored after the last successful run. Unchanged -> skip all of
// it. Any edit to config.php (i.e. a new migration) changes the fingerprint, so
// migrations still apply automatically after a deploy. Set
// TMS_FORCE_SCHEMA_CHECK=1 in .env to force a re-check on every request.
$tmsSchemaFp = md5((string)@md5_file(__FILE__) . '|' . (string)@filesize(__DIR__ . '/../data/packages_seed.json') . '|schema-v1');
$tmsSchemaOk = false;
if (!in_array(strtolower((string)tms_env('TMS_FORCE_SCHEMA_CHECK', '')), ['1', 'true', 'yes', 'on'], true)) {
    try {
        $tmsSchemaOk = hash_equals($tmsSchemaFp, (string)$dbh->query("SELECT v FROM tbl_app_meta WHERE k = 'schema_fingerprint'")->fetchColumn());
    } catch (Throwable $e) { $tmsSchemaOk = false; } // table not created yet
}
if ($tmsSchemaOk) {
    foreach (['tms_db_init', 'tms_ai_tables', 'tms_explore_tables', 'tms_api_tables', 'tms_marketplace_tables', 'tms_supplier_tables', 'tms_notice_tables', 'tms_autopilot_tables', 'tms_memory_tables'] as $_k) { $_SESSION[$_k] = true; }
} else {
    foreach (['tms_db_init', 'tms_ai_tables', 'tms_explore_tables', 'tms_api_tables', 'tms_marketplace_tables', 'tms_supplier_tables', 'tms_notice_tables', 'tms_autopilot_tables', 'tms_memory_tables'] as $_k) { unset($_SESSION[$_k]); }
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

// Round 37 (items 12/13) -- Hotel System + Activities Marketplace as
// standalone bookable products, separate from tbltourpackages.
if (empty($_SESSION['tms_marketplace_tables'])) {
    _tms_run_marketplace_migrations($dbh);
    $_SESSION['tms_marketplace_tables'] = true;
}

// Supplier / travel-partner marketplace tables (see SUPPLIERS.md). Idempotent; session-gated like the others.
if (empty($_SESSION['tms_supplier_tables'])) {
    require_once __DIR__ . '/services/SupplierService.php';
    SupplierService::migrate($dbh);
    $_SESSION['tms_supplier_tables'] = true;
}
// Travel notices (admin-entered, sourced; see TRAVEL_NOTICES.md).
if (empty($_SESSION['tms_notice_tables'])) {
    require_once __DIR__ . '/services/TravelNoticeService.php';
    TravelNoticeService::migrate($dbh);
    $_SESSION['tms_notice_tables'] = true;
}
// Trip Autopilot proposals (see TRIP_AUTOPILOT.md).
if (empty($_SESSION['tms_autopilot_tables'])) {
    require_once __DIR__ . '/services/TripAutopilot.php';
    TripAutopilot::migrate($dbh);
    $_SESSION['tms_autopilot_tables'] = true;
}
// Assistant memory the user controls (see backend/ai/TravelMemory.php).
if (empty($_SESSION['tms_memory_tables'])) {
    require_once __DIR__ . '/ai/TravelMemory.php';
    TravelMemory::migrate($dbh);
    $_SESSION['tms_memory_tables'] = true;
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
if (!$tmsSchemaOk) {
    _tms_repair_schema_drift($dbh);
    try {
        $dbh->exec("CREATE TABLE IF NOT EXISTS tbl_app_meta (k VARCHAR(60) NOT NULL PRIMARY KEY, v VARCHAR(120) NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $dbh->prepare("INSERT INTO tbl_app_meta (k, v) VALUES ('schema_fingerprint', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$tmsSchemaFp]);
    } catch (Throwable $e) { error_log('[TMS Schema] could not store schema fingerprint: ' . $e->getMessage()); }
}

require_once __DIR__ . '/log-activity.php';
require_once __DIR__ . '/system-events.php';
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
 * Widens a text column that an old schema made too narrow. Never shrinks, never touches data.
 * Real defect this fixes: tms.sql created tblusers.MobileNumber as CHAR(10) and EmailId as VARCHAR(70), but
 * Bangladeshi mobile numbers have 11 digits, so on strict-mode MySQL/MariaDB (the XAMPP default) signup died with
 * "Data too long" (HTTP 500). tblenquiry.MobileNumber had the same problem.
 * @param array<string,array{0:int,1:string}> $cols column => [minimum length, full column definition]
 */
function tms_widen_columns(PDO $dbh, string $table, array $cols): void {
    try {
        $st = $dbh->prepare("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$table]);
        $have = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$n, $len]) $have[strtolower($n)] = $len === null ? null : (int)$len;
    } catch (Exception $e) { error_log("[TMS Schema] Could not inspect `$table`: " . $e->getMessage()); return; }
    foreach ($cols as $col => [$min, $def]) {
        $len = $have[strtolower($col)] ?? null;
        if ($len === null || $len >= $min) continue;
        try {
            $dbh->exec("ALTER TABLE `$table` MODIFY COLUMN $def");
            error_log("[TMS Schema] Widened `$table`.`$col` from $len to $min+ characters.");
        } catch (Exception $e) { error_log("[TMS Schema] FAILED widening `$table`.`$col`: " . $e->getMessage()); }
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
        // NEW (Round 20, spec item 48 -- Privacy): backs privacy.php's
        // notification/personalization toggles and the account-deletion
        // flow. NotificationsEnabled gates only the non-critical booking-
        // status emails sent via tms_notify_booking_owner() -- security/
        // transactional mail (password reset, the original booking
        // confirmation) is never gated by this, on purpose, so turning
        // notifications off can't accidentally lock someone out of a
        // password reset. PersonalizationEnabled gates whether
        // TravelRecommender pulls past-booking history into its prompts.
        // DeletedAt marks an anonymized (soft-deleted) account -- see
        // privacy.php's delete flow and tms_anonymize_user() below.
        'NotificationsEnabled'   => 'NotificationsEnabled TINYINT(1) DEFAULT 1',
        'PersonalizationEnabled' => 'PersonalizationEnabled TINYINT(1) DEFAULT 1',
        'DeletedAt'              => 'DeletedAt TIMESTAMP NULL DEFAULT NULL',
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
        // Payment architecture (see PAYMENTS.md). Self-healing here (like
        // every other column in this function) means the new payment.php
        // flow works immediately on any install, local or already-hosted,
        // without anyone having to remember to run a separate migration
        // step -- consistent with how every other column in this project
        // has always been added. database/migrate.php still exists and
        // tracks this same change explicitly for anyone who wants an
        // auditable schema history (Rule 39); both mechanisms agreeing is
        // fine since ALTER TABLE ADD COLUMN here is itself guarded by the
        // existing-column check at the top of tms_ensure_columns().
        'PaymentStatus'      => "PaymentStatus VARCHAR(20) DEFAULT 'unpaid'",
        'PaymentId'          => 'PaymentId INT DEFAULT NULL',
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
    // Seed real emergency numbers once. tblemergencycontacts has always been
    // created by the migrations and never populated, so emergency.php — a page
    // whose entire purpose is those numbers — rendered the red SOS banner, a
    // search box that filtered nothing, and then a blank gap. These are the
    // official public emergency lines for the countries this catalogue covers.
    try {
        $hasContacts = (int)$dbh->query("SELECT COUNT(*) FROM tblemergencycontacts")->fetchColumn();
        if ($hasContacts === 0) {
            $seedContacts = [
                ['Bangladesh',  '999', '999', '999', '333',
                 'Nearest embassy or high commission in Dhaka — check your own government\'s travel advisory page.',
                 'One national number (999) covers police, ambulance and fire. 333 is the government information and tourist helpline. Keep a photocopy of your passport separate from the original.'],
                ['India',       '112', '108', '101', '1363',
                 'Your country\'s embassy is in New Delhi; several also have consulates in Mumbai, Chennai and Kolkata.',
                 '112 is the single emergency number nationwide; 108 reaches an ambulance directly in most states. 1363 is the national tourist helpline (multilingual, 24 hours).'],
                ['Nepal',       '100', '102', '101', '1144',
                 'Most embassies are in Kathmandu.',
                 'Mobile coverage is patchy on trekking routes — register your route with your guesthouse. Altitude sickness is the most common serious problem; descend rather than wait it out.'],
                ['Bhutan',      '113', '112', '110', '1345',
                 'Very few countries have an embassy in Thimphu; India\'s embassy handles many consular matters.',
                 'Travel is normally through a licensed operator — your guide is your first point of contact in any emergency and can reach services faster than you can.'],
                ['Thailand',    '191', '1669', '199', '1672',
                 'Embassies are concentrated in Bangkok.',
                 '1672 is the Tourist Authority of Thailand call centre (English, 8am-8pm). The dedicated Tourist Police number is 1155 and is English-speaking, 24 hours.'],
                ['Indonesia',   '110', '118', '113', '112',
                 'Embassies are in Jakarta; many countries also have a consulate in Bali (Denpasar).',
                 '112 works from mobiles nationwide. In Bali, the tourist police can be reached on 0361 224111. Traffic accidents are the single most common cause of tourist injury.'],
                ['Malaysia',    '999', '999', '994', '1300885050',
                 'Embassies are in Kuala Lumpur.',
                 '999 covers police and ambulance (112 from a mobile also works). Tourism Malaysia\'s infoline is 1300 88 5050.'],
                ['Singapore',   '999', '995', '995', '1800736 2000',
                 'Embassies and high commissions are throughout the city.',
                 '999 for police, 995 for ambulance and fire. Non-emergency medical advice is available at any polyclinic.'],
                ['United Arab Emirates', '999', '998', '997', '800555',
                 'Embassies are in Abu Dhabi; most also have a consulate in Dubai.',
                 '800 555 is the Dubai Police non-emergency line. Public behaviour rules are stricter than many visitors expect — read your operator\'s briefing.'],
                ['France',      '17', '15', '18', '112',
                 'Embassies are in Paris.',
                 '112 works anywhere in the EU, from any phone, in English. 114 is the SMS emergency number for the deaf and hard of hearing.'],
                ['Switzerland', '117', '144', '118', '112',
                 'Embassies are in Bern; several countries also have a consulate in Geneva or Zurich.',
                 '1414 reaches Rega, the air-rescue service, which matters in the mountains. 112 also works everywhere.'],
                ['United Kingdom', '999', '999', '999', '101',
                 'Embassies and high commissions are in London.',
                 '999 (or 112) for emergencies, 101 for non-urgent police, 111 for urgent-but-not-emergency medical advice.'],
                ['Maldives',    '119', '102', '118', '1418',
                 'Very few embassies are resident in Malé; most cover the Maldives from Colombo or New Delhi.',
                 'On a resort island, tell reception first — they coordinate the seaplane or speedboat evacuation. Decompression illness after diving needs the chamber in Malé.'],
            ];
            $ins = $dbh->prepare(
                "INSERT INTO tblemergencycontacts
                 (Country, PoliceNumber, AmbulanceNumber, FireNumber, TouristHelpline, Embassy, SafetyTips)
                 VALUES (?,?,?,?,?,?,?)"
            );
            foreach ($seedContacts as $row) { $ins->execute($row); }
        }
    } catch (Exception $e) {
        error_log('[TMS Schema] emergency contacts seed failed: ' . $e->getMessage());
    }

    try {
        $dbh->exec("UPDATE tblbooking SET BookingDate = FromDate WHERE BookingDate IS NULL AND FromDate IS NOT NULL AND FromDate != ''");
    } catch (Exception $e) {
        error_log('[TMS Schema] BookingDate backfill failed: ' . $e->getMessage());
    }

    // Persistent history for the site-wide chatbot widget. Keyed by chat_uid
    // (the tms_chat_uid cookie, see tms_chat_uid() below) rather than by the PHP
    // session, so a visitor's conversation survives a page navigation, a refresh
    // AND a logout — the session dying mid-conversation is exactly why history
    // used to vanish. user_email is recorded alongside when known, purely so an
    // admin can trace a conversation back to an account. Created here rather
    // than in the once-per-session $aiTables list, because a visitor with an
    // already-active session would otherwise never get the table.
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tbl_site_chat_messages (
                id INT(11) NOT NULL AUTO_INCREMENT,
                chat_uid VARCHAR(40) NOT NULL,
                user_email VARCHAR(100) DEFAULT NULL,
                role ENUM('user','assistant') NOT NULL,
                message LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_site_chat_uid (chat_uid, id),
                INDEX idx_site_chat_created (created_at),
                INDEX idx_site_chat_email (user_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[TMS] tbl_site_chat_messages migration failed: ' . $e->getMessage());
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
    // Travel Wallet document expiry reminders -- previously the "Notes"
    // field's own placeholder text suggested typing "Expires March 2027" by
    // hand, with nothing ever reading it back out. A real, structured date
    // lets scripts/check_wallet_expiry.php actually notify someone before
    // their passport/visa/insurance expires, instead of relying on them to
    // remember a note they wrote themselves months earlier.
    tms_ensure_columns($dbh, 'tbltraveldocuments', [
        'ExpiryDate'      => 'ExpiryDate DATE DEFAULT NULL',
        'ExpiryNotifiedAt' => 'ExpiryNotifiedAt TIMESTAMP NULL DEFAULT NULL',
    ]);
    // Round 86 (master prompt item 28 -- encryption at rest for wallet
    // documents, see backend/wallet-crypto.php). Encrypted=0 (the default)
    // means this row's file on disk is plaintext -- true for every document
    // uploaded before this round, and left that way rather than rewriting
    // files that already exist (Rule 2). Encrypted=1 means the file is an
    // AES-256-GCM blob ([IV][tag][ciphertext]) that wallet-document.php must
    // decrypt before serving. Every upload from this round forward sets 1.
    tms_ensure_columns($dbh, 'tbltraveldocuments', [
        'Encrypted' => 'Encrypted TINYINT(1) NOT NULL DEFAULT 0',
    ]);
    // Round 87 (master prompt item 7 -- "Package System"): before this,
    // Inclusions/Exclusions/Cancellation Policy did not exist anywhere in
    // this codebase -- not a column, not a PackageMeta JSON key, not an
    // admin form field, confirmed by direct code read rather than assuming
    // the prior gap-audit's "unstructured blob" framing was accurate (it
    // wasn't quite -- the concepts were simply absent, not just
    // unstructured). One item per line, consistent with how PackageFetures/
    // PackageDetails already store simple admin-authored text -- no need
    // for a JSON structure for what's fundamentally a short list.
    tms_ensure_columns($dbh, 'tbltourpackages', [
        'Inclusions'         => 'Inclusions MEDIUMTEXT DEFAULT NULL',
        'Exclusions'         => 'Exclusions MEDIUMTEXT DEFAULT NULL',
        'CancellationPolicy' => 'CancellationPolicy MEDIUMTEXT DEFAULT NULL',
    ]);
    // Round 87: Add-ons are relational (a package defines what's available;
    // a booking records what was actually selected and at what price), so
    // unlike Inclusions/Exclusions this needs real tables, not columns --
    // same CREATE TABLE IF NOT EXISTS idiom already used for
    // tbltraveldocuments just above. tblbookingaddons.AddOnId is nullable
    // and Name/Price are snapshotted at booking time so a later edit or
    // deletion of the catalog add-on never rewrites what a customer was
    // actually charged and told they'd get.
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tblpackageaddons (
                AddOnId INT(11) NOT NULL AUTO_INCREMENT,
                PackageId INT(11) NOT NULL,
                Name VARCHAR(150) NOT NULL,
                Description VARCHAR(500) DEFAULT NULL,
                Price INT(11) NOT NULL DEFAULT 0,
                IsActive TINYINT(1) NOT NULL DEFAULT 1,
                CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (AddOnId),
                INDEX idx_addon_package (PackageId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tblbookingaddons (
                Id INT(11) NOT NULL AUTO_INCREMENT,
                BookingId INT(11) NOT NULL,
                AddOnId INT(11) DEFAULT NULL,
                Name VARCHAR(150) NOT NULL,
                Price INT(11) NOT NULL DEFAULT 0,
                Quantity INT(11) NOT NULL DEFAULT 1,
                CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (Id),
                INDEX idx_bookingaddons_booking (BookingId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Round 91 (items 10/81 -- Add to Cart never carried add-on selections
        // through to checkout, a disclosed scope limit from Round 87). Cart
        // add-ons are NOT snapshotted like tblbookingaddons -- a cart is
        // transient scratch state (hours/days, not a permanent receipt), so
        // Name/Price are read live from tblpackageaddons at cart/checkout
        // display time; only the real booking created at checkout gets a
        // snapshotted tblbookingaddons row. No FK CASCADE (this project's
        // convention throughout -- see tblbookingaddons above): cart.php's
        // single-item removal and checkout.php's post-checkout cart clear
        // both explicitly delete the matching tblcartaddons rows themselves.
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tblcartaddons (
                Id INT(11) NOT NULL AUTO_INCREMENT,
                CartId INT(11) NOT NULL,
                AddOnId INT(11) NOT NULL,
                Quantity INT(11) NOT NULL DEFAULT 1,
                CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (Id),
                INDEX idx_cartaddons_cart (CartId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[TMS] tblpackageaddons/tblbookingaddons/tblcartaddons migration failed: ' . $e->getMessage());
    }
    // Feature Phase 2c: persist the issues/highlights the AI was already
    // being asked for (and silently discarding), plus lightweight fake-review
    // flagging fields.
    tms_ensure_columns($dbh, 'tbl_review_sentiment', [
        'issues'            => 'issues TEXT DEFAULT NULL',
        'highlights'        => 'highlights TEXT DEFAULT NULL',
        'is_suspicious'     => 'is_suspicious TINYINT(1) DEFAULT 0',
        'suspicious_reason' => 'suspicious_reason VARCHAR(255) DEFAULT NULL',
    ]);
    // Travel Modes (master prompt item 67): tbl_user_profile already existed
    // (interests/budget_range/preferred_climate/travel_style) but nothing in
    // the app ever wrote to it -- there was no settings UI at all (a real,
    // separate gap from this one). travel_mode is a distinct concept from
    // travel_style: it's the traveler-type preset (family/couple/solo/
    // business/accessible) the master prompt names explicitly, used to
    // adjust recommendation weighting and surfaced messaging, not a vague
    // style tag. Nullable/no-preference by default -- never assumed.
    tms_ensure_columns($dbh, 'tbl_user_profile', [
        'travel_mode' => 'travel_mode VARCHAR(20) DEFAULT NULL',
    ]);
    // Personalization (master prompt item 23): tbl_user_profile already
    // captured budget/climate/travel_mode/interests, but "food preferences"
    // -- one of the spec's explicitly-named preference dimensions -- had no
    // field anywhere. Nullable/no-preference by default, same as every
    // other field here -- never assumed for a user who hasn't set one.
    tms_ensure_columns($dbh, 'tbl_user_profile', [
        'food_preference' => 'food_preference VARCHAR(30) DEFAULT NULL',
    ]);
    // Round 80 (master prompt items 12/13 -- Hotel System / Activities
    // Marketplace "reviews" requirement): tblreviews was package-only
    // (PackageId, with no way to attach a review to a hotel or activity).
    // Two new nullable FK columns let one review row point at exactly one
    // of the three entity types (enforced in application code, not a DB
    // constraint, to stay consistent with how PackageId itself was never
    // constrained either) -- existing package reviews are unaffected
    // (both new columns default NULL for every pre-existing row).
    tms_ensure_columns($dbh, 'tblreviews', [
        'HotelId'    => 'HotelId INT DEFAULT NULL',
        'ActivityId' => 'ActivityId INT DEFAULT NULL',
    ]);
    // Round 82 (master prompt item 54 -- "Content/Blog"): the spec names six
    // fields every article "should have" -- author, published date, updated
    // date, sources, related destinations, related packages. author
    // (AuthorId) and published date (CreatedAt) already existed; the other
    // four did not. UpdatedAt mirrors tblpages's own existing pattern exactly
    // (DEFAULT NULL, not DEFAULT CURRENT_TIMESTAMP, so every pre-existing
    // post stays NULL here rather than falsely showing "just updated" the
    // moment this migration runs -- it only gets a real value the next time
    // that specific post is actually edited). RelatedDestination is a plain
    // string (matching the real Country values tbltourpackages/
    // destinations.php already use), not a foreign key to a "destinations"
    // table -- no such table exists in this schema (destinations are derived
    // from tbltourpackages.Country everywhere else too), so a fake FK would
    // violate Rule 3. RelatedPackageId is a real nullable reference to
    // tbltourpackages.PackageId, unenforced at the DB level (consistent with
    // every other cross-table reference in this schema, e.g. tblreviews'
    // PackageId/HotelId/ActivityId above).
    tms_ensure_columns($dbh, 'tblblog', [
        'UpdatedAt'          => 'UpdatedAt TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        'Sources'            => 'Sources MEDIUMTEXT DEFAULT NULL',
        'RelatedDestination' => 'RelatedDestination VARCHAR(100) DEFAULT NULL',
        'RelatedPackageId'   => 'RelatedPackageId INT DEFAULT NULL',
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
    // Customer 2FA columns (previously only created by an optional SQL migration, which made
    // two-factor-setup.php fatal with "Unknown column" on installs that never ran it).
    tms_ensure_columns($dbh, 'tblusers', [
        'TwoFASecret'  => 'TwoFASecret VARCHAR(64) DEFAULT NULL',
        'TwoFAEnabled' => 'TwoFAEnabled TINYINT(1) DEFAULT 0',
    ]);
    tms_widen_columns($dbh, 'tblusers', [
        'MobileNumber' => [20, 'MobileNumber VARCHAR(20) DEFAULT NULL'],
        'EmailId'      => [100, 'EmailId VARCHAR(100) DEFAULT NULL'],
        'Password'     => [255, 'Password VARCHAR(255) DEFAULT NULL'],
    ]);
    tms_widen_columns($dbh, 'tblenquiry', ['MobileNumber' => [20, 'MobileNumber VARCHAR(20) DEFAULT NULL']]);
    try {
        $dbh->exec("UPDATE admin SET Role='superadmin' WHERE Role IS NULL OR Role=''");
    } catch (Exception $e) { /* column may not exist yet on first-ever run — fine, next request repairs it */ }
    try {
        $dbh->exec("ALTER TABLE admin ADD UNIQUE KEY uq_admin_username (UserName)");
    } catch (Exception $e) { /* already exists, or (rare) pre-existing duplicate usernames — non-fatal either way */ }

    // FIX (live testing, round 20): the exact "phantom column" drift this
    // function exists to repair (see the class doc-comment above) had never
    // actually been applied to tblcustomtrips itself. It pre-existed with
    // only `id` and `AiItinerary` (the latter added by a one-off ALTER at
    // tms_backfill... time), so the full CREATE TABLE IF NOT EXISTS further
    // down in this file -- which declares UserEmail, TripName, Destination,
    // StartDate, EndDate, Budget, TravelersCount, Accommodation, Transport,
    // Activities, MealPlan, SpecialRequests, Status, AdminNote, CreatedAt --
    // was always a silent no-op against the already-existing table. Nobody
    // caught this because custom-trip.php's own INSERT is only reachable via
    // a real form POST, and its "my past trips" SELECT (the one that throws)
    // only runs for a signed-in visitor -- this page had never actually been
    // live-tested signed-in before. Confirmed via a real PDOException:
    // "Column not found: 1054 Unknown column 'UserEmail' in 'where clause'
    // in custom-trip.php:72".
    tms_ensure_columns($dbh, 'tblcustomtrips', [
        'UserEmail'       => 'UserEmail VARCHAR(100) DEFAULT NULL',
        'TripName'        => 'TripName VARCHAR(200) DEFAULT NULL',
        'Destination'     => 'Destination VARCHAR(200) DEFAULT NULL',
        'StartDate'       => 'StartDate DATE DEFAULT NULL',
        'EndDate'         => 'EndDate DATE DEFAULT NULL',
        'Budget'          => 'Budget INT(11) DEFAULT NULL',
        'TravelersCount'  => 'TravelersCount INT(11) DEFAULT NULL',
        'Accommodation'   => 'Accommodation VARCHAR(100) DEFAULT NULL',
        'Transport'       => 'Transport VARCHAR(100) DEFAULT NULL',
        'Activities'      => 'Activities MEDIUMTEXT DEFAULT NULL',
        'MealPlan'        => 'MealPlan VARCHAR(100) DEFAULT NULL',
        'SpecialRequests' => 'SpecialRequests MEDIUMTEXT DEFAULT NULL',
        'Status'          => "Status VARCHAR(50) DEFAULT 'Pending'",
        'AdminNote'       => 'AdminNote MEDIUMTEXT DEFAULT NULL',
        'CreatedAt'       => 'CreatedAt TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
    ]);

    // Round 84 (master prompt items 8/37 -- discovered while live-testing the
    // money-arithmetic fix, not something I set out to change): tblpayment_events
    // has always been missing 4 columns that PaymentService.php's own code
    // requires -- ProviderEventId/Signature/ProcessingStatus/RetryCount are
    // read and written throughout confirmSandboxPayment() and handleWebhook(),
    // but neither the CREATE TABLE IF NOT EXISTS fallback just above nor
    // database/migrations/002_payments.sql ever declared them (both still say
    // so; kept as historical record, not edited, per Rule 39 -- this
    // tms_ensure_columns call is the actual fix, additive-only per Rule 8).
    // Confirmed via a real PDOException reproduced live: "SQLSTATE[42S22]:
    // Column not found: 1054 Unknown column 'ProcessingStatus' in 'field
    // list'" -- every sandbox payment confirmation was silently failing
    // (caught, logged, returns false) with no visible error to the customer
    // beyond "Payment could not be processed right now."
    tms_ensure_columns($dbh, 'tblpayment_events', [
        'ProviderEventId'  => 'ProviderEventId VARCHAR(150) DEFAULT NULL',
        'Signature'        => 'Signature VARCHAR(255) DEFAULT NULL',
        'ProcessingStatus' => "ProcessingStatus VARCHAR(20) NOT NULL DEFAULT 'received'",
        'RetryCount'       => 'RetryCount INT(11) NOT NULL DEFAULT 0',
    ]);

    // Round 96 (item 47 -- Audit Log): tblactivity_log has only ever had
    // Actor/Action/Details/IPAddress/CreatedAt -- everything gets packed
    // into one free-text Details string with no structured before/after
    // state, and no request metadata at all. The master prompt asks for
    // "object, old value, new value... request information where
    // appropriate". Additive-only (Rule 8) -- every one of the ~65
    // existing tms_log_activity() call sites keeps writing exactly as
    // before; these columns are simply NULL for rows/call sites that
    // don't pass them.
    tms_ensure_columns($dbh, 'tblactivity_log', [
        'ObjectType'    => 'ObjectType VARCHAR(40) DEFAULT NULL',
        'ObjectId'      => 'ObjectId VARCHAR(40) DEFAULT NULL',
        'OldValue'      => 'OldValue VARCHAR(500) DEFAULT NULL',
        'NewValue'      => 'NewValue VARCHAR(500) DEFAULT NULL',
        'RequestMethod' => 'RequestMethod VARCHAR(10) DEFAULT NULL',
        'RequestUri'    => 'RequestUri VARCHAR(255) DEFAULT NULL',
        'UserAgent'     => 'UserAgent VARCHAR(255) DEFAULT NULL',
    ]);

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

    // "TMS V2" prompt -- AI Budget Autopilot: tracks the last time each
    // booking was notified about approaching/exceeding its budget, so
    // scripts/check_budget_overspend.php (a periodic script, same idiom as
    // scripts/check_wallet_expiry.php -- Rule 73, no queue broker needed for
    // this project's size) doesn't re-notify on every run. One row per
    // booking, not per user, since the "budget" is the booking's own
    // TotalCost (see backend/ai/ExpenseAnalyst::getBudgetComparison()).
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS tblbudget_alerts (
                BookingId INT(11) NOT NULL,
                LastNotifiedAt TIMESTAMP NULL DEFAULT NULL,
                LastPercentUsed INT(11) DEFAULT NULL,
                PRIMARY KEY (BookingId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[TMS] tblbudget_alerts migration failed: ' . $e->getMessage());
    }

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
  `FromDate` varchar(100) DEFAULT NULL, `ToDate` varchar(100) DEFAULT NULL,
  `Comment` mediumtext DEFAULT NULL,
  `NoOfPersons` int(11) DEFAULT NULL, `BookingDate` date DEFAULT NULL,
  `TotalCost` int(11) DEFAULT NULL, `CouponCode` varchar(50) DEFAULT NULL,
  `DiscountApplied` int(11) DEFAULT 0, `status` int(1) DEFAULT 0,
  `CancelledBy` varchar(10) DEFAULT NULL,
  `RegDate` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`BookingId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
// BUG FOUND BY ROUND 51's DISASTER-RECOVERY TEST (a real restore into a
// completely fresh, empty database -- the first time this fallback
// CREATE TABLE had ever actually been exercised end-to-end): FromDate,
// ToDate and Comment are core booking columns checkout.php inserts into
// and admin/booking-calendar.php, admin/ai-dashboard.php,
// admin/operations-dashboard.php, ticket.php and admin/do-export.php all
// select from -- but they were missing from this fallback schema and NOT
// in tms_ensure_columns()'s add-on list either, because every prior round
// was tested against a database descended from the original tms.sql
// (which always had them). A from-scratch install relying purely on this
// self-healing fallback would have had checkout fail outright on its
// first INSERT. Purely additive (CREATE TABLE IF NOT EXISTS is a no-op on
// any database that already has this table), so this cannot affect an
// existing install -- it only closes the gap for a genuinely fresh one.

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
  `UpdatedAt` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `Sources` mediumtext DEFAULT NULL,
  `RelatedDestination` varchar(100) DEFAULT NULL,
  `RelatedPackageId` int(11) DEFAULT NULL,
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

// Payment ledger (see PAYMENTS.md / database/migrations/002_payments.sql —
// identical definitions, kept in both places deliberately: self-healing
// here means it Just Works on every install with zero manual steps, and
// the migration file gives an explicit, auditable, Rule-39 schema history
// for anyone who wants to know exactly what changed and when).
"CREATE TABLE IF NOT EXISTS `tblpayments` (
  `PaymentId` INT(11) NOT NULL AUTO_INCREMENT,
  `BookingId` INT(11) NOT NULL,
  `Provider` VARCHAR(30) NOT NULL,
  `ProviderMode` VARCHAR(10) NOT NULL DEFAULT 'sandbox',
  `ProviderTransactionId` VARCHAR(120) DEFAULT NULL,
  `AmountMinor` BIGINT(20) NOT NULL,
  `Currency` VARCHAR(3) NOT NULL DEFAULT 'BDT',
  `Status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `IdempotencyKey` VARCHAR(64) NOT NULL,
  `RawRequest` MEDIUMTEXT DEFAULT NULL,
  `RawResponse` MEDIUMTEXT DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`PaymentId`), UNIQUE KEY `uq_idempotency` (`IdempotencyKey`),
  KEY `idx_booking` (`BookingId`), KEY `idx_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1",

// Round 84: added ProviderEventId/Signature/ProcessingStatus/RetryCount here
// too, for a brand-new install -- PaymentService.php's webhook dedup/retry
// logic needs all four (see the tms_ensure_columns call further down, which
// is what actually repairs an install that already had this table without
// them).
"CREATE TABLE IF NOT EXISTS `tblpayment_events` (
  `EventId` INT(11) NOT NULL AUTO_INCREMENT,
  `PaymentId` INT(11) DEFAULT NULL, `Provider` VARCHAR(30) NOT NULL,
  `ProviderEventId` VARCHAR(150) DEFAULT NULL,
  `EventType` VARCHAR(60) NOT NULL, `SignatureValid` TINYINT(1) NOT NULL DEFAULT 0,
  `Signature` VARCHAR(255) DEFAULT NULL,
  `Payload` MEDIUMTEXT DEFAULT NULL, `ProcessedAt` TIMESTAMP NULL DEFAULT NULL,
  `ProcessingStatus` VARCHAR(20) NOT NULL DEFAULT 'received',
  `RetryCount` INT(11) NOT NULL DEFAULT 0,
  `ReceivedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`EventId`), KEY `idx_payment` (`PaymentId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1",

"CREATE TABLE IF NOT EXISTS `tblpayment_refunds` (
  `RefundId` INT(11) NOT NULL AUTO_INCREMENT,
  `PaymentId` INT(11) NOT NULL, `AmountMinor` BIGINT(20) NOT NULL,
  `Reason` VARCHAR(255) DEFAULT NULL, `Status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `RequestedBy` VARCHAR(100) DEFAULT NULL, `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ProcessedAt` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`RefundId`), KEY `idx_payment` (`PaymentId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1",

// Price / availability alerts (Rule 26/27). One-shot: IsActive flips to 0
// the moment the condition is met and a notification is fired, so a
// customer isn't renotified every hour the price stays low.
"CREATE TABLE IF NOT EXISTS `tbl_price_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `UserEmail` VARCHAR(100) NOT NULL,
  `PackageId` INT(11) NOT NULL, `TargetPrice` DECIMAL(12,2) NOT NULL,
  `IsActive` TINYINT(1) NOT NULL DEFAULT 1, `NotifiedAt` TIMESTAMP NULL DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_active` (`IsActive`), KEY `idx_package` (`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tbl_availability_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `UserEmail` VARCHAR(100) NOT NULL,
  `PackageId` INT(11) NOT NULL,
  `IsActive` TINYINT(1) NOT NULL DEFAULT 1, `NotifiedAt` TIMESTAMP NULL DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_active` (`IsActive`), KEY `idx_package` (`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Price history (Rule 26 -- "Track historical prices where legitimate data
// is available"). One row per package per calendar day, upserted by
// tms_record_price_snapshot() -- never backfilled or fabricated (Rule 3):
// this table starts empty and only ever grows real snapshots forward from
// whenever scripts/check_alerts.php's cron next runs. RecordedDate is a
// plain DATE column (not derived from RecordedAt via a DATE() expression)
// specifically so the UNIQUE KEY below can dedupe "already snapshotted
// today" with a portable INSERT ... ON DUPLICATE KEY UPDATE.
"CREATE TABLE IF NOT EXISTS `tbl_price_history` (
  `Id` INT(11) NOT NULL AUTO_INCREMENT, `PackageId` INT(11) NOT NULL,
  `Price` INT(11) NOT NULL, `RecordedDate` DATE NOT NULL,
  `RecordedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`), UNIQUE KEY `idx_pkg_date` (`PackageId`, `RecordedDate`),
  KEY `idx_package` (`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Group travel + expense splitting (Rule 24/25). Money here is
// AmountMinor (integer minor units) from the start -- unlike
// tblbooking.TotalCost's inherited float debt (see DATABASE.md), this is
// new code with no legacy float callers to stay compatible with, so it
// does the integer-money rule properly from day one.
"CREATE TABLE IF NOT EXISTS `tblgroup_trips` (
  `GroupId` INT(11) NOT NULL AUTO_INCREMENT,
  `Name` VARCHAR(150) NOT NULL,
  `PackageId` INT(11) DEFAULT NULL COMMENT 'optional link to a package this group is booking together',
  `CreatedBy` VARCHAR(100) NOT NULL,
  `Currency` VARCHAR(3) NOT NULL DEFAULT 'BDT',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`GroupId`), KEY `idx_creator` (`CreatedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblgroup_members` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `GroupId` INT(11) NOT NULL, `UserEmail` VARCHAR(100) NOT NULL,
  `Role` VARCHAR(20) NOT NULL DEFAULT 'member' COMMENT 'organizer | member',
  `JoinedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_group_member` (`GroupId`,`UserEmail`), KEY `idx_email` (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblgroup_expenses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `GroupId` INT(11) NOT NULL, `PaidByEmail` VARCHAR(100) NOT NULL,
  `Description` VARCHAR(200) NOT NULL, `AmountMinor` BIGINT(20) NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_group` (`GroupId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Round 103 (master prompt item 24, "GROUP TRAVEL" -- comments): the spec
// explicitly lists shared itinerary/voting/comments alongside expense
// splitting; expense splitting + roles were built in Round 16, comments
// had zero code anywhere (confirmed by grep) until now. Kept intentionally
// simple -- a flat discussion thread per group, no reactions/replies/edit/
// delete -- matching Rule 73 (don't overengineer) and this table's own
// narrow purpose (group members discussing the trip, not a general forum).
"CREATE TABLE IF NOT EXISTS `tblgroup_comments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `GroupId` INT(11) NOT NULL, `UserEmail` VARCHAR(100) NOT NULL,
  `Comment` VARCHAR(1000) NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_group_created` (`GroupId`,`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Round 104 (master prompt item 24, "GROUP TRAVEL" -- voting): the last of
// the three previously-missing sub-features (comments done Round 103;
// shared itinerary is still not built, tracked separately). Any member can
// start a poll (e.g. "which date works?" / "which activity?"); each member
// gets exactly one vote per poll, enforced by the UNIQUE key below rather
// than only in application code -- re-voting UPDATEs their existing row
// instead of inserting a second one, so a poll can never be stuffed by
// double-submitting.
"CREATE TABLE IF NOT EXISTS `tblgroup_polls` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `GroupId` INT(11) NOT NULL, `Question` VARCHAR(300) NOT NULL,
  `CreatedBy` VARCHAR(100) NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_group` (`GroupId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblgroup_poll_options` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `PollId` INT(11) NOT NULL, `OptionText` VARCHAR(200) NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_poll` (`PollId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblgroup_poll_votes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `PollId` INT(11) NOT NULL, `OptionId` INT(11) NOT NULL,
  `UserEmail` VARCHAR(100) NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_poll_voter` (`PollId`,`UserEmail`), KEY `idx_option` (`OptionId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Round 105 (master prompt item 24, "shared itinerary" -- the last of the
// three original Group Travel gaps, after Round 103's comments and Round
// 104's voting): a flat, chronological list of planned activities per
// group trip. DayLabel is deliberately free text (e.g. "Day 1" or a typed
// date), not a real DATE column -- a group trip may not have fixed dates
// agreed yet, and forcing one would be exactly the kind of fabricated
// structure Rule 3 warns against.
"CREATE TABLE IF NOT EXISTS `tblgroup_itinerary_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `GroupId` INT(11) NOT NULL,
  `DayLabel` VARCHAR(50) DEFAULT NULL,
  `Activity` VARCHAR(300) NOT NULL,
  `AddedBy` VARCHAR(100) NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_group` (`GroupId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Round 64 (master prompt item 56, "MONITORING"): generic system-event log
// for failure modes that had zero admin-visible tracking before -- see
// backend/system-events.php's own docblock for why this is separate from
// tbl_ai_telemetry/tblpayment_events/tblactivity_log rather than reusing one
// of them.
"CREATE TABLE IF NOT EXISTS `tbl_system_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(30) NOT NULL,
  `event_type` VARCHAR(60) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `message` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sysevents_category` (`category`),
  KEY `idx_sysevents_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Round 66 (master prompt item 44, SECURITY): forgot-password.php used to
// "verify" identity with only EmailId+MobileNumber -- both guessable/
// obtainable facts, not secrets -- and reset the password in that same
// request. Anyone who knew (or found) a customer's email and phone number
// could take over their account with zero access to their inbox or phone.
// Fixed the standard way: a single-use, random, time-limited token (same
// shape as api/index.php's tblapi_tokens -- raw token emailed to the
// account's registered address, only its SHA-256 hash stored here) that
// must be presented before any password is changed.
"CREATE TABLE IF NOT EXISTS `tbl_password_resets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `UserEmail` VARCHAR(100) NOT NULL,
  `TokenHash` VARCHAR(64) NOT NULL,
  `ExpiresAt` TIMESTAMP NOT NULL,
  `UsedAt` TIMESTAMP NULL DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pwreset_token_hash` (`TokenHash`),
  KEY `idx_pwreset_email` (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// FEATURE (Round 71, master prompt item 42 "API architecture" + item 44
// "rate limiting"/"API abuse"): api/index.php had zero request throttling
// of its own -- the only protection any of its endpoints had was
// tbl_login_attempts, and that only covers the `login` action. A fixed
// window counter, entirely SQL-clock-driven (WindowStart computed via
// MySQL's own NOW(), never PHP's time()/date() -- Round 66 found a real
// ~4 hour PHP/MySQL clock disagreement in this environment, so any new
// time-bucketed logic here has to stay SQL-only from the start).
"CREATE TABLE IF NOT EXISTS `tbl_api_rate_limit` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `Bucket` VARCHAR(40) NOT NULL,
  `Identifier` VARCHAR(100) NOT NULL,
  `WindowStart` BIGINT NOT NULL,
  `RequestCount` INT(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_rl_bucket_id_window` (`Bucket`,`Identifier`,`WindowStart`),
  KEY `idx_api_rl_window` (`WindowStart`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $sql) {
        try { $dbh->exec($sql); } catch (Exception $e) {}
    }

    // Seed admin -- SECURITY: no hard-coded default password any more. A fresh,
    // empty `admin` table is only bootstrapped when TMS_BOOTSTRAP_ADMIN_PASSWORD
    // is set in .env (min 12 chars); otherwise nothing is created and the log
    // says how to create one:  php tools/create-admin.php
    try {
        if ((int)$dbh->query("SELECT COUNT(*) FROM admin")->fetchColumn() === 0) {
            $bootPw = (string)tms_env('TMS_BOOTSTRAP_ADMIN_PASSWORD', '');
            if (strlen($bootPw) >= 12) {
                $hash = password_hash($bootPw, PASSWORD_BCRYPT, ['cost' => 12]);
                $st = $dbh->prepare("INSERT INTO admin (UserName, Password) VALUES ('admin', ?)");
                $st->execute([$hash]);
            } else {
                error_log('[TMS] No admin account exists. Run `php tools/create-admin.php` (or set TMS_BOOTSTRAP_ADMIN_PASSWORD, 12+ chars, in .env).');
            }
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

    // Seed Terms / Privacy pages when missing, or when they still hold the
    // sample text shipped in tms.sql (a copied "Yahoo! India" terms page and
    // lorem-ipsum privacy page). Admin-edited pages are never touched.
    // These are plain-language DRAFTS describing what this software actually
    // does; the business owner must review them (ideally with a lawyer).
    try {
        $policyDefaults = tms_default_policy_pages();
        $sel = $dbh->prepare("SELECT id, detail FROM tblpages WHERE type = :t LIMIT 1");
        foreach ($policyDefaults as $ptype => $pbody) {
            $sel->execute([':t' => $ptype]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $dbh->prepare("INSERT INTO tblpages(type, detail) VALUES(:t, :d)")->execute([':t' => $ptype, ':d' => $pbody]);
            } elseif (stripos($row['detail'], 'Yahoo') !== false || stripos($row['detail'], 'At vero eos') !== false) {
                $dbh->prepare("UPDATE tblpages SET detail = :d WHERE id = :id")->execute([':d' => $pbody, ':id' => $row['id']]);
            }
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
        // Round 80: mirror idx_reviews_pkg for the two new review targets.
        "CREATE INDEX IF NOT EXISTS idx_reviews_hotel ON tblreviews(HotelId)",
        "CREATE INDEX IF NOT EXISTS idx_reviews_activity ON tblreviews(ActivityId)",
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

// Round 115 database cleanup: tbl_ai_analytics (a metric_key/metric_value
// cache table) removed from this self-healing list. Confirmed genuinely
// dead first -- grepped the whole codebase for any INSERT/SELECT/UPDATE/
// DELETE against it and found none; it had been self-documented as "its
// own dead-schema cleanup candidate" since the Round 34 comment still on
// tbl_ai_telemetry below. Leaving the CREATE TABLE IF NOT EXISTS here
// would have silently recreated it forever, defeating the point of
// dropping it -- see database/migrations/009_index_and_dead_table_cleanup.sql
// for the actual DROP TABLE.

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
  `travel_mode` varchar(20) DEFAULT NULL,
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

// Round 34 (item 21, "AI Provider Abstraction + telemetry"): one row per
// AIClient call (any provider), separate from tbl_ai_usage_log (which
// exists purely to power the per-user rate limiter and is left untouched)
// and from tbl_ai_analytics (a metric_key/metric_value cache with a UNIQUE
// key per metric -- the wrong shape for a per-call event log, and still
// unused by anything; flagged as its own dead-schema cleanup candidate
// rather than silently repurposed here). Lets the admin AI dashboard show
// real per-feature success rate, latency and token usage instead of only
// a raw call count.
"CREATE TABLE IF NOT EXISTS `tbl_ai_telemetry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `feature` varchar(60) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `cached` tinyint(1) NOT NULL DEFAULT 0,
  `latency_ms` int(11) DEFAULT NULL,
  `prompt_tokens` int(11) DEFAULT NULL,
  `completion_tokens` int(11) DEFAULT NULL,
  `total_tokens` int(11) DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX idx_telemetry_time (`created_at`),
  INDEX idx_telemetry_feature (`feature`),
  INDEX idx_telemetry_provider (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Round 35 (item 22, "AI Evaluation suite"): one row per admin-triggered
// run of includes/ai/AIEvaluator.php -- a pass/fail snapshot across a
// representative slice of real AI features, so a provider or prompt change
// (e.g. switching AI_PROVIDER, Round 34) can be checked for regressions
// before/after rather than only found by a customer hitting a broken
// feature. results_json holds the full per-case detail; the four scalar
// columns are just for a fast history list without parsing JSON.
"CREATE TABLE IF NOT EXISTS `tbl_ai_eval_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(30) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `total_cases` int(11) DEFAULT NULL,
  `passed_cases` int(11) DEFAULT NULL,
  `results_json` longtext DEFAULT NULL,
  `run_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX idx_eval_time (`created_at`)
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
 * Round 37 (items 12/13) -- Hotel System + Activities Marketplace as their
 * own standalone bookable products, not attributes bolted onto
 * tbltourpackages (which already had HotelName/HotelRating/MealPlan columns,
 * but only as read-only description text on a tour package, never a
 * separately browsable/bookable listing).
 *
 * RULE 3 (never fabricate real-world data): this project has no real hotel
 * or activity supplier feed, contract, or API. Every row `tms_seed_hotels_
 * activities()` inserts is clearly-synthetic sample/demo inventory --
 * `IsSampleData` is hardcoded 1 on every seeded row, and hotels.php/
 * activities.php/their detail pages render a persistent "Sample Listing"
 * badge and an explanatory banner rather than presenting these as real,
 * bookable-today properties. A booking against one of these still writes a
 * real row (so the feature is genuinely functional end-to-end, not a static
 * mockup), but nothing here should ever be read by a user as "book last
 * night's actual hotel room" -- it demonstrates the marketplace shape
 * against a real, live-data-source integration point
 * (tms_seed_hotels_activities) that could later be swapped for a real
 * supplier feed without touching the browse/booking pages at all.
 *
 * Separate booking tables (tblhotel_bookings/tblactivity_bookings) rather
 * than trying to generalize tblbooking (which hardcodes a single PackageId
 * FK, no product-type column) -- that would be a much larger, riskier
 * schema change touching every existing package-booking code path for a
 * benefit this round doesn't need (Rule 8: no casual/broad schema changes).
 */
function _tms_run_marketplace_migrations(PDO $dbh): void {
    $tables = [
"CREATE TABLE IF NOT EXISTS `tblhotels` (
  `HotelId` int(11) NOT NULL AUTO_INCREMENT,
  `HotelName` varchar(200) NOT NULL,
  `City` varchar(100) DEFAULT NULL,
  `Country` varchar(100) DEFAULT NULL,
  `StarRating` tinyint(1) DEFAULT NULL COMMENT '1-5',
  `PricePerNight` int(11) DEFAULT NULL,
  `Amenities` varchar(500) DEFAULT NULL,
  `Description` mediumtext DEFAULT NULL,
  `HotelImage` varchar(300) DEFAULT NULL,
  `AvgRating` decimal(3,1) DEFAULT 0.0,
  `IsSampleData` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Rule 3 -- always 1 until a real supplier feed exists',
  `Status` int(1) DEFAULT 1,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`HotelId`), INDEX idx_hotel_city (`City`), INDEX idx_hotel_status (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblactivities` (
  `ActivityId` int(11) NOT NULL AUTO_INCREMENT,
  `ActivityName` varchar(200) NOT NULL,
  `City` varchar(100) DEFAULT NULL,
  `Country` varchar(100) DEFAULT NULL,
  `Category` varchar(100) DEFAULT NULL,
  `PricePerPerson` int(11) DEFAULT NULL,
  `DurationHours` decimal(4,1) DEFAULT NULL,
  `Description` mediumtext DEFAULT NULL,
  `ActivityImage` varchar(300) DEFAULT NULL,
  `AvgRating` decimal(3,1) DEFAULT 0.0,
  `IsSampleData` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Rule 3 -- always 1 until a real supplier feed exists',
  `Status` int(1) DEFAULT 1,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ActivityId`), INDEX idx_activity_city (`City`), INDEX idx_activity_status (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblhotel_bookings` (
  `BookingId` int(11) NOT NULL AUTO_INCREMENT,
  `HotelId` int(11) NOT NULL,
  `UserEmail` varchar(100) NOT NULL,
  `CheckInDate` date DEFAULT NULL,
  `CheckOutDate` date DEFAULT NULL,
  `NumRooms` int(11) DEFAULT 1,
  `NumGuests` int(11) DEFAULT 1,
  `MobileNumber` varchar(20) DEFAULT NULL,
  `Comment` mediumtext DEFAULT NULL,
  `TotalCost` int(11) DEFAULT NULL,
  `VerificationCode` varchar(20) DEFAULT NULL,
  `status` int(1) DEFAULT 0 COMMENT '0=Pending,1=Confirmed,2=Cancelled -- same convention as tblbooking.status',
  `RegDate` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`BookingId`), INDEX idx_hb_hotel (`HotelId`), INDEX idx_hb_user (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `tblactivity_bookings` (
  `BookingId` int(11) NOT NULL AUTO_INCREMENT,
  `ActivityId` int(11) NOT NULL,
  `UserEmail` varchar(100) NOT NULL,
  `ActivityDate` date DEFAULT NULL,
  `NumParticipants` int(11) DEFAULT 1,
  `MobileNumber` varchar(20) DEFAULT NULL,
  `Comment` mediumtext DEFAULT NULL,
  `TotalCost` int(11) DEFAULT NULL,
  `VerificationCode` varchar(20) DEFAULT NULL,
  `status` int(1) DEFAULT 0 COMMENT '0=Pending,1=Confirmed,2=Cancelled -- same convention as tblbooking.status',
  `RegDate` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`BookingId`), INDEX idx_ab_activity (`ActivityId`), INDEX idx_ab_user (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) {
        try { $dbh->exec($sql); } catch (Exception $e) {}
    }
    tms_seed_hotels_activities($dbh);
}

/**
 * Loads data/hotels_seed.json and data/activities_seed.json (clearly
 * synthetic sample listings -- see _tms_run_marketplace_migrations()'s
 * docblock) and inserts any not already present (matched by name), so
 * re-running this on every request is always safe and never duplicates.
 * Mirrors tms_seed_tourism_packages()'s exact dedupe-by-name approach.
 */
function tms_seed_hotels_activities(PDO $dbh): void {
    try {
        $hotelsPath = __DIR__ . '/../data/hotels_seed.json';
        if (file_exists($hotelsPath)) {
            $rows = json_decode(file_get_contents($hotelsPath), true);
            if (is_array($rows)) {
                $check = $dbh->prepare("SELECT HotelId FROM tblhotels WHERE HotelName=:n LIMIT 1");
                $ins = $dbh->prepare("INSERT INTO tblhotels
                    (HotelName, City, Country, StarRating, PricePerNight, Amenities, Description, HotelImage, AvgRating, IsSampleData, Status)
                    VALUES (:name,:city,:country,:stars,:price,:amenities,:desc,:img,:rating,1,1)");
                foreach ($rows as $row) {
                    $check->execute([':n' => $row['HotelName']]);
                    if ($check->fetch()) continue;
                    $ins->execute([
                        ':name' => $row['HotelName'], ':city' => $row['City'], ':country' => $row['Country'],
                        ':stars' => $row['StarRating'], ':price' => $row['PricePerNight'],
                        ':amenities' => $row['Amenities'] ?? '', ':desc' => $row['Description'] ?? '',
                        ':img' => $row['HotelImage'] ?? '', ':rating' => $row['AvgRating'] ?? 4.0,
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        error_log('[TMS Seed] Hotel seed failed: ' . $e->getMessage());
    }

    try {
        $activitiesPath = __DIR__ . '/../data/activities_seed.json';
        if (file_exists($activitiesPath)) {
            $rows = json_decode(file_get_contents($activitiesPath), true);
            if (is_array($rows)) {
                $check = $dbh->prepare("SELECT ActivityId FROM tblactivities WHERE ActivityName=:n LIMIT 1");
                $ins = $dbh->prepare("INSERT INTO tblactivities
                    (ActivityName, City, Country, Category, PricePerPerson, DurationHours, Description, ActivityImage, AvgRating, IsSampleData, Status)
                    VALUES (:name,:city,:country,:cat,:price,:hours,:desc,:img,:rating,1,1)");
                foreach ($rows as $row) {
                    $check->execute([':n' => $row['ActivityName']]);
                    if ($check->fetch()) continue;
                    $ins->execute([
                        ':name' => $row['ActivityName'], ':city' => $row['City'], ':country' => $row['Country'],
                        ':cat' => $row['Category'], ':price' => $row['PricePerPerson'], ':hours' => $row['DurationHours'],
                        ':desc' => $row['Description'] ?? '', ':img' => $row['ActivityImage'] ?? '', ':rating' => $row['AvgRating'] ?? 4.0,
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        error_log('[TMS Seed] Activity seed failed: ' . $e->getMessage());
    }
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

/**
 * Sample tour packages (data/packages_seed.json) are demo content, not the
 * business's real catalogue. They are only inserted when the owner opts in
 * with TMS_SEED_SAMPLE_PACKAGES=1 in .env, so a real install never has fake
 * packages (re)created behind its back. See tools/remove-sample-data.php.
 */
function tms_sample_packages_enabled(): bool {
    return (string)tms_env('TMS_SEED_SAMPLE_PACKAGES', '0') === '1';
}

function tms_seed_tourism_packages(PDO $dbh): void {
    if (!tms_sample_packages_enabled()) return;
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
                ':rating'   => 0, // no invented ratings: AvgRating comes from real reviews only
                ':country'  => $row['Country'],
                ':region'   => $row['Region'],
                ':featured' => ($row['Country'] === 'Bangladesh' && mt_rand(0, 4) === 0) ? 1 : 0,
                ':meta'     => json_encode($rowMeta + ['is_sample' => true]),
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
    // FIX (Round 66, master prompt item 44, low-priority hardening): a plain
    // === comparison of two hashes is a timing side-channel in theory (early
    // exit on the first mismatched byte) -- unlikely to be practically
    // exploitable over a network for a fixed-length MD5 hex string, but
    // hash_equals() is the standard constant-time comparison for exactly
    // this situation and costs nothing to use here, same as this file's own
    // CSRF check (tms_csrf_validate() below) already does.
    if (strlen($stored) === 32 && hash_equals($stored, md5($plain)) && $recordId !== null) {
        $dbh->prepare("UPDATE `$table` SET Password=? WHERE `$pkCol`=?")
            ->execute([tms_hash_password($plain), $recordId]);
        return true;
    }
    return false;
}

// ══════════════════════════════════════════════════════════════════════════════
// PRIVACY (Round 20, spec item 48) -- data export + account deletion
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Single source of truth for "every table that stores a row keyed to one
 * customer's email", used by BOTH privacy.php's data export and the account-
 * deletion anonymization below -- so the two can never drift out of sync
 * with each other (export something, forget to also scrub it on deletion,
 * or vice versa). Built by systematically grepping every CREATE TABLE in
 * this project for a UserEmail/user_email column, not from memory -- see
 * SESSION_SUMMARY_INDEX.md's Round 20 entry for how this list was produced.
 * Not claimed to be exhaustive forever: a new table with a user-email column
 * added later needs adding here too (nothing enforces that automatically).
 *
 * @return array<int, array{0:string,1:string}> [table, columnName] pairs.
 */
function tms_privacy_tables(): array {
    return [
        ['tblbooking', 'UserEmail'],
        ['tblissues', 'UserEmail'],
        ['tblreviews', 'UserEmail'],
        ['tblwishlist', 'UserEmail'],
        ['tblrecentlyviewed', 'UserEmail'],
        ['tblcoupons', 'UserEmail'],
        ['tblbudgetplans', 'UserEmail'],
        ['tblcustomtrips', 'UserEmail'],
        ['tblloyalty', 'UserEmail'],
        ['tblloyalty_log', 'UserEmail'],
        ['tbl_notifications', 'UserEmail'],
        ['tblcart', 'UserEmail'],
        ['tbl_price_alerts', 'UserEmail'],
        ['tbl_availability_alerts', 'UserEmail'],
        ['tblgroup_members', 'UserEmail'],
        ['tblrecommendations', 'UserEmail'],
        ['tblchat_messages', 'UserEmail'],
        ['tblchat_threads', 'UserEmail'],
        ['tblapi_tokens', 'UserEmail'],
        ['tbltraveldocuments', 'UserEmail'],
        ['tblhotel_bookings', 'UserEmail'], // Round 37 -- Hotel System bookings
        ['tblactivity_bookings', 'UserEmail'], // Round 37 -- Activities Marketplace bookings
        ['tbl_ai_usage_log', 'user_email'],
        ['tbl_ai_telemetry', 'user_email'], // Round 34 -- new AI telemetry log, same treatment as tbl_ai_usage_log
        ['tbl_ai_itineraries', 'user_email'],
        ['tbl_ai_packages', 'user_email'],
        ['tbl_copilot_sessions', 'user_email'],
        ['tbl_travel_memories', 'user_email'],
        ['tbl_user_profile', 'user_email'],
        ['tbl_ai_user_memory', 'user_email'], // assistant memory the user controls (TravelMemory)
        ['tbl_explore_history', 'user_email'],
        ['tbl_explore_chat', 'user_email'],
    ];
}

/**
 * Assembles everything this project stores about one customer into a plain
 * array, ready to json_encode() for privacy.php's "export my data" download.
 * Reads only -- never modifies anything. Tables that don't exist yet on an
 * older install are skipped rather than erroring (tms_ensure_columns-style
 * defensiveness), so this never breaks a partially-migrated database.
 */
function tms_export_user_data(PDO $dbh, string $email): array {
    $out = ['exported_at' => date('c'), 'account_email' => $email];

    try {
        $stmt = $dbh->prepare("SELECT id, FullName, MobileNumber, EmailId, RegDate, Language FROM tblusers WHERE EmailId = ? LIMIT 1");
        $stmt->execute([$email]);
        $out['profile'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $out['profile'] = null;
    }

    foreach (tms_privacy_tables() as [$table, $col]) {
        try {
            $stmt = $dbh->prepare("SELECT * FROM `$table` WHERE `$col` = ?");
            $stmt->execute([$email]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) $out[$table] = $rows;
        } catch (Exception $e) {
            // Table doesn't exist on this install, or a column mismatch --
            // skip it rather than failing the whole export over one table.
            continue;
        }
    }

    return $out;
}

/**
 * Right-to-erasure account deletion, implemented as anonymization rather
 * than a hard DELETE. Why: tblbooking/tblpayments and friends are this
 * project's financial/audit record (Rule 2 -- never silently remove
 * working functionality, which a cascading hard-delete of a paid booking
 * history effectively would, and most jurisdictions' consumer/tax law
 * expects transaction records to survive an account closure anyway). What
 * this DOES do: replace every trace of real PII (name, phone, email,
 * password) with an anonymous, unusable placeholder, consistently across
 * every table in tms_privacy_tables() -- so the original email/name/phone
 * are not recoverable from this database afterward, while row counts (and
 * therefore admin financial reporting, loyalty history, etc.) stay intact
 * against the new anonymous identifier. Locks the account out (unusable
 * random password hash + DeletedAt set) rather than leaving it loggable-into.
 *
 * @return bool true on success (all writes committed), false on failure
 *              (transaction rolled back, nothing changed).
 */
function tms_anonymize_user(PDO $dbh, string $email, int $userId): bool {
    $anonEmail = 'deleted-user-' . $userId . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '@deleted.invalid';
    $lockedPassword = tms_hash_password(bin2hex(random_bytes(32))); // unguessable, unusable

    // Travel Wallet documents (passport/visa/ID scans -- see tbltraveldocuments
    // and uploads/wallet/) are the one exception to this function's general
    // "anonymize, don't hard-delete" policy explained above: they are not a
    // financial/audit record like a booking, they're sensitive personal
    // document files with no legitimate reason to survive account deletion.
    // wallet.php's own single-document delete button already hard-deletes the
    // row and unlink()s the file (see the `delete_doc` handler there) -- this
    // does the same for every document this user has, rather than leaving the
    // generic anonymize-loop below just repoint an orphaned file's UserEmail
    // to the new anonymous identifier and call it deleted.
    $walletFilesToUnlink = [];
    try {
        $wq = $dbh->prepare("SELECT StoredFilename FROM tbltraveldocuments WHERE UserEmail = :e");
        $wq->execute([':e' => $email]);
        $walletFilesToUnlink = $wq->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // Table may not exist on an older/partial install -- nothing to do.
        $walletFilesToUnlink = [];
    }

    try {
        $dbh->beginTransaction();

        $dbh->prepare(
            "UPDATE tblusers SET FullName = 'Deleted User', MobileNumber = NULL,
             EmailId = :anon, Password = :pw, DeletedAt = NOW() WHERE id = :id"
        )->execute([':anon' => $anonEmail, ':pw' => $lockedPassword, ':id' => $userId]);

        try {
            // Saved assistant preferences are deleted outright (not just re-pointed to the anonymous address).
            $dbh->prepare("DELETE FROM tbl_ai_user_memory WHERE user_email = :e")->execute([':e' => $email]);
        } catch (Exception $e) { /* table absent on an older install */ }
        try {
            $dbh->prepare("DELETE FROM tbltraveldocuments WHERE UserEmail = :e")->execute([':e' => $email]);
        } catch (Exception $e) {
            error_log("[TMS Privacy] Could not delete tbltraveldocuments rows for user {$userId}: " . $e->getMessage());
        }

        foreach (tms_privacy_tables() as [$table, $col]) {
            try {
                $dbh->prepare("UPDATE `$table` SET `$col` = :anon WHERE `$col` = :old")
                    ->execute([':anon' => $anonEmail, ':old' => $email]);
            } catch (Exception $e) {
                // Most likely cause: this table doesn't exist on an older/
                // partial install. Log and move on to the next table rather
                // than aborting the whole deletion over one missing table --
                // the tblusers row itself (the actual account/login) is
                // already anonymized above regardless of what happens here.
                error_log("[TMS Privacy] Could not anonymize `$table`.`$col` for user {$userId}: " . $e->getMessage());
            }
        }

        $dbh->commit();
    } catch (Exception $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log('[TMS Privacy] Account anonymization failed for user ' . $userId . ': ' . $e->getMessage());
        return false;
    }

    // Only unlink the actual files once the DB transaction has committed --
    // if it had rolled back instead, the tbltraveldocuments rows (and the
    // account) would still exist, and deleting the files first would have
    // orphaned them the other way around (a row pointing at a file that no
    // longer exists).
    foreach ($walletFilesToUnlink as $storedFilename) {
        $path = 'uploads/wallet/' . $storedFilename;
        if (is_file($path)) @unlink($path);
    }

    return true;
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
/** The symbol of the currency amounts are STORED in (the base currency). */
define('TMS_BASE_SYMBOL', TMS_CURRENCIES['BDT']['symbol']);

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
 * The active currency as a small array for client-side rendering:
 * ['code','symbol','decimals','rate'] where rate converts a BDT amount into the
 * active currency. Several pages (budget-planner.php, image-analyzer.php) build
 * prices in JavaScript and used to hardcode a Taka symbol — so a visitor who had
 * chosen EUR saw "৳5,000" in the planner beside "€ 38" for the same saved plan.
 */
function tms_currency_js(): array {
    $code = tms_active_currency();
    $meta = TMS_CURRENCIES[$code];
    $rate = 1.0;
    if ($code !== 'BDT') {
        global $tmsCache;
        $fx = tms_get_fx_rates($tmsCache instanceof CacheService ? $tmsCache : null);
        if ($fx['ok'] && !empty($fx['rates'][$code])) {
            $rate = (float)$fx['rates'][$code];
        } else {
            $code = 'BDT';
            $meta = TMS_CURRENCIES['BDT'];
        }
    }
    return ['code' => $code, 'symbol' => $meta['symbol'], 'decimals' => (int)$meta['decimals'], 'rate' => $rate];
}

/**
 * NEW (Round 20, spec item 53 — SEO): echoes a canonical URL, OpenGraph
 * tags, and an optional JSON-LD structured-data block, from whatever real
 * data the caller has on hand — nothing here is invented if a field is
 * missing (an empty/absent value is simply omitted from the output, never
 * filled with a placeholder). Call once inside a page's own <head>, after
 * its <title> tag; every page here builds its own <head> individually
 * (there is no single shared head include), so this is opt-in per page
 * rather than sitewide by default — only wired into the highest-SEO-value
 * page types for now (destination listing, package details, blog posts),
 * per the spec's own named examples. Extending it to the rest of the ~60
 * page files is the same one-line pattern, just not done yet everywhere.
 *
 * @param array $o {
 *   string $title        Required. Plain text page title (no site suffix).
 *   string $description  Optional. Plain text description/summary.
 *   string $image        Optional. Absolute or site-relative image URL.
 *   string $type         Optional. og:type, default 'website'.
 *   string $canonical    Optional. Absolute canonical URL; defaults to the
 *                        current request's scheme+host+path (query string
 *                        stripped, so filtered/paginated views canonicalize
 *                        to their base page rather than each combination of
 *                        filters counting as a separate page).
 *   array  $jsonld       Optional. A pre-built schema.org array to emit as
 *                        JSON-LD. Only pass fields backed by real data.
 * }
 */
function tms_seo_meta(array $o): void {
    $title = trim($o['title'] ?? '');
    if ($title === '') return;

    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $canonical = $o['canonical'] ?? ($scheme . '://' . $host . $path);
    $description = trim($o['description'] ?? '');
    $image     = trim($o['image'] ?? '');
    $type      = $o['type'] ?? 'website';

    echo '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:site_name" content="TMS">' . "\n";
    echo '<meta property="og:type" content="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<meta property="og:url" content="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    if ($description !== '') {
        echo '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        echo '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    if ($image !== '') {
        // Social crawlers don't run from this domain, so a site-relative
        // path (e.g. "images/1.jpg") has to be resolved to absolute or the
        // image simply won't load in the share preview.
        if (!preg_match('#^https?://#i', $image)) {
            $image = $scheme . '://' . $host . '/' . ltrim($image, '/');
        }
        echo '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    if (!empty($o['jsonld']) && is_array($o['jsonld'])) {
        echo '<script type="application/ld+json">'
            . json_encode($o['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>' . "\n";
    }

    // NEW (Round 81, master prompt item 53 -- SEO "breadcrumbs"
    // requirement): 'breadcrumbs' is an optional array of ['label','url']
    // pairs (last entry's 'url' may be omitted -- it's the current page).
    // This emits a SEPARATE BreadcrumbList JSON-LD script tag, independent
    // of whatever the caller already passed as 'jsonld' above (e.g.
    // package-details.php's Product+Review schema) -- a page having more
    // than one <script type="application/ld+json"> block is standard,
    // valid schema.org practice, so no existing call site's 'jsonld' array
    // needs to change shape. Pair with tms_render_breadcrumb_nav() for the
    // matching visible trail in the page body.
    if (!empty($o['breadcrumbs']) && is_array($o['breadcrumbs'])) {
        $items = [];
        foreach (array_values($o['breadcrumbs']) as $i => $crumb) {
            $crumbUrl = $crumb['url'] ?? $canonical;
            // schema.org expects an absolute URL per ListItem -- site-relative
            // hrefs like 'index.php' (fine for the visible <a> in
            // tms_render_breadcrumb_nav()) need resolving here the same way
            // tms_seo_meta()'s own 'image' handling above already does.
            if (!preg_match('#^https?://#i', $crumbUrl)) {
                $crumbUrl = $scheme . '://' . $host . '/' . ltrim($crumbUrl, '/');
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => (string)($crumb['label'] ?? ''),
                'item'     => $crumbUrl,
            ];
        }
        if ($items) {
            echo '<script type="application/ld+json">'
                . json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '</script>' . "\n";
        }
    }
}

/**
 * Visible breadcrumb trail matching tms_seo_meta()'s 'breadcrumbs' JSON-LD
 * (master prompt item 53). Pass the same ['label','url'] array to both --
 * this renders the accessible <nav><ol> version in the page body. The
 * last item is rendered as plain text (aria-current="page"), never a link,
 * regardless of whether a 'url' was given for it.
 *
 * @param array $items  [['label'=>'Home','url'=>'index.php'], ..., ['label'=>'Current Page']]
 */
function tms_render_breadcrumb_nav(array $items): void {
    $items = array_values($items);
    if (count($items) < 2) return; // a single crumb ("Home" alone) isn't worth showing
    echo '<nav class="tms-breadcrumb" aria-label="Breadcrumb"><ol>';
    $last = count($items) - 1;
    foreach ($items as $i => $item) {
        $label = htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<li>';
        if ($i !== $last && !empty($item['url'])) {
            echo '<a href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
        } else {
            echo '<span aria-current="page">' . $label . '</span>';
        }
        echo '</li>';
    }
    echo '</ol></nav>' . "\n";
}

/**
 * Cache-busting (master prompt item 49): appends a `?v=<mtime>` query
 * string to a site-owned CSS/JS asset path, based on that file's real
 * last-modified time on disk -- so a browser/CDN that has an old copy
 * cached fetches the new one the moment the file actually changes on
 * disk, without needing a manual version bump anywhere or an unconditional
 * cache-buster that defeats caching entirely (e.g. appending time()).
 *
 * Resolves the asset relative to the CURRENTLY EXECUTING script's own
 * directory (via $_SERVER['SCRIPT_FILENAME'], not this file's own
 * __DIR__) so the same call works correctly whether it's made from a
 * root-level page (css/modern-theme.css -> ./css/modern-theme.css) or an
 * admin-level page (css/admin-v6.css -> admin/css/admin-v6.css) -- exactly
 * how the browser itself resolves that same relative href/src.
 *
 * Deliberately scoped to this project's own, actively-edited theme
 * stylesheets (css/modern-theme.css, admin/css/admin-v6.css) rather than
 * every vendor/library asset (bootstrap, jquery, font-awesome) referenced
 * across the site -- those are pinned bundle files that essentially never
 * change in place, so cache-busting them adds no real benefit while
 * multiplying the number of places this touches for no gain (Rule 73 --
 * don't overengineer).
 *
 * @param string $relPath  Site-relative path as it already appears in the
 *                          page's own href/src, e.g. 'css/modern-theme.css'.
 * @return string           The same path with '?v=<mtime>' appended, or
 *                          unchanged if the file can't be found on disk
 *                          (fails open -- a missing asset shouldn't also
 *                          break the page's markup).
 */
function tms_asset_ver(string $relPath): string {
    $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    $full = $scriptDir . '/' . $relPath;
    $mtime = @filemtime($full);
    if ($mtime === false) {
        return $relPath;
    }
    return $relPath . '?v=' . $mtime;
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

/**
 * File Upload Security (master prompt item 45): verifies an uploaded
 * file's REAL content type via libmagic file-signature sniffing (PHP's
 * finfo extension, backed by the same "magic bytes" database `file(1)`
 * uses) -- never the client-supplied $_FILES[...]['type'], which the spec
 * explicitly calls out as untrustworthy (it's just whatever the browser
 * sent, trivially spoofable). This is what "actual file signature"
 * validation means in practice: an attacker can rename a executable/script
 * to "photo.jpg", but they can't make finfo's magic-byte scan of the
 * file's real content lie about what it actually is.
 *
 * wallet.php already did exactly this (Round ?, its own docblock) for
 * documents; this generalizes that one working pattern into a shared
 * helper instead of each of the other 5 upload endpoints (reviews.php,
 * admin/add-package.php, admin/change-image.php, admin/edit-package.php,
 * admin/add-blog.php, admin/edit-blog.php) either re-implementing it
 * slightly differently or skipping it entirely and trusting the file
 * extension alone, which is exactly the gap this closes.
 *
 * @param string $tmpPath           $_FILES[...]['tmp_name'] -- must be a
 *                                  real PHP-managed upload (checked via
 *                                  is_uploaded_file()).
 * @param array  $allowedExtensions Lowercase extensions this endpoint
 *                                  accepts, e.g. ['jpg','jpeg','png','webp'].
 * @return array{ok: bool, mime: ?string, error: ?string}
 */
function tms_validate_upload_signature(string $tmpPath, array $allowedExtensions): array {
    static $extToMimes = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
    ];

    if (!is_uploaded_file($tmpPath)) {
        // Not a file PHP itself received via a real upload -- refuse
        // outright rather than let something else feed this function an
        // arbitrary server-side path.
        return ['ok' => false, 'mime' => null, 'error' => 'Upload could not be verified.'];
    }

    $finfo = $tmpPath !== '' ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $realMime = $finfo ? finfo_file($finfo, $tmpPath) : false;
    if ($finfo) finfo_close($finfo);

    if ($realMime === false || $realMime === null || $realMime === '') {
        return ['ok' => false, 'mime' => null, 'error' => "Could not verify this file's actual content type."];
    }

    $allowedMimes = [];
    foreach ($allowedExtensions as $ext) {
        $allowedMimes = array_merge($allowedMimes, $extToMimes[strtolower($ext)] ?? []);
    }
    $allowedMimes = array_values(array_unique($allowedMimes));

    if (!in_array($realMime, $allowedMimes, true)) {
        return [
            'ok' => false,
            'mime' => $realMime,
            'error' => "This file's actual content ($realMime) doesn't match an allowed type for this upload -- "
                . "renaming a file's extension doesn't change what it really is.",
        ];
    }

    return ['ok' => true, 'mime' => $realMime, 'error' => null];
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
 * Returns the logged-in admin's role, looked up fresh from the DB each call
 * (cheap single-row lookup by primary-key-backed username) rather than
 * trusted from the session, so a role change takes effect on the admin's
 * very next page load instead of only their next login.
 *
 * Valid values (master prompt spec item 46 -- granular RBAC, Round 20):
 *   superadmin | booking_manager | package_manager | finance | support |
 *   content_manager | ai_manager | operations
 * Plus one legacy value kept working for any admin created before this
 * round: 'manager' -- broad access (same set it always had: everything
 * except the handful of pages that were already superadmin-only). Not
 * offered for NEW admins any more (see manage-admins.php) since the 8
 * roles above are strictly more specific, but an existing manager account
 * is never silently broken (Rule 2).
 */
function tms_admin_role(PDO $dbh): string {
    if (empty($_SESSION['alogin'])) return '';
    try {
        $stmt = $dbh->prepare("SELECT Role FROM admin WHERE UserName = :u LIMIT 1");
        $stmt->execute([':u' => $_SESSION['alogin']]);
        $role = $stmt->fetchColumn();
        // A NULL/empty Role means this admin predates the RBAC column
        // entirely (the original seed `admin` account has no Role value at
        // all -- see tms.sql). Falling open to 'superadmin' here is a
        // deliberate, narrow compatibility decision so that pre-existing
        // installation never silently loses its one working admin account
        // (Rule 2) -- NOT a general license to fail open below.
        return $role ?: 'superadmin';
    } catch (Exception $e) {
        // Round 97 security fix (item 44/46): a *query failure* is not the
        // same situation as "this admin predates the Role column" above --
        // it can be hit by ANY logged-in admin, including deliberately
        // low-privileged ones, on a transient DB hiccup, a lock timeout, or
        // schema drift. The previous code returned 'superadmin' here too,
        // which meant a momentary DB error silently escalated every admin
        // (not just legacy ones) to full access for that request -- a
        // fail-OPEN authorization bypass, not a resilience feature.
        // tms_require_admin() (session-only) still works fine on '' , and
        // tms_admin_require_role() degrades to a normal 403 -- so failing
        // closed here costs nothing except (correctly) refusing a
        // role-gated action when the role genuinely couldn't be verified.
        error_log('[TMS RBAC] Role lookup failed, failing closed (no elevated role granted): ' . $e->getMessage());
        return '';
    }
}

/**
 * The 8 granular admin roles from the master prompt spec (item 46), plus
 * the pre-existing 'superadmin' and legacy 'manager' values, mapped to a
 * human-readable label for admin UI (create/change-role dropdowns, role
 * badges). Single source of truth so the dropdown and the badge colors
 * can't drift from what tms_admin_can() actually enforces.
 */
function tms_admin_role_labels(): array {
    return [
        'superadmin'      => 'Super Admin',
        'booking_manager' => 'Booking Manager',
        'package_manager' => 'Package Manager',
        'finance'         => 'Finance',
        'support'         => 'Support',
        'content_manager' => 'Content Manager',
        'ai_manager'      => 'AI Manager',
        'operations'      => 'Operations',
        'manager'         => 'Manager (legacy, broad access)',
    ];
}

/**
 * Does the logged-in admin's role satisfy $allowedRoles? superadmin always
 * passes regardless of what's listed (single place this is true, so no
 * page gate can accidentally lock superadmins out by forgetting to list
 * it -- see tms_admin_require_role()). Used both to enforce a page (below)
 * and to decide whether to show a nav link for it (sidebarmenu.php), so
 * the two can never disagree.
 */
function tms_admin_can(PDO $dbh, array $allowedRoles): bool {
    $role = tms_admin_role($dbh);
    if ($role === 'superadmin') return true;
    return in_array($role, $allowedRoles, true);
}

/**
 * Gate a page to specific admin roles. Call tms_require_admin() first (or
 * rely on this calling it), then this. Shows a friendly 403 page instead of
 * a blank screen or silent redirect, since an admin clicking a link they
 * can't see (if the sidebar somehow still shows it) deserves an explanation.
 * superadmin is always implicitly allowed -- see tms_admin_can().
 */
function tms_admin_require_role(PDO $dbh, array $allowedRoles): void {
    tms_require_admin();
    if (!tms_admin_can($dbh, $allowedRoles)) {
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
           . '<p>Your admin role (' . htmlspecialchars(tms_admin_role_labels()[tms_admin_role($dbh)] ?? tms_admin_role($dbh), ENT_QUOTES, 'UTF-8') . ') doesn\'t include '
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
        // FIX (Round 66, master prompt item 44): this used to fetch
        // MAX(CreatedAt) as a string and hand it to PHP's DateTime, then
        // compare against PHP's own "now" (new DateTime()) -- correct only
        // if PHP's configured timezone and the MySQL server's clock agree.
        // Verified live on this environment that they don't (PHP's
        // date.timezone is Europe/Berlin; MySQL's SELECT NOW() returned a
        // time 4 hours ahead of PHP's), which was silently turning every
        // real lockout into one lasting hours instead of
        // TMS_LOGIN_LOCKOUT_MINS, and (found while building this round's
        // password-reset token, which hit the exact same class of bug --
        // see forgot-password.php) was the reason a freshly-issued,
        // unexpired reset token could appear already expired. Fixed by
        // computing the remaining time entirely in SQL, so both sides of
        // the comparison (MAX(CreatedAt) and NOW()) come from the same
        // MySQL clock and PHP's own timezone setting no longer matters.
        // This connection uses PDO::ATTR_EMULATE_PREPARES => false (native
        // prepares), which this MySQL driver does NOT support reusing the
        // same named placeholder twice in one query for ("Invalid
        // parameter number" -- confirmed live) -- so the interval is bound
        // twice under two different names instead, both the same value.
        $stmt = $dbh->prepare(
            "SELECT COUNT(*), CEIL(TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(CreatedAt), INTERVAL :mins1 MINUTE)) / 60)
             FROM tbl_login_attempts
             WHERE (Email = :email OR IPAddress = :ip) AND CreatedAt > DATE_SUB(NOW(), INTERVAL :mins2 MINUTE)"
        );
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':mins1', TMS_LOGIN_LOCKOUT_MINS, PDO::PARAM_INT);
        $stmt->bindValue(':mins2', TMS_LOGIN_LOCKOUT_MINS, PDO::PARAM_INT);
        $stmt->execute();
        [$count, $minsLeft] = $stmt->fetch(PDO::FETCH_NUM);

        if ((int)$count < TMS_LOGIN_MAX_ATTEMPTS || $minsLeft === null) {
            return 0;
        }
        return $minsLeft > 0 ? max(1, (int)$minsLeft) : 0;
    } catch (Exception $e) {
        error_log('[TMS Login] Lockout check failed: ' . $e->getMessage());
        return 0; // fail open — never lock people out due to an infra error
    }
}

function tms_record_failed_login(PDO $dbh, string $email, string $ip): void {
    try {
        $dbh->prepare("INSERT INTO tbl_login_attempts(Email, IPAddress) VALUES(:e, :ip)")
            ->execute([':e' => $email, ':ip' => $ip]);

        // Opportunistic cleanup: rows older than a day are useless forever
        // (the lockout window is 15 minutes -- see TMS_LOGIN_LOCKOUT_MINS
        // above), and nothing else ever deletes a failed-attempt row that
        // never led to a successful login (tms_clear_login_attempts only
        // clears the matching email/IP). Without this, tbl_login_attempts
        // grows without bound under sustained failed-login traffic. A random
        // 2% chance per call keeps this from adding a DELETE to every single
        // failed login while still bounding growth over time -- a full cron
        // job for a single housekeeping DELETE would be overengineering for
        // what this is (Rule 73).
        if (random_int(1, 100) <= 2) {
            $dbh->exec("DELETE FROM tbl_login_attempts WHERE CreatedAt < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
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
// SITE CHATBOT IDENTITY & HISTORY
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Persistent chat-widget identity: deliberately a long-lived COOKIE, not the PHP
 * session. The session dies on logout/expiry, and tying chat history to it was
 * exactly why the conversation disappeared after a refresh or a logout. A cookie
 * survives both, so the same browser keeps its history whether the visitor is
 * signed in, signed out, or mid-conversation. Signed-in turns are additionally
 * tagged with the user's email (see tbl_site_chat_messages) for admin lookups —
 * retrieval is always by this cookie, never by login state.
 *
 * Must be called before output is sent; the chat API calls it before echoing.
 */
function tms_chat_uid(): string {
    if (!empty($_COOKIE['tms_chat_uid']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['tms_chat_uid'])) {
        return $_COOKIE['tms_chat_uid'];
    }
    $uid = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        setcookie('tms_chat_uid', $uid, [
            'expires'  => time() + 400 * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
    }
    $_COOKIE['tms_chat_uid'] = $uid; // usable immediately on this same request
    return $uid;
}

/**
 * Read the recent turns for one chat_uid, oldest-first, ready to hand to the
 * model. Bounded by design: history is written by an unauthenticated cookie, so
 * it must never be able to grow the prompt (or the table) without limit.
 */
function tms_chat_history(PDO $dbh, string $chatUid, int $limit = 12): array {
    try {
        $st = $dbh->prepare(
            "SELECT role, message FROM tbl_site_chat_messages
             WHERE chat_uid = :uid ORDER BY id DESC LIMIT :lim"
        );
        $st->bindValue(':uid', $chatUid, PDO::PARAM_STR);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return array_map(fn($r) => ['role' => $r['role'], 'content' => (string)$r['message']], $rows);
    } catch (Throwable $e) {
        error_log('[TMS Chat] history read failed: ' . $e->getMessage());
        return [];
    }
}

/** Append one turn, then keep this visitor's history to a sane size. */
function tms_chat_save(PDO $dbh, string $chatUid, string $role, string $message, ?string $email = null): void {
    try {
        $st = $dbh->prepare(
            "INSERT INTO tbl_site_chat_messages (chat_uid, user_email, role, message)
             VALUES (:uid, :em, :role, :msg)"
        );
        $st->execute([
            ':uid'  => $chatUid,
            ':em'   => ($email && $email !== 'guest') ? $email : null,
            ':role' => $role === 'assistant' ? 'assistant' : 'user',
            ':msg'  => mb_substr($message, 0, 8000),
        ]);
        // Per-visitor cap: keep the newest 50 turns for this cookie.
        $dbh->prepare(
            "DELETE FROM tbl_site_chat_messages
             WHERE chat_uid = :uid AND id <= (
                 SELECT keep_id FROM (
                     SELECT MIN(id) AS keep_id FROM (
                         SELECT id FROM tbl_site_chat_messages
                         WHERE chat_uid = :uid2 ORDER BY id DESC LIMIT 50
                     ) recent
                 ) bound
             ) AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM tbl_site_chat_messages
                     WHERE chat_uid = :uid3 ORDER BY id DESC LIMIT 50
                 ) keeprows
             )"
        )->execute([':uid' => $chatUid, ':uid2' => $chatUid, ':uid3' => $chatUid]);
    } catch (Throwable $e) {
        error_log('[TMS Chat] history write failed: ' . $e->getMessage());
    }
}

/**
 * Global housekeeping for the write-heavy chat/AI log tables. None of these were
 * ever pruned, so every one of them grew without bound. Runs at most once an
 * hour (guarded by a cache flag) so it costs nothing on a normal request.
 */
function tms_prune_ai_logs(PDO $dbh): void {
    global $tmsCache;
    if (isset($tmsCache) && $tmsCache->get('ai_log_prune') !== null) return;
    if (isset($tmsCache)) $tmsCache->set('ai_log_prune', 1, 3600);
    $jobs = [
        "DELETE FROM tbl_site_chat_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        "DELETE FROM tbl_ai_usage_log       WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
        "DELETE FROM tbl_explore_chat       WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        "DELETE FROM tbl_copilot_sessions   WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
    ];
    foreach ($jobs as $sql) {
        try { $dbh->exec($sql); } catch (Throwable $e) { /* table may not exist yet */ }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// WEATHER — real live weather + 5-day forecast, DB-cached, provider-abstracted
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/weather/WeatherProviderInterface.php';
require_once __DIR__ . '/weather/OpenWeatherMapProvider.php';
require_once __DIR__ . '/weather/OpenMeteoProvider.php';

/**
 * Fetch live weather + forecast for a lat/lng. Returns an array with keys:
 * ok(bool), city, temp, feels_like, humidity, wind_speed, condition, icon,
 * forecast(array of daily summaries), error(string, only if !ok).
 * Never throws — always returns a usable array so the page can render gracefully.
 *
 * PROVIDER ABSTRACTION (Round 89, master prompt items 41/71): this function
 * used to hardcode OpenWeatherMap's exact request/response shape inline,
 * with the Open-Meteo fallback as a second hardcoded code path in the same
 * function -- exactly what this project's own AIProviderInterface /
 * PaymentProviderInterface pattern (backend/ai/providers,
 * backend/payments) already exists to avoid elsewhere. This function now
 * owns only what does NOT vary by provider -- coordinate validation, the
 * shared cache, negative-caching on failure, and system-health telemetry --
 * and delegates the actual fetch to whichever WeatherProviderInterface
 * implementation is configured (see backend/weather/). Behavior (cache
 * keys/TTLs, provider priority/fallback order, returned field shape) is
 * unchanged from before this refactor.
 */
function tms_get_weather(PDO $dbh, float $lat, float $lng, string $label = ''): array {
    // FIX (Round 14): this was `if (!$lat && !$lng)` — it required BOTH to be
    // zero, so a package with only one coordinate set (or a bad value) was sent
    // to the weather API as a real point and the reading was rendered as that
    // destination's live weather. Now either missing coordinate, or an
    // out-of-range one, is reported honestly.
    if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
        return ['ok' => false, 'error' => 'no_coords'];
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return ['ok' => false, 'error' => 'bad_coords'];
    }

    $cacheKey = 'owm_' . round($lat, 3) . '_' . round($lng, 3);

    // Try cache first (Phase 1d: shared CacheService instead of this
    // function's own tblweathercache table — see includes/CacheService.php)
    global $tmsCache;
    if (isset($tmsCache)) {
        $cachedData = $tmsCache->get($cacheKey);
        if (is_array($cachedData)) return $cachedData;
    }

    // Provider priority: OpenWeatherMap first if configured (richer data --
    // "feels like", named city), Open-Meteo always included as the free,
    // no-signup fallback so real live weather still works out of the box
    // with zero configuration (this is the same behavior the old inline
    // "if empty(OPENWEATHER_API_KEY)" branch used to give -- just expressed
    // as a provider list instead of a hardcoded if/else).
    $providers = [];
    $owm = new OpenWeatherMapProvider();
    if ($owm->isConfigured()) { $providers[] = $owm; }
    $providers[] = new OpenMeteoProvider();

    $result = ['ok' => false, 'error' => 'fetch_failed'];
    foreach ($providers as $provider) {
        $attempt = $provider->fetch($lat, $lng, $label);

        // MONITORING (Round 72, master prompt item 56): a real success/
        // latency signal for the "Weather" line on admin/system-health.php.
        // Only logged on an actual live fetch attempt, never a cache hit
        // (the early `return $cachedData` above never reaches here) --
        // matches how AI telemetry's own health-page query already filters
        // to `cached = 0` so a cache hit can't flatter the numbers.
        tms_log_system_event($dbh, 'weather', $provider->name(), (bool)$attempt['ok'],
            $attempt['ok'] ? null : ($attempt['error'] ?? 'unknown error'));

        if ($attempt['ok']) {
            $result = $attempt;
            break; // success -- don't fall through to the next provider
        }
        // else: fall through and try the next provider, exactly like the
        // old inline code falling from the OWM branch to
        // tms_get_weather_openmeteo() on any failure.
    }

    if ($result['ok']) {
        if (isset($tmsCache)) {
            $tmsCache->set($cacheKey, $result, WEATHER_CACHE_TTL);
        }
    } else {
        // FIX (Round 14): failures used to be left uncached, so during an
        // outage EVERY page load re-attempted every provider — up to 6s
        // each, and compare.php looped it over 3 packages (up to 36s),
        // blowing max_execution_time and replacing the whole page with the
        // generic fatal-error screen. A short negative TTL keeps the site
        // responsive while every provider is down.
        if (isset($tmsCache)) {
            $tmsCache->set($cacheKey, $result, 120);
        }
    }

    return $result;
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
    $res  = curl_exec($ch);
    $err  = curl_errno($ch);
    $emsg = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== 0 || $res === false) {
        error_log('[TMS HTTP] request failed: ' . $emsg . ' — ' . parse_url($url, PHP_URL_HOST));
        return null;
    }

    // FIX (Round 14): this used to return the body regardless of status, so an
    // OpenWeatherMap 401 (bad key) or 429 (quota exceeded) looked like success,
    // json_decode gave an array with no 'main', and the code silently fell back
    // to Open-Meteo forever — no user message, no log line. A deployer who typos
    // their key had no way to find out.
    if ($code >= 400) {
        $host = parse_url($url, PHP_URL_HOST);
        if ($code === 401 || $code === 403) {
            error_log("[TMS HTTP] {$host} rejected the API key (HTTP {$code}). Check the matching key in .env.");
        } elseif ($code === 429) {
            error_log("[TMS HTTP] {$host} rate limit / quota exceeded (HTTP 429).");
        } else {
            error_log("[TMS HTTP] {$host} returned HTTP {$code}.");
        }
        return null;
    }
    return $res;
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
    // FIX (Round 14): tms_currency() calls this for EVERY price it formats when
    // the visitor picked a non-BDT currency, and only successes were cached. With
    // the FX API unreachable that meant one 6-second cURL call per price on the
    // page — a package list would hang for a minute or hit max_execution_time.
    // A per-request memo plus a short negative TTL fixes both.
    static $memo = null;
    if ($memo !== null) return $memo;

    $cacheKey = 'fx:bdt_rates';
    if ($tmsCache) {
        $cached = $tmsCache->get($cacheKey);
        if ($cached !== null) { $memo = $cached; return $memo; }
    }
    $raw = _tms_http_get('https://open.er-api.com/v6/latest/BDT');
    if ($raw === null) {
        $memo = ['ok' => false];
        if ($tmsCache) $tmsCache->set($cacheKey, $memo, 300);
        return $memo;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['result'] ?? '') !== 'success' || empty($data['rates'])) {
        $memo = ['ok' => false];
        if ($tmsCache) $tmsCache->set($cacheKey, $memo, 300);
        return $memo;
    }
    $result = [
        'ok'         => true,
        'base'       => 'BDT',
        'rates'      => $data['rates'],
        'updated'    => $data['time_last_update_utc'] ?? null,
    ];
    if ($tmsCache) $tmsCache->set($cacheKey, $result, 12 * 3600);
    $memo = $result;
    return $result;
}

/**
 * Round 115 continuation (master prompt item 37 -- Currency): captures
 * the FX conversion actually shown to the customer at the moment a
 * booking is created, when they had a non-BDT display currency selected
 * (tms_active_currency()) -- source, rate, and timestamp, exactly as
 * item 37 requires, persisted per booking rather than only ever used
 * live for display (tms_currency() converts at whatever rate is live
 * when a page is VIEWED, which drifts over time -- this is a fixed
 * historical record of what the customer actually saw when they paid).
 * Every price is still stored/charged in BDT (tblbooking.TotalCost,
 * unchanged) -- this is purely an additional, informational record for
 * their invoice and for support to reference later, never a second
 * settlement currency. Returns all-null fields when the active currency
 * is BDT (nothing to convert) or the FX feed is unavailable -- a
 * third-party API being down must never block a booking.
 */
function tms_capture_booking_fx(float $bdtAmount): array {
    $null = ['currency' => null, 'rate' => null, 'converted_amount' => null, 'source' => null, 'rate_timestamp' => null];
    $code = tms_active_currency();
    if ($code === 'BDT') { return $null; }

    global $tmsCache;
    $fx = tms_get_fx_rates($tmsCache instanceof CacheService ? $tmsCache : null);
    if (!$fx['ok'] || empty($fx['rates'][$code])) { return $null; }

    $rate = (float)$fx['rates'][$code];
    return [
        'currency'         => $code,
        'rate'             => $rate,
        'converted_amount' => round($bdtAmount * $rate, 2),
        'source'           => 'open.er-api.com',
        'rate_timestamp'   => $fx['updated'] ?? date('Y-m-d H:i:s'),
    ];
}

/**
 * tms_booking_fx_columns_exist() -- defensive guard (Rule 8): migration 013
 * adds tblbooking.DisplayCurrency/DisplayAmount/FxRate/FxRateSource/
 * FxRateTimestamp, but a deployment may not have run
 * `php database/migrate.php` yet at the moment this code first goes live.
 * Every INSERT/SELECT that touches those columns checks this first and
 * degrades gracefully (booking still succeeds in BDT, ticket still
 * displays, just without the FX snapshot) instead of throwing a fatal
 * PDOException ("Unknown column") that would otherwise break checkout
 * entirely until the migration runs. Result is cached per-request.
 */
function tms_booking_fx_columns_exist(PDO $dbh): bool {
    static $exists = null;
    if ($exists === null) {
        try {
            $dbh->query('SELECT DisplayCurrency, DisplayAmount, FxRate, FxRateSource, FxRateTimestamp FROM tblbooking LIMIT 0');
            $exists = true;
        } catch (Throwable $e) {
            $exists = false;
        }
    }
    return $exists;
}

/**
 * tms_customer_2fa_columns_exist() -- same defensive guard (Rule 8) for
 * migration 014 (tblusers.TwoFASecret/TwoFAEnabled). Until that migration
 * runs, customer login silently behaves exactly as it did before this
 * feature existed, instead of every login attempt throwing a fatal
 * PDOException. Result is cached per-request.
 */
function tms_customer_2fa_columns_exist(PDO $dbh): bool {
    static $exists = null;
    if ($exists === null) {
        try {
            $dbh->query('SELECT TwoFASecret, TwoFAEnabled FROM tblusers LIMIT 0');
            $exists = true;
        } catch (Throwable $e) {
            $exists = false;
        }
    }
    return $exists;
}

/**
 * Draft Terms of Service and Privacy Policy, written from what this application
 * really does. Not legal advice: the owner must review and edit them (Admin >
 * Pages). Cancellation tiers are deliberately not stated here, so there is one
 * source of truth: the policy shown at checkout.
 */
function tms_default_policy_pages(): array {
    $terms = '<h4>1. About these terms</h4>'
        . '<p>These terms apply when you browse this site, create an account or make a booking. By ticking the box at checkout you confirm you have read them.</p>'
        . '<h4>2. Bookings</h4>'
        . '<p>A booking starts as <strong>pending</strong>. It is only confirmed once our team confirms it, and you will see the status change in your account. Prices are shown before you confirm and may change until a booking is confirmed.</p>'
        . '<h4>3. Payments</h4>'
        . '<p>Payments are handled by the payment methods offered at checkout. A payment is only recorded once the payment provider confirms it to us. If a payment does not complete, your booking stays pending.</p>'
        . '<h4>4. Cancellations and refunds</h4>'
        . '<p>The cancellation and refund policy that applies to your booking is shown on the checkout page before you confirm. That policy is part of these terms.</p>'
        . '<h4>5. Your account</h4>'
        . '<p>Keep your password private and give accurate details. You can export or delete your data from the privacy settings in your account.</p>'
        . '<h4>6. AI assistant and trip tools</h4>'
        . '<p>Our assistant, itinerary planner and recommendations are automated and can be wrong. Treat them as suggestions: check prices, availability, opening times, visa and safety information with us or the official source before you rely on them.</p>'
        . '<h4>7. Changes</h4>'
        . '<p>We may update these terms. The version on this page is the current one.</p>'
        . '<h4>8. Contact</h4>'
        . '<p>Questions? Use the contact page.</p>';
    $privacy = '<h4>What we collect</h4>'
        . '<p>Your name, email address and password (stored only as a hash), an optional mobile number, your bookings, reviews, wishlist and enquiries, and any documents you choose to upload to your Travel Wallet (stored encrypted). We also keep technical logs needed to run and secure the site.</p>'
        . '<h4>How we use it</h4>'
        . '<p>To run your account and bookings, send booking and security emails, prevent fraud and abuse, and improve the site. We do not send marketing email.</p>'
        . '<h4>AI features</h4>'
        . '<p>When you use the assistant, planner or recommendations, the text you type and relevant site data are processed by the AI service this site is configured to use. If that service is hosted by us, the text stays on our systems. If it is a third-party service, your text is sent to that provider. Do not enter passwords, payment details or other secrets in AI chats. You can delete your AI history in your privacy settings.</p>'
        . '<h4>Sharing</h4>'
        . '<p>We share data only with providers needed to deliver the service (for example payment and email providers), or where the law requires it. We do not sell personal data.</p>'
        . '<h4>Your choices</h4>'
        . '<p>Signed-in customers can export their data, delete AI history, change notification and personalisation settings and delete their account from the privacy settings page. Deleting an account anonymises it; records we must keep for accounting may be retained without identifying details.</p>'
        . '<h4>Contact</h4>'
        . '<p>Use the contact page for any privacy question.</p>';
    return ['terms' => $terms, 'privacy' => $privacy];
}

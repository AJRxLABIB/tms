<?php
/**
 * health.php — internal health check (Rule 57).
 *
 * Deliberately does NOT include backend/config.php directly, because
 * config.php calls exit() on a DB connection failure (existing, working
 * behavior for every normal page -- not changed here, Rule 2) which would
 * make it impossible for this endpoint to ever report "database: down"
 * instead of just dying itself. Instead this does its own minimal,
 * independent checks first.
 *
 * Reports UP/DOWN per dependency and configured/not-configured for each
 * external API key -- never the key values themselves (Rule 57: never
 * expose credentials or sensitive infra details). Returns HTTP 200 if the
 * database (the only hard dependency every page needs) is up, 503
 * otherwise, so this is safe to point an uptime monitor at.
 */

declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/env-loader.php';
if (!function_exists('tms_env')) {
    // env-loader.php only defines tms_load_dotenv(); tms_env() itself lives
    // in config.php. Redefine the tiny read-only accessor here rather than
    // pulling in all of config.php (which would risk the exit()-on-DB-down
    // behavior described above).
    function tms_env(string $key, $default = null) {
        $value = getenv($key);
        if ($value === false || $value === '') return $default;
        if (strtolower($value) === 'true') return true;
        if (strtolower($value) === 'false') return false;
        return $value;
    }
}
tms_load_dotenv(dirname(__DIR__) . '/.env');

$status = ['status' => 'ok', 'timestamp' => date('c'), 'checks' => []];
$httpCode = 200;

// ── Database ────────────────────────────────────────────────────────────
$dbStart = microtime(true);
try {
    $dbh = new PDO(
        'mysql:host=' . tms_env('DB_HOST', 'localhost') . ';dbname=' . tms_env('DB_NAME', 'tms') . ';charset=utf8mb4',
        tms_env('DB_USER', 'root'),
        tms_env('DB_PASS', ''),
        [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $dbh->query('SELECT 1');
    $status['checks']['database'] = ['status' => 'up', 'latency_ms' => round((microtime(true) - $dbStart) * 1000)];
} catch (Throwable $e) {
    $status['checks']['database'] = ['status' => 'down'];
    $status['status'] = 'down';
    $httpCode = 503;
    error_log('[TMS Health] Database check failed: ' . $e->getMessage());
}

// ── External services: report configured/not-configured only, never make
//    a real paid API call from a health check and never echo the key. ────
$apiChecks = [
    'ai_provider'      => 'GEMINI_API_KEY',
    'weather_provider' => 'OPENWEATHER_API_KEY',
    'maps_provider'    => 'GOOGLE_MAPS_API_KEY',
];
foreach ($apiChecks as $label => $envKey) {
    $configured = tms_env($envKey, '') !== '';
    $status['checks'][$label] = ['status' => $configured ? 'configured' : 'not_configured'];
}

// ── Payment provider (sandbox is always "up" since it makes no external call) ──
$status['checks']['payment_provider'] = [
    'status' => 'up',
    'mode' => tms_env('PAYMENT_PROVIDER', 'sandbox'),
];

// Public callers (uptime monitors, anyone on the internet) get only up/down. The per-service breakdown, latency and
// payment mode are shown only with the shared secret HEALTH_TOKEN (sent in the X-Health-Token header, never in a URL).
$healthToken = (string)tms_env('HEALTH_TOKEN', '');
$given       = (string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');
$detailed    = $healthToken !== '' && strlen($healthToken) >= 16 && $given !== '' && hash_equals($healthToken, $given);
if (!$detailed) {
    $status = ['status' => $status['status']];
}

header('Cache-Control: no-store');
http_response_code($httpCode);
echo json_encode($status, JSON_PRETTY_PRINT);

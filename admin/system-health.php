<?php
/**
 * admin/system-health.php — master prompt item 56 ("MONITORING"): a single
 * consolidated admin view of Database / AI / Weather / Maps / Payments /
 * Email / External-API health, error rate and latency.
 *
 * This is deliberately a THIN AGGREGATOR, not new infrastructure: AI
 * telemetry (success rate, latency, token usage) already lives in
 * tbl_ai_telemetry with its own detailed view at ai-dashboard.php; payment
 * failures/pending already live in tblpayments with their own detailed
 * view at operations-dashboard.php. This page re-runs the same summary
 * queries those pages already use (cheap, indexed, last-7-days) and links
 * out to each for detail, rather than duplicating their logic or their
 * tables. The two genuinely new signals here — email send outcomes and
 * booking-checkout failures — come from tbl_system_events
 * (backend/system-events.php), added this round specifically because
 * neither had ANY admin-visible tracking before (both only ever reached
 * the PHP error log). Weather and Maps/Explore now have the same kind of
 * real per-call tracking: tms_get_weather() (backend/config.php) -- which,
 * as of Round 89, delegates the actual fetch to whichever
 * WeatherProviderInterface implementation is configured, see
 * backend/weather/ -- and ExploreService::httpRequest() both call
 * tms_log_system_event() on every attempt, so the Weather/Maps cards below
 * are genuine success/failure counts from tbl_system_events, not a
 * configured/not-configured guess. Other External-API providers without
 * that instrumentation still fall back to the safe, credential-free
 * configured/not-configured check from health.php — reported honestly as
 * "not tracked" rather than invented.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('../backend/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['operations', 'manager']); // same roles as operations-dashboard.php

// ── Database: live check, same idea as health.php but against the
//    connection this page already has (no second connection needed). ──────
$dbStart = microtime(true);
try {
    $dbh->query('SELECT 1');
    $dbStatus = 'up';
    $dbLatencyMs = round((microtime(true) - $dbStart) * 1000);
} catch (Exception $e) {
    $dbStatus = 'down';
    $dbLatencyMs = null;
}

// ── AI: same summary query as admin/ai-dashboard.php (last 7 days,
//    non-cached calls only, so a cache hit doesn't flatter the latency). ──
try {
    $stmt = $dbh->prepare(
        "SELECT COUNT(*) AS calls, SUM(success) AS successes, ROUND(AVG(latency_ms)) AS avg_latency_ms
         FROM tbl_ai_telemetry WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND cached = 0"
    );
    $stmt->execute();
    $aiSummary = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
} catch (Exception $e) { $aiSummary = null; }
$aiCalls       = $aiSummary ? (int)$aiSummary->calls : 0;
$aiSuccessRate = $aiCalls > 0 ? round(100 * (int)$aiSummary->successes / $aiCalls) : null;
$aiAvgLatency  = $aiSummary && $aiSummary->avg_latency_ms !== null ? (int)$aiSummary->avg_latency_ms : null;

// ── Payments: same counts as operations-dashboard.php ──────────────────────
try {
    $paymentFailures = (int)$dbh->query("SELECT COUNT(*) FROM tblpayments WHERE Status = 'failed'")->fetchColumn();
    $pendingPayments = (int)$dbh->query("SELECT COUNT(*) FROM tblpayments WHERE Status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    $paymentFailures = null; $pendingPayments = null;
}

// ── Email: new, from tbl_system_events (last 7 days) ────────────────────────
try {
    $stmt = $dbh->prepare(
        "SELECT COUNT(*) AS total, SUM(success) AS successes
         FROM tbl_system_events WHERE category = 'email' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $stmt->execute();
    $emailSummary = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
} catch (Exception $e) { $emailSummary = null; }
$emailTotal    = $emailSummary ? (int)$emailSummary->total : 0;
$emailFailures = $emailSummary ? $emailTotal - (int)$emailSummary->successes : 0;

// ── Booking failures: new, from tbl_system_events (last 7 days) ────────────
try {
    $bookingFailures = (int)$dbh->query(
        "SELECT COUNT(*) FROM tbl_system_events WHERE category = 'booking' AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();
} catch (Exception $e) { $bookingFailures = 0; }

// ── Weather / Maps: new (Round 72), from tbl_system_events (last 7 days).
//    Previously reported below as "not implemented yet" -- now real,
//    logged at every live fetch in tms_get_weather() (backend/config.php,
//    which as of Round 89 delegates to a WeatherProviderInterface --
//    backend/weather/) and ExploreService::httpRequest(). ────────────────
try {
    $stmt = $dbh->prepare(
        "SELECT COUNT(*) AS total, SUM(success) AS successes
         FROM tbl_system_events WHERE category = 'weather' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $stmt->execute();
    $weatherSummary = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
} catch (Exception $e) { $weatherSummary = null; }
$weatherTotal    = $weatherSummary ? (int)$weatherSummary->total : 0;
$weatherFailures = $weatherSummary ? $weatherTotal - (int)$weatherSummary->successes : 0;

try {
    $stmt = $dbh->prepare(
        "SELECT COUNT(*) AS total, SUM(success) AS successes
         FROM tbl_system_events WHERE category = 'maps' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $stmt->execute();
    $mapsSummary = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
} catch (Exception $e) { $mapsSummary = null; }
$mapsTotal    = $mapsSummary ? (int)$mapsSummary->total : 0;
$mapsFailures = $mapsSummary ? $mapsTotal - (int)$mapsSummary->successes : 0;

// ── Recent events feed (both categories, most recent 15) — the raw detail
//    behind the two new summary numbers above. ─────────────────────────────
try {
    $recentEvents = $dbh->query(
        "SELECT category, event_type, success, message, created_at
         FROM tbl_system_events ORDER BY id DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) { $recentEvents = []; }

// ── Weather/Maps/External APIs: configured/not-configured only (Rule 57:
//    never make a real paid call just to check a health page, never expose
//    the key) — same approach as health.php, just reported alongside
//    everything else here instead of a separate JSON endpoint. ─────────────
$providerChecks = [
    'Weather (OpenWeatherMap)' => tms_env('OPENWEATHER_API_KEY', '') !== '',
    'Maps (Google Maps)'       => tms_env('GOOGLE_MAPS_API_KEY', '') !== '',
    // What the app really uses right now (auto = local Ollama, else a valid Gemini key, else none). Details: ai-setup.php.
    'AI provider'              => (function () use ($dbh) {
                                       try { require_once __DIR__ . '/../backend/ai/AIClient.php'; return AIClient::resolution($dbh)['provider'] !== 'none'; }
                                       catch (Throwable $e) { return false; }
                                   })(),
    'SMTP (Email)'             => tms_env('SMTP_HOST', '') !== '',
];

$pageTitle = 'System Health';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS Admin | System Health</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
    <h2><i class="fa fa-heartbeat"></i> System Health</h2>
    <p style="color:var(--text-muted);">Master prompt item 56 (Monitoring) — Database / AI / Weather / Maps / Payments / Email at a
        glance. AI usage detail lives on <a href="ai-dashboard.php">AI Usage Dashboard</a>; payment/booking business
        metrics live on <a href="operations-dashboard.php">Operations</a> — this page is the cross-system summary.</p>

    <div class="ops-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $dbStatus==='up'?'#047857':'#C0392B'; ?>;">
          <?php echo $dbStatus==='up' ? 'Up' : 'Down'; ?>
        </div>
        <div style="color:var(--text-muted);">Database<?php echo $dbLatencyMs!==null ? ' · '.$dbLatencyMs.'ms' : ''; ?></div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $aiSuccessRate===null?'var(--text-primary)':($aiSuccessRate>=90?'#047857':'#B45309'); ?>;">
          <?php echo $aiSuccessRate!==null ? $aiSuccessRate.'%' : 'n/a'; ?>
        </div>
        <div style="color:var(--text-muted);">AI success rate (7d, <?php echo $aiCalls; ?> calls<?php echo $aiAvgLatency!==null?', '.$aiAvgLatency.'ms avg':''; ?>)</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo ($paymentFailures ?? 0)>0?'#C0392B':'var(--text-primary)'; ?>;"><?php echo $paymentFailures ?? 'n/a'; ?></div>
        <div style="color:var(--text-muted);">Payment failures (all-time)</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $emailFailures>0?'#B45309':'var(--text-primary)'; ?>;"><?php echo $emailFailures; ?></div>
        <div style="color:var(--text-muted);">Email failures (7d, of <?php echo $emailTotal; ?> sent)</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $bookingFailures>0?'#B45309':'var(--text-primary)'; ?>;"><?php echo $bookingFailures; ?></div>
        <div style="color:var(--text-muted);">Booking checkout failures (7d)</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $weatherFailures>0?'#B45309':'var(--text-primary)'; ?>;"><?php echo $weatherFailures; ?></div>
        <div style="color:var(--text-muted);">Weather fetch failures (7d, of <?php echo $weatherTotal; ?>)</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $mapsFailures>0?'#B45309':'var(--text-primary)'; ?>;"><?php echo $mapsFailures; ?></div>
        <div style="color:var(--text-muted);">Maps/Explore fetch failures (7d, of <?php echo $mapsTotal; ?>)</div>
      </div>
    </div>

    <?php
    // Inventory synchronisation health (additive panel). Table comes from migration 023; absent = "not available".
    require_once __DIR__ . '/../backend/services/InventoryService.php';
    $invSync = null; $invHolds = null;
    try {
        $invSync = InventoryService::syncHealth($dbh);
        $invHolds = (int)$dbh->query("SELECT COUNT(*) FROM tbl_inventory_holds WHERE Status = 'active' AND ExpiresAt < NOW()")->fetchColumn();
    } catch (Throwable $e) { $invSync = null; }
    ?>
    <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px;">
      <h4 style="margin:0 0 10px;font-weight:700;">Inventory synchronisation</h4>
      <?php if ($invSync === null): ?>
        <div style="color:var(--text-muted);">Not available (apply migration 023 with <code>php database/migrate.php</code>).</div>
      <?php elseif (!$invSync): ?>
        <div style="color:var(--text-muted);">No synchronisation runs recorded. Inventory is maintained manually or not tracked.</div>
      <?php else: ?>
        <table class="table table-condensed" style="margin:0;">
          <thead><tr><th>Source</th><th>State</th><th>Detail</th><th>Seen / changed</th><th>Started</th></tr></thead>
          <tbody>
          <?php foreach ($invSync as $s): $h = $s['health']; $bad = $h['stale'] || $h['state'] === 'failed'; ?>
            <tr>
              <td><?php echo htmlspecialchars($s['source'], ENT_QUOTES, 'UTF-8'); ?><?php echo $s['entity_type'] ? ' <small>(' . htmlspecialchars((string)$s['entity_type'], ENT_QUOTES, 'UTF-8') . ')</small>' : ''; ?></td>
              <td style="font-weight:700;color:<?php echo $bad ? '#B45309' : '#047857'; ?>;"><?php echo $bad ? 'Needs attention' : 'Healthy'; ?> · <?php echo htmlspecialchars($h['state'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($h['message'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo (int)$s['items_seen']; ?> / <?php echo (int)$s['items_changed']; ?></td>
              <td><?php echo htmlspecialchars($s['started_at'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <?php if ($invHolds): ?>
        <div style="margin-top:10px;color:#B45309;font-weight:600;"><?php echo (int)$invHolds; ?> inventory hold(s) have lapsed and not been released: run <code>php scripts/expire_inventory_holds.php --apply</code> (schedule it every 5 minutes).</div>
      <?php endif; ?>
    </div>

    <?php
    // Setup warnings: only things this page can verify without a paid call.
    $setupWarnings = [];
    $wk = (string)tms_env('WALLET_ENCRYPTION_KEY', '');
    if ($wk === '') {
        $setupWarnings[] = 'WALLET_ENCRYPTION_KEY is not set in .env, so Travel Wallet uploads are refused. Generate one with: php -r "echo bin2hex(random_bytes(32));"';
    } elseif (!preg_match('/^[0-9a-fA-F]{64}$/', $wk)) {
        $setupWarnings[] = 'WALLET_ENCRYPTION_KEY must be exactly 64 hex characters; Travel Wallet uploads are refused until it is fixed.';
    }
    try {
        $seedFile = __DIR__ . '/../data/packages_seed.json';
        $seedNames = [];
        if (is_file($seedFile)) {
            foreach ((array)json_decode((string)file_get_contents($seedFile), true) as $r) { if (!empty($r['PackageName'])) $seedNames[$r['PackageName']] = true; }
        }
        $sampleCount = 0;
        foreach ($dbh->query("SELECT PackageName FROM tbltourpackages")->fetchAll(PDO::FETCH_COLUMN) as $n) { if (isset($seedNames[$n])) $sampleCount++; }
        if ($sampleCount > 0) {
            $setupWarnings[] = $sampleCount . ' tour package(s) are generated sample data from data/packages_seed.json (their ratings were randomly generated). Preview and remove them with: php tools/remove-sample-data.php  (add --apply to delete those with no bookings).';
        }
    } catch (Exception $e) {}
    ?>
    <?php if ($setupWarnings): ?>
    <div role="alert" style="border-left:4px solid #B45309;background:var(--card-bg,#fff);color:var(--text-primary,#17231e);padding:12px 16px;border-radius:8px;margin:0 0 20px;">
      <strong>Setup needs attention</strong>
      <ul style="margin:8px 0 0 18px;padding:0;">
        <?php foreach ($setupWarnings as $w): ?><li style="margin-bottom:4px;"><?php echo htmlspecialchars($w, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <h4>External providers</h4>
    <p style="color:var(--text-muted);font-size:13px;margin-top:-8px;">Configured/not-configured only — this project never makes a
        real paid API call just to check a health page, and never displays key values (Rule 57).</p>
    <table class="table table-striped">
      <thead><tr><th>Provider</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($providerChecks as $label => $configured): ?>
        <tr>
          <td><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></td>
          <td><span style="color:<?php echo $configured?'#047857':'#5F6D65'; ?>;">
            <i class="fa fa-<?php echo $configured?'check-circle':'minus-circle'; ?>"></i>
            <?php echo $configured?'Configured':'Not configured'; ?>
          </span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="color:var(--text-muted);font-size:12px;">Maps (Leaflet/OpenStreetMap tiles) needs no API key and has no
        configured/not-configured state to check — it's always available. Per-call error-rate tracking for Weather
        and Maps/Explore now exists too (Round 72, master prompt item 56) — see the two new stat cards above, sourced
        from the same <code>tbl_system_events</code> log as the Email/Booking cards, and the recent-events feed below.</p>

    <h4>Recent system events</h4>
    <?php if (empty($recentEvents)): ?>
    <p style="color:var(--text-muted);padding:20px 0;"><i class="fa fa-info-circle"></i> No email or booking-failure events recorded yet.</p>
    <?php else: ?>
    <table class="table table-striped">
      <thead><tr><th>When</th><th>Category</th><th>Event</th><th>Result</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($recentEvents as $ev): ?>
        <tr>
          <td><?php echo htmlspecialchars($ev->created_at, ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars(ucfirst($ev->category), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars($ev->event_type, ENT_QUOTES, 'UTF-8'); ?></td>
          <td><span style="color:<?php echo $ev->success?'#047857':'#C0392B'; ?>;"><?php echo $ev->success?'OK':'Failed'; ?></span></td>
          <td style="font-size:12px;color:var(--text-muted);"><?php echo htmlspecialchars($ev->message ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>
</body>
</html>

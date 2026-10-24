<?php
/**
 * admin/operations-dashboard.php — Rule 55: live operational visibility,
 * separate from the historical/analytics dashboard (admin/dashboard.php).
 * "What needs attention right now" rather than "how are we doing overall."
 */
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['operations', 'manager']); // Round 20 RBAC (item 46)

$today = date('Y-m-d');

$arrivals = $dbh->prepare(
    "SELECT b.BookingId, b.UserEmail, p.PackageName, b.NoOfPersons
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId
     WHERE b.FromDate = :today AND b.status = 1 ORDER BY b.BookingId"
);
$arrivals->execute([':today' => $today]);
$arrivals = $arrivals->fetchAll();

$departures = $dbh->prepare(
    "SELECT b.BookingId, b.UserEmail, p.PackageName, b.NoOfPersons
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId
     WHERE b.ToDate = :today AND b.status = 1 ORDER BY b.BookingId"
);
$departures->execute([':today' => $today]);
$departures = $departures->fetchAll();

$pendingBookings = (int)$dbh->query("SELECT COUNT(*) FROM tblbooking WHERE status = 0")->fetchColumn();

// PaymentStatus/tblpayments are self-healing (see includes/config.php) but
// may not exist yet on the instant this deploys somewhere new -- fail soft
// rather than take the whole operations dashboard down over one widget.
try {
    $paymentFailures = (int)$dbh->query("SELECT COUNT(*) FROM tblpayments WHERE Status = 'failed'")->fetchColumn();
    $pendingPayments = (int)$dbh->query("SELECT COUNT(*) FROM tblpayments WHERE Status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    $paymentFailures = null; $pendingPayments = null;
}

$refundRequests = (int)$dbh->query("SELECT COUNT(*) FROM tblbooking WHERE RefundStatus = 'requested'")->fetchColumn();

$openTickets = (int)$dbh->query("SELECT COUNT(*) FROM tblissues WHERE status = 0")->fetchColumn();

$lowInventory = $dbh->query(
    "SELECT PackageId, PackageName, SeatsAvailable,
            SeatsAvailable - COALESCE((SELECT SUM(NoOfPersons) FROM tblbooking b WHERE b.PackageId = p.PackageId AND b.status IN (0,1)), 0) AS Remaining
     FROM tbltourpackages p
     WHERE SeatsAvailable IS NOT NULL
     HAVING Remaining <= 3
     ORDER BY Remaining ASC"
)->fetchAll();

// WEATHER ALERTS (Round 88, master prompt item 55): live weather for every
// destination with a confirmed booking arriving in the next N days, using
// this platform's existing real weather integration (tms_get_weather() --
// OpenWeatherMap if OPENWEATHER_API_KEY is set, otherwise the free
// Open-Meteo fallback -- see includes/config.php/backend/config.php; DB-
// cached, so this costs nothing extra on repeat dashboard loads). This was
// the one genuinely missing piece of this page -- arrivals/departures/
// pending/payment-failures/refunds/tickets/low-inventory above were already
// real and live.
//
// "Flight disruptions" (also listed in master prompt item 55) is
// deliberately NOT implemented: this platform has no flight-booking model
// anywhere in the schema (no tblflightbooking-style table, no flight
// numbers/carriers on tblbooking) -- frontend/flight-status.php is a
// standalone, unrelated lookup tool a visitor can use for any flight
// number, not tied to any booking here. A "flight disruptions" widget would
// have nothing real to show, so per Rule 7 (no fake placeholder features)
// this is disclosed here in the UI itself rather than fabricated or
// silently dropped.
$weatherWindowDays = 7;
$weatherUntil = date('Y-m-d', strtotime("+{$weatherWindowDays} days"));
$upcomingDestinations = $dbh->prepare(
    "SELECT p.PackageId, p.PackageName, p.PackageLocation, p.MapLat, p.MapLng,
            MIN(b.FromDate) AS NextArrival, COUNT(*) AS BookingCount, SUM(b.NoOfPersons) AS TravelerCount
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.status = 1 AND b.FromDate BETWEEN :today AND :until
       AND p.MapLat IS NOT NULL AND p.MapLng IS NOT NULL
     GROUP BY p.PackageId, p.PackageName, p.PackageLocation, p.MapLat, p.MapLng
     ORDER BY NextArrival ASC"
);
$upcomingDestinations->execute([':today' => $today, ':until' => $weatherUntil]);
$upcomingDestinations = $upcomingDestinations->fetchAll(PDO::FETCH_OBJ);

/**
 * Keyword match over a weather condition/description string. Works across
 * both providers this platform uses: OpenWeatherMap's `condition` field uses
 * short categories (Thunderstorm, Snow, Squall, Tornado, Extreme...) and
 * Open-Meteo's provider (backend/weather/OpenMeteoProvider.php, as of
 * Round 89) returns descriptive phrases (e.g. "Heavy rain", "Thunderstorm
 * with hail", "Violent rain showers").
 */
function tms_ops_weather_is_severe(string $text): bool {
    static $needles = ['thunderstorm','tornado','hurricane','squall','extreme',
        'heavy rain','heavy snow','violent','hail','blizzard','freezing rain'];
    $text = strtolower($text);
    foreach ($needles as $n) { if (strpos($text, $n) !== false) return true; }
    return false;
}

$weatherAlerts = [];
$weatherCheckFailed = 0;
foreach ($upcomingDestinations as $dest) {
    $w = tms_get_weather($dbh, (float)$dest->MapLat, (float)$dest->MapLng, $dest->PackageLocation);
    if (!$w['ok']) { $weatherCheckFailed++; continue; }

    $hits = [];
    if (tms_ops_weather_is_severe(($w['condition'] ?? '') . ' ' . ($w['description'] ?? ''))) {
        $hits[] = 'Now: ' . ($w['description'] ?: $w['condition']);
    }
    foreach (($w['forecast'] ?? []) as $day) {
        if (empty($day['date']) || $day['date'] > $weatherUntil) continue; // only what's within the booking window
        if (tms_ops_weather_is_severe($day['condition'] ?? '')) {
            $hits[] = ($day['day_label'] ?? $day['date']) . ' (' . $day['date'] . '): ' . $day['condition'];
        }
    }
    if (!empty($hits)) {
        $weatherAlerts[] = [
            'package'      => $dest->PackageName,
            'location'     => $dest->PackageLocation,
            'next_arrival' => $dest->NextArrival,
            'travelers'    => (int)$dest->TravelerCount,
            'hits'         => $hits,
            'source'       => $w['source'] ?? 'unknown',
        ];
    }
}

// ───────────────────────── Action panels (read-only) ─────────────────────────
// Each panel is independent: a missing table or column shows "not available" for that panel only. Nothing here
// changes data; each row links to the page where the action is taken (with its own permission check).
function tms_ops_panel(callable $fn, int $limit = 10): array {
    try { return ['ok' => true, 'rows' => $fn($limit)]; }
    catch (Throwable $e) { error_log('[TourBuddy ops dashboard] panel failed: ' . $e->getMessage()); return ['ok' => false, 'rows' => []]; }
}
function tms_ops_age(?string $ts): string {
    if (!$ts) return '-';
    $s = time() - (int)strtotime($ts);
    if ($s < 90) return 'just now';
    if ($s < 5400) return (int)round($s / 60) . ' min ago';
    if ($s < 129600) return (int)round($s / 3600) . ' h ago';
    return (int)round($s / 86400) . ' d ago';
}

$panelOldPending = tms_ops_panel(function (int $n) use ($dbh) {
    return $dbh->query(
        "SELECT b.BookingId, b.UserEmail, p.PackageName, COALESCE(b.BookingDate, b.RegDate) AS ts, b.PaymentStatus
         FROM tblbooking b LEFT JOIN tbltourpackages p ON p.PackageId = b.PackageId
         WHERE b.status = 0 AND COALESCE(b.BookingDate, b.RegDate) < DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY ts ASC LIMIT " . (int)$n
    )->fetchAll(PDO::FETCH_ASSOC);
});
$panelPayPending = tms_ops_panel(function (int $n) use ($dbh) {
    return $dbh->query(
        "SELECT p.PaymentId, p.BookingId, p.Provider, p.ProviderMode, p.AmountMinor, p.Currency, p.CreatedAt AS ts
         FROM tblpayments p WHERE p.Status = 'pending' AND p.CreatedAt < DATE_SUB(NOW(), INTERVAL 60 MINUTE)
         ORDER BY p.CreatedAt ASC LIMIT " . (int)$n
    )->fetchAll(PDO::FETCH_ASSOC);
});
$panelRefunds = tms_ops_panel(function (int $n) use ($dbh) {
    $rows = $dbh->query(
        "SELECT b.BookingId, b.UserEmail, b.TotalCost, b.UpdationDate AS ts, 'booking refund requested' AS kind
         FROM tblbooking b WHERE b.RefundStatus = 'requested' ORDER BY b.UpdationDate ASC LIMIT " . (int)$n
    )->fetchAll(PDO::FETCH_ASSOC);
    try {
        $more = $dbh->query(
            "SELECT p.BookingId, '' AS UserEmail, r.AmountMinor / 100 AS TotalCost, r.CreatedAt AS ts, 'payment refund pending' AS kind
             FROM tblpayment_refunds r JOIN tblpayments p ON p.PaymentId = r.PaymentId
             WHERE r.Status = 'pending' ORDER BY r.CreatedAt ASC LIMIT " . (int)$n
        )->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_merge($rows, $more);
    } catch (Throwable $e) { /* refunds table not present */ }
    return $rows;
});
$panelPayEvents = tms_ops_panel(function (int $n) use ($dbh) {
    return $dbh->query(
        "SELECT EventId, Provider, EventType, ProcessingStatus, SignatureValid, RetryCount, ReceivedAt AS ts
         FROM tblpayment_events
         WHERE ProcessingStatus IN ('failed','rejected') OR (ProcessingStatus = 'received' AND ReceivedAt < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
         ORDER BY EventId DESC LIMIT " . (int)$n
    )->fetchAll(PDO::FETCH_ASSOC);
});
$panelJobs = tms_ops_panel(function (int $n) use ($dbh) {
    return $dbh->query(
        "SELECT id, event_type, status, attempts, max_attempts, last_error, created_at AS ts
         FROM tbl_outbox_events WHERE status IN ('failed','dead')
            OR (status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
         ORDER BY id DESC LIMIT " . (int)$n
    )->fetchAll(PDO::FETCH_ASSOC);
});
$jobCounts = tms_ops_panel(function (int $n) use ($dbh) {
    return $dbh->query("SELECT status, COUNT(*) AS c FROM tbl_outbox_events GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
});
$panelSecurity = tms_ops_panel(function (int $n) use ($dbh) {
    $rows = [];
    foreach ($dbh->query(
        "SELECT Actor, Action, Details, CreatedAt AS ts FROM tblactivity_log
         WHERE Action LIKE '%login%' OR Action LIKE '%2FA%' OR Action LIKE '%password%' OR Action LIKE '%role%' OR Action LIKE '%admin%' OR Action LIKE '%locked%' OR Action LIKE '%denied%'
         ORDER BY id DESC LIMIT " . (int)$n)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = ['source' => 'activity log', 'what' => $r['Action'], 'detail' => trim(($r['Actor'] ? $r['Actor'] . ': ' : '') . mb_substr((string)$r['Details'], 0, 120)), 'ts' => $r['ts'], 'sev' => 'info'];
    }
    try {
        foreach ($dbh->query(
            "SELECT category, event_type, message, created_at AS ts FROM tbl_system_events WHERE success = 0 ORDER BY id DESC LIMIT " . (int)$n)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['source' => 'system event', 'what' => $r['category'] . ' / ' . $r['event_type'], 'detail' => (string)$r['message'], 'ts' => $r['ts'], 'sev' => 'warning'];
        }
    } catch (Throwable $e) { /* table absent */ }
    usort($rows, fn($a, $b) => strcmp((string)$b['ts'], (string)$a['ts']));
    return array_slice($rows, 0, $n);
});
$aiHealth = [];
try {
    require_once __DIR__ . '/../backend/ai/ProviderHealth.php';
    foreach (['gemini', 'openai', 'ollama'] as $prov) {
        $st = ProviderHealth::state($dbh, $prov);
        $aiHealth[$prov] = $st + ['open' => $st['open_until'] > time()];
    }
} catch (Throwable $e) { $aiHealth = []; }
$aiActive = defined('AI_PROVIDER') ? (string)AI_PROVIDER : '';

$opsEsc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$pageTitle = 'Operations Dashboard';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy Admin | Operations Dashboard</title>
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
    <h2><i class="fa fa-dashboard"></i> Operations Dashboard</h2>
    <p style="color:var(--text-muted);">What needs attention right now — see <a href="dashboard.php">Dashboard</a> for historical analytics.</p>

    <div class="ops-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;"><?php echo count($arrivals); ?></div>
        <div style="color:var(--text-muted);">Arrivals today</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;"><?php echo count($departures); ?></div>
        <div style="color:var(--text-muted);">Departures today</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $pendingBookings>0?'#B45309':'var(--text-primary)'; ?>;"><?php echo $pendingBookings; ?></div>
        <div style="color:var(--text-muted);">Pending bookings</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo ($paymentFailures ?? 0)>0?'#C0392B':'var(--text-primary)'; ?>;"><?php echo $paymentFailures ?? 'n/a'; ?></div>
        <div style="color:var(--text-muted);">Payment failures</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;"><?php echo $pendingPayments ?? 'n/a'; ?></div>
        <div style="color:var(--text-muted);">Payments pending</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $refundRequests>0?'#B45309':'var(--text-primary)'; ?>;"><?php echo $refundRequests; ?></div>
        <div style="color:var(--text-muted);">Refund requests</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $openTickets>0?'#B45309':'var(--text-primary)'; ?>;"><?php echo $openTickets; ?></div>
        <div style="color:var(--text-muted);">Open support tickets</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo count($lowInventory)>0?'#C0392B':'var(--text-primary)'; ?>;"><?php echo count($lowInventory); ?></div>
        <div style="color:var(--text-muted);">Low-inventory packages</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo count($weatherAlerts)>0?'#C0392B':'var(--text-primary)'; ?>;"><?php echo count($weatherAlerts); ?></div>
        <div style="color:var(--text-muted);">Weather alerts (next <?php echo (int)$weatherWindowDays; ?>d)</div>
      </div>
    </div>

    <?php if (!empty($lowInventory)): ?>
    <h4>Low inventory (3 seats or fewer remaining)</h4>
    <table class="table table-striped">
      <thead><tr><th>Package</th><th>Remaining</th></tr></thead>
      <tbody>
        <?php foreach ($lowInventory as $p): ?>
        <tr><td><?php echo htmlspecialchars($p->PackageName, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo max(0,(int)$p->Remaining); ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($arrivals)): ?>
    <h4>Arriving today</h4>
    <table class="table table-striped">
      <thead><tr><th>Booking</th><th>Package</th><th>Traveler</th><th>Persons</th></tr></thead>
      <tbody>
        <?php foreach ($arrivals as $b): ?>
        <tr><td>#BK<?php echo (int)$b->BookingId; ?></td><td><?php echo htmlspecialchars($b->PackageName, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($b->UserEmail, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$b->NoOfPersons; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php
    // Small renderer: heading, "not available" fallback, "all clear" message, or a table.
    $opsPanel = function (string $icon, string $title, array $panel, array $cols, callable $row, string $clear) use ($opsEsc) {
        echo '<h4 style="margin-top:28px;"><i class="fa fa-' . $opsEsc($icon) . '" style="color:var(--text-muted);"></i> ' . $opsEsc($title) . '</h4>';
        if (!$panel['ok']) { echo '<p style="color:var(--text-muted);">Not available on this install right now (a table this panel reads is missing or unreadable).</p>'; return; }
        if (!$panel['rows']) { echo '<p style="color:#2e9e5b;"><i class="fa fa-check-circle"></i> ' . $opsEsc($clear) . '</p>'; return; }
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr>';
        foreach ($cols as $c) echo '<th scope="col">' . $opsEsc($c) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($panel['rows'] as $r) { echo '<tr>'; foreach ($row($r) as $cell) echo '<td>' . $cell . '</td>'; echo '</tr>'; }
        echo '</tbody></table></div>';
    };
    $sev = function (?string $ts, int $midH, int $highH) {
        $h = $ts ? (time() - (int)strtotime($ts)) / 3600 : 0;
        if ($h >= $highH) return '<span style="color:#C0392B;font-weight:700;">High</span>';
        if ($h >= $midH)  return '<span style="color:#B45309;font-weight:700;">Medium</span>';
        return '<span style="color:var(--text-muted);">Low</span>';
    };
    $opsPanel('hourglass-half', 'Pending bookings older than 24 hours (holding seats)', $panelOldPending,
        ['Severity', 'Booking', 'Package', 'Traveler', 'Payment', 'Created'],
        fn($r) => [$sev($r['ts'], 24, 72), '<a href="manage-bookings.php">#BK' . (int)$r['BookingId'] . '</a>', $opsEsc($r['PackageName']), $opsEsc($r['UserEmail']), $opsEsc($r['PaymentStatus'] ?: 'unpaid'), $opsEsc($r['ts']) . ' <small style="color:var(--text-muted);">(' . $opsEsc(tms_ops_age($r['ts'])) . ')</small>'],
        'No pending booking is older than 24 hours.');
    $opsPanel('credit-card', 'Payments pending for over 60 minutes', $panelPayPending,
        ['Severity', 'Payment', 'Booking', 'Provider', 'Amount', 'Started'],
        fn($r) => [$sev($r['ts'], 1, 24), '#' . (int)$r['PaymentId'], '<a href="manage-bookings.php">#BK' . (int)$r['BookingId'] . '</a>', $opsEsc($r['Provider'] . ' (' . $r['ProviderMode'] . ')'), $opsEsc(number_format(((int)$r['AmountMinor']) / 100, 2) . ' ' . $r['Currency']), $opsEsc($r['ts']) . ' <small style="color:var(--text-muted);">(' . $opsEsc(tms_ops_age($r['ts'])) . ')</small>'],
        'No payment has been pending for more than an hour.');
    $opsPanel('undo', 'Refunds awaiting action', $panelRefunds,
        ['Severity', 'Booking', 'Type', 'Traveler', 'Amount (BDT)', 'Waiting since'],
        fn($r) => [$sev($r['ts'], 48, 120), '<a href="manage-bookings.php">#BK' . (int)$r['BookingId'] . '</a>', $opsEsc($r['kind']), $opsEsc($r['UserEmail'] ?: '-'), $opsEsc($r['TotalCost'] !== null ? number_format((float)$r['TotalCost'], 2) : '-'), $opsEsc($r['ts']) . ' <small style="color:var(--text-muted);">(' . $opsEsc(tms_ops_age($r['ts'])) . ')</small>'],
        'No refund is waiting for action.');
    $opsPanel('exchange', 'Failed, rejected or stuck payment webhook events', $panelPayEvents,
        ['Severity', 'Event', 'Provider', 'Type', 'Status', 'Signature', 'Retries', 'Received'],
        fn($r) => [$r['ProcessingStatus'] === 'received' ? '<span style="color:#B45309;font-weight:700;">Medium</span>' : '<span style="color:#C0392B;font-weight:700;">High</span>', '#' . (int)$r['EventId'], $opsEsc($r['Provider']), $opsEsc($r['EventType']), $opsEsc($r['ProcessingStatus']), $r['SignatureValid'] ? 'valid' : 'not verified', (int)$r['RetryCount'], $opsEsc($r['ts']) . ' <small style="color:var(--text-muted);">(' . $opsEsc(tms_ops_age($r['ts'])) . ')</small>'],
        'No failed or stuck webhook events.');
    $jobsTotals = $jobCounts['ok'] ? $jobCounts['rows'] : [];
    $opsPanel('cogs', 'Background jobs: failed, dead or stuck' . ($jobCounts['ok'] ? ' (queue: ' . (int)($jobsTotals['pending'] ?? 0) . ' pending, ' . (int)($jobsTotals['done'] ?? 0) . ' done)' : ''), $panelJobs,
        ['Severity', 'Job', 'Type', 'Status', 'Attempts', 'Last error', 'Created'],
        fn($r) => [$r['status'] === 'dead' ? '<span style="color:#C0392B;font-weight:700;">High</span>' : '<span style="color:#B45309;font-weight:700;">Medium</span>', '#' . (int)$r['id'], $opsEsc($r['event_type']), $opsEsc($r['status']), (int)$r['attempts'] . '/' . (int)$r['max_attempts'], $opsEsc(mb_substr((string)$r['last_error'], 0, 120)), $opsEsc($r['ts']) . ' <small style="color:var(--text-muted);">(' . $opsEsc(tms_ops_age($r['ts'])) . ')</small>'],
        'No failed or stuck background jobs.');
    ?>

    <h4 style="margin-top:28px;"><i class="fa fa-magic" style="color:var(--text-muted);"></i> AI runtime health <small style="font-weight:400;color:var(--text-muted);">— configured provider: <strong><?php echo $opsEsc($aiActive ?: 'unknown'); ?></strong>. Booking and payments work without AI; see <a href="ai-dashboard.php">AI Usage</a> and <a href="ai-safety.php">AI Safety</a>.</small></h4>
    <?php if (!$aiHealth): ?>
      <p style="color:var(--text-muted);">Not available on this install right now.</p>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-striped"><thead><tr><th scope="col">Provider</th><th scope="col">Circuit breaker</th><th scope="col">Consecutive failures</th><th scope="col">Last failure type</th></tr></thead><tbody>
      <?php foreach ($aiHealth as $name => $st): ?>
        <tr>
          <td><?php echo $opsEsc($name); ?><?php echo $name === $aiActive ? ' <small>(active)</small>' : ''; ?></td>
          <td><?php echo $st['open'] ? '<span style="color:#C0392B;font-weight:700;">Open until ' . $opsEsc(date('H:i:s', $st['open_until'])) . '</span>' : '<span style="color:#2e9e5b;">Closed</span>'; ?></td>
          <td><?php echo (int)$st['fails']; ?></td>
          <td><?php echo $opsEsc($st['last'] ?: '-'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <p style="color:var(--text-muted);font-size:13px;">The breaker only reflects recent calls this site made; a provider with no recent calls shows Closed.</p>
    <?php endif; ?>

    <?php
    $opsPanel('shield', 'Recent security and admin-account events', $panelSecurity,
        ['Severity', 'Source', 'Event', 'Detail', 'When'],
        fn($r) => [$r['sev'] === 'warning' ? '<span style="color:#B45309;font-weight:700;">Medium</span>' : '<span style="color:var(--text-muted);">Info</span>', $opsEsc($r['source']), $opsEsc($r['what']), $opsEsc($r['detail']), $opsEsc($r['ts']) . ' <small style="color:var(--text-muted);">(' . $opsEsc(tms_ops_age($r['ts'])) . ')</small>'],
        'No recent security or admin-account events.');
    ?>
    <p style="color:var(--text-muted);font-size:13px;">Full history: <a href="activity-log.php">Activity Log</a> · <a href="system-health.php">System Health</a>. Panels here are read-only; actions are taken on the linked pages under their own permissions.</p>

    <h4><i class="fa fa-cloud" style="color:#0ea5e9;"></i> Weather alerts <small style="font-weight:400;color:var(--text-muted);">— destinations with confirmed travelers arriving in the next <?php echo (int)$weatherWindowDays; ?> days, checked against live weather</small></h4>
    <?php if (!empty($weatherAlerts)): ?>
    <table class="table table-striped">
      <thead><tr><th>Package</th><th>Destination</th><th>Next Arrival</th><th>Travelers</th><th>Alert</th><th>Source</th></tr></thead>
      <tbody>
        <?php foreach ($weatherAlerts as $wa): ?>
        <tr>
          <td><?php echo htmlspecialchars($wa['package'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars($wa['location'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars($wa['next_arrival'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo (int)$wa['travelers']; ?></td>
          <td style="color:#c0392b;font-weight:600;">
            <?php foreach ($wa['hits'] as $hit): ?>
              <div><i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($hit, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
          </td>
          <td style="color:var(--text-muted);font-size:12px;"><?php echo htmlspecialchars($wa['source'], ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php elseif (empty($upcomingDestinations)): ?>
    <p style="color:var(--text-muted);">No confirmed bookings with a destination arriving in the next <?php echo (int)$weatherWindowDays; ?> days (or none of those packages have map coordinates set yet — weather needs a location).</p>
    <?php else: ?>
    <p style="color:#2e9e5b;"><i class="fa fa-check-circle"></i> No severe weather found for any destination with travelers arriving in the next <?php echo (int)$weatherWindowDays; ?> days.<?php if ($weatherCheckFailed > 0): ?> (<?php echo (int)$weatherCheckFailed; ?> destination<?php echo $weatherCheckFailed===1?'':'s'; ?> could not be checked right now — the weather provider didn't respond.)<?php endif; ?></p>
    <?php endif; ?>

    <h4><i class="fa fa-plane" style="color:var(--text-muted);"></i> Flight disruptions</h4>
    <p style="color:var(--text-muted);">Not applicable on this platform — TourBuddy has no flight-booking model (no flight numbers, carriers, or a flight-booking table anywhere in the schema). <a href="../flight-status.php">Flight Status Lookup</a> is a standalone tool a visitor can use to check any flight, but it isn't tied to any booking here, so there is nothing real for this dashboard to monitor. Shown here rather than a fabricated widget, per this project's "no fake placeholder features" rule.</p>
</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>
</body>
</html>

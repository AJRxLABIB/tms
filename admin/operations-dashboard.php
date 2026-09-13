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

$pageTitle = 'Operations Dashboard';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS Admin | Operations Dashboard</title>
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
    <p style="color:#888;">What needs attention right now — see <a href="dashboard.php">Dashboard</a> for historical analytics.</p>

    <div class="ops-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;"><?php echo count($arrivals); ?></div>
        <div style="color:#888;">Arrivals today</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;"><?php echo count($departures); ?></div>
        <div style="color:#888;">Departures today</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $pendingBookings>0?'#e67e22':'#333'; ?>;"><?php echo $pendingBookings; ?></div>
        <div style="color:#888;">Pending bookings</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo ($paymentFailures ?? 0)>0?'#e74c3c':'#333'; ?>;"><?php echo $paymentFailures ?? 'n/a'; ?></div>
        <div style="color:#888;">Payment failures</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;"><?php echo $pendingPayments ?? 'n/a'; ?></div>
        <div style="color:#888;">Payments pending</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $refundRequests>0?'#e67e22':'#333'; ?>;"><?php echo $refundRequests; ?></div>
        <div style="color:#888;">Refund requests</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo $openTickets>0?'#e67e22':'#333'; ?>;"><?php echo $openTickets; ?></div>
        <div style="color:#888;">Open support tickets</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo count($lowInventory)>0?'#e74c3c':'#333'; ?>;"><?php echo count($lowInventory); ?></div>
        <div style="color:#888;">Low-inventory packages</div>
      </div>
      <div class="ops-card" style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <div style="font-size:28px;font-weight:800;color:<?php echo count($weatherAlerts)>0?'#e74c3c':'#333'; ?>;"><?php echo count($weatherAlerts); ?></div>
        <div style="color:#888;">Weather alerts (next <?php echo (int)$weatherWindowDays; ?>d)</div>
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

    <h4><i class="fa fa-cloud" style="color:#0ea5e9;"></i> Weather alerts <small style="font-weight:400;color:#94a3b8;">— destinations with confirmed travelers arriving in the next <?php echo (int)$weatherWindowDays; ?> days, checked against live weather</small></h4>
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
          <td style="color:#94a3b8;font-size:12px;"><?php echo htmlspecialchars($wa['source'], ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php elseif (empty($upcomingDestinations)): ?>
    <p style="color:#888;">No confirmed bookings with a destination arriving in the next <?php echo (int)$weatherWindowDays; ?> days (or none of those packages have map coordinates set yet — weather needs a location).</p>
    <?php else: ?>
    <p style="color:#2e9e5b;"><i class="fa fa-check-circle"></i> No severe weather found for any destination with travelers arriving in the next <?php echo (int)$weatherWindowDays; ?> days.<?php if ($weatherCheckFailed > 0): ?> (<?php echo (int)$weatherCheckFailed; ?> destination<?php echo $weatherCheckFailed===1?'':'s'; ?> could not be checked right now — the weather provider didn't respond.)<?php endif; ?></p>
    <?php endif; ?>

    <h4><i class="fa fa-plane" style="color:#94a3b8;"></i> Flight disruptions</h4>
    <p style="color:#888;">Not applicable on this platform — TMS has no flight-booking model (no flight numbers, carriers, or a flight-booking table anywhere in the schema). <a href="../flight-status.php">Flight Status Lookup</a> is a standalone tool a visitor can use to check any flight, but it isn't tied to any booking here, so there is nothing real for this dashboard to monitor. Shown here rather than a fabricated widget, per this project's "no fake placeholder features" rule.</p>
</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>
</body>
</html>

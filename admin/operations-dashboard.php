<?php
/**
 * admin/operations-dashboard.php — Rule 55: live operational visibility,
 * separate from the historical/analytics dashboard (admin/dashboard.php).
 * "What needs attention right now" rather than "how are we doing overall."
 */
require('includes/config.php');
tms_require_admin();

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
<link href="css/admin-v6.css" rel="stylesheet">
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
</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>
</body>
</html>

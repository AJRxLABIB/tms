<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
if(!strlen($_SESSION['alogin'])){ header('location:index.php'); exit; }

// Stats - wrapped in try/catch so a missing table won't crash the page
function safe_count(PDO $db, string $sql): int {
    try { return (int)$db->query($sql)->fetchColumn(); } catch(Exception $e) { return 0; }
}
$cnt     = safe_count($dbh, "SELECT COUNT(*) FROM tblusers");
$cnt1    = safe_count($dbh, "SELECT COUNT(*) FROM tblbooking");
$cnt2    = safe_count($dbh, "SELECT COUNT(*) FROM tblenquiry");
$cnt3    = safe_count($dbh, "SELECT COUNT(*) FROM tbltourpackages");
$cnt5    = safe_count($dbh, "SELECT COUNT(*) FROM tblissues");
$rev     = safe_count($dbh, "SELECT COALESCE(SUM(p.PackagePrice),0) FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId WHERE b.status=1");
$pending = safe_count($dbh, "SELECT COUNT(*) FROM tblbooking WHERE status=0");
// ACCESSIBILITY FIX (Round 68, master prompt item 32): the booking-status
// donut below (#statusChart) had no accessible name at all -- a screen
// reader announced it as a bare, unlabeled <canvas>, and its three figures
// (confirmed/pending/cancelled) were otherwise only ever rendered INSIDE
// the canvas by Chart.js, so there was no text anywhere on the page a
// screen reader could fall back to (WCAG 1.1.1). Computed here, server-side,
// so the label is accurate even if Chart.js's own CDN script never loads
// (see statusChartUnavailable below) or JS is disabled entirely.
$confirmedCount = safe_count($dbh, "SELECT COUNT(*) FROM tblbooking WHERE status=1");
$cancelledCount = safe_count($dbh, "SELECT COUNT(*) FROM tblbooking WHERE status=2");

// Recent bookings
try {
    $recentQ = $dbh->query("SELECT b.BookingId, b.UserEmail, p.PackageName, b.RegDate, b.status
        FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId
        ORDER BY b.BookingId DESC LIMIT 6");
    $recent = $recentQ->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) { $recent = []; }

$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet"></noscript>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1>Dashboard</h1>
      <p>Welcome back, <?= htmlentities($_SESSION['alogin']) ?>. Here's what's happening today.</p>
    </div>
    <a href="revenue-analytics.php" class="btn-v6 primary">
      <i class="fa fa-bar-chart"></i> Full Analytics
    </a>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <a href="manage-users.php" class="stat-card" style="text-decoration:none;">
      <div class="stat-card-top">
        <div class="stat-icon blue"><i class="fa fa-users"></i></div>
        <span class="stat-trend neutral">All time</span>
      </div>
      <div>
        <div class="stat-card-value"><?= number_format($cnt) ?></div>
        <div class="stat-card-label">Registered Users</div>
      </div>
    </a>
    <a href="manage-bookings.php" class="stat-card" style="text-decoration:none;">
      <div class="stat-card-top">
        <div class="stat-icon green"><i class="fa fa-calendar-check-o"></i></div>
        <span class="stat-trend up">+<?= $cnt1 ?></span>
      </div>
      <div>
        <div class="stat-card-value"><?= number_format($cnt1) ?></div>
        <div class="stat-card-label">Total Bookings</div>
        <div class="stat-card-sub"><?= $pending ?> pending review</div>
      </div>
    </a>
    <a href="revenue-analytics.php" class="stat-card" style="text-decoration:none;">
      <div class="stat-card-top">
        <div class="stat-icon purple"><i class="fa fa-money-bill-wave"></i></div>
        <span class="stat-trend up">Confirmed</span>
      </div>
      <div>
        <div class="stat-card-value"><?= tms_currency((float)$rev) ?></div>
        <div class="stat-card-label">Total Revenue</div>
      </div>
    </a>
    <a href="manage-enquiries.php" class="stat-card" style="text-decoration:none;">
      <div class="stat-card-top">
        <div class="stat-icon amber"><i class="fa fa-envelope"></i></div>
        <span class="stat-trend neutral">Inbox</span>
      </div>
      <div>
        <div class="stat-card-value"><?= number_format($cnt2) ?></div>
        <div class="stat-card-label">Enquiries</div>
      </div>
    </a>
    <a href="manage-packages.php" class="stat-card" style="text-decoration:none;">
      <div class="stat-card-top">
        <div class="stat-icon green"><i class="fa fa-briefcase"></i></div>
        <span class="stat-trend neutral">Active</span>
      </div>
      <div>
        <div class="stat-card-value"><?= number_format($cnt3) ?></div>
        <div class="stat-card-label">Tour Packages</div>
      </div>
    </a>
    <a href="manage-issues.php" class="stat-card" style="text-decoration:none;">
      <div class="stat-card-top">
        <div class="stat-icon red"><i class="fa fa-exclamation-triangle"></i></div>
        <span class="stat-trend <?= $cnt5>0?'danger':'neutral' ?>"><?= $cnt5>0?'Open':'Clear' ?></span>
      </div>
      <div>
        <div class="stat-card-value"><?= number_format($cnt5) ?></div>
        <div class="stat-card-label">Support Issues</div>
      </div>
    </a>
  </div>

  <!-- RESPONSIVE FIX (Round 54, master prompt item 61): this two-column
       layout was an inline style (grid-template-columns:1fr 320px), which
       a CSS media query can't override. At narrow widths the fixed 320px
       side column alone ate the whole viewport, leaving the 1fr column
       effectively 0/negative width and forcing the page to overflow
       (found at 320px: the Recent Bookings table's column computed to
       1.6px wide while the table inside stayed at its natural ~590px,
       overflowing the page). Moved to a class (admin-v6.css) so the
       existing @media(max-width:768px) block can stack it to one column
       on phones/tablets, matching how the rest of this page already
       collapses there. -->
  <div class="tms-dashboard-grid">
    <!-- Recent Bookings Table -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-clock-o" style="color:#1d4ed8;margin-right:8px;"></i> Recent Bookings</h3>
        <a href="manage-bookings.php" class="btn-v6 ghost sm">View all</a>
      </div>
      <div style="overflow-x:auto;">
        <div class="table-responsive"><table class="tms-table">
          <thead>
            <tr>
              <th>#ID</th>
              <th>User</th>
              <th>Package</th>
              <th>Date</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php if(count($recent)===0): ?>
            <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:32px;">No bookings yet.</td></tr>
          <?php else: ?>
          <?php foreach($recent as $b): ?>
          <tr>
            <td><span style="font-weight:600;color:#1d4ed8;">#BK<?= str_pad($b->BookingId,3,'0',STR_PAD_LEFT) ?></span></td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlentities($b->UserEmail) ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlentities($b->PackageName) ?></td>
            <td style="color:#64748b;"><?= date('d M Y', strtotime($b->RegDate)) ?></td>
            <td>
              <?php if($b->status==1): ?>
                <span class="badge-pill success">Confirmed</span>
              <?php elseif($b->status==2): ?>
                <span class="badge-pill danger">Cancelled</span>
              <?php else: ?>
                <span class="badge-pill warning">Pending</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header">
          <h3><i class="fa fa-bolt" style="color:#d97706;margin-right:8px;"></i> Quick Actions</h3>
        </div>
        <div class="card-body" style="padding:16px;display:flex;flex-direction:column;gap:8px;">
          <a href="add-package.php" class="btn-v6 primary" style="justify-content:center;">
            <i class="fa fa-plus"></i> Add New Package
          </a>
          <a href="manage-bookings.php" class="btn-v6 ghost" style="justify-content:center;">
            <i class="fa fa-calendar"></i> View Bookings
          </a>
          <a href="manage-coupons.php" class="btn-v6 ghost" style="justify-content:center;">
            <i class="fa fa-tag"></i> Create Coupon
          </a>
          <a href="export-report.php" class="btn-v6 ghost" style="justify-content:center;">
            <i class="fa fa-download"></i> Export Report
          </a>
          <a href="add-blog.php" class="btn-v6 ghost" style="justify-content:center;">
            <i class="fa fa-pencil"></i> Write Blog Post
          </a>
        </div>
      </div>

      <!-- Booking status donut -->
      <div class="card">
        <div class="card-header"><h3><i class="fa fa-pie-chart" style="color:#7c3aed;margin-right:8px;"></i> Booking Status</h3></h3></div>
        <div class="card-body" style="padding:16px;">
          <canvas id="statusChart" height="180" style="display:none;" role="img" aria-label="Booking status: <?= $confirmedCount ?> confirmed, <?= $pending ?> pending, <?= $cancelledCount ?> cancelled"></canvas>
          <div id="statusChartEmpty" style="text-align:center;padding:40px;color:#94a3b8;display:none;">
            <i class="fa fa-pie-chart" style="font-size:32px;display:block;margin-bottom:10px;"></i>
            No bookings yet.
          </div>
          <div id="statusChartUnavailable" style="text-align:center;padding:40px;color:#94a3b8;display:none;">
            <i class="fa fa-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:10px;"></i>
            Chart couldn't load (offline or blocked script). Booking status is still shown in the summary cards above.
          </div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
var confirmed = <?= (int)$dbh->query("SELECT COUNT(*) FROM tblbooking WHERE status=1")->fetchColumn() ?>;
var pending2  = <?= (int)$dbh->query("SELECT COUNT(*) FROM tblbooking WHERE status=0")->fetchColumn() ?>;
var cancelled = <?= (int)$dbh->query("SELECT COUNT(*) FROM tblbooking WHERE status=2")->fetchColumn() ?>;

// Master prompt item 62 (error/empty/loading states): this used to call
// `new Chart(...)` unconditionally. Chart.js loads from a CDN
// (cdn.jsdelivr.net, see the <script src> above) with no local fallback --
// if that request is blocked (ad-blocker, offline, corporate proxy) the
// global `Chart` is simply undefined and the old code threw a silent,
// console-only ReferenceError, leaving the canvas a blank rectangle with
// no explanation. There was also no distinct "no bookings yet" state: on a
// brand-new install with zero bookings the donut just rendered as an empty
// ring, which reads as broken rather than "nothing here yet".
if (typeof Chart === 'undefined') {
  document.getElementById('statusChartUnavailable').style.display = 'block';
} else if (confirmed === 0 && pending2 === 0 && cancelled === 0) {
  document.getElementById('statusChartEmpty').style.display = 'block';
} else {
  var canvas = document.getElementById('statusChart');
  canvas.style.display = 'block';
  new Chart(canvas,{
    type:'doughnut',
    data:{
      labels:['Confirmed','Pending','Cancelled'],
      datasets:[{data:[confirmed,pending2,cancelled],backgroundColor:['#059669','#d97706','#dc2626'],borderWidth:0,borderRadius:4}]
    },
    options:{responsive:true,cutout:'68%',plugins:{legend:{position:'bottom',labels:{font:{size:12},padding:12,usePointStyle:true}}}}
  });
}
</script>
</body></html>
<?php // end auth check ?>
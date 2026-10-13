<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_admin_require_role($dbh, ['superadmin', 'finance']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Revenue Analytics</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:var(--text-primary);margin:0;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-edit{background:#DCF1E5;color:#104B3A;}.btn-edit:hover{background:#B8E4C9;}
.btn-delete{background:#fee2e2;color:#991b1b;}.btn-delete:hover{background:#fecaca;}
.btn-approve{background:#d1fae5;color:#065f46;}.btn-approve:hover{background:#a7f3d0;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-bar-chart" style="color:#176B50;margin-right:10px;"></i>Revenue Analytics</h2>
</div>


<?php
function safe_q(PDO $db, string $sql, array $p=[]) {
    try { $q=$db->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_OBJ); }
    catch(Exception $e){ return []; }
}
function safe_val2(PDO $db, string $sql): string {
    try { return (string)$db->query($sql)->fetchColumn(); } catch(Exception $e){ return '0'; }
}
$totalRev    = safe_val2($dbh,"SELECT COALESCE(SUM(CASE WHEN b.TotalCost IS NOT NULL AND b.TotalCost > 0 THEN b.TotalCost ELSE p.PackagePrice END),0) FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId WHERE b.status=1");
$totalBook   = safe_val2($dbh,"SELECT COUNT(*) FROM tblbooking");
$confirmedBook = safe_val2($dbh,"SELECT COUNT(*) FROM tblbooking WHERE status=1");
$avgRev      = $confirmedBook>0 ? ($totalRev/$confirmedBook) : 0.0;
$thisMonthRev = safe_val2($dbh,"SELECT COALESCE(SUM(CASE WHEN b.TotalCost IS NOT NULL AND b.TotalCost > 0 THEN b.TotalCost ELSE p.PackagePrice END),0) FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId WHERE b.status=1 AND DATE_FORMAT(b.RegDate,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')");
$lastMonthRev = safe_val2($dbh,"SELECT COALESCE(SUM(CASE WHEN b.TotalCost IS NOT NULL AND b.TotalCost > 0 THEN b.TotalCost ELSE p.PackagePrice END),0) FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId WHERE b.status=1 AND DATE_FORMAT(b.RegDate,'%Y-%m')=DATE_FORMAT(DATE_SUB(NOW(),INTERVAL 1 MONTH),'%Y-%m')");
$momChange   = $lastMonthRev > 0 ? (($thisMonthRev - $lastMonthRev) / $lastMonthRev) * 100 : ($thisMonthRev > 0 ? 100 : 0);
$topPackages = safe_q($dbh,"SELECT p.PackageName, COUNT(b.BookingId) as cnt, SUM(CASE WHEN b.TotalCost IS NOT NULL AND b.TotalCost > 0 THEN b.TotalCost ELSE p.PackagePrice END) as revenue FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId WHERE b.status=1 GROUP BY p.PackageId ORDER BY revenue DESC LIMIT 10");
$monthlyData = safe_q($dbh,"SELECT DATE_FORMAT(b.RegDate,'%Y-%m') as month, COUNT(*) as bookings, SUM(CASE WHEN b.TotalCost IS NOT NULL AND b.TotalCost > 0 THEN b.TotalCost ELSE p.PackagePrice END) as revenue FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId WHERE b.status=1 GROUP BY month ORDER BY month DESC LIMIT 12");
$maxMonthRevenue = 0;
foreach ($monthlyData as $m) { $maxMonthRevenue = max($maxMonthRevenue, (float)$m->revenue); }
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:#176B50;"><?=tms_currency($totalRev)?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Total Revenue</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:var(--text-primary);"><?=$totalBook?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Total Bookings</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:#065f46;"><?=$confirmedBook?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Confirmed</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:#1F8A66;">৳ <?=number_format($avgRev,2)?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Avg. Per Booking</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:var(--text-primary);"><?=tms_currency((float)$thisMonthRev)?></div>
    <div style="font-size:12px;color:<?= $momChange >= 0 ? '#0B6B2E' : '#B91C1C' ?>;margin-top:4px;">
      <i class="fa fa-arrow-<?= $momChange >= 0 ? 'up' : 'down' ?>"></i> <?= number_format(abs($momChange),1) ?>% vs last month
    </div>
  </div>
</div>

<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:20px;">
  <h3 style="font-size:15px;font-weight:700;margin-bottom:20px;"><i class="fa fa-bar-chart" style="color:#176B50;margin-right:8px;"></i>Revenue by Month</h3>
  <?php if (empty($monthlyData)): ?>
  <div style="text-align:center;color:var(--text-muted);padding:20px;">No data yet.</div>
  <?php else: ?>
  <div style="display:flex;align-items:flex-end;gap:10px;height:180px;padding-top:10px;">
    <?php foreach (array_reverse($monthlyData) as $m):
      $barHeight = $maxMonthRevenue > 0 ? max(4, round(((float)$m->revenue / $maxMonthRevenue) * 150)) : 4;
    ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;">
      <div style="font-size:10.5px;color:var(--text-muted);font-weight:600;">৳<?= number_format($m->revenue/1000,1) ?>k</div>
      <div style="width:100%;max-width:38px;background:linear-gradient(180deg,#1F8A66,#176B50);border-radius:6px 6px 0 0;height:<?= $barHeight ?>px;" title="<?= htmlspecialchars($m->month) ?>: <?= tms_currency((float)$m->revenue) ?>"></div>
      <div style="font-size:10px;color:var(--text-muted);"><?= date('M', strtotime($m->month.'-01')) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fa fa-trophy" style="color:#f59e0b;margin-right:8px;"></i>Top Packages by Revenue</h3>
    <div class="table-responsive"><table class="tms-table" style="font-size:12px;">
      <thead><tr><th>#</th><th>Package</th><th>Bookings</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php if(empty($topPackages)): ?>
      <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px;">No data yet.</td></tr>
      <?php else: $i=1; foreach($topPackages as $tp): ?>
      <tr>
        <td><?=$i++?></td>
        <td><?=htmlspecialchars($tp->PackageName??'—')?></td>
        <td><?=$tp->cnt?></td>
        <td><strong>৳ <?=number_format($tp->revenue,2)?></strong></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fa fa-calendar" style="color:#176B50;margin-right:8px;"></i>Monthly Revenue</h3>
    <div class="table-responsive"><table class="tms-table" style="font-size:12px;">
      <thead><tr><th>Month</th><th>Bookings</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php if(empty($monthlyData)): ?>
      <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:20px;">No data yet.</td></tr>
      <?php else: foreach($monthlyData as $m): ?>
      <tr>
        <td><?=$m->month?></td>
        <td><?=$m->bookings?></td>
        <td><strong>৳ <?=number_format($m->revenue,2)?></strong></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>


<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

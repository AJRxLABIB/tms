<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_admin_require_role($dbh, ['superadmin', 'operations', 'finance']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | User Analytics</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-users" style="color:#1d4ed8;margin-right:10px;"></i>User Analytics</h2>
</div>

<?php
function safe_q(PDO $db, string $sql, array $p=[]) {
    try { $q=$db->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_OBJ); }
    catch(Exception $e){ return []; }
}
function safe_val3(PDO $db, string $sql): string {
    try { return (string)$db->query($sql)->fetchColumn(); } catch(Exception $e){ return '0'; }
}

$totalUsers   = safe_val3($dbh, "SELECT COUNT(*) FROM tblusers");
$newThisMonth = safe_val3($dbh, "SELECT COUNT(*) FROM tblusers WHERE DATE_FORMAT(RegDate,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')");
$activeUsers  = safe_val3($dbh, "SELECT COUNT(DISTINCT UserEmail) FROM tblbooking");
$avgBookings  = (int)$totalUsers > 0 ? round(((int)safe_val3($dbh, "SELECT COUNT(*) FROM tblbooking")) / max(1,(int)$totalUsers), 1) : 0;

// Signups by month, last 12 months — the "user growth" chart Phase 5 asked for.
$signupsByMonth = safe_q($dbh, "SELECT DATE_FORMAT(RegDate,'%Y-%m') as month, COUNT(*) as cnt
    FROM tblusers WHERE RegDate IS NOT NULL GROUP BY month ORDER BY month DESC LIMIT 12");
$maxSignups = 0;
foreach ($signupsByMonth as $m) { $maxSignups = max($maxSignups, (int)$m->cnt); }

// Top spenders — now meaningful, since Round 3 fixed TotalCost actually
// getting populated on booking creation. Before that fix this table would
// have shown ~0 for everyone regardless of what they'd actually booked.
$topSpenders = safe_q($dbh, "SELECT b.UserEmail, COALESCE(u.FullName, b.UserEmail) as FullName,
    COUNT(*) as bookings, COALESCE(SUM(CASE WHEN b.TotalCost IS NOT NULL AND b.TotalCost > 0 THEN b.TotalCost ELSE p.PackagePrice END),0) as spent
    FROM tblbooking b
    JOIN tbltourpackages p ON p.PackageId = b.PackageId
    LEFT JOIN tblusers u ON u.EmailId = b.UserEmail
    WHERE b.status = 1
    GROUP BY b.UserEmail ORDER BY spent DESC LIMIT 10");

$mostActive = safe_q($dbh, "SELECT b.UserEmail, COALESCE(u.FullName, b.UserEmail) as FullName, COUNT(*) as cnt
    FROM tblbooking b LEFT JOIN tblusers u ON u.EmailId = b.UserEmail
    GROUP BY b.UserEmail ORDER BY cnt DESC LIMIT 10");
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:#1d4ed8;"><?=$totalUsers?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;">Total Users</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:#16a34a;"><?=$newThisMonth?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;">New This Month</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:#0f172a;"><?=$activeUsers?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;">Users w/ ≥1 Booking</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <div style="font-size:26px;font-weight:800;color:#7c3aed;"><?=$avgBookings?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;">Avg. Bookings / User</div>
  </div>
</div>

<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:20px;">
  <h3 style="font-size:15px;font-weight:700;margin-bottom:20px;"><i class="fa fa-line-chart" style="color:#1d4ed8;margin-right:8px;"></i>User Growth (Signups by Month)</h3>
  <?php if (empty($signupsByMonth)): ?>
  <div style="text-align:center;color:#94a3b8;padding:20px;">No signup data yet.</div>
  <?php else: ?>
  <div style="display:flex;align-items:flex-end;gap:10px;height:180px;padding-top:10px;">
    <?php foreach (array_reverse($signupsByMonth) as $m):
      $barHeight = $maxSignups > 0 ? max(4, round(((int)$m->cnt / $maxSignups) * 150)) : 4;
    ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;">
      <div style="font-size:10.5px;color:#64748b;font-weight:600;"><?= $m->cnt ?></div>
      <div style="width:100%;max-width:38px;background:linear-gradient(180deg,#34d399,#059669);border-radius:6px 6px 0 0;height:<?= $barHeight ?>px;" title="<?= htmlspecialchars($m->month) ?>: <?= $m->cnt ?> signups"></div>
      <div style="font-size:10px;color:#94a3b8;"><?= date('M', strtotime($m->month.'-01')) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fa fa-money" style="color:#f59e0b;margin-right:8px;"></i>Top Spenders</h3>
    <div class="table-responsive"><table class="tms-table" style="font-size:12px;">
      <thead><tr><th>#</th><th>User</th><th>Bookings</th><th>Total Spent</th></tr></thead>
      <tbody>
      <?php if(empty($topSpenders)): ?>
      <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:20px;">No confirmed bookings yet.</td></tr>
      <?php else: $i=1; foreach($topSpenders as $ts): ?>
      <tr>
        <td><?=$i++?></td>
        <td><?=htmlspecialchars($ts->FullName??'—')?></td>
        <td><?=$ts->bookings?></td>
        <td><strong><?=tms_currency((float)$ts->spent)?></strong></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fa fa-star" style="color:#1d4ed8;margin-right:8px;"></i>Most Active Users</h3>
    <div class="table-responsive"><table class="tms-table" style="font-size:12px;">
      <thead><tr><th>#</th><th>User</th><th>Bookings (all)</th></tr></thead>
      <tbody>
      <?php if(empty($mostActive)): ?>
      <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px;">No bookings yet.</td></tr>
      <?php else: $i=1; foreach($mostActive as $ma): ?>
      <tr>
        <td><?=$i++?></td>
        <td><?=htmlspecialchars($ma->FullName??'—')?></td>
        <td><?=$ma->cnt?></td>
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

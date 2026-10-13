<?php
require __DIR__ . '/_layout.php';
$sup = sup_require_login($dbh); $sid = (int)$sup['SupplierId'];
$pk = SupplierService::packages($dbh, $sid);
$by = ['approved' => 0, 'pending_review' => 0, 'rejected' => 0];
foreach ($pk as $p) { if (isset($by[$p['ApprovalStatus']])) $by[$p['ApprovalStatus']]++; }
$bk = SupplierService::bookings($dbh, $sid, 500);
$awaiting = 0; $upcoming = 0; $today = date('Y-m-d');
foreach ($bk as $b) {
    if ((int)$b['status'] !== 2 && empty($b['Decision'])) $awaiting++;
    if ((int)$b['status'] === 1 && $b['FromDate'] >= $today) $upcoming++;
}
$owed = 0.0; $paid = 0.0;
foreach (SupplierService::settlements($dbh, $sid, ['issued', 'paid']) as $s) { if ($s['Status'] === 'issued') $owed += (float)$s['NetAmount']; else $paid += (float)$s['NetAmount']; }
sup_head('Dashboard', $sup, 'dashboard.php');
?>
<h1>Welcome, <?php echo h($sup['ContactName']); ?></h1>
<p class="sp-sub"><?php echo h($sup['BusinessName']); ?> &middot; <?php echo sup_status_badge($sup['Status']); ?></p>
<?php if ($sup['Status'] === 'pending'): ?><div class="sp-alert warn" role="status">Your business is waiting for verification. You can list packages as soon as an administrator verifies it.</div><?php endif; ?>
<?php if ($sup['VerificationNotes'] && $sup['Status'] !== 'verified'): ?><div class="sp-alert info">Note from TMS: <?php echo h($sup['VerificationNotes']); ?></div><?php endif; ?>
<div class="sp-grid">
  <div class="sp-stat"><b><?php echo $by['approved']; ?></b><span>Live packages</span></div>
  <div class="sp-stat"><b><?php echo $by['pending_review']; ?></b><span>Packages in review</span></div>
  <div class="sp-stat"><b><?php echo $awaiting; ?></b><span>Bookings awaiting your response</span></div>
  <div class="sp-stat"><b><?php echo $upcoming; ?></b><span>Upcoming confirmed bookings</span></div>
  <div class="sp-stat"><b><?php echo sup_money($owed); ?></b><span>Net owed to you (issued, unpaid)</span></div>
  <div class="sp-stat"><b><?php echo sup_money($paid); ?></b><span>Net paid out to date</span></div>
</div>
<div class="sp-card"><h2 style="margin-top:0">Quick actions</h2>
  <a class="btn" href="packages.php?new=1">Add a package</a>
  <a class="btn secondary" href="blackouts.php">Block dates</a>
  <a class="btn secondary" href="bookings.php">Review bookings</a>
</div>
<p class="sp-sub">Your commission rate is <?php echo h(number_format((float)$sup['CommissionPct'], 2)); ?>% of each completed, fully paid booking. Figures above come from TMS booking and settlement records.</p>
<?php sup_foot();

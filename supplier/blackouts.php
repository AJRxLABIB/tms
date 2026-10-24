<?php
require __DIR__ . '/_layout.php';
$sup = sup_require_login($dbh); $sid = (int)$sup['SupplierId'];
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    if (isset($_POST['delete_id'])) {
        $msg = SupplierService::deleteBlackout($dbh, $sid, (int)$_POST['delete_id']) ? 'Blackout removed.' : '';
        if (!$msg) $err = 'Blackout not found.';
    } else {
        $r = SupplierService::addBlackout($dbh, $sid, (int)($_POST['package_id'] ?? 0), (string)($_POST['date_from'] ?? ''), (string)($_POST['date_to'] ?? ''), (string)($_POST['reason'] ?? ''));
        if ($r['ok']) $msg = 'Dates blocked. Customers can no longer book this package for that period.'; else $err = $r['error'];
    }
}
$pk = array_filter(SupplierService::packages($dbh, $sid), fn($p) => $p['ApprovalStatus'] !== null);
$list = SupplierService::blackouts($dbh, $sid);
sup_head('Blackout dates', $sup, 'blackouts.php');
?>
<h1>Blackout dates</h1>
<p class="sp-sub">Block dates when a package cannot run. TourBuddy refuses new bookings that overlap them. Existing bookings are not cancelled automatically; contact TourBuddy about those.</p>
<?php if ($msg): ?><div class="sp-alert ok" role="status"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="sp-alert bad" role="alert"><?php echo h($err); ?></div><?php endif; ?>
<?php if (!$pk): ?><div class="sp-card sp-empty">Add a package first.</div><?php else: ?>
<form method="post" class="sp-card sp-form">
  <?php tms_csrf_field(); ?>
  <div><label for="package_id">Package</label><select id="package_id" name="package_id" required>
    <?php foreach ($pk as $p): ?><option value="<?php echo (int)$p['PackageId']; ?>"><?php echo h($p['PackageName']); ?></option><?php endforeach; ?></select></div>
  <div><label for="reason">Reason (optional)</label><input id="reason" name="reason" maxlength="200"></div>
  <div><label for="date_from">From</label><input id="date_from" name="date_from" type="date" required min="<?php echo date('Y-m-d'); ?>"></div>
  <div><label for="date_to">To (inclusive)</label><input id="date_to" name="date_to" type="date" required min="<?php echo date('Y-m-d'); ?>"></div>
  <div class="full"><button class="btn" type="submit">Block these dates</button></div>
</form>
<?php endif; ?>
<h2>Upcoming blackouts</h2>
<?php if (!$list): ?><div class="sp-card sp-empty">No blackout dates set.</div><?php else: ?>
<div class="sp-table-wrap" tabindex="0" role="region" aria-label="Blackout dates"><table><thead><tr><th scope="col">Package</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Reason</th><th scope="col">Action</th></tr></thead><tbody>
<?php foreach ($list as $b): ?><tr><td><?php echo h($b['PackageName']); ?></td><td><?php echo h($b['DateFrom']); ?></td><td><?php echo h($b['DateTo']); ?></td><td><?php echo h($b['Reason']); ?></td>
 <td><form method="post" class="inline"><?php tms_csrf_field(); ?><input type="hidden" name="delete_id" value="<?php echo (int)$b['BlackoutId']; ?>"><button class="btn danger sm" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php sup_foot();

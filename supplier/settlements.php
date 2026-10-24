<?php
require __DIR__ . '/_layout.php';
$sup = sup_require_login($dbh); $sid = (int)$sup['SupplierId'];
$detail = null; $items = [];
if (!empty($_GET['id'])) {
    // Only issued/paid settlements that belong to this supplier; drafts are internal.
    $st = $dbh->prepare("SELECT * FROM tbl_supplier_settlements WHERE SettlementId = ? AND SupplierId = ? AND Status IN ('issued','paid')");
    $st->execute([(int)$_GET['id'], $sid]);
    $detail = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detail) {
        $it = $dbh->prepare('SELECT i.BookingId, i.GrossAmount, i.CommissionAmount, i.NetAmount, p.PackageName FROM tbl_supplier_settlement_items i JOIN tblbooking b ON b.BookingId = i.BookingId JOIN tbltourpackages p ON p.PackageId = b.PackageId WHERE i.SettlementId = ? AND p.SupplierId = ? ORDER BY i.BookingId');
        $it->execute([$detail['SettlementId'], $sid]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
    }
}
$list = SupplierService::settlements($dbh, $sid, ['issued', 'paid']);
sup_head('Settlements', $sup, 'settlements.php');
?>
<h1>Settlements</h1>
<p class="sp-sub">A settlement covers confirmed, fully paid bookings whose trip has ended. Gross is what the customer paid; commission is calculated at the rate in force when the settlement was created.</p>
<?php if ($detail): ?>
<div class="sp-card"><h2 style="margin-top:0">Invoice <?php echo h($detail['InvoiceNo']); ?> <?php echo sup_status_badge($detail['Status']); ?></h2>
  <p>Period <?php echo h($detail['PeriodStart']); ?> to <?php echo h($detail['PeriodEnd']); ?> &middot; commission <?php echo h(number_format((float)$detail['CommissionPct'], 2)); ?>%
  <?php if ($detail['Status'] === 'paid'): ?>&middot; paid <?php echo h($detail['PaidAt']); ?> (ref <?php echo h($detail['PaymentReference']); ?>)<?php endif; ?></p>
  <div class="sp-table-wrap" tabindex="0" role="region" aria-label="Settlement items"><table><thead><tr><th scope="col">Booking</th><th scope="col">Package</th><th scope="col">Gross</th><th scope="col">Commission</th><th scope="col">Net to you</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td>#<?php echo (int)$i['BookingId']; ?></td><td><?php echo h($i['PackageName']); ?></td><td><?php echo sup_money($i['GrossAmount']); ?></td><td><?php echo sup_money($i['CommissionAmount']); ?></td><td><?php echo sup_money($i['NetAmount']); ?></td></tr><?php endforeach; ?>
  <tr><th scope="row" colspan="2">Total</th><td><b><?php echo sup_money($detail['GrossAmount']); ?></b></td><td><b><?php echo sup_money($detail['CommissionAmount']); ?></b></td><td><b><?php echo sup_money($detail['NetAmount']); ?></b></td></tr>
  </tbody></table></div>
  <p><a href="settlements.php">Back to all settlements</a></p></div>
<?php endif; ?>
<?php if (!$list): ?><div class="sp-card sp-empty">No settlements have been issued yet.</div><?php else: ?>
<div class="sp-table-wrap" tabindex="0" role="region" aria-label="Settlements"><table><thead><tr><th scope="col">Invoice</th><th scope="col">Period</th><th scope="col">Gross</th><th scope="col">Commission</th><th scope="col">Net</th><th scope="col">Status</th></tr></thead><tbody>
<?php foreach ($list as $s): ?><tr><td><a href="settlements.php?id=<?php echo (int)$s['SettlementId']; ?>"><?php echo h($s['InvoiceNo']); ?></a></td><td><?php echo h($s['PeriodStart']); ?> to <?php echo h($s['PeriodEnd']); ?></td>
 <td><?php echo sup_money($s['GrossAmount']); ?></td><td><?php echo sup_money($s['CommissionAmount']); ?></td><td><?php echo sup_money($s['NetAmount']); ?></td><td><?php echo sup_status_badge($s['Status']); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php sup_foot();

<?php
require __DIR__ . '/_layout.php';
$sup = sup_require_login($dbh); $sid = (int)$sup['SupplierId'];
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $r = SupplierService::respondToBooking($dbh, $sid, (int)($_POST['booking_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''));
    if ($r['ok']) $msg = 'Response recorded. TMS staff will see it.'; else $err = $r['error'];
}
$rows = SupplierService::bookings($dbh, $sid, 200);
$statusLabel = [0 => ['warn', 'Pending'], 1 => ['ok', 'Confirmed'], 2 => ['bad', 'Cancelled']];
sup_head('Bookings', $sup, 'bookings.php');
?>
<h1>Bookings</h1>
<p class="sp-sub">Your response tells TMS whether you can deliver the service. TMS staff confirm or cancel the booking itself. Customer name and phone appear once TMS has confirmed a booking.</p>
<?php if ($msg): ?><div class="sp-alert ok" role="status"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="sp-alert bad" role="alert"><?php echo h($err); ?></div><?php endif; ?>
<?php if (!$rows): ?><div class="sp-card sp-empty">No bookings for your packages yet.</div><?php else: ?>
<div class="sp-table-wrap" tabindex="0" role="region" aria-label="Bookings"><table>
<thead><tr><th scope="col">Ref</th><th scope="col">Package</th><th scope="col">Dates</th><th scope="col">Guests</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Customer</th><th scope="col">Your response</th></tr></thead><tbody>
<?php foreach ($rows as $b): [$cls, $lbl] = $statusLabel[(int)$b['status']] ?? ['info', '?']; ?>
<tr><td>#<?php echo (int)$b['BookingId']; ?></td><td><?php echo h($b['PackageName']); ?></td>
 <td><?php echo h($b['FromDate']); ?> &rarr; <?php echo h($b['ToDate']); ?></td><td><?php echo (int)$b['NoOfPersons']; ?></td>
 <td><?php echo sup_money($b['TotalCost']); ?><br><span class="sp-hint"><?php echo h($b['PaymentStatus']); ?></span></td>
 <td><span class="badge <?php echo $cls; ?>"><?php echo $lbl; ?></span></td>
 <td><?php echo $b['CustomerName'] ? h($b['CustomerName']) . '<br>' . h($b['CustomerMobile']) : '<span class="sp-hint">Shown after confirmation</span>'; ?></td>
 <td><?php if ((int)$b['status'] === 2): ?>&mdash;<?php else: ?>
   <?php if ($b['Decision']): ?><span class="badge <?php echo $b['Decision'] === 'accepted' ? 'ok' : 'bad'; ?>"><?php echo h(ucfirst($b['Decision'])); ?></span><?php if ($b['ResponseNote']): ?><div class="sp-hint"><?php echo h($b['ResponseNote']); ?></div><?php endif; ?><?php endif; ?>
   <form method="post" style="margin-top:6px"><?php tms_csrf_field(); ?><input type="hidden" name="booking_id" value="<?php echo (int)$b['BookingId']; ?>">
     <label class="sr-only" for="n<?php echo (int)$b['BookingId']; ?>">Note (required when declining)</label>
     <input id="n<?php echo (int)$b['BookingId']; ?>" name="note" maxlength="255" placeholder="Note (required to decline)" style="margin-bottom:6px">
     <button class="btn sm" name="decision" value="accepted" type="submit">Accept</button> <button class="btn danger sm" name="decision" value="declined" type="submit">Decline</button></form>
 <?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php sup_foot();

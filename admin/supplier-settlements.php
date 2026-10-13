<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require_once dirname(__DIR__) . '/backend/services/SupplierService.php';
tms_require_admin();
tms_admin_require_role($dbh, ['finance', 'manager']);
$actor = (string)($_SESSION['alogin'] ?? 'admin');
$msg = ''; $msgType = ''; $preview = null;
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$sid = (int)($_POST['sid'] ?? 0);
$start = (string)($_POST['start'] ?? ''); $end = (string)($_POST['end'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'preview' || $act === 'create') {
        $okDates = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end);
        if (!$okDates || !SupplierService::find($dbh, $sid)) { $msg = 'Choose a supplier and a valid period.'; $msgType = 'error'; }
        elseif ($act === 'preview') { $preview = SupplierService::previewSettlement($dbh, $sid, $start, $end); }
        else {
            $r = SupplierService::createSettlement($dbh, $sid, $start, $end, $actor);
            $msg = $r['ok'] ? 'Settlement drafted. Review it, then mark it issued.' : (string)$r['error']; $msgType = $r['ok'] ? 'success' : 'error';
        }
    } elseif ($act === 'transition') {
        $r = SupplierService::transitionSettlement($dbh, (int)($_POST['id'] ?? 0), (string)($_POST['to'] ?? ''), $actor, (string)($_POST['reference'] ?? ''));
        $msg = $r['ok'] ? 'Settlement updated.' : (string)$r['error']; $msgType = $r['ok'] ? 'success' : 'error';
    }
}
$verified = $dbh->query("SELECT SupplierId, BusinessName, CommissionPct FROM tbl_suppliers WHERE Status='verified' ORDER BY BusinessName")->fetchAll(PDO::FETCH_ASSOC);
$list = SupplierService::settlements($dbh);
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Supplier Settlements</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row h1{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:var(--text-primary);margin:0 0 18px;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.sx-wrap{overflow-x:auto}.sx-wrap table{min-width:820px}
.sx-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;border:1px solid transparent;cursor:pointer;min-height:32px}
.sx-ok{background:#d1fae5;color:#065f46}.sx-bad{background:#fee2e2;color:#991b1b}.sx-neutral{background:#E6EFE9;color:#104B3A}
.sx-in{padding:7px 9px;border:1px solid #cbd5cf;border-radius:6px;font-size:13px;min-height:36px}
.sx-form{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;padding:16px;margin-bottom:22px}
.sx-form label{display:block;font-size:12px;font-weight:700;margin-bottom:4px}
.sx-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:#E6EFE9;color:#104B3A}
.sx-pill.draft{background:#E4EEF9;color:#1C4A7A}.sx-pill.void{background:#fee2e2;color:#991b1b}
html[data-theme="dark"] .nx-neutral,html[data-theme="dark"] .sx-neutral{background:#24332B;color:#EAF1EC}html[data-theme="dark"] .nx-ok,html[data-theme="dark"] .sx-ok{background:#14321F;color:#9AE6BC}html[data-theme="dark"] .nx-bad,html[data-theme="dark"] .sx-bad{background:#3A1414;color:#FCA5A5}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row"><h1><i class="fa fa-money" style="color:#176B50;margin-right:10px;"></i>Supplier Settlements</h1></div>
<p style="color:#5F6D65;max-width:760px">A settlement groups confirmed, fully-paid bookings whose trip has finished and that are not yet in another settlement. The commission rate is frozen when the settlement is drafted. This page records what you owe a supplier; it does <b>not</b> send money. Pay by bank or bKash yourself, then record the reference.</p>
<?php if ($msg): ?><div class="alert-msg alert-<?php echo $msgType; ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>

<form method="post" class="card sx-form"><?php tms_csrf_field(); ?>
<div><label for="sid">Supplier (verified)</label><select class="sx-in" id="sid" name="sid" required><option value="">Choose…</option>
<?php foreach ($verified as $v): ?><option value="<?php echo (int)$v['SupplierId']; ?>"<?php echo $sid === (int)$v['SupplierId'] ? ' selected' : ''; ?>><?php echo e($v['BusinessName']); ?> (<?php echo e($v['CommissionPct']); ?>%)</option><?php endforeach; ?></select></div>
<div><label for="start">Trips ending from</label><input class="sx-in" type="date" id="start" name="start" value="<?php echo e($start); ?>" required></div>
<div><label for="end">to</label><input class="sx-in" type="date" id="end" name="end" value="<?php echo e($end); ?>" required></div>
<button class="sx-btn sx-neutral" name="action" value="preview">Preview</button>
<?php if ($preview && $preview['eligible']): ?><button class="sx-btn sx-ok" name="action" value="create" onclick="return confirm('Draft a settlement for these bookings?')">Draft settlement</button><?php endif; ?>
</form>

<?php if ($preview !== null): ?>
<div class="card" style="padding:16px;margin-bottom:22px" role="region" aria-label="Settlement preview">
<b><?php echo count($preview['eligible']); ?> eligible booking(s), gross ৳ <?php echo number_format($preview['gross']); ?></b>
<?php if ($preview['review'] > 0): ?><p style="color:#7A4F0E;margin:8px 0 0"><?php echo (int)$preview['review']; ?> partially-refunded booking(s) in this period are excluded and need a manual decision.</p><?php endif; ?>
<?php if ($preview['eligible']): ?><ul style="margin:10px 0 0;padding-left:18px"><?php foreach ($preview['eligible'] as $b): ?><li>Booking #<?php echo (int)$b['BookingId']; ?> · ৳ <?php echo number_format((float)$b['TotalCost']); ?></li><?php endforeach; ?></ul>
<?php else: ?><p style="margin:8px 0 0">Nothing to settle in this period.</p><?php endif; ?></div>
<?php endif; ?>

<div class="sx-wrap card"><table class="table"><thead><tr><th>Invoice</th><th>Supplier</th><th>Period</th><th>Gross</th><th>Commission</th><th>Net payable</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$list): ?><tr><td colspan="8" style="text-align:center;color:#5F6D65;padding:22px">No settlements yet.</td></tr><?php endif; ?>
<?php foreach ($list as $s): ?>
<tr><td><?php echo e($s['InvoiceNo']); ?></td><td><?php echo e($s['BusinessName']); ?></td><td><?php echo e($s['PeriodStart']); ?> → <?php echo e($s['PeriodEnd']); ?></td>
<td>৳ <?php echo number_format((float)$s['GrossAmount'], 2); ?></td><td>৳ <?php echo number_format((float)$s['CommissionAmount'], 2); ?> (<?php echo e($s['CommissionPct']); ?>%)</td><td><b>৳ <?php echo number_format((float)$s['NetAmount'], 2); ?></b></td>
<td><span class="sx-pill <?php echo e($s['Status']); ?>"><?php echo e($s['Status']); ?></span><?php if ($s['PaymentReference']): ?><br><small>Ref: <?php echo e($s['PaymentReference']); ?></small><?php endif; ?></td>
<td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="transition"><input type="hidden" name="id" value="<?php echo (int)$s['SettlementId']; ?>">
<?php if (SupplierService::canTransitionSettlement((string)$s['Status'], 'issued')): ?><button class="sx-btn sx-ok" name="to" value="issued">Issue</button><?php endif; ?>
<?php if (SupplierService::canTransitionSettlement((string)$s['Status'], 'paid')): ?><label class="sr-only" for="r<?php echo (int)$s['SettlementId']; ?>">Payment reference</label><input class="sx-in" id="r<?php echo (int)$s['SettlementId']; ?>" name="reference" maxlength="120" placeholder="Bank / bKash ref" style="width:150px"><button class="sx-btn sx-ok" name="to" value="paid" onclick="return confirm('Record this settlement as paid? This does not move money.')">Mark paid</button><?php endif; ?>
<?php if (SupplierService::canTransitionSettlement((string)$s['Status'], 'void')): ?><button class="sx-btn sx-bad" name="to" value="void" onclick="return confirm('Void this settlement? Its bookings become eligible again.')">Void</button><?php endif; ?>
</form></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div></main></div>
</body></html>

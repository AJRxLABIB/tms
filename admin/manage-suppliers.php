<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require_once dirname(__DIR__) . '/backend/services/SupplierService.php';
tms_require_admin();
tms_admin_require_role($dbh, ['manager', 'package_manager']);
$actor = (string)($_SESSION['alogin'] ?? 'admin');
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = (string)($_POST['action'] ?? '');
    $sid = (int)($_POST['sid'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if ($act === 'status') {
        $to = (string)($_POST['to'] ?? '');
        $r = SupplierService::setSupplierStatus($dbh, $sid, $to, $actor, $note);
        $msg = $r['ok'] ? 'Supplier updated.' : (string)$r['error']; $msgType = $r['ok'] ? 'success' : 'error';
    } elseif ($act === 'commission') {
        $ok = SupplierService::setCommission($dbh, $sid, (float)($_POST['pct'] ?? -1), $actor);
        $msg = $ok ? 'Commission updated. It applies to settlements created from now on.' : 'Commission must be between 0 and 50.'; $msgType = $ok ? 'success' : 'error';
    } elseif ($act === 'package') {
        $r = SupplierService::reviewPackage($dbh, (int)($_POST['pid'] ?? 0), (string)($_POST['decision'] ?? ''), $note, $actor);
        $msg = $r['ok'] ? 'Decision recorded.' : (string)$r['error']; $msgType = $r['ok'] ? 'success' : 'error';
    }
}
$suppliers = $dbh->query("SELECT s.*, (SELECT COUNT(*) FROM tbltourpackages p WHERE p.SupplierId = s.SupplierId) AS Pkgs FROM tbl_suppliers s ORDER BY FIELD(s.Status,'pending','verified','suspended','rejected'), s.SupplierId DESC")->fetchAll(PDO::FETCH_ASSOC);
$queue = SupplierService::reviewQueue($dbh);
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Suppliers</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row h1{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:var(--text-primary);margin:0 0 18px;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.sx-wrap{overflow-x:auto}.sx-wrap table{min-width:760px}
.sx-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;border:1px solid transparent;cursor:pointer;min-height:32px}
.sx-ok{background:#d1fae5;color:#065f46}.sx-bad{background:#fee2e2;color:#991b1b}.sx-neutral{background:#E6EFE9;color:#104B3A}
.sx-note{width:100%;min-width:150px;padding:6px 8px;border:1px solid #cbd5cf;border-radius:6px;font-size:13px;margin-bottom:6px}
.sx-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:#E6EFE9;color:#104B3A}
.sx-pill.pending{background:#FFF3D6;color:#7A4F0E}.sx-pill.suspended,.sx-pill.rejected{background:#fee2e2;color:#991b1b}
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
<div class="page-title-row"><h1><i class="fa fa-briefcase" style="color:#176B50;margin-right:10px;"></i>Suppliers</h1></div>
<?php if ($msg): ?><div class="alert-msg alert-<?php echo $msgType; ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>

<h2 style="font-size:17px;margin:6px 0 10px">Packages waiting for review (<?php echo count($queue); ?>)</h2>
<div class="sx-wrap card" style="margin-bottom:26px">
<table class="table"><thead><tr><th>Package</th><th>Supplier</th><th>Price</th><th>Seats</th><th>Cancellation policy</th><th>Decision</th></tr></thead><tbody>
<?php if (!$queue): ?><tr><td colspan="6" style="text-align:center;color:#5F6D65;padding:22px">Nothing is waiting for review.</td></tr><?php endif; ?>
<?php foreach ($queue as $q): ?>
<tr><td><b><?php echo e($q['PackageName']); ?></b><br><small><?php echo e($q['PackageType']); ?> · <?php echo e($q['PackageLocation']); ?></small></td>
<td><?php echo e($q['BusinessName']); ?><br><span class="sx-pill <?php echo e($q['SupplierStatus']); ?>"><?php echo e($q['SupplierStatus']); ?></span></td>
<td>৳ <?php echo number_format((float)$q['PackagePrice']); ?></td><td><?php echo (int)$q['SeatsAvailable']; ?></td><td><?php echo e($q['CancellationPolicy'] ?: 'None stated'); ?></td>
<td><form method="post"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="package"><input type="hidden" name="pid" value="<?php echo (int)$q['PackageId']; ?>">
<label class="sr-only" for="pn<?php echo (int)$q['PackageId']; ?>">Note to supplier</label>
<input class="sx-note" id="pn<?php echo (int)$q['PackageId']; ?>" name="note" maxlength="255" placeholder="Required when rejecting">
<button class="sx-btn sx-ok" name="decision" value="approve">Approve &amp; publish</button>
<button class="sx-btn sx-bad" name="decision" value="reject" onclick="return confirm('Reject this package and send it back to the supplier?')">Reject</button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div>

<h2 style="font-size:17px;margin:6px 0 10px">All suppliers (<?php echo count($suppliers); ?>)</h2>
<div class="sx-wrap card">
<table class="table"><thead><tr><th>Business</th><th>Contact</th><th>Status</th><th>Packages</th><th>Commission</th><th>Actions</th></tr></thead><tbody>
<?php if (!$suppliers): ?><tr><td colspan="6" style="text-align:center;color:#5F6D65;padding:22px">No suppliers have registered yet.</td></tr><?php endif; ?>
<?php foreach ($suppliers as $s): $id = (int)$s['SupplierId']; ?>
<tr><td><b><?php echo e($s['BusinessName']); ?></b><br><small>Reg. no: <?php echo e($s['BusinessRegNo'] ?: '—'); ?></small></td>
<td><?php echo e($s['ContactName']); ?><br><small><?php echo e($s['Email']); ?> · <?php echo e($s['Phone']); ?></small></td>
<td><span class="sx-pill <?php echo e($s['Status']); ?>"><?php echo e($s['Status']); ?></span><?php if ($s['VerificationNotes']): ?><br><small><?php echo e($s['VerificationNotes']); ?></small><?php endif; ?></td>
<td><?php echo (int)$s['Pkgs']; ?></td>
<td><form method="post" style="display:flex;gap:6px;align-items:center"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="commission"><input type="hidden" name="sid" value="<?php echo $id; ?>">
<label class="sr-only" for="c<?php echo $id; ?>">Commission percent</label><input id="c<?php echo $id; ?>" name="pct" type="number" step="0.5" min="0" max="50" value="<?php echo e($s['CommissionPct']); ?>" style="width:74px;padding:5px;border:1px solid #cbd5cf;border-radius:6px"> %
<button class="sx-btn sx-neutral">Save</button></form></td>
<td><form method="post"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="sid" value="<?php echo $id; ?>">
<label class="sr-only" for="n<?php echo $id; ?>">Reason</label><input class="sx-note" id="n<?php echo $id; ?>" name="note" maxlength="255" placeholder="Reason (required to reject or suspend)">
<?php foreach (['verified' => [$s['Status'] === 'suspended' ? 'Reinstate' : 'Verify', 'sx-ok'], 'rejected' => ['Reject', 'sx-bad'], 'suspended' => ['Suspend', 'sx-bad']] as $to => [$lbl, $cls]):
  if (SupplierService::canTransitionSupplier((string)$s['Status'], $to)): ?>
<button class="sx-btn <?php echo $cls; ?>" name="to" value="<?php echo $to; ?>"<?php if ($to !== 'verified'): ?> onclick="return confirm('<?php echo $lbl; ?> this supplier? Suspending also takes all their packages offline.')"<?php endif; ?>><?php echo $lbl; ?></button>
<?php endif; endforeach; ?>
<?php if (SupplierService::canTransitionSupplier((string)$s['Status'], 'pending')): ?><button class="sx-btn sx-neutral" name="to" value="pending">Reinstate to pending</button><?php endif; ?>
</form></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div></main></div>
</body></html>

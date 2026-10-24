<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require_once dirname(__DIR__) . '/backend/services/SupplierQuality.php';
tms_require_admin();
tms_admin_require_role($dbh, ['manager', 'package_manager']);
$actor = (string)($_SESSION['alogin'] ?? 'admin');
$msg = ''; $msgType = '';
try { $dbh->query('SELECT 1 FROM tbl_supplier_disputes LIMIT 1'); $dbh->query('SELECT 1 FROM tbl_supplier_quality_snapshots LIMIT 1'); }
catch (Throwable $e) { http_response_code(503); exit('Supplier quality tables are missing. Run: php database/migrate.php (migration 018_supplier_quality.sql).'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = (string)($_POST['action'] ?? '');
    $sid = (int)($_POST['sid'] ?? 0);
    if ($act === 'refresh') {
        if (SupplierService::find($dbh, $sid)) { SupplierQuality::snapshot($dbh, $sid); $msg = 'Quality snapshot recalculated.'; $msgType = 'success'; }
        else { $msg = 'Supplier not found.'; $msgType = 'error'; }
    } elseif ($act === 'review') {
        $snap = SupplierQuality::latestSnapshot($dbh, $sid);
        $r = SupplierQuality::recordReview($dbh, $sid, $actor, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''), $snap ? (int)$snap['SnapshotId'] : null);
        $msg = $r['ok'] ? 'Review decision recorded. Supplier status was not changed; use Suppliers to verify, suspend or reinstate.' : (string)$r['error']; $msgType = $r['ok'] ? 'success' : 'error';
    } elseif ($act === 'dispute') {
        $r = SupplierQuality::setDisputeStatus($dbh, (int)($_POST['did'] ?? 0), (string)($_POST['to'] ?? ''), $actor, (string)($_POST['resolution'] ?? ''));
        $msg = $r['ok'] ? 'Dispute updated.' : (string)$r['error']; $msgType = $r['ok'] ? 'success' : 'error';
    }
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$suppliers = $dbh->query("SELECT SupplierId, BusinessName, Status FROM tbl_suppliers ORDER BY FIELD(Status,'verified','pending','suspended','rejected'), SupplierId DESC")->fetchAll(PDO::FETCH_ASSOC);
$detailId = (int)($_GET['sid'] ?? 0);
$detail = null;
if ($detailId > 0 && SupplierService::find($dbh, $detailId)) {
    $detail = SupplierQuality::score(SupplierQuality::metrics($dbh, $detailId));
}
$rows = [];
foreach ($suppliers as $s) {
    $snap = SupplierQuality::latestSnapshot($dbh, (int)$s['SupplierId']);
    $rows[] = $s + ['snap' => $snap];
}
$disputes = SupplierQuality::disputes($dbh, null, false);
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Supplier Quality</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row h1{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:var(--text-primary);margin:0 0 18px;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.sx-wrap{overflow-x:auto}.sx-wrap table{min-width:720px}
.sx-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;border:1px solid transparent;cursor:pointer;min-height:32px}
.sx-ok{background:#d1fae5;color:#065f46}.sx-bad{background:#fee2e2;color:#991b1b}.sx-neutral{background:#E6EFE9;color:#104B3A}
.sx-note{width:100%;min-width:150px;padding:6px 8px;border:1px solid #cbd5cf;border-radius:6px;font-size:13px;margin-bottom:6px}
.sx-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:#E6EFE9;color:#104B3A}
.sx-pill.warn{background:#FFF3D6;color:#7A4F0E}.sx-pill.bad{background:#fee2e2;color:#991b1b}
.sx-card{padding:16px;margin-bottom:22px}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
html[data-theme="dark"] .sx-neutral{background:#24332B;color:#EAF1EC}html[data-theme="dark"] .sx-ok{background:#14321F;color:#9AE6BC}html[data-theme="dark"] .sx-bad{background:#3A1414;color:#FCA5A5}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row"><h1><i class="fa fa-balance-scale" style="color:#176B50;margin-right:10px;"></i>Supplier quality</h1></div>
<p style="max-width:760px;font-size:13.5px">Scores are an aid for human review. They are built from documented criteria, hide nothing, show &ldquo;insufficient data&rdquo; for small samples, and never suspend or penalise a supplier on their own.</p>
<?php if ($msg): ?><div class="alert-msg alert-<?php echo $msgType; ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>

<?php if ($detail): $dName = ''; foreach ($suppliers as $s) { if ((int)$s['SupplierId'] === $detailId) $dName = $s['BusinessName']; } ?>
<div class="card sx-card">
  <h2 style="font-size:17px;margin:0 0 6px"><?php echo e($dName); ?> &mdash; live calculation</h2>
  <p><b><?php echo $detail['score'] === null ? 'Insufficient data' : e($detail['score']) . ' / 100'; ?></b>
     <?php if ($detail['needs_review']): ?> <span class="sx-pill warn">Needs human review</span><?php endif; ?></p>
  <p style="font-size:13px"><?php echo e($detail['note']); ?></p>
  <?php if ($detail['review_reasons']): ?><ul><?php foreach ($detail['review_reasons'] as $rr): ?><li><?php echo e($rr); ?></li><?php endforeach; ?></ul><?php endif; ?>
  <div class="sx-wrap"><table class="table"><thead><tr><th>Criterion</th><th>Weight</th><th>Sample</th><th>Value</th><th>Explanation</th></tr></thead><tbody>
  <?php foreach ($detail['criteria'] as $c): ?>
    <tr><td><?php echo e($c['label']); ?></td><td><?php echo (int)$c['weight']; ?></td><td><?php echo (int)$c['sample']; ?></td>
    <td><?php echo $c['measured'] ? e(round($c['value'] * 100)) . '%' : '<span class="sx-pill">Insufficient data</span>'; ?></td><td><?php echo e($c['explanation']); ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <form method="post" style="margin-top:12px"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="sid" value="<?php echo $detailId; ?>">
    <label for="rdec"><b>Record a review decision</b></label>
    <select id="rdec" name="decision" class="sx-note" style="max-width:280px">
      <option value="no_action">No action</option><option value="monitor">Monitor</option><option value="request_info">Request information</option>
      <option value="warn">Issue a warning</option><option value="escalate_to_status_change">Escalate to a status change</option></select>
    <label class="sr-only" for="rnote">Reason</label>
    <input class="sx-note" id="rnote" name="note" maxlength="500" required minlength="5" placeholder="Written reason (required)">
    <button class="sx-btn sx-ok">Save decision</button>
  </form>
  <?php $hist = SupplierQuality::reviewHistory($dbh, $detailId, 10); if ($hist): ?>
  <h3 style="font-size:14px;margin-top:14px">Decision history</h3><ul style="font-size:13px">
  <?php foreach ($hist as $h): ?><li><?php echo e($h['CreatedAt']); ?> &middot; <?php echo e($h['Reviewer']); ?> &middot; <b><?php echo e($h['Decision']); ?></b> &mdash; <?php echo e($h['Note']); ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>

<h2 style="font-size:17px;margin:6px 0 10px">Open and recent disputes (<?php echo count($disputes); ?>)</h2>
<div class="sx-wrap card" style="margin-bottom:26px"><table class="table"><thead><tr><th>Supplier</th><th>Dispute</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$disputes): ?><tr><td colspan="4" style="text-align:center;padding:22px">No disputes.</td></tr><?php endif; ?>
<?php foreach ($disputes as $d): ?>
<tr><td><?php echo e($d['BusinessName']); ?></td>
<td><b><?php echo e($d['Subject']); ?></b><br><small><?php echo e($d['CreatedAt']); ?></small><p style="margin:6px 0 0;font-size:13px"><?php echo nl2br(e($d['Body'])); ?></p>
<?php if ($d['Resolution']): ?><p style="font-size:13px"><b>Resolution (<?php echo e($d['ResolvedBy']); ?>):</b> <?php echo e($d['Resolution']); ?></p><?php endif; ?></td>
<td><span class="sx-pill <?php echo $d['Status'] === 'resolved' ? '' : 'warn'; ?>"><?php echo e($d['Status']); ?></span></td>
<td><?php if ($d['Status'] !== 'resolved'): ?><form method="post"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="dispute"><input type="hidden" name="did" value="<?php echo (int)$d['DisputeId']; ?>">
<label class="sr-only" for="res<?php echo (int)$d['DisputeId']; ?>">Resolution</label>
<input class="sx-note" id="res<?php echo (int)$d['DisputeId']; ?>" name="resolution" maxlength="2000" placeholder="Written resolution (required to close)">
<?php if ($d['Status'] === 'open'): ?><button class="sx-btn sx-neutral" name="to" value="under_review">Start review</button><?php endif; ?>
<button class="sx-btn sx-ok" name="to" value="resolved">Resolve</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<h2 style="font-size:17px;margin:6px 0 10px">Suppliers</h2>
<div class="sx-wrap card"><table class="table"><thead><tr><th>Supplier</th><th>Status</th><th>Last snapshot</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $s): $sn = $s['snap']; ?>
<tr><td><?php echo e($s['BusinessName']); ?></td><td><span class="sx-pill"><?php echo e($s['Status']); ?></span></td>
<td><?php if ($sn): ?><?php echo $sn['Score'] === null ? 'Insufficient data' : e($sn['Score']) . ' / 100'; ?><?php if ((int)$sn['NeedsReview']): ?> <span class="sx-pill warn">Needs review</span><?php endif; ?><br><small><?php echo e($sn['ComputedAt']); ?></small><?php else: ?>&mdash;<?php endif; ?></td>
<td style="white-space:nowrap"><a class="sx-btn sx-neutral" href="supplier-quality.php?sid=<?php echo (int)$s['SupplierId']; ?>">Breakdown</a>
<form method="post" style="display:inline"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="sid" value="<?php echo (int)$s['SupplierId']; ?>"><button class="sx-btn sx-neutral">Save snapshot</button></form></td></tr>
<?php endforeach; ?></tbody></table></div>
</div></main></div>
</body></html>

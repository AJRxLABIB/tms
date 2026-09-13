<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['content_manager', 'support', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Reviews</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-edit{background:#dbeafe;color:#1e40af;}.btn-edit:hover{background:#bfdbfe;}
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
  <h2><i class="fa fa-star" style="color:#1d4ed8;margin-right:10px;"></i>Reviews</h2>
</div>


<?php
$msg=''; $msgType='';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete','approve','reject'], true) && isset($_POST['id'])) {
    tms_csrf_validate();
    $rid=(int)$_POST['id'];
    if($_POST['action']==='delete'){
        $dbh->prepare("DELETE FROM tblreviews WHERE id=:id")->execute([':id'=>$rid]);
        // Audit log coverage (Round 52).
        tms_log_activity($dbh, $_SESSION['alogin'], 'Review Deleted', "id=$rid");
        $msg="Review deleted."; $msgType='success';
    } elseif($_POST['action']==='approve'){
        $dbh->prepare("UPDATE tblreviews SET Status=1 WHERE id=:id")->execute([':id'=>$rid]);
        // Recalculate the reviewed entity's average rating now that this
        // review counts. ROUND 80: a review can now target a package, a
        // hotel, or an activity (never more than one), so this checks all
        // three FK columns instead of assuming PackageId.
        $idRow = $dbh->prepare("SELECT PackageId, HotelId, ActivityId FROM tblreviews WHERE id=:id");
        $idRow->execute([':id'=>$rid]);
        $row = $idRow->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['PackageId']) {
                $dbh->prepare("UPDATE tbltourpackages SET AvgRating=(SELECT AVG(Rating) FROM tblreviews WHERE PackageId=:p AND Status=1) WHERE PackageId=:p2")
                    ->execute([':p'=>$row['PackageId'], ':p2'=>$row['PackageId']]);
            } elseif ($row['HotelId']) {
                $dbh->prepare("UPDATE tblhotels SET AvgRating=(SELECT AVG(Rating) FROM tblreviews WHERE HotelId=:p AND Status=1) WHERE HotelId=:p2")
                    ->execute([':p'=>$row['HotelId'], ':p2'=>$row['HotelId']]);
            } elseif ($row['ActivityId']) {
                $dbh->prepare("UPDATE tblactivities SET AvgRating=(SELECT AVG(Rating) FROM tblreviews WHERE ActivityId=:p AND Status=1) WHERE ActivityId=:p2")
                    ->execute([':p'=>$row['ActivityId'], ':p2'=>$row['ActivityId']]);
            }
        }
        tms_log_activity($dbh, $_SESSION['alogin'], 'Review Approved', "id=$rid");
        $msg="Review approved."; $msgType='success';
    } elseif($_POST['action']==='reject'){
        $dbh->prepare("UPDATE tblreviews SET Status=2 WHERE id=:id")->execute([':id'=>$rid]);
        tms_log_activity($dbh, $_SESSION['alogin'], 'Review Rejected', "id=$rid");
        $msg="Review rejected and hidden from the public site."; $msgType='success';
    }
}
try{
    // PERFORMANCE FIX (Section 9 audit): every review ever submitted, with
    // a JOIN and no LIMIT. Same pattern as manage-packages.php.
    $revPerPage = 25;
    $revPage    = max(1, (int)($_GET['p'] ?? 1));
    $revTotal   = (int)$dbh->query("SELECT COUNT(*) FROM tblreviews")->fetchColumn();
    $revTotalPages = max(1, (int)ceil($revTotal / $revPerPage));
    $revPage    = min($revPage, $revTotalPages);
    $revOffset  = ($revPage - 1) * $revPerPage;

    // ROUND 80: LEFT JOIN all three possible targets (package/hotel/
    // activity) and pick whichever one this row actually has -- was a
    // single INNER JOIN to tbltourpackages only, which would have silently
    // hidden any hotel/activity review from this admin list entirely.
    $rq = $dbh->prepare("SELECT r.*,
            COALESCE(p.PackageName, h.HotelName, a.ActivityName) AS EntityName,
            CASE WHEN r.PackageId IS NOT NULL THEN 'Package' WHEN r.HotelId IS NOT NULL THEN 'Hotel' ELSE 'Activity' END AS EntityType
        FROM tblreviews r
        LEFT JOIN tbltourpackages p ON p.PackageId=r.PackageId
        LEFT JOIN tblhotels h ON h.HotelId=r.HotelId
        LEFT JOIN tblactivities a ON a.ActivityId=r.ActivityId
        ORDER BY r.id DESC LIMIT :lim OFFSET :off");
    $rq->bindValue(':lim', $revPerPage, PDO::PARAM_INT);
    $rq->bindValue(':off', $revOffset, PDO::PARAM_INT);
    $rq->execute();
    $reviews=$rq->fetchAll(PDO::FETCH_OBJ);
}catch(Exception $e){ $reviews=[]; $revTotal=0; $revTotalPages=1; $revPage=1; }
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>User</th><th>Type</th><th>Reviewing</th><th>Rating</th><th>Review</th><th>Photo</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($reviews)): ?>
  <tr><td colspan="10" style="text-align:center;padding:40px;color:#94a3b8;">No reviews yet.</td></tr>
  <?php else: $i=1; foreach($reviews as $r): ?>
  <?php
    $statusVal = (int)($r->Status ?? 0);
    $statusMeta = [
        0 => ['label'=>'Pending',  'bg'=>'#fef3c7', 'fg'=>'#92400e'],
        1 => ['label'=>'Approved', 'bg'=>'#d1fae5', 'fg'=>'#065f46'],
        2 => ['label'=>'Rejected', 'bg'=>'#fee2e2', 'fg'=>'#991b1b'],
    ][$statusVal] ?? ['label'=>'Unknown','bg'=>'#f1f5f9','fg'=>'#64748b'];
  ?>
  <tr>
    <td><?=$i++?></td>
    <td><?=htmlspecialchars($r->UserName ?? '—')?></td>
    <td><?=htmlspecialchars($r->EntityType ?? '—')?></td>
    <td><?=htmlspecialchars($r->EntityName ?? '—')?></td>
    <td><?php $rat=(int)($r->Rating??0); for($s=1;$s<=5;$s++) echo '<i class="fa fa-star" style="color:'.($s<=$rat?'#f59e0b':'#e2e8f0').'"></i>'; ?></td>
    <td style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars(substr($r->ReviewText??'',0,80))?></td>
    <td>
      <?php if(!empty($r->ReviewImage)): ?>
        <a href="../uploads/reviews/<?=htmlspecialchars($r->ReviewImage,ENT_QUOTES,'UTF-8')?>" target="_blank" title="View full-size photo">
          <img loading="lazy" src="../uploads/reviews/<?=htmlspecialchars($r->ReviewImage,ENT_QUOTES,'UTF-8')?>" onerror="this.src='../images/no-image-placeholder.jpg'" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;">
        </a>
      <?php else: ?>
        <span style="color:#cbd5e1;">—</span>
      <?php endif; ?>
    </td>
    <td><span style="padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:<?=$statusMeta['bg']?>;color:<?=$statusMeta['fg']?>"><?=$statusMeta['label']?></span></td>
    <td><?=htmlspecialchars($r->PostingDate??'—')?></td>
    <td style="display:flex;gap:5px;">
      <?php if($statusVal!==1): ?><form method="post" style="display:contents"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=$r->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-approve"><i class="fa fa-check"></i> Approve</button></form><?php endif; ?>
      <?php if($statusVal!==2): ?><form method="post" style="display:contents"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?=$r->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Reject this review? It will be hidden from the public site.')"><i class="fa fa-ban"></i> Reject</button></form><?php endif; ?>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete permanently?')"><i class="fa fa-trash"></i></button></form>
    </td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<div style="font-size:12.5px;color:#94a3b8;margin:10px 0;"><?=$revTotal?> review<?=$revTotal!==1?'s':''?> total &nbsp;·&nbsp; page <?=$revPage?> of <?=$revTotalPages?></div>
<?php if($revTotalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
    <?php for($pn=1; $pn<=$revTotalPages; $pn++):
        $qs = http_build_query(array_merge($_GET, ['p'=>$pn])); ?>
    <a href="?<?=$qs?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;
        <?=$pn===$revPage ? 'background:#1d4ed8;color:#fff;' : 'background:#f1f5f9;color:#334155;'?>"><?=$pn?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>


<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

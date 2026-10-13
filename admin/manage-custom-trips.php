<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['operations', 'booking_manager', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Custom Trips</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
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
  <h2><i class="fa fa-map-o" style="color:#1d4ed8;margin-right:10px;"></i>Custom Trips</h2>
</div>


<?php
$msg=''; $msgType='';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $cid=(int)$_POST['id'];
    $dbh->prepare("DELETE FROM tblcustomtrips WHERE id=:id")->execute([':id'=>$cid]);
    // Audit log coverage (Round 52).
    tms_log_activity($dbh, $_SESSION['alogin'], 'Custom Trip Request Deleted', "id=$cid");
    $msg="Custom trip deleted."; $msgType='success';
}
try{ $trips=$dbh->query("SELECT * FROM tblcustomtrips ORDER BY id DESC")->fetchAll(PDO::FETCH_OBJ); }
catch(Exception $e){ $trips=[]; }
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>User</th><th>Trip Name</th><th>Destination</th><th>Travelers</th><th>Budget</th><th>Date</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($trips)): ?>
  <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">No custom trip requests yet.</td></tr>
  <?php else: $i=1; foreach($trips as $t): ?>
  <tr>
    <td><?=$i++?></td>
    <td><?=htmlspecialchars($t->UserEmail??'—')?></td>
    <td><?=htmlspecialchars($t->TripName??'—')?></td>
    <td><?=htmlspecialchars($t->Destination??'—')?></td>
    <td><?=htmlspecialchars($t->TravelersCount??'—')?></td>
    <td><?=htmlspecialchars($t->Budget??'—')?></td>
    <td><?=$t->CreatedAt??'—'?></td>
    <td><form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$t->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete?')"><i class="fa fa-trash"></i></button></form></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>


<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

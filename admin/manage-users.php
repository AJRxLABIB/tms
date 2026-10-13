<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['support', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Users</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:var(--text-primary);margin:0;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-edit{background:#DCF1E5;color:#104B3A;}.btn-edit:hover{background:#B8E4C9;}
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
  <h2><i class="fa fa-users" style="color:#176B50;margin-right:10px;"></i>Manage Users</h2>
</div>


<?php
$msg=''; $msgType='';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $uid=(int)$_POST['id'];
    $delUser = $dbh->prepare("SELECT EmailId FROM tblusers WHERE id=:id");
    $delUser->execute([':id'=>$uid]);
    $delUser = $delUser->fetchColumn();
    $dbh->prepare("DELETE FROM tblusers WHERE id=:id")->execute([':id'=>$uid]);
    // Audit log coverage (Round 52). Deleting a user account is exactly the
    // kind of destructive, PII-affecting action an audit trail exists for.
    tms_log_activity($dbh, $_SESSION['alogin'], 'User Deleted', "UserId=$uid" . ($delUser ? ", Email: $delUser" : ''));
    $msg="User deleted."; $msgType='success';
}
try{
    // PERFORMANCE FIX (Section 9 audit): every registered user, forever,
    // with no LIMIT at all. Same pattern as manage-packages.php.
    $userPerPage = 25;
    $userPage    = max(1, (int)($_GET['p'] ?? 1));
    $userTotal   = (int)$dbh->query("SELECT COUNT(*) FROM tblusers")->fetchColumn();
    $userTotalPages = max(1, (int)ceil($userTotal / $userPerPage));
    $userPage    = min($userPage, $userTotalPages);
    $userOffset  = ($userPage - 1) * $userPerPage;

    $uq = $dbh->prepare("SELECT * FROM tblusers ORDER BY id DESC LIMIT :lim OFFSET :off");
    $uq->bindValue(':lim', $userPerPage, PDO::PARAM_INT);
    $uq->bindValue(':off', $userOffset, PDO::PARAM_INT);
    $uq->execute();
    $users=$uq->fetchAll(PDO::FETCH_OBJ);
}catch(Exception $e){
    // Master prompt item 62 (error/empty/loading states): a real DB failure
    // here used to be indistinguishable from a genuinely-empty user table --
    // both silently fell through to the same "No users registered yet." row
    // with no indication anything had gone wrong. Now it surfaces through
    // the same $msg/$msgType alert banner this page already uses for
    // success messages (matching the pattern already established in
    // manage-bookings.php's identical try/catch).
    $users=[]; $userTotal=0; $userTotalPages=1; $userPage=1;
    $msg = "Error loading users: " . $e->getMessage();
    $msgType = 'error';
}
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-<?=$msgType==='success'?'check-circle':'exclamation-circle'?>"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Mobile</th><th>Joined</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($users)): ?>
  <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No users registered yet.</td></tr>
  <?php else: $i=1; foreach($users as $u): ?>
  <tr>
    <td><?=$i++?></td>
    <td><?=htmlspecialchars(($u->FirstName??'').' '.($u->LastName??''))?></td>
    <td><?=htmlspecialchars($u->EmailId??$u->email??'—')?></td>
    <td><?=htmlspecialchars($u->MobileNumber??$u->mobile??'—')?></td>
    <td><?=$u->RegDate??$u->created_at??'—'?></td>
    <td><form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$u->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete this user?')"><i class="fa fa-trash"></i> Delete</button></form></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<div style="font-size:12.5px;color:var(--text-muted);margin:10px 0;"><?=$userTotal?> user<?=$userTotal!==1?'s':''?> total &nbsp;·&nbsp; page <?=$userPage?> of <?=$userTotalPages?></div>
<?php if($userTotalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
    <?php for($pn=1; $pn<=$userTotalPages; $pn++):
        $qs = http_build_query(array_merge($_GET, ['p'=>$pn])); ?>
    <a href="?<?=$qs?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;
        <?=$pn===$userPage ? 'background:#176B50;color:#fff;' : 'background:#f1f5f9;color:#334155;'?>"><?=$pn?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>


<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

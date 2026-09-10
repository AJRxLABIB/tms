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
<title>TMS | Support Issues</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="css/admin-v6.css" rel="stylesheet">
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
  <h2><i class="fa fa-exclamation-triangle" style="color:#1d4ed8;margin-right:10px;"></i>Support Issues</h2>
</div>


<?php
$msg=''; $msgType='';
if(isset($_POST['reply'])){
    tms_csrf_validate();
    $iid = (int)($_POST['issue_id'] ?? 0);
    $reply = tms_clean($_POST['reply'] ?? '');
    if ($iid && $reply !== '') {
        // Write to AdminRemark/AdminremarkDate — the exact columns the live
        // customer-facing issuetickets.php page reads to show "Answered"
        // and display the reply. Also set status=1 for the admin-side
        // Resolved/Open badge, so both stay in sync from one action instead
        // of two disconnected concepts.
        $dbh->prepare("UPDATE tblissues SET AdminRemark=:r, AdminremarkDate=NOW(), status=1 WHERE id=:id")
            ->execute([':r'=>$reply, ':id'=>$iid]);
        // Notify the customer — reuses the same tms_notify() infrastructure
        // wired up for other events in Round 10, since replying to a ticket
        // is exactly the kind of event a customer would want a notification for.
        $ownerQ = $dbh->prepare("SELECT UserEmail FROM tblissues WHERE id=:id");
        $ownerQ->execute([':id'=>$iid]);
        $ownerEmail = $ownerQ->fetchColumn();
        if ($ownerEmail) {
            tms_notify($dbh, $ownerEmail, "💬 Support replied to your ticket.", 'issuetickets.php');
        }
        $msg="Reply sent."; $msgType='success';
    } else { $msg="Enter a reply message."; $msgType='error'; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $iid=(int)$_POST['id'];
    $dbh->prepare("DELETE FROM tblissues WHERE id=:id")->execute([':id'=>$iid]);
    $msg="Issue deleted."; $msgType='success';
}
try{
    $issues=$dbh->query("SELECT * FROM tblissues ORDER BY id DESC")->fetchAll(PDO::FETCH_OBJ);
}catch(Exception $e){ $issues=[]; }
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>User</th><th>Issue</th><th>Message</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($issues)): ?>
  <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">No support issues reported.</td></tr>
  <?php else: $i=1; foreach($issues as $is): ?>
  <?php $answered = !empty($is->AdminRemark); ?>
  <tr>
    <td><?=$i++?></td>
    <td><?=htmlspecialchars($is->UserEmail??'—')?></td>
    <td><?=htmlspecialchars($is->Issue??'—')?></td>
    <td style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?=htmlspecialchars($is->Description??'')?>"><?=htmlspecialchars(substr($is->Description??'',0,80))?></td>
    <td><span style="padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:<?=$answered?'#d1fae5':'#fef3c7'?>;color:<?=$answered?'#065f46':'#92400e'?>"><?=$answered?'Answered':'Open'?></span>
      <?php if($answered): ?><div style="font-size:11px;color:#94a3b8;margin-top:4px;max-width:200px;">"<?=htmlspecialchars(substr($is->AdminRemark,0,60))?><?=strlen($is->AdminRemark)>60?'…':''?>"</div><?php endif; ?>
    </td>
    <td><?=htmlspecialchars($is->PostingDate??'—')?></td>
    <td style="display:flex;gap:5px;">
      <button type="button" class="action-btn btn-edit" onclick="document.getElementById('replyBox<?=$is->id?>').style.display='block'"><i class="fa fa-reply"></i> Reply</button>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$is->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete?')"><i class="fa fa-trash"></i></button></form>
    </td>
  </tr>
  <tr id="replyBox<?=$is->id?>" style="display:none;">
    <td colspan="7" style="background:#f8fafc;">
      <form method="post" style="display:flex;gap:8px;align-items:flex-start;padding:10px 0;">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="issue_id" value="<?=$is->id?>">
        <textarea name="reply" placeholder="Write a reply the customer will see..." style="flex:1;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-height:50px;"><?=htmlspecialchars($is->AdminRemark??'')?></textarea>
        <button type="submit" class="btn-v6 primary" style="white-space:nowrap;"><i class="fa fa-paper-plane"></i> Send Reply</button>
      </form>
    </td>
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

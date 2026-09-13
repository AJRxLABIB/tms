<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['content_manager', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Pages</title>
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
  <h2><i class="fa fa-file-text" style="color:#1d4ed8;margin-right:10px;"></i>Manage Pages</h2>
</div>


<?php
$msg=''; $msgType='';
if(isset($_POST['save_page'])){
    tms_csrf_validate();
    $pid = (int)($_POST['page_id'] ?? 0);
    $detail = $_POST['detail'] ?? '';
    if ($pid) {
        // Round 96 (item 47): read the pre-edit content before overwriting
        // it, so there's an actual before/after to log -- page content can
        // be long, so tms_log_activity() itself truncates Old/NewValue to
        // 500 chars; this isn't meant to be a full revision history, just
        // enough to see that a real change happened and roughly what it was.
        $prevStmt = $dbh->prepare("SELECT detail FROM tblpages WHERE id=:id");
        $prevStmt->execute([':id'=>$pid]);
        $prevDetail = (string)$prevStmt->fetchColumn();
        $dbh->prepare("UPDATE tblpages SET detail=:d, UpdatedAt=NOW() WHERE id=:id")
            ->execute([':d'=>$detail, ':id'=>$pid]);
        // Audit log coverage (Round 52).
        tms_log_activity($dbh, $_SESSION['alogin'], 'Static Page Edited', "PageId=$pid", 'page', $pid, $prevDetail, $detail);
        $msg="Page updated."; $msgType='success';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $pid=(int)$_POST['id'];
    $dbh->prepare("DELETE FROM tblpages WHERE id=:id")->execute([':id'=>$pid]);
    tms_log_activity($dbh, $_SESSION['alogin'], 'Static Page Deleted', "PageId=$pid", 'page', $pid);
    $msg="Page deleted."; $msgType='success';
}
try{ $pages=$dbh->query("SELECT * FROM tblpages ORDER BY id DESC")->fetchAll(PDO::FETCH_OBJ); }
catch(Exception $e){ $pages=[]; }
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Page Type</th><th>Last Updated</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($pages)): ?>
  <tr><td colspan="4" style="text-align:center;padding:40px;color:#94a3b8;">No pages found.</td></tr>
  <?php else: $i=1; foreach($pages as $p): ?>
  <tr>
    <td><?=$i++?></td>
    <td><strong><?=htmlspecialchars(ucfirst($p->type??'—'))?></strong></td>
    <td><?=htmlspecialchars($p->UpdatedAt??'Never')?></td>
    <td style="display:flex;gap:5px;">
      <button type="button" class="action-btn btn-edit" onclick="document.getElementById('editBox<?=$p->id?>').style.display='block'"><i class="fa fa-edit"></i> Edit</button>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$p->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete page?')"><i class="fa fa-trash"></i></button></form>
    </td>
  </tr>
  <tr id="editBox<?=$p->id?>" style="display:none;">
    <td colspan="4" style="background:#f8fafc;">
      <form method="post" style="padding:10px 0;">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="page_id" value="<?=$p->id?>">
        <textarea name="detail" style="width:100%;min-height:200px;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12.5px;font-family:monospace;"><?=htmlspecialchars($p->detail??'')?></textarea>
        <div style="font-size:11px;color:#94a3b8;margin:6px 0;">HTML content — edit carefully, this is inserted directly into the page.</div>
        <button type="submit" name="save_page" class="btn-v6 primary"><i class="fa fa-save"></i> Save Changes</button>
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

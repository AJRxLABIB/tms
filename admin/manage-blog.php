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
<title>TMS | Blog Posts</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:#17231E;margin:0;}
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
  <h2><i class="fa fa-pencil-square-o" style="color:#176B50;margin-right:10px;"></i>Blog Posts</h2>
</div>


<?php
$msg=''; $msgType='';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $bid=(int)$_POST['id'];
    $dbh->prepare("DELETE FROM tblblog WHERE id=:id")->execute([':id'=>$bid]);
    // Audit log coverage (Round 52).
    tms_log_activity($dbh, $_SESSION['alogin'], 'Blog Post Deleted', "id=$bid");
    $msg="Blog post deleted."; $msgType='success';
}
try{
    // BUG FIX (caught while working on Round 82, master prompt item 54):
    // this listing referenced $p->Author and $p->PostingDate, neither of
    // which is a real tblblog column (the schema has AuthorId + CreatedAt) --
    // so both always silently fell back to their '??' defaults ('Admin' /
    // '—') regardless of the post's real author or date. Joining to admin
    // the same way blog.php/blog-post.php already do surfaces the real
    // values here too.
    $posts=$dbh->query("SELECT b.*, a.UserName AS AuthorName FROM tblblog b LEFT JOIN admin a ON b.AuthorId=a.id ORDER BY b.id DESC")->fetchAll(PDO::FETCH_OBJ);
}catch(Exception $e){ $posts=[]; }
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div style="margin-bottom:16px;"><a href="add-blog.php" class="btn-v6 primary"><i class="fa fa-plus"></i> Add Blog Post</a></div>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Title</th><th>Author</th><th>Published</th><th>Updated</th><th>Related</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($posts)): ?>
  <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">No blog posts yet. <a href="add-blog.php">Add one</a>.</td></tr>
  <?php else: $i=1; foreach($posts as $p): ?>
  <tr>
    <td><?=$i++?></td>
    <td><?=htmlspecialchars($p->Title??'—')?></td>
    <td><?=htmlspecialchars($p->AuthorName??'Admin')?></td>
    <td><?= !empty($p->CreatedAt) ? date('d M Y', strtotime($p->CreatedAt)) : '—' ?></td>
    <td><?= !empty($p->UpdatedAt) ? date('d M Y', strtotime($p->UpdatedAt)) : '<span style="color:#c7c7c7;">—</span>' ?></td>
    <td style="font-size:12px;">
      <?php if (!empty($p->RelatedDestination)): ?><span style="display:inline-block;background:#eafaf1;color:#1a8754;padding:2px 8px;border-radius:12px;margin:0 4px 2px 0;"><?=htmlspecialchars($p->RelatedDestination)?></span><?php endif; ?>
      <?php if (!empty($p->RelatedPackageId)): ?><span style="display:inline-block;background:#e3f2fd;color:#1976d2;padding:2px 8px;border-radius:12px;margin:0 4px 2px 0;">Pkg #<?=(int)$p->RelatedPackageId?></span><?php endif; ?>
      <?php if (empty($p->RelatedDestination) && empty($p->RelatedPackageId)): ?><span style="color:#c7c7c7;">—</span><?php endif; ?>
    </td>
    <td style="display:flex;gap:5px;">
      <a href="edit-blog.php?id=<?=$p->id?>" class="action-btn btn-edit"><i class="fa fa-edit"></i> Edit</a>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$p->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete this post?')"><i class="fa fa-trash"></i></button></form>
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

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
<title>TMS | TMS Admin | Add Blog Post</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<?php

$msg = ''; $error = '';
$adminQ = $dbh->query("SELECT id,UserName as name FROM admin LIMIT 1");
$admin = $adminQ->fetch(PDO::FETCH_OBJ);

if (isset($_POST['save_post'])) {
    tms_csrf_validate();
    $title = tms_clean($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $tag = tms_clean($_POST['tag'] ?? '');
    $authorId = $admin ? (int)$admin->id : 1;

    if (empty($title) || empty($content)) { $error = 'Title and content are required.'; }
    else {
        $cover = '';
        if (!empty($_FILES['cover']['name'])) {
            $allowedCoverExt = ['jpg','jpeg','png','webp'];
            $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
            $maxCoverSize = 5 * 1024 * 1024;
            // FILE UPLOAD SECURITY (master prompt item 45): this silently
            // dropped the cover image with no size cap and no real
            // content-type check if the extension looked right -- brought
            // in line with the other image-upload endpoints.
            if (!in_array($ext, $allowedCoverExt)) {
                $error = 'Only JPG, JPEG, PNG, WEBP cover images are allowed.';
            } elseif (($_FILES['cover']['size'] ?? 0) > $maxCoverSize) {
                $error = 'Cover image must be under 5 MB.';
            } else {
                $sigCheck = tms_validate_upload_signature($_FILES['cover']['tmp_name'], $allowedCoverExt);
                if (!$sigCheck['ok']) {
                    $error = $sigCheck['error'];
                } else {
                    $cover = 'blog_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    @mkdir('blogimages', 0777, true);
                    move_uploaded_file($_FILES['cover']['tmp_name'], "blogimages/$cover");
                    tms_compress_uploaded_image("blogimages/$cover");
                }
            }
        }
        // BUG FIX (item 45 follow-up): the INSERT + success message used to
        // run unconditionally here, even when the cover-image validation
        // above had just set $error -- so a rejected upload (bad extension,
        // oversized, or failed signature check) would still publish the
        // post (with an empty CoverImage) while showing a contradictory
        // error + "Post published!" pair. Now only publishes when the
        // upload step didn't fail.
        if (!$error) {
            $dbh->prepare("INSERT INTO tblblog(Title,Content,Tag,CoverImage,AuthorId) VALUES(:t,:c,:tg,:ci,:ai)")
                ->execute([':t'=>$title,':c'=>$content,':tg'=>$tag,':ci'=>$cover,':ai'=>$authorId]);
            // Audit log coverage (Round 52, master prompt item — only 4 of 34
            // admin pages logged to tblactivity_log before this round).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Blog Post Created', "Title: \"$title\"");
            $msg = 'Post published!';
        }
    }
}
?>





<?php if ($msg): ?><div class="alert-v6 success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-v6 danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card">
  <div class="card-header"><h3>New Blog Post</h3></div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?php tms_csrf_field(); ?>
      <div class="form-group"><label for="blogTitleField">Title *</label><input type="text" name="title" id="blogTitleField" class="form-control" required></div>
      <div class="row">
        <div class="col-md-6"><div class="form-group"><label for="blogTagField">Tag / Category</label><input type="text" name="tag" id="blogTagField" class="form-control" placeholder="e.g. Travel Tips, Destination"></div></div>
        <div class="col-md-6"><div class="form-group"><label for="blogCoverField">Cover Image</label><input type="file" name="cover" id="blogCoverField" class="form-control" accept="image/*"></div></div>
      </div>
      <div class="form-group"><label for="blogContentField">Content *</label><textarea name="content" id="blogContentField" class="form-control" rows="12" required></textarea></div>
      <button name="save_post" class="btn-v6 success"><i class="fa fa-paper-plane"></i> Publish Post</button>
      <a href="manage-blog.php" class="btn-v6 ghost">Cancel</a>
    </form>
  </div>
</div>
</div>

</div></div>

</div>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

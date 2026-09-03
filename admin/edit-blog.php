<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | TMS Admin | Edit Blog Post</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="css/admin-v6.css" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<?php

$msg = ''; $error = '';
$id = tms_int($_GET['id'] ?? 0, 1);
$post = $dbh->prepare("SELECT * FROM tblblog WHERE id=:id LIMIT 1");
$post->execute([':id'=>$id]);
$post = $post->fetch(PDO::FETCH_OBJ);
if (!$post) { header('Location: manage-blog.php'); exit; }

if (isset($_POST['save_post'])) {
    tms_csrf_validate();
    $title   = tms_clean($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $tag     = tms_clean($_POST['tag'] ?? '');
    if (empty($title)||empty($content)) { $error='Title and content required.'; }
    else {
        $cover = $post->CoverImage;
        if (!empty($_FILES['cover']['name'])) {
            $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','webp'])) {
                $cover = 'blog_'.time().'.'.$ext;
                @mkdir('blogimages',0777,true);
                move_uploaded_file($_FILES['cover']['tmp_name'],"blogimages/$cover");
                tms_compress_uploaded_image("blogimages/$cover");
            }
        }
        $dbh->prepare("UPDATE tblblog SET Title=:t,Content=:c,Tag=:tg,CoverImage=:ci WHERE id=:id")
            ->execute([':t'=>$title,':c'=>$content,':tg'=>$tag,':ci'=>$cover,':id'=>$id]);
        $msg='Post updated!';
        $post = $dbh->prepare("SELECT * FROM tblblog WHERE id=:id LIMIT 1");
        $post->execute([':id'=>$id]);
        $post = $post->fetch(PDO::FETCH_OBJ);
    }
}
?>





<?php if ($msg): ?><div class="alert-v6 success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-v6 danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card">
  <div class="card-header"><h3>Edit Blog Post</h3></div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?php tms_csrf_field(); ?>
      <div class="form-group"><label>Title *</label><input type="text" name="title" class="form-control" value="<?= htmlspecialchars($post->Title) ?>" required></div>
      <div class="row">
        <div class="col-md-6"><div class="form-group"><label>Tag</label><input type="text" name="tag" class="form-control" value="<?= htmlspecialchars($post->Tag??'') ?>"></div></div>
        <div class="col-md-6"><div class="form-group"><label>Cover Image (leave blank to keep current)</label><input type="file" name="cover" class="form-control" accept="image/*"></div></div>
      </div>
      <div class="form-group"><label>Content *</label><textarea name="content" class="form-control" rows="12" required><?= htmlspecialchars($post->Content) ?></textarea></div>
      <button name="save_post" class="btn-v6 success"><i class="fa fa-save"></i> Update Post</button>
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

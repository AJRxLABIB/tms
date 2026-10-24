<?php
require_once __DIR__ . '/../backend/session-boot.php';
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
<title>TMS | TMS Admin | Edit Blog Post</title>
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
$id = tms_int($_GET['id'] ?? 0, 1);
$post = $dbh->prepare("SELECT * FROM tblblog WHERE id=:id LIMIT 1");
$post->execute([':id'=>$id]);
$post = $post->fetch(PDO::FETCH_OBJ);
if (!$post) { header('Location: manage-blog.php'); exit; }

// Round 82 (master prompt item 54): same real-data pickers as add-blog.php.
$blogDestOptions = $dbh->query("SELECT DISTINCT Country FROM tbltourpackages WHERE Country IS NOT NULL AND Country<>'' ORDER BY Country ASC")->fetchAll(PDO::FETCH_COLUMN);
$blogPkgOptions  = $dbh->query("SELECT PackageId, PackageName FROM tbltourpackages WHERE Status=1 ORDER BY PackageName ASC")->fetchAll(PDO::FETCH_OBJ);

if (isset($_POST['save_post'])) {
    tms_csrf_validate();
    $title   = tms_clean($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $tag     = tms_clean($_POST['tag'] ?? '');
    $sources = trim($_POST['sources'] ?? '');
    $relatedDestination = tms_clean($_POST['related_destination'] ?? '');
    $relatedPackageId = tms_int($_POST['related_package_id'] ?? 0, 0);
    if ($relatedDestination !== '' && !in_array($relatedDestination, $blogDestOptions, true)) {
        $relatedDestination = '';
    }
    if ($relatedPackageId > 0) {
        $pkgCheck = $dbh->prepare("SELECT COUNT(*) FROM tbltourpackages WHERE PackageId=:pid AND Status=1");
        $pkgCheck->execute([':pid' => $relatedPackageId]);
        if (!$pkgCheck->fetchColumn()) { $relatedPackageId = 0; }
    }
    if (empty($title)||empty($content)) { $error='Title and content required.'; }
    else {
        $cover = $post->CoverImage;
        if (!empty($_FILES['cover']['name'])) {
            // FILE UPLOAD SECURITY (master prompt item 45): this used to
            // trust the extension alone (no real content-type check) with
            // no size cap, and silently kept the old cover image with no
            // error message when the extension looked wrong -- inconsistent
            // with the other upload endpoints and confusing for admins.
            // Brought in line with add-blog.php's pattern.
            $allowedCoverExt = ['jpg','jpeg','png','webp'];
            $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
            $maxCoverSize = 5 * 1024 * 1024;
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
                    @mkdir('blogimages',0777,true);
                    move_uploaded_file($_FILES['cover']['tmp_name'],"blogimages/$cover");
                    tms_compress_uploaded_image("blogimages/$cover");
                }
            }
        }
        // BUG FIX (item 45 follow-up, same pattern as add-blog.php): only
        // run the UPDATE when the cover-image validation above didn't just
        // set $error -- otherwise a rejected upload would still overwrite
        // the post's title/content while showing an error, or (worse) could
        // have already blanked CoverImage before this fix existed.
        if (!$error) {
            // UpdatedAt is not set explicitly here -- the column's own
            // ON UPDATE CURRENT_TIMESTAMP definition (see backend/config.php's
            // tms_ensure_columns() call, Round 82) bumps it automatically
            // whenever any UPDATE touches this row, the same way tblpages's
            // existing UpdatedAt column already works.
            $dbh->prepare("UPDATE tblblog SET Title=:t,Content=:c,Tag=:tg,CoverImage=:ci,Sources=:src,RelatedDestination=:rd,RelatedPackageId=:rp WHERE id=:id")
                ->execute([
                    ':t'=>$title, ':c'=>$content, ':tg'=>$tag, ':ci'=>$cover,
                    ':src'=>$sources !== '' ? $sources : null,
                    ':rd'=>$relatedDestination !== '' ? $relatedDestination : null,
                    ':rp'=>$relatedPackageId > 0 ? $relatedPackageId : null,
                    ':id'=>$id,
                ]);
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Blog Post Edited', "id=$id, Title: \"$title\"");
            $msg='Post updated!';
            $post = $dbh->prepare("SELECT * FROM tblblog WHERE id=:id LIMIT 1");
            $post->execute([':id'=>$id]);
            $post = $post->fetch(PDO::FETCH_OBJ);
        }
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
      <div class="form-group"><label for="blogTitleField">Title *</label><input type="text" id="blogTitleField" name="title" class="form-control" value="<?= htmlspecialchars($post->Title) ?>" required></div>
      <div class="row">
        <div class="col-md-6"><div class="form-group"><label for="blogTagField">Tag</label><input type="text" id="blogTagField" name="tag" class="form-control" value="<?= htmlspecialchars($post->Tag??'') ?>"></div></div>
        <div class="col-md-6"><div class="form-group"><label for="blogCoverField">Cover Image (leave blank to keep current)</label><input type="file" id="blogCoverField" name="cover" class="form-control" accept="image/*"></div></div>
      </div>
      <div class="form-group"><label for="blogContentField">Content *</label><textarea id="blogContentField" name="content" class="form-control" rows="12" required><?= htmlspecialchars($post->Content) ?></textarea></div>
      <div class="row">
        <div class="col-md-6">
          <div class="form-group">
            <label for="blogRelatedDestField">Related Destination <small style="font-weight:400;color:var(--text-muted);">(optional)</small></label>
            <select id="blogRelatedDestField" name="related_destination" class="form-control">
              <option value="">— None —</option>
              <?php foreach ($blogDestOptions as $c): ?>
              <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>" <?= ($post->RelatedDestination ?? '')===$c?'selected':'' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-group">
            <label for="blogRelatedPkgField">Related Package <small style="font-weight:400;color:var(--text-muted);">(optional)</small></label>
            <select id="blogRelatedPkgField" name="related_package_id" class="form-control">
              <option value="">— None —</option>
              <?php foreach ($blogPkgOptions as $p): ?>
              <option value="<?= (int)$p->PackageId ?>" <?= (int)($post->RelatedPackageId ?? 0)===(int)$p->PackageId?'selected':'' ?>><?= htmlspecialchars($p->PackageName, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label for="blogSourcesField">Sources <small style="font-weight:400;color:var(--text-muted);">(optional — one URL or citation per line)</small></label>
        <textarea id="blogSourcesField" name="sources" class="form-control" rows="3" placeholder="e.g. https://example.gov/visa-requirements"><?= htmlspecialchars($post->Sources ?? '') ?></textarea>
      </div>
      <?php if (!empty($post->UpdatedAt)): ?>
      <p style="font-size:12px;color:var(--text-muted);">Last updated: <?= date('d M Y, g:i a', strtotime($post->UpdatedAt)) ?></p>
      <?php endif; ?>
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

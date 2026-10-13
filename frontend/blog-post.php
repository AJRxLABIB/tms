<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see backend/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.

$id = tms_int($_GET['id'] ?? 0, 1);
$q = $dbh->prepare("SELECT b.*,a.UserName as AuthorName FROM tblblog b LEFT JOIN admin a ON b.AuthorId=a.id WHERE b.id=:id LIMIT 1");
$q->execute([':id'=>$id]);
$post = $q->fetch(PDO::FETCH_OBJ);
if (!$post) { header('Location: blog.php'); exit; }

// Round 82 (master prompt item 54): resolve the related package to its real,
// current name via a fresh lookup rather than trusting any name stored at
// write time -- RelatedPackageId has no DB-level FK constraint (consistent
// with every other cross-table reference in this schema), so the package it
// points at could since have been deleted or unpublished. Only show the
// link when it still resolves to a real, currently-published package.
$relatedPackage = null;
if (!empty($post->RelatedPackageId)) {
    $rpQ = $dbh->prepare("SELECT PackageId, PackageName FROM tbltourpackages WHERE PackageId=:pid AND Status=1 LIMIT 1");
    $rpQ->execute([':pid' => $post->RelatedPackageId]);
    $relatedPackage = $rpQ->fetch(PDO::FETCH_OBJ) ?: null;
}
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | <?= htmlspecialchars($post->Title) ?></title>
<?php
// NEW (Round 20, spec item 53 — SEO): real data only — description is the
// post's own content (truncated to a plain-text excerpt, not invented),
// image is the post's own uploaded cover (omitted if it has none), and the
// JSON-LD Article fields are all pulled from the same $post row already
// loaded above.
$plainExcerpt = trim(preg_replace('/\s+/', ' ', strip_tags($post->Content)));
if (mb_strlen($plainExcerpt) > 200) { $plainExcerpt = mb_substr($plainExcerpt, 0, 197) . '...'; }
// FIX (same bug as package-details.php, caught in live testing): ?id= is
// this page's resource identifier, not a filter -- tms_seo_meta()'s default
// canonical (query string stripped) would make every blog post canonicalize
// to the same bare blog-post.php URL. Pass it explicitly, built from the
// real request path so it still works under any install subfolder.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
tms_seo_meta([
    'title'       => $post->Title,
    'description' => $plainExcerpt,
    'image'       => $post->CoverImage ? ('admin/blogimages/' . $post->CoverImage) : '',
    'type'        => 'article',
    'canonical'   => $scheme . '://' . $host . $path . '?id=' . (int)$post->id,
    'jsonld'      => array_filter([
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $post->Title,
        'datePublished' => date('c', strtotime($post->CreatedAt)),
        // Round 82 (master prompt item 54 -- "updated date"): only present
        // once a post has actually been edited since this column was added
        // (it's NULL until then, see backend/config.php's tms_ensure_columns()
        // call) -- array_filter drops the whole key rather than emitting a
        // dateModified that just duplicates datePublished for every
        // never-edited post.
        'dateModified'  => !empty($post->UpdatedAt) ? date('c', strtotime($post->UpdatedAt)) : null,
        'author'        => ['@type' => 'Person', 'name' => $post->AuthorName ?? 'TMS'],
    ]),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Blog', 'url' => 'blog.php'],
        ['label' => $post->Title],
    ],
]);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.post-hero{width:100%;max-height:420px;object-fit:cover;border-radius:12px;margin-bottom:28px;}
.post-body{max-width:820px;margin:0 auto;}
.post-body h1{font-size:30px;font-weight:800;color:#222;margin-bottom:10px;}
.post-meta{color:var(--text-muted);font-size:14px;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid #eee;}
.post-content{font-size:16px;line-height:1.9;color:#444;}
html[data-theme="dark"] .post-meta { color: var(--text-light) !important; }
html[data-theme="dark"] .post-content { color: var(--text) !important; }
.share-row{margin-top:36px;padding-top:20px;border-top:1px solid #eee;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="container" style="padding:40px 15px 60px;">
  <?php tms_render_breadcrumb_nav([
      ['label' => 'Home', 'url' => 'index.php'],
      ['label' => 'Blog', 'url' => 'blog.php'],
      ['label' => $post->Title],
  ]); ?>
  <a href="blog.php" style="color:var(--secondary);font-size:14px;"><i class="fa fa-arrow-left"></i> Back to Blog</a>
  <div class="post-body" style="margin-top:20px;">
    <?php if ($post->CoverImage): ?>
      <img src="admin/blogimages/<?= htmlspecialchars($post->CoverImage) ?>" class="post-hero" onerror="this.src='images/1.jpg'" alt="<?= htmlspecialchars($post->Title) ?>">
    <?php endif; ?>
    <?php if ($post->Tag): ?><span style="background:#e3f2fd;color:#1976d2;padding:4px 12px;border-radius:20px;font-size:13px;"><?= htmlspecialchars($post->Tag) ?></span><?php endif; ?>
    <h1 style="margin-top:12px;"><?= htmlspecialchars($post->Title) ?></h1>
    <div class="post-meta">
      <i class="fa fa-user"></i> <?= htmlspecialchars($post->AuthorName ?? 'Admin') ?>
      &nbsp;·&nbsp; <i class="fa fa-calendar"></i> Published <?= date('d M Y', strtotime($post->CreatedAt)) ?>
      <?php if (!empty($post->UpdatedAt)): ?>
      &nbsp;·&nbsp; <i class="fa fa-pencil"></i> Updated <?= date('d M Y', strtotime($post->UpdatedAt)) ?>
      <?php endif; ?>
    </div>
    <?php if (!empty($post->RelatedDestination) || $relatedPackage): ?>
    <div class="post-related-tags" style="margin-bottom:20px;">
      <?php if (!empty($post->RelatedDestination)): ?>
      <a href="destinations.php?country=<?= urlencode($post->RelatedDestination) ?>" style="display:inline-block;background:#eafaf1;color:#1a8754;padding:5px 12px;border-radius:20px;font-size:13px;margin:0 8px 8px 0;text-decoration:none;">
        <i class="fa fa-map-marker"></i> <?= htmlspecialchars($post->RelatedDestination, ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endif; ?>
      <?php if ($relatedPackage): ?>
      <a href="package-details.php?pkgid=<?= (int)$relatedPackage->PackageId ?>" style="display:inline-block;background:#e3f2fd;color:#1976d2;padding:5px 12px;border-radius:20px;font-size:13px;margin:0 8px 8px 0;text-decoration:none;">
        <i class="fa fa-suitcase"></i> <?= htmlspecialchars($relatedPackage->PackageName, ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="post-content"><?= nl2br(htmlspecialchars($post->Content)) ?></div>
    <?php if (!empty($post->Sources)): ?>
    <div class="post-sources" style="margin-top:28px;padding-top:18px;border-top:1px solid #eee;font-size:13.5px;color:var(--text-muted);">
      <strong>Sources:</strong>
      <ul style="margin:8px 0 0;padding-left:20px;">
        <?php foreach (preg_split('/\r\n|\r|\n/', trim($post->Sources)) as $srcLine): ?>
          <?php $srcLine = trim($srcLine); if ($srcLine === '') continue; ?>
          <li>
            <?php if (preg_match('#^https?://#i', $srcLine)): ?>
              <a href="<?= htmlspecialchars($srcLine, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer nofollow"><?= htmlspecialchars($srcLine, ENT_QUOTES, 'UTF-8') ?></a>
            <?php else: ?>
              <?= htmlspecialchars($srcLine, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <div class="share-row">
      <strong>Share:</strong>
      <a href="https://wa.me/?text=<?= urlencode($post->Title . ' - ' . 'https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']) ?>" target="_blank" class="btn btn-success btn-sm"><i class="fa fa-whatsapp"></i> WhatsApp</a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode('https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']) ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fa fa-facebook"></i> Facebook</a>
    </div>
  </div>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see includes/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.

$id = tms_int($_GET['id'] ?? 0, 1);
$q = $dbh->prepare("SELECT b.*,a.UserName as AuthorName FROM tblblog b LEFT JOIN admin a ON b.AuthorId=a.id WHERE b.id=:id LIMIT 1");
$q->execute([':id'=>$id]);
$post = $q->fetch(PDO::FETCH_OBJ);
if (!$post) { header('Location: blog.php'); exit; }
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
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
    'jsonld'      => [
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $post->Title,
        'datePublished' => date('c', strtotime($post->CreatedAt)),
        'author'        => ['@type' => 'Person', 'name' => $post->AuthorName ?? 'TMS'],
    ],
]);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.post-hero{width:100%;max-height:420px;object-fit:cover;border-radius:12px;margin-bottom:28px;}
.post-body{max-width:820px;margin:0 auto;}
.post-body h1{font-size:30px;font-weight:800;color:#222;margin-bottom:10px;}
.post-meta{color:#aaa;font-size:14px;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid #eee;}
.post-content{font-size:16px;line-height:1.9;color:#444;}
html[data-theme="dark"] .post-meta { color: var(--text-light) !important; }
html[data-theme="dark"] .post-content { color: var(--text) !important; }
.share-row{margin-top:36px;padding-top:20px;border-top:1px solid #eee;}
</style>
</head><body>
<?php include('includes/header.php'); ?>
<div class="container" style="padding:40px 15px 60px;">
  <a href="blog.php" style="color:var(--secondary);font-size:14px;"><i class="fa fa-arrow-left"></i> Back to Blog</a>
  <div class="post-body" style="margin-top:20px;">
    <?php if ($post->CoverImage): ?>
      <img src="admin/blogimages/<?= htmlspecialchars($post->CoverImage) ?>" class="post-hero" onerror="this.src='images/1.jpg'">
    <?php endif; ?>
    <?php if ($post->Tag): ?><span style="background:#e3f2fd;color:#1976d2;padding:4px 12px;border-radius:20px;font-size:13px;"><?= htmlspecialchars($post->Tag) ?></span><?php endif; ?>
    <h1 style="margin-top:12px;"><?= htmlspecialchars($post->Title) ?></h1>
    <div class="post-meta">
      <i class="fa fa-user"></i> <?= htmlspecialchars($post->AuthorName ?? 'Admin') ?>
      &nbsp;·&nbsp; <i class="fa fa-calendar"></i> <?= date('d M Y', strtotime($post->CreatedAt)) ?>
    </div>
    <div class="post-content"><?= nl2br(htmlspecialchars($post->Content)) ?></div>
    <div class="share-row">
      <strong>Share:</strong>
      <a href="https://wa.me/?text=<?= urlencode($post->Title . ' - ' . 'https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']) ?>" target="_blank" class="btn btn-success btn-sm"><i class="fa fa-whatsapp"></i> WhatsApp</a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode('https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']) ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fa fa-facebook"></i> Facebook</a>
    </div>
  </div>
</div>
<?php include('includes/footer.php'); ?>
</body></html>

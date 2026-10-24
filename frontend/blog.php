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

$search = tms_clean($_GET['search'] ?? '');
$where = $search ? "WHERE Title LIKE :s OR Content LIKE :s2" : "";
$params = $search ? [':s'=>"%$search%",':s2'=>"%$search%"] : [];

// PERFORMANCE FIX (Section 9 audit): this loaded every blog post ever
// published on a single request, with a JOIN and no LIMIT — fine with a
// handful of posts, but it doesn't stay fine.
$blogPerPage = 9; // 3 columns x 3 rows, matches the col-md-4 grid below
$blogPage    = max(1, (int)($_GET['p'] ?? 1));

$countQ = $dbh->prepare("SELECT COUNT(*) FROM tblblog b $where");
$countQ->execute($params);
$blogTotal = (int)$countQ->fetchColumn();
$blogTotalPages = max(1, (int)ceil($blogTotal / $blogPerPage));
$blogPage = min($blogPage, $blogTotalPages);
$blogOffset = ($blogPage - 1) * $blogPerPage;

$q = $dbh->prepare("SELECT b.*,a.UserName as AuthorName FROM tblblog b LEFT JOIN admin a ON b.AuthorId=a.id $where ORDER BY b.id DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) { $q->bindValue($k, $v, PDO::PARAM_STR); }
$q->bindValue(':lim', $blogPerPage, PDO::PARAM_INT);
$q->bindValue(':off', $blogOffset, PDO::PARAM_INT);
$q->execute();
$posts = $q->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Travel Blog</title>
<?php
// SEO meta broadening (master prompt item 53, extending Round 20's work on
// destinations.php/blog-post.php/package-details.php to this listing page):
// same plain-listing pattern as destinations.php -- canonical intentionally
// strips the query string (default) so a search or page-number doesn't
// count as separate duplicate content, only title/description vary.
$blogSeoTitle = $search !== '' ? ('Blog search: ' . $search) : 'Travel Blog';
$blogSeoDesc  = $blogTotal > 0
    ? "Browse {$blogTotal} travel articles and guides."
    : 'Travel articles and guides.';
tms_seo_meta([
    'title'       => $blogSeoTitle,
    'description' => $blogSeoDesc,
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Blog'],
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
.page-title{background:linear-gradient(135deg,#2ecc71,#27ae60);color:#fff;padding:40px 0;margin-bottom:30px;}
.blog-card{background:var(--white);border-radius:12px;box-shadow:0 3px 20px rgba(0,0,0,0.08);overflow:hidden;margin-bottom:24px;transition:transform 0.2s;}
.blog-card:hover{transform:translateY(-4px);}
.blog-card img{width:100%;height:200px;object-fit:cover;}
.blog-body{padding:18px;}
.blog-body h4{font-size:17px;font-weight:700;color:var(--text);margin:0 0 8px;}
.blog-meta{color:var(--text-muted);font-size:12px;margin-bottom:10px;}
html[data-theme="dark"] .blog-meta { color: var(--text-light) !important; }
html[data-theme="dark"] .blog-body p { color: var(--text-muted) !important; }
.blog-body p{color:#666;font-size:14px;line-height:1.7;}
.blog-tag{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;margin-bottom:8px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
      <h2 style="margin:0;"><i class="fa fa-pencil"></i> <?php echo tms_t('heading_travel_blog','Travel Blog &amp; Tips'); ?></h2>
      <form method="get" style="display:flex;gap:8px;">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="<?php echo tms_t('placeholder_search_articles','Search articles...'); ?>" class="form-control" style="width:220px;">
        <button class="btn btn-light" aria-label="<?= htmlspecialchars(tms_t("btn_search","Search"), ENT_QUOTES, "UTF-8") ?>"><i class="fa fa-search" aria-hidden="true"></i></button>
      </form>
    </div>
  </div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php tms_render_breadcrumb_nav([
    ['label' => 'Home', 'url' => 'index.php'],
    ['label' => 'Blog'],
]); ?>
<?php if (count($posts) === 0): ?>
  <div style="text-align:center;padding:60px 0;color:var(--text-muted);">
    <i class="fa fa-pencil-square-o" style="font-size:56px;"></i>
    <h3 style="margin-top:16px;"><?php echo tms_t('msg_no_articles_yet','No articles yet'); ?></h3>
    <p><?php echo tms_t('dest_no_articles_subtitle','Check back soon for travel tips and destination guides.'); ?></p>
  </div>
<?php else: ?>
<div class="row">
<?php foreach ($posts as $p): ?>
<div class="col-md-4 col-sm-6">
  <div class="blog-card">
    <?php $blogPh = tms_placeholder_image((string)($p->Tag ?? ''), (string)$p->id); ?>
    <?php if ($p->CoverImage): ?>
      <img class="blog-cover" loading="lazy" width="400" height="200" src="admin/blogimages/<?= htmlspecialchars($p->CoverImage) ?>"<?= tms_img_onerror($blogPh) ?> alt="<?= htmlspecialchars($p->Title, ENT_QUOTES, 'UTF-8') ?>">
    <?php else: ?>
      <img class="blog-cover" loading="lazy" width="400" height="200" src="<?= htmlspecialchars($blogPh, ENT_QUOTES, 'UTF-8') ?>" alt="">
    <?php endif; ?>
    <div class="blog-body">
      <?php if ($p->Tag): ?><span class="blog-tag"><?= htmlspecialchars($p->Tag) ?></span><?php endif; ?>
      <h4><?= htmlspecialchars($p->Title) ?></h4>
      <div class="blog-meta"><i class="fa fa-user"></i> <?= htmlspecialchars($p->AuthorName ?? tms_t('default_author_admin','Admin')) ?> &nbsp;·&nbsp; <i class="fa fa-calendar"></i> <?= date('d M Y', strtotime($p->CreatedAt)) ?></div>
      <p><?= htmlspecialchars(substr(strip_tags($p->Content), 0, 140)) ?>...</p>
      <a href="blog-post.php?id=<?= (int)$p->id ?>" class="btn btn-primary btn-sm" style="margin-top:6px;"><?php echo tms_t('link_read_more','Read More &rarr;'); ?></a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php if ($blogTotalPages > 1): ?>
<div class="blog-pager" style="display:flex;justify-content:center;gap:6px;margin-top:30px;flex-wrap:wrap;" role="navigation" aria-label="Blog pages">
    <?php for ($pn = 1; $pn <= $blogTotalPages; $pn++):
        $qs = http_build_query(array_merge($_GET, ['p' => $pn])); ?>
    <a href="?<?= $qs ?>" class="<?= $pn === $blogPage ? 'is-current' : '' ?>"<?= $pn === $blogPage ? ' aria-current="page"' : '' ?>><?= $pn ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

<?php
session_start();
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

// ─── Filter params ──────────────────────────────────────────────────────────
$country  = tms_clean($_GET['country']  ?? '');
$region   = tms_clean($_GET['region']   ?? '');
$category = tms_clean($_GET['category'] ?? '');
$search   = tms_normalize_quotes(tms_clean($_GET['search']   ?? ''));
$perPage  = 12;
$page     = tms_int($_GET['page'] ?? 1, 1, 9999);
$offset   = ($page - 1) * $perPage;

// ─── Cascading dropdown data ────────────────────────────────────────────────
$countries = $dbh->query("SELECT DISTINCT Country FROM tbltourpackages WHERE Country IS NOT NULL AND Country<>'' ORDER BY (Country='Bangladesh') DESC, Country ASC")->fetchAll(PDO::FETCH_COLUMN);

$regions = [];
if ($country !== '') {
    $rq = $dbh->prepare("SELECT DISTINCT Region FROM tbltourpackages WHERE Country=:c AND Region IS NOT NULL AND Region<>'' ORDER BY Region ASC");
    $rq->execute([':c' => $country]);
    $regions = $rq->fetchAll(PDO::FETCH_COLUMN);
}

$categories = $dbh->query("SELECT DISTINCT PackageType FROM tbltourpackages WHERE PackageType IS NOT NULL AND PackageType<>'' ORDER BY PackageType ASC")->fetchAll(PDO::FETCH_COLUMN);

// ─── Build main query ───────────────────────────────────────────────────────
$where  = [];
$params = [];
if ($country !== '')  { $where[] = "Country = :country";   $params[':country']  = $country; }
if ($region !== '')   { $where[] = "Region = :region";     $params[':region']   = $region; }
if ($category !== '') { $where[] = "PackageType = :cat";   $params[':cat']      = $category; }
if ($search !== '') {
    $where[] = "(PackageName LIKE :s1 OR PackageLocation LIKE :s2 OR Country LIKE :s3 OR Region LIKE :s4)";
    $params[':s1'] = $params[':s2'] = $params[':s3'] = $params[':s4'] = '%' . $search . '%';
}
$whereSQL   = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$hasFilters = ($country !== '' || $region !== '' || $category !== '' || $search !== '');

$countQ = $dbh->prepare("SELECT COUNT(*) FROM tbltourpackages $whereSQL");
$countQ->execute($params);
$totalRows  = (int)$countQ->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

$sql = "SELECT * FROM tbltourpackages $whereSQL ORDER BY (Country='Bangladesh') DESC, PackageId DESC LIMIT :limit OFFSET :offset";
$q = $dbh->prepare($sql);
foreach ($params as $k => $v) { $q->bindValue($k, $v, PDO::PARAM_STR); }
$q->bindValue(':limit', $perPage, PDO::PARAM_INT);
$q->bindValue(':offset', $offset, PDO::PARAM_INT);
$q->execute();
$results = $q->fetchAll(PDO::FETCH_OBJ);

// ─── Featured, Popular, Recently Viewed (only shown when no filters active) ──
$featured = [];
$popular  = [];
$recent   = [];
if (!$hasFilters) {
    // Note: IsFeatured is indexed and only a small subset of rows match, so we fetch
    // a modest pool and shuffle in PHP rather than ORDER BY RAND() (which forces a
    // full filesort even on an indexed column and gets expensive as the catalog grows).
    $featuredPool = $dbh->query("SELECT * FROM tbltourpackages WHERE IsFeatured=1 ORDER BY PackageId DESC LIMIT 30")->fetchAll(PDO::FETCH_OBJ);
    shuffle($featuredPool);
    $featured = array_slice($featuredPool, 0, 6);
    $popular  = $dbh->query("SELECT * FROM tbltourpackages ORDER BY ViewCount DESC, AvgRating DESC LIMIT 8")->fetchAll(PDO::FETCH_OBJ);

    if (!empty($_SESSION['recently_viewed'])) {
        $ids = array_map('intval', $_SESSION['recently_viewed']);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $rq2 = $dbh->prepare("SELECT * FROM tbltourpackages WHERE PackageId IN ($in)");
        $rq2->execute($ids);
        $byId = [];
        foreach ($rq2->fetchAll(PDO::FETCH_OBJ) as $row) { $byId[(int)$row->PackageId] = $row; }
        foreach ($ids as $id) { if (isset($byId[$id])) $recent[] = $byId[$id]; }
    }
}

function destUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return 'destinations.php?' . http_build_query($params);
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Explore Destinations</title>
<?php
// NEW (Round 20, spec item 53 — SEO): canonical intentionally always points
// at the base listing URL (query string stripped by tms_seo_meta's default)
// so every filter/search combination doesn't count as separate duplicate
// content -- only the title/description vary with the active filter, for a
// nicer social-share preview.
$seoTitleParts = array_filter([$category ?: null, $region ?: null, $country ?: null]);
tms_seo_meta([
    'title'       => $seoTitleParts ? ('Destinations: ' . implode(', ', $seoTitleParts)) : 'Explore Destinations',
    'description' => $totalRows > 0
        ? "Browse {$totalRows} tour packages across our destinations."
        : 'Browse tour packages across our destinations.',
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Destinations'],
    ],
]);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.dest-toolbar{background:var(--white);border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:20px 25px;margin-bottom:26px;}
.dest-toolbar .form-control{border:1.5px solid #e0e0e0;border-radius:7px;height:40px;font-size:14px;}
.dest-toolbar .form-control:focus{border-color:var(--secondary);box-shadow:none;}
.dest-toolbar label{font-size:12.5px;font-weight:600;color:#555;margin-bottom:4px;display:block;}
.btn-search{background:var(--secondary);color:#fff;border:none;border-radius:7px;height:40px;padding:0 22px;font-weight:600;}
.btn-search:hover{background:#2d6a97;color:#fff;}
.btn-reset{background:#f5f5f5;color:#555;border:1px solid #ddd;border-radius:7px;height:40px;padding:0 16px;font-weight:600;}
.btn-reset:hover{background:#e8e8e8;}
.cat-pills{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 28px;}
.cat-pill{background:var(--white);border:1.5px solid #e0e8f0;border-radius:22px;padding:7px 16px;font-size:13px;
    color:#555;font-weight:600;transition:.2s;text-decoration:none;display:inline-block;}
.cat-pill:hover{border-color:var(--secondary);color:var(--secondary);text-decoration:none;}
.cat-pill.active{background:var(--secondary);border-color:var(--secondary);color:#fff;}
.dest-section-title{font-weight:700;font-size:20px;margin:34px 0 16px;display:flex;align-items:center;gap:8px;}
.dest-scroll{display:flex;gap:16px;overflow-x:auto;padding-bottom:10px;-webkit-overflow-scrolling:touch;}
.dest-scroll::-webkit-scrollbar{height:6px;}
.dest-scroll::-webkit-scrollbar-thumb{background:#d8e2ec;border-radius:10px;}
.dest-card{flex:0 0 230px;background:var(--white);border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);
    overflow:hidden;transition:.2s;text-decoration:none;color:inherit;display:block;}
.dest-card:hover{box-shadow:0 6px 22px rgba(0,0,0,.13);transform:translateY(-3px);text-decoration:none;color:inherit;}
.dest-card img{width:100%;height:130px;object-fit:cover;}
.dest-card .body{padding:12px 14px;}
.dest-card h6{font-weight:700;font-size:14px;margin:0 0 4px;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.dest-card .loc{font-size:12px;color:#888;margin:0;}
.dest-card .price{font-size:13px;color:var(--secondary);font-weight:700;margin-top:6px;}
.dest-badge{position:absolute;background:#f0ad4e;color:#fff;font-size:10px;font-weight:700;padding:3px 8px;
    border-radius:4px;margin:8px;}
.grid-card{background:var(--white);border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);overflow:hidden;
    transition:.2s;margin-bottom:22px;}
.grid-card:hover{box-shadow:0 6px 22px rgba(0,0,0,.13);transform:translateY(-2px);}
.grid-card img{width:100%;height:160px;object-fit:cover;}
.grid-card .body{padding:14px 16px;}
.grid-card h5{font-weight:700;font-size:15px;margin:0 0 6px;}
.grid-card .loc{font-size:12.5px;color:#888;margin:0 0 8px;}
.grid-card .price{color:var(--secondary);font-weight:700;font-size:14px;}
.results-info{color:#888;font-size:13px;margin-bottom:16px;}
.no-results{text-align:center;padding:60px 20px;color:#aaa;}
.no-results i{font-size:50px;display:block;margin-bottom:16px;}
html[data-theme="dark"] .dest-toolbar label,
html[data-theme="dark"] .cat-pill { color: var(--text-muted) !important; }
html[data-theme="dark"] .btn-reset { background: #232b37 !important; color: var(--text-muted) !important; border-color: #333f4d !important; }
html[data-theme="dark"] .dest-card .loc,
html[data-theme="dark"] .grid-card .loc,
html[data-theme="dark"] .results-info { color: var(--text-muted) !important; }
html[data-theme="dark"] .no-results { color: var(--text-light) !important; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="banner-3">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo tms_t('dest_hero_title','Explore Destinations'); ?></h1>
        <p style="color:#eaf2fb;"><?php echo tms_t('dest_hero_subtitle','Browse by country, region and category — Bangladesh destinations shown first'); ?></p>
    </div>
</div>

<div class="rooms">
    <div class="container">
        <?php tms_render_breadcrumb_nav([
            ['label' => 'Home', 'url' => 'index.php'],
            ['label' => 'Destinations'],
        ]); ?>

        <!-- ── Cascading destination picker ── -->
        <div class="dest-toolbar wow fadeInDown" data-wow-delay=".1s">
            <form method="get" action="destinations.php" id="destForm">
                <div class="row" style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px;">
                    <div style="flex:1;min-width:160px;">
                        <label for="destCountry"><i class="fa fa-flag"></i> <?php echo tms_t('label_country','Country'); ?></label>
                        <select name="country" id="destCountry" class="form-control" onchange="document.getElementById('region_hidden').value='';this.form.submit()">
                            <option value=""><?php echo tms_t('dest_all_countries','All Countries'); ?></option>
                            <?php foreach ($countries as $c): ?>
                            <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $country===$c?'selected':''; ?>>
                                <?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?><?php echo $c==='Bangladesh'?' ⭐':''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:1;min-width:160px;">
                        <label for="region_hidden"><i class="fa fa-map"></i> <?php echo tms_t('label_region','State / Division'); ?></label>
                        <select name="region" id="region_hidden" class="form-control" onchange="this.form.submit()" <?php echo $country===''?'disabled':''; ?>>
                            <option value=""><?php echo tms_t('dest_all_regions','All Regions'); ?></option>
                            <?php foreach ($regions as $r): ?>
                            <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $region===$r?'selected':''; ?>>
                                <?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:2;min-width:220px;">
                        <label for="destSearch"><i class="fa fa-search"></i> <?php echo tms_t('label_search_destination','Search Destination'); ?></label>
                        <input type="text" name="search" id="destSearch" class="form-control" placeholder="<?php echo htmlspecialchars(tms_t('dest_search_placeholder',"Try 'beach', 'Cox's Bazar', 'Paris'…"), ENT_QUOTES, 'UTF-8'); ?>"
                               value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn-search"><i class="fa fa-search"></i> <?php echo tms_t('btn_search','Search'); ?></button>
                        <a href="destinations.php" class="btn-reset"><?php echo tms_t('btn_reset','Reset'); ?></a>
                    </div>
                </div>
            </form>
        </div>

        <!-- ── Category filter pills ── -->
        <div class="cat-pills wow fadeIn">
            <a href="<?php echo destUrl(['category'=>'','page'=>1]); ?>" class="cat-pill <?php echo $category===''?'active':''; ?>"><?php echo tms_t('dest_all_categories','All Categories'); ?></a>
            <?php foreach ($categories as $cat): ?>
            <a href="<?php echo destUrl(['category'=>$cat,'page'=>1]); ?>" class="cat-pill <?php echo $category===$cat?'active':''; ?>">
                <?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$hasFilters): ?>

            <?php if (!empty($featured)): ?>
            <div class="dest-section-title"><i class="fa fa-certificate" style="color:#f0ad4e"></i> <?php echo tms_t('heading_featured_destinations','Featured Destinations'); ?></div>
            <div class="dest-scroll wow fadeInUp">
                <?php foreach ($featured as $f): ?>
                <a href="package-details.php?pkgid=<?php echo (int)$f->PackageId; ?>" class="dest-card">
                    <img loading="lazy" src="<?php echo htmlspecialchars(tms_package_image_src($f->PackageImage), ENT_QUOTES, 'UTF-8'); ?>"<?php echo tms_responsive_img_attrs(tms_package_image_src($f->PackageImage)); ?>
                         onerror="this.src='images/no-image-placeholder.jpg'" alt="<?php echo htmlspecialchars($f->PackageName, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="body">
                        <h6><?php echo htmlspecialchars($f->PackageName, ENT_QUOTES, 'UTF-8'); ?></h6>
                        <p class="loc"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($f->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="price"><?php echo tms_currency((float)$f->PackagePrice); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($popular)): ?>
            <div class="dest-section-title"><i class="fa fa-fire" style="color:#e74c3c"></i> <?php echo tms_t('heading_popular_destinations','Popular Destinations'); ?></div>
            <div class="dest-scroll wow fadeInUp">
                <?php foreach ($popular as $p): ?>
                <a href="package-details.php?pkgid=<?php echo (int)$p->PackageId; ?>" class="dest-card">
                    <img loading="lazy" src="<?php echo htmlspecialchars(tms_package_image_src($p->PackageImage), ENT_QUOTES, 'UTF-8'); ?>"<?php echo tms_responsive_img_attrs(tms_package_image_src($p->PackageImage)); ?>
                         onerror="this.src='images/no-image-placeholder.jpg'" alt="<?php echo htmlspecialchars($p->PackageName, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="body">
                        <h6><?php echo htmlspecialchars($p->PackageName, ENT_QUOTES, 'UTF-8'); ?></h6>
                        <p class="loc"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($p->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="price"><?php echo tms_currency((float)$p->PackagePrice); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($recent)): ?>
            <div class="dest-section-title"><i class="fa fa-history" style="color:var(--secondary)"></i> <?php echo tms_t('heading_recently_viewed','Recently Viewed'); ?></div>
            <div class="dest-scroll wow fadeInUp">
                <?php foreach ($recent as $rv): ?>
                <a href="package-details.php?pkgid=<?php echo (int)$rv->PackageId; ?>" class="dest-card">
                    <img loading="lazy" src="<?php echo htmlspecialchars(tms_package_image_src($rv->PackageImage), ENT_QUOTES, 'UTF-8'); ?>"<?php echo tms_responsive_img_attrs(tms_package_image_src($rv->PackageImage)); ?>
                         onerror="this.src='images/no-image-placeholder.jpg'" alt="<?php echo htmlspecialchars($rv->PackageName, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="body">
                        <h6><?php echo htmlspecialchars($rv->PackageName, ENT_QUOTES, 'UTF-8'); ?></h6>
                        <p class="loc"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($rv->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="price"><?php echo tms_currency((float)$rv->PackagePrice); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="dest-section-title"><i class="fa fa-globe" style="color:#27ae60"></i> <?php echo tms_t('heading_all_destinations','All Destinations'); ?></div>
        <?php else: ?>
            <div class="dest-section-title"><i class="fa fa-search" style="color:#27ae60"></i> <?php echo tms_t('heading_search_results','Search Results'); ?></div>
        <?php endif; ?>

        <!-- ── Results grid ── -->
        <div class="results-info">
            <strong><?php echo $totalRows; ?></strong> <?php echo tms_t('label_destinations_found','destinations found'); ?>
            &nbsp;·&nbsp; <?php echo tms_t('label_page','Page'); ?> <?php echo $page; ?> <?php echo tms_t('label_of_pagination','of'); ?> <?php echo max(1,$totalPages); ?>
        </div>

        <?php if (count($results) > 0): ?>
        <div class="row">
            <?php foreach ($results as $r): ?>
            <div class="col-md-3 col-sm-6">
                <div class="grid-card wow fadeInUp">
                    <a href="package-details.php?pkgid=<?php echo (int)$r->PackageId; ?>">
                        <img loading="lazy" src="<?php echo htmlspecialchars(tms_package_image_src($r->PackageImage), ENT_QUOTES, 'UTF-8'); ?>"<?php echo tms_responsive_img_attrs(tms_package_image_src($r->PackageImage)); ?>
                             onerror="this.src='images/no-image-placeholder.jpg'" alt="<?php echo htmlspecialchars($r->PackageName, ENT_QUOTES, 'UTF-8'); ?>">
                    </a>
                    <div class="body">
                        <h5><?php echo htmlspecialchars($r->PackageName, ENT_QUOTES, 'UTF-8'); ?></h5>
                        <p class="loc"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($r->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></p>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span class="price"><?php echo tms_currency((float)$r->PackagePrice); ?></span>
                            <a href="package-details.php?pkgid=<?php echo (int)$r->PackageId; ?>" style="font-size:12px;color:var(--secondary);font-weight:600;">
                                <?php echo tms_t('btn_view_details','View Details'); ?> <i class="fa fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div style="text-align:center;margin:20px 0 10px;">
            <ul class="pagination">
                <?php if ($page > 1): ?><li><a href="<?php echo destUrl(['page'=>$page-1]); ?>">&lsaquo; <?php echo tms_t('pagination_prev','Prev'); ?></a></li><?php endif; ?>
                <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
                    <li class="<?php echo $p===$page?'active':''; ?>"><a href="<?php echo destUrl(['page'=>$p]); ?>"><?php echo $p; ?></a></li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><li><a href="<?php echo destUrl(['page'=>$page+1]); ?>"><?php echo tms_t('pagination_next','Next'); ?> &rsaquo;</a></li><?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="no-results">
            <i class="fa fa-map-o"></i>
            <p><?php echo tms_t('dest_no_match','No destinations match your filters.'); ?></p>
            <a href="destinations.php"><?php echo tms_t('link_view_all_destinations','View all destinations'); ?></a>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include('backend/footer.php'); ?>
</body>
</html>

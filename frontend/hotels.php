<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// Public browsing page, same as package-list.php -- booking (on
// hotel-details.php) still requires sign-in.

/**
 * hotels.php — Round 37 (item 12: "Hotel System").
 *
 * A standalone, separately browsable/bookable hotel catalog -- not the
 * read-only HotelName/HotelRating text that already lived on
 * tbltourpackages. Backed by `tblhotels`, seeded from data/hotels_seed.json.
 *
 * RULE 3: this project has no real hotel supplier feed. Every listing here
 * is clearly-synthetic sample/demo data (see
 * backend/config.php::_tms_run_marketplace_migrations()'s docblock) --
 * labeled with a persistent badge and banner below, not presented as real
 * bookable-today inventory.
 */

$perPage    = 6;
$page       = tms_int($_GET['page'] ?? 1, 1, 9999);
$offset     = ($page - 1) * $perPage;
$search     = tms_normalize_quotes(tms_clean($_GET['search'] ?? ''));
$city       = tms_clean($_GET['city'] ?? '');
$minStars   = isset($_GET['stars']) && is_numeric($_GET['stars']) ? max(0, min(5, (int)$_GET['stars'])) : 0;
$maxPrice   = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? max(0, (float)$_GET['max_price']) : null;
$sortBy     = in_array($_GET['sort'] ?? '', ['price_asc','price_desc','rating'], true) ? $_GET['sort'] : 'default';

$where  = ['Status=1'];
$params = [];
if ($search !== '') {
    $where[] = "(HotelName LIKE :search OR City LIKE :search2 OR Country LIKE :search3)";
    $params[':search'] = '%'.$search.'%'; $params[':search2'] = '%'.$search.'%'; $params[':search3'] = '%'.$search.'%';
}
if ($city !== '') { $where[] = "City = :city"; $params[':city'] = $city; }
if ($minStars > 0) { $where[] = "StarRating >= :stars"; $params[':stars'] = $minStars; }
if ($maxPrice !== null) { $where[] = "PricePerNight <= :maxprice"; $params[':maxprice'] = $maxPrice; }
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$orderSQL = match ($sortBy) {
    'price_asc'  => 'ORDER BY PricePerNight ASC',
    'price_desc' => 'ORDER BY PricePerNight DESC',
    'rating'     => 'ORDER BY AvgRating DESC',
    default      => 'ORDER BY HotelId DESC',
};

$countQ = $dbh->prepare("SELECT COUNT(*) FROM tblhotels $whereSQL");
$countQ->execute($params);
$totalRows  = (int)$countQ->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

$sql = "SELECT * FROM tblhotels $whereSQL $orderSQL LIMIT :limit OFFSET :offset";
$q = $dbh->prepare($sql);
foreach ($params as $k => $v) { $q->bindValue($k, $v, PDO::PARAM_STR); }
$q->bindValue(':limit', $perPage, PDO::PARAM_INT);
$q->bindValue(':offset', $offset, PDO::PARAM_INT);
$q->execute();
$results = $q->fetchAll(PDO::FETCH_OBJ);

$cities = $dbh->query("SELECT DISTINCT City FROM tblhotels WHERE Status=1 AND City IS NOT NULL AND City <> '' ORDER BY City")->fetchAll(PDO::FETCH_COLUMN);

function hotelPageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return 'hotels.php?' . http_build_query($params);
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Hotels</title>
<?php
// SEO meta broadening (master prompt item 53): same plain-listing pattern
// as destinations.php/blog.php -- canonical strips the query string
// (default) so a filter combination isn't counted as duplicate content.
// RULE 3: no JSON-LD/structured data here on purpose -- tblhotels is
// explicitly sample/demo data (see this file's own docblock above), and
// emitting schema.org Hotel/Offer/AggregateRating markup would tell search
// engines these are real, ratable, bookable-today listings, which they are
// not. The description says so plainly instead of asserting it via markup.
tms_seo_meta([
    'title'       => 'Hotels',
    'description' => 'Browse hotels by city, star rating, and price. Sample/demo listings for the TMS hotel marketplace feature.',
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Hotels'],
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
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.pkg-toolbar{background:var(--white);border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:20px 25px;margin-bottom:20px;}
.pkg-toolbar .form-control{border:1.5px solid #e0e0e0;border-radius:7px;height:40px;font-size:14px;}
.btn-search{background:var(--secondary);color:#fff;border:none;border-radius:7px;height:40px;padding:0 22px;font-weight:600;}
.btn-reset{background:#f5f5f5;color:#555;border:1px solid #ddd;border-radius:7px;height:40px;padding:0 16px;font-weight:600;}
.results-info{color:var(--text-muted);font-size:13px;margin-bottom:16px;}
.no-results{text-align:center;padding:60px 20px;color:var(--text-muted);}
.no-results i{font-size:50px;display:block;margin-bottom:16px;}
.pagination-wrap{text-align:center;margin:30px 0 10px;}
.pagination>li>a,.pagination>li>span{color:var(--secondary);border-radius:6px!important;margin:0 2px;border:1.5px solid #d8e8f5;}
.pagination>.active>a{background:var(--secondary);border-color:var(--secondary);}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="banner-3">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo tms_t('heading_tms_hotels','TMS – Hotels'); ?></h1>
    </div>
</div>

<div class="rooms">
    <div class="container">
        <?php tms_render_breadcrumb_nav([
            ['label' => 'Home', 'url' => 'index.php'],
            ['label' => 'Hotels'],
        ]); ?>
        <div class="tms-sample-banner wow fadeIn">
            <i class="fa fa-info-circle"></i> <?php echo tms_t('dest_hotels_sample_banner','These hotel listings are <strong>sample/demo data</strong> for showcasing the TMS hotel marketplace feature -- not a live, real-world booking inventory from an actual supplier. Bookings you make are recorded for demonstration purposes.'); ?>
        </div>

        <div class="pkg-toolbar wow fadeInDown" data-wow-delay=".1s">
            <form method="get" action="hotels.php">
                <div class="row" style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px;">
                    <div class="col-md-4" style="flex:1;min-width:180px;">
                        <label for="hotelsSearch" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;"><i class="fa fa-search"></i> <?php echo tms_t('btn_search','Search'); ?></label>
                        <input type="text" name="search" id="hotelsSearch" class="form-control" placeholder="Hotel name, city, or country…"
                               value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3" style="flex:0 0 180px;">
                        <label for="hotelsCity" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;"><i class="fa fa-map-marker"></i> <?php echo tms_t('label_city','City'); ?></label>
                        <select name="city" id="hotelsCity" class="form-control">
                            <option value=""><?php echo tms_t('dest_all_cities','All Cities'); ?></option>
                            <?php foreach ($cities as $c): ?>
                            <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $city===$c?'selected':''; ?>><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2" style="flex:0 0 150px;">
                        <label for="hotelsStars" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;"><i class="fa fa-star"></i> <?php echo tms_t('label_min_stars','Min Stars'); ?></label>
                        <select name="stars" id="hotelsStars" class="form-control">
                            <option value="0"><?php echo tms_t('label_any','Any'); ?></option>
                            <?php for ($s=3; $s<=5; $s++): ?>
                            <option value="<?php echo $s; ?>" <?php echo $minStars===$s?'selected':''; ?>><?php echo $s; ?>+ <?php echo tms_t('label_stars_suffix','Stars'); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2" style="flex:0 0 170px;">
                        <label for="hotelsSort" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;"><i class="fa fa-sort"></i> <?php echo tms_t('label_sort_by','Sort By'); ?></label>
                        <select name="sort" id="hotelsSort" class="form-control">
                            <option value="default" <?php echo $sortBy==='default'?'selected':''; ?>><?php echo tms_t('sort_newest','Newest First'); ?></option>
                            <option value="price_asc" <?php echo $sortBy==='price_asc'?'selected':''; ?>><?php echo tms_t('sort_price_asc','Price: Low → High'); ?></option>
                            <option value="price_desc" <?php echo $sortBy==='price_desc'?'selected':''; ?>><?php echo tms_t('sort_price_desc','Price: High → Low'); ?></option>
                            <option value="rating" <?php echo $sortBy==='rating'?'selected':''; ?>><?php echo tms_t('sort_top_rated','Top Rated'); ?></option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn-search"><i class="fa fa-search"></i> <?php echo tms_t('btn_search','Search'); ?></button>
                        <a href="hotels.php" class="btn-reset"><?php echo tms_t('btn_reset','Reset'); ?></a>
                    </div>
                </div>
            </form>
        </div>

        <div class="results-info">
            <strong><?php echo $totalRows; ?></strong> <?php echo tms_t('label_hotels_found','hotels found'); ?>
            &nbsp;·&nbsp; <?php echo tms_t('label_page','Page'); ?> <?php echo $page; ?> <?php echo tms_t('label_of_pagination','of'); ?> <?php echo max(1,$totalPages); ?>
        </div>

        <?php if (count($results) > 0): ?>
        <?php foreach ($results as $h): ?>
        <div class="rom-btm wow fadeInUp animated" data-wow-delay=".05s">
            <div class="col-md-3 room-left">
                <span class="tms-stamp">HOTEL</span>
                <img loading="lazy" src="<?php echo htmlspecialchars($h->HotelImage ?: 'images/no-image-placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>"
                     onerror="this.src='images/no-image-placeholder.jpg'" class="img-responsive" alt="<?php echo htmlspecialchars($h->HotelName, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6 room-midle">
                <h4><?php echo htmlspecialchars($h->HotelName, ENT_QUOTES, 'UTF-8'); ?> <span class="tms-sample-badge"><i class="fa fa-flask"></i> <?php echo tms_t('badge_sample','Sample'); ?></span></h4>
                <h6><?php for ($s=1;$s<=5;$s++): ?><i class="fa fa-star<?php echo $s<=(int)$h->StarRating?'':'-o'; ?>" style="color:#f0ad4e;font-size:12px;"></i><?php endfor; ?></h6>
                <p><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlspecialchars($h->City.', '.$h->Country, ENT_QUOTES, 'UTF-8'); ?></p>
                <p><i class="fa fa-list" style="color:#27ae60"></i> <?php echo htmlspecialchars($h->Amenities ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if ((float)$h->AvgRating > 0): ?>
                <p style="margin:4px 0;"><i class="fa fa-star" style="color:#f0ad4e;font-size:13px;"></i> <span style="font-size:12px;color:var(--text-muted);"><?php echo number_format((float)$h->AvgRating,1); ?>/5</span></p>
                <?php endif; ?>
            </div>
            <div class="col-md-3 room-right">
                <h5><?php echo tms_currency((float)$h->PricePerNight); ?><span style="font-size:11px;color:var(--text-muted);font-weight:400;"> /night</span></h5>
                <a href="hotel-details.php?id=<?php echo (int)$h->HotelId; ?>" class="view"><?php echo tms_t('btn_view_book','View &amp; Book'); ?> <i class="fa fa-arrow-right"></i></a>
            </div>
            <div class="clearfix"></div>
        </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
            <ul class="pagination">
                <?php if ($page > 1): ?><li><a href="<?php echo hotelPageUrl($page-1); ?>">&lsaquo;</a></li><?php endif; ?>
                <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
                <li class="<?php echo $p===$page?'active':''; ?>"><a href="<?php echo hotelPageUrl($p); ?>"><?php echo $p; ?></a></li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><li><a href="<?php echo hotelPageUrl($page+1); ?>">&rsaquo;</a></li><?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="no-results">
            <i class="fa fa-hotel"></i>
            <h3><?php echo tms_t('msg_no_hotels_found','No hotels found'); ?></h3>
            <p><?php echo tms_t('msg_try_different_search_hotels','Try a different search or'); ?> <a href="hotels.php"><?php echo tms_t('link_view_all_hotels','view all hotels'); ?></a>.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
<?php include('backend/signin.php'); ?>
</body>
</html>

<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
tms_require_user(); // Batch 1 (landing/auth gating): whole app now requires login

// ─── Pagination & filter params ───────────────────────────────────────────────
$perPage    = 6;
$page       = tms_int($_GET['page'] ?? 1, 1, 9999);
$offset     = ($page - 1) * $perPage;
$search     = tms_normalize_quotes(tms_clean($_GET['search'] ?? ''));
$filterType = tms_clean($_GET['type']   ?? '');
$sortBy     = in_array($_GET['sort'] ?? '', ['price_asc','price_desc','name']) ? $_GET['sort'] : 'default';
$quick      = tms_clean($_GET['quick'] ?? '');
$maxPrice   = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? max(0, (float)$_GET['max_price']) : null;

// ─── Smart Search quick filters ────────────────────────────────────────────────
// Deliberately grounded in columns that actually exist and hold real data,
// rather than inventing categories (e.g. "Weekend Getaway") the schema/seed
// data can't back up. "Cheap" and "Premium" are computed against this
// catalog's own live average, not a guessed fixed price, so they stay
// meaningful as prices change.
$avgPriceRow = $dbh->query("SELECT AVG(PackagePrice) FROM tbltourpackages WHERE Status=1")->fetchColumn();
$avgPrice = $avgPriceRow ? (float)$avgPriceRow : 0;
$quickFilters = [
    'cheap'   => ['label' => 'Budget-Friendly', 'icon' => 'fa-tag'],
    'premium' => ['label' => 'Premium',         'icon' => 'fa-gem'],
    'family'  => ['label' => 'Family',          'icon' => 'fa-users'],
    'couple'  => ['label' => 'Couple',          'icon' => 'fa-heart'],
    'group'   => ['label' => 'Group',           'icon' => 'fa-user-friends'],
    'rated'   => ['label' => 'Highly Rated',    'icon' => 'fa-star'],
];

// ─── Build query ──────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($search !== '') {
    $where[]              = "(PackageName LIKE :search OR PackageLocation LIKE :search2 OR Country LIKE :search3)";
    $params[':search']    = '%' . $search . '%';
    $params[':search2']   = '%' . $search . '%';
    $params[':search3']   = '%' . $search . '%';
}
if ($filterType !== '') {
    $where[]              = "PackageType = :ptype";
    $params[':ptype']     = $filterType;
}
if ($quick !== '' && isset($quickFilters[$quick])) {
    switch ($quick) {
        case 'cheap':
            $where[] = "PackagePrice <= :avgprice";
            $params[':avgprice'] = $avgPrice;
            break;
        case 'premium':
            $where[] = "PackagePrice > :avgprice2";
            $params[':avgprice2'] = $avgPrice;
            break;
        case 'family':
            $where[] = "PackageType LIKE '%Family%'";
            break;
        case 'couple':
            $where[] = "PackageType LIKE '%Couple%'";
            break;
        case 'group':
            $where[] = "PackageType LIKE '%Group%'";
            break;
        case 'rated':
            $where[] = "AvgRating >= 4";
            break;
    }
}
if ($maxPrice !== null) {
    $where[] = "PackagePrice <= :maxprice";
    $params[':maxprice'] = $maxPrice;
}
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$orderSQL = match($sortBy) {
    'price_asc'  => 'ORDER BY PackagePrice ASC',
    'price_desc' => 'ORDER BY PackagePrice DESC',
    'name'       => 'ORDER BY PackageName ASC',
    default      => 'ORDER BY PackageId DESC',
};

// Total count for pagination
$countQ = $dbh->prepare("SELECT COUNT(*) FROM tbltourpackages $whereSQL");
$countQ->execute($params);
$totalRows  = (int)$countQ->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

// Paginated results
$sql   = "SELECT * FROM tbltourpackages $whereSQL $orderSQL LIMIT :limit OFFSET :offset";
$query = $dbh->prepare($sql);
foreach ($params as $k => $v) { $query->bindValue($k, $v, PDO::PARAM_STR); }
$query->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$query->bindValue(':offset', $offset,  PDO::PARAM_INT);
$query->execute();
$results = $query->fetchAll(PDO::FETCH_OBJ);

// Batch-fetch wishlist membership for the current page's results in ONE query
// (previously ran a separate SELECT per card in the loop below — a classic N+1 pattern)
$wishlistIds = [];
if (!empty($_SESSION['login']) && count($results) > 0) {
    $ids = array_map(function($r) { return (int)$r->PackageId; }, $results);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $wQ  = $dbh->prepare("SELECT PackageId FROM tblwishlist WHERE UserEmail=? AND PackageId IN ($in)");
    $wQ->execute(array_merge([$_SESSION['login']], $ids));
    $wishlistIds = array_map('intval', $wQ->fetchAll(PDO::FETCH_COLUMN));
}

// Package types for filter dropdown
$typesQ = $dbh->query("SELECT DISTINCT PackageType FROM tbltourpackages ORDER BY PackageType");
$packageTypes = $typesQ->fetchAll(PDO::FETCH_COLUMN);

// Translate package names for the active language (cached after first request —
// see tms_translate_batch() in includes/i18n.php). Falls back to the original
// English name for any package/language pair the AI hasn't translated yet.
$translatedNames = [];
if (tms_active_language() !== 'en' && count($results) > 0) {
    $toTranslate = [];
    foreach ($results as $r) { $toTranslate[$r->PackageId] = $r->PackageName; }
    $translatedNames = tms_translate_batch($toTranslate, tms_active_language(), $dbh);
}
function tms_pkg_name(object $result, array $translatedNames): string {
    return $translatedNames[$result->PackageId] ?? $result->PackageName;
}

/** Build a pagination URL preserving current filters. */
function pageUrl(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return 'package-list.php?' . http_build_query($params);
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | Package List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
/* ── Search / filter bar ── */
.pkg-toolbar{background:var(--white);border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);
    padding:20px 25px;margin-bottom:28px;}
.pkg-toolbar .form-control{border:1.5px solid #e0e0e0;border-radius:7px;height:40px;font-size:14px;}
.pkg-toolbar .form-control:focus{border-color:var(--secondary);box-shadow:none;}
.btn-search{background:var(--secondary);color:#fff;border:none;border-radius:7px;
    height:40px;padding:0 22px;font-weight:600;transition:.2s;}
.btn-search:hover{background:#2d6a97;color:#fff;}
.btn-reset{background:#f5f5f5;color:#555;border:1px solid #ddd;border-radius:7px;
    height:40px;padding:0 16px;font-weight:600;transition:.2s;}
.btn-reset:hover{background:#e8e8e8;}
/* ── Package card ── */
.rom-btm{background:var(--white);border-radius:10px;box-shadow:0 2px 14px rgba(0,0,0,.07);
    margin-bottom:24px;overflow:hidden;transition:.25s;display:flex;flex-wrap:wrap;}
.rom-btm:hover{box-shadow:0 6px 28px rgba(0,0,0,.13);transform:translateY(-2px);}
.room-left img{width:100%;height:180px;object-fit:cover;}
.room-midle{padding:18px 18px 10px;}
.room-right{padding:18px;display:flex;flex-direction:column;justify-content:center;align-items:flex-end;}
.room-right h5{font-size:1.4em;font-weight:700;color:var(--secondary);margin:0 0 12px;}
.room-right .view{background:var(--secondary);color:#fff;border-radius:6px;padding:9px 22px;
    font-size:13px;font-weight:600;display:inline-block;transition:.2s;}
.room-right .view:hover{background:#2d6a97;text-decoration:none;}
/* ── Pagination ── */
.pagination-wrap{text-align:center;margin:30px 0 10px;}
.pagination>li>a,.pagination>li>span{color:var(--secondary);border-radius:6px!important;margin:0 2px;
    border:1.5px solid #d8e8f5;}
.pagination>.active>a,.pagination>.active>a:hover{background:var(--secondary);border-color:var(--secondary);}
.pagination>li>a:hover{background:#e8f2fa;color:var(--secondary);}
/* ── Results count ── */
.results-info{color:#888;font-size:13px;margin-bottom:16px;}
/* ── No results ── */
.no-results{text-align:center;padding:60px 20px;color:#aaa;}
.no-results i{font-size:50px;display:block;margin-bottom:16px;}
</style>
</head>
<body>
<?php include('includes/header.php'); ?>

<div class="banner-3">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s">TMS – Package List</h1>
    </div>
</div>

<div class="rooms">
    <div class="container">
        <!-- ── Smart Search quick filters ── -->
        <div class="wow fadeInDown" data-wow-delay=".05s" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
            <a href="package-list.php<?php echo ($search!=='' || $filterType!=='') ? '?search='.urlencode($search).'&type='.urlencode($filterType) : ''; ?>"
               class="nearby-tab<?php echo $quick===''?' active':''; ?>">All Packages</a>
            <?php foreach ($quickFilters as $qkey => $qinfo):
                $qparams = $_GET; $qparams['quick'] = $qkey; unset($qparams['page']);
            ?>
            <a href="package-list.php?<?php echo htmlspecialchars(http_build_query($qparams), ENT_QUOTES, 'UTF-8'); ?>"
               class="nearby-tab<?php echo $quick===$qkey?' active':''; ?>">
                <i class="fa <?php echo $qinfo['icon']; ?>"></i> <?php echo htmlspecialchars($qinfo['label'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <style>.nearby-tab{background:var(--white);border:1.5px solid #e2e8f0;border-radius:20px;padding:7px 15px;font-size:12.5px;font-weight:600;color:#64748b;cursor:pointer;transition:.2s;text-decoration:none;display:inline-block;}
        .nearby-tab:hover{border-color:var(--secondary);color:var(--secondary);text-decoration:none;}
html[data-theme="dark"] .btn-reset { background: #232b37 !important; color: var(--text-muted) !important; border-color: #333f4d !important; }
html[data-theme="dark"] .results-info { color: var(--text-muted) !important; }
html[data-theme="dark"] .no-results { color: var(--text-light) !important; }
        .nearby-tab.active{background:var(--secondary);border-color:var(--secondary);color:#fff;}</style>
        <!-- ── Search & filter bar ── -->
        <div class="pkg-toolbar wow fadeInDown" data-wow-delay=".1s">
            <form method="get" action="package-list.php">
                <div class="row" style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px;">
                    <div class="col-md-4" style="flex:1;min-width:180px;">
                        <label style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;">
                            <i class="fa fa-search"></i> Search
                        </label>
                        <input type="text" name="search" class="form-control"
                               placeholder="Destination or package name…"
                               value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3" style="flex:0 0 200px;">
                        <label style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;">
                            <i class="fa fa-filter"></i> Type
                        </label>
                        <select name="type" class="form-control">
                            <option value="">All Types</option>
                            <?php foreach ($packageTypes as $t): ?>
                                <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo ($filterType === $t) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3" style="flex:0 0 200px;">
                        <label style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;">
                            <i class="fa fa-sort"></i> Sort By
                        </label>
                        <select name="sort" class="form-control">
                            <option value="default"    <?php echo $sortBy==='default'    ?'selected':''; ?>>Newest First</option>
                            <option value="price_asc"  <?php echo $sortBy==='price_asc'  ?'selected':''; ?>>Price: Low → High</option>
                            <option value="price_desc" <?php echo $sortBy==='price_desc' ?'selected':''; ?>>Price: High → Low</option>
                            <option value="name"       <?php echo $sortBy==='name'       ?'selected':''; ?>>Name A–Z</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:0;">
                        <button type="submit" class="btn-search">
                            <i class="fa fa-search"></i> Search
                        </button>
                        <a href="package-list.php" class="btn-reset">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="room-bottom">
            <!-- ── Results summary ── -->
            <div class="results-info" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div>
                <?php if ($search !== '' || $filterType !== ''): ?>
                    Showing <strong><?php echo $totalRows; ?></strong> result<?php echo $totalRows !== 1 ? 's' : ''; ?>
                    <?php echo $search !== '' ? 'for "<strong>' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '</strong>"' : ''; ?>
                    <?php echo $filterType !== '' ? '· Type: <strong>' . htmlspecialchars($filterType, ENT_QUOTES, 'UTF-8') . '</strong>' : ''; ?>
                    <?php echo $maxPrice !== null ? '· Under <strong>' . tms_currency($maxPrice) . '</strong>' : ''; ?>
                <?php else: ?>
                    <strong><?php echo $totalRows; ?></strong> package<?php echo $totalRows !== 1 ? 's' : ''; ?> available
                <?php endif; ?>
                &nbsp;·&nbsp; Page <?php echo $page; ?> of <?php echo max(1, $totalPages); ?>
                </div>
                <?php if (count($results) > 0): ?>
                <div style="display:flex;gap:6px;">
                    <button type="button" id="viewListBtn" class="nearby-tab active" onclick="tmsSwitchView('list')"><i class="fa fa-list"></i> List</button>
                    <button type="button" id="viewMapBtn" class="nearby-tab" onclick="tmsSwitchView('map')"><i class="fa fa-map"></i> Map</button>
                </div>
                <?php endif; ?>
            </div>

            <?php
            // Coordinates for the map view — only packages on this page that
            // actually have MapLat/MapLng set get a pin (no fake placement).
            $mapPoints = [];
            foreach ($results as $r) {
                if (!empty($r->MapLat) && !empty($r->MapLng)) {
                    $mapPoints[] = [
                        'id' => (int)$r->PackageId,
                        'name' => $r->PackageName,
                        'lat' => (float)$r->MapLat,
                        'lng' => (float)$r->MapLng,
                        'price' => tms_currency((float)$r->PackagePrice),
                    ];
                }
            }
            ?>
            <?php if (count($results) > 0): ?>
            <div id="pkgMapView" style="display:none;height:420px;border-radius:var(--radius-md);margin-bottom:24px;background:#eef2f5;"></div>
            <?php endif; ?>

            <?php if (count($results) > 0): ?>
                <div id="pkgListView">
                <?php foreach ($results as $result): ?>
                <div class="rom-btm wow fadeInUp animated" data-wow-delay=".05s">
                    <div class="col-md-3 room-left">
                        <span class="tms-stamp"><?php echo htmlspecialchars(mb_strtoupper(substr($result->PackageType ?? 'TOUR', 0, 12), 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <img loading="lazy"
                             src="<?php echo htmlspecialchars(tms_package_image_src($result->PackageImage), ENT_QUOTES, 'UTF-8'); ?>"
                             onerror="this.src='images/no-image-placeholder.jpg'"
                             class="img-responsive" alt="<?php echo htmlspecialchars(tms_pkg_name($result, $translatedNames), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-6 room-midle">
                        <h4><?php echo htmlspecialchars(tms_pkg_name($result, $translatedNames), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <h6><i class="fa fa-tag" style="color:var(--secondary)"></i> <?php echo htmlspecialchars($result->PackageType, ENT_QUOTES, 'UTF-8'); ?></h6>
                        <p><i class="fa fa-map-marker" style="color:#e74c3c"></i> <strong>Location:</strong> <?php echo htmlspecialchars($result->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><i class="fa fa-list" style="color:#27ae60"></i> <?php echo htmlspecialchars($result->PackageFetures ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (!empty($result->AvgRating) && $result->AvgRating > 0): ?>
                        <p style="margin:4px 0;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="fa fa-star<?php echo $s <= round($result->AvgRating) ? '' : '-o'; ?>" style="color:#f0ad4e;font-size:13px;"></i>
                            <?php endfor; ?>
                            <span style="font-size:12px;color:#888;margin-left:4px;"><?php echo number_format($result->AvgRating, 1); ?></span>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 room-right">
                        <?php $listPricing = ((float)$result->PackagePrice > 0) ? tms_dynamic_price($dbh, (int)$result->PackageId, (float)$result->PackagePrice) : null; ?>
                        <h5><?php echo tms_currency((float)($listPricing['final'] ?? $result->PackagePrice)); ?></h5>
                        <?php if (!empty($listPricing) && $listPricing['demand_pct'] > 0): ?>
                        <div style="font-size:10.5px;font-weight:600;color:#f97316;margin:-8px 0 8px;"><i class="fa fa-bolt"></i> High demand</div>
                        <?php endif; ?>
                        <a href="package-details.php?pkgid=<?php echo (int)$result->PackageId; ?>" class="view">
                            <?php echo tms_t('btn_view_details','View Details'); ?> <i class="fa fa-arrow-right"></i>
                        </a>
                        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
                            <!-- Wishlist Button -->
                            <?php if(!empty($_SESSION['login'])):
                                $inWish = in_array((int)$result->PackageId, $wishlistIds, true);
                            ?>
                            <form method="post" action="wishlist.php" style="display:inline;">
                                <?php tms_csrf_field(); ?>
                                <input type="hidden" name="pkgid" value="<?php echo (int)$result->PackageId; ?>">
                                <button name="toggle_wish" style="background:<?php echo $inWish?'#e74c3c':'#fff'; ?>;color:<?php echo $inWish?'#fff':'#e74c3c'; ?>;border:1px solid #e74c3c;padding:5px 12px;border-radius:6px;font-size:13px;cursor:pointer;">
                                    <i class="fa fa-heart<?php echo $inWish?'':'-o'; ?>"></i> <?php echo $inWish?'Saved':'Save'; ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <!-- Compare Button -->
                            <?php
                            $inCompare = in_array($result->PackageId, $_SESSION['compare']??[]);
                            $compareCount = count($_SESSION['compare']??[]);
                            ?>
                            <?php if(!$inCompare && $compareCount < 3): ?>
                            <form method="post" action="compare.php" style="display:inline;">
                                <?php tms_csrf_field(); ?>
                                <input type="hidden" name="pkgid" value="<?php echo (int)$result->PackageId; ?>">
                                <button name="add_compare" style="background:var(--secondary);color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:13px;cursor:pointer;">
                                    <i class="fa fa-columns"></i> Compare
                                </button>
                            </form>
                            <?php elseif($inCompare): ?>
                            <span style="background:#e8f5e9;color:#2e7d32;padding:5px 12px;border-radius:6px;font-size:13px;border:1px solid #a5d6a7;">
                                <i class="fa fa-check"></i> Added
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="clearfix"></div>
                </div>
                <?php endforeach; ?>
                </div>

                <!-- ── Pagination ── -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li><a href="<?php echo pageUrl(1); ?>" title="First">&laquo;</a></li>
                            <li><a href="<?php echo pageUrl($page - 1); ?>" title="Previous">&lsaquo;</a></li>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage   = min($totalPages, $page + 2);
                        for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="<?php echo ($p === $page) ? 'active' : ''; ?>">
                                <a href="<?php echo pageUrl($p); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <li><a href="<?php echo pageUrl($page + 1); ?>" title="Next">&rsaquo;</a></li>
                            <li><a href="<?php echo pageUrl($totalPages); ?>" title="Last">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="no-results">
                    <i class="fa fa-search"></i>
                    <h3><?php echo tms_t('msg_no_packages_found','No packages found'); ?></h3>
                    <p>Try a different search term or <a href="package-list.php">view all packages</a>.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (count($results) > 0): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
var TMS_MAP_POINTS = <?php echo json_encode($mapPoints, JSON_UNESCAPED_SLASHES); ?>;
var tmsPkgMap = null;
function tmsSwitchView(view) {
    var listEl = document.getElementById('pkgListView');
    var mapEl = document.getElementById('pkgMapView');
    var listBtn = document.getElementById('viewListBtn');
    var mapBtn = document.getElementById('viewMapBtn');
    if (view === 'map') {
        if (listEl) listEl.style.display = 'none';
        mapEl.style.display = 'block';
        listBtn.classList.remove('active');
        mapBtn.classList.add('active');
        if (!tmsPkgMap) {
            if (!TMS_MAP_POINTS.length) {
                mapEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:13px;">None of the packages on this page have map coordinates set yet.</div>';
                return;
            }
            tmsPkgMap = L.map('pkgMapView');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 18
            }).addTo(tmsPkgMap);
            var bounds = [];
            TMS_MAP_POINTS.forEach(function(p) {
                var m = L.marker([p.lat, p.lng]).addTo(tmsPkgMap)
                    .bindPopup('<strong>' + p.name.replace(/</g,'&lt;') + '</strong><br>' + p.price +
                        '<br><a href="package-details.php?id=' + p.id + '">View package</a>');
                bounds.push([p.lat, p.lng]);
            });
            tmsPkgMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 });
        } else {
            setTimeout(function(){ tmsPkgMap.invalidateSize(); }, 50);
        }
    } else {
        if (listEl) listEl.style.display = 'block';
        mapEl.style.display = 'none';
        mapBtn.classList.remove('active');
        listBtn.classList.add('active');
    }
}
</script>
<?php endif; ?>

<?php include('includes/footer.php'); ?>
<?php include('includes/write-us.php'); ?>
<?php include('includes/signin.php'); ?>
</body>
</html>

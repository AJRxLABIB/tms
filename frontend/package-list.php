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

// ─── Pagination & filter params ───────────────────────────────────────────────
$perPage    = 6;
$page       = tms_int($_GET['page'] ?? 1, 1, 9999);
$offset     = ($page - 1) * $perPage;
$search     = tms_normalize_quotes(tms_clean($_GET['search'] ?? ''));
$filterType = tms_clean($_GET['type']   ?? '');
$sortBy     = in_array($_GET['sort'] ?? '', ['price_asc','price_desc','name']) ? $_GET['sort'] : 'default';
$quick      = tms_clean($_GET['quick'] ?? '');
$maxPrice   = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? max(0, (float)$_GET['max_price']) : null;
// Round 36 (item 30, structured search) -- a duration range in days, from
// either the manual "Duration" dropdown below or the natural-language
// search box's parsed output (backend/ai/NLSearchParser.php). Both
// produce the same [min, max] shape; a single value (min===max) is an
// exact-day request, a real range is a bucket like "3-4 days".
$durationMin = isset($_GET['dur_min']) && is_numeric($_GET['dur_min']) ? max(0, min(60, (int)$_GET['dur_min'])) : null;
$durationMax = isset($_GET['dur_max']) && is_numeric($_GET['dur_max']) ? max(0, min(60, (int)$_GET['dur_max'])) : null;
// The manual "Duration" dropdown below submits a single bucket value
// ("2-3") instead of two separate params -- parse it into the same
// [min, max] shape when dur_min/dur_max weren't already given directly
// (that path is only used by the NL search box's redirect).
if ($durationMin === null && $durationMax === null && !empty($_GET['dur']) && preg_match('/^(\d{1,2})-(\d{1,2})$/', (string)$_GET['dur'], $dm)) {
    $durationMin = max(0, min(60, (int)$dm[1]));
    $durationMax = max($durationMin, min(60, (int)$dm[2]));
}
if ($durationMin !== null && $durationMax === null) { $durationMax = $durationMin; }
if ($durationMax !== null && $durationMin === null) { $durationMin = $durationMax; }
// An informational note surfaced by the NL search box (e.g. "December"
// wasn't usable as a filter) -- display-only, never used in the query.
$nlNote = mb_substr(tms_clean($_GET['nl_note'] ?? ''), 0, 300);

// ─── Smart Search quick filters ────────────────────────────────────────────────
// Deliberately grounded in columns that actually exist and hold real data,
// rather than inventing categories (e.g. "Weekend Getaway") the schema/seed
// data can't back up. "Cheap" and "Premium" are computed against this
// catalog's own live average, not a guessed fixed price, so they stay
// meaningful as prices change.
$avgPriceRow = $dbh->query("SELECT AVG(PackagePrice) FROM tbltourpackages WHERE Status=1")->fetchColumn();
$avgPrice = $avgPriceRow ? (float)$avgPriceRow : 0;
// FIX (Round 14): the Family / Couple / Group chips matched
// `PackageType LIKE '%Family%'` etc. Only the nine original demo rows use those
// words — the 400-package catalogue that ships in data/packages_seed.json uses
// types like Adventure, Beach, Heritage, Island, Mountain, Nature, Wildlife. So
// "Couple" returned 1 result out of 413 and "Family" returned 4, which reads as
// a broken filter. The audience chips are replaced with chips built from the
// types the catalogue actually contains, so every chip is guaranteed to return
// results and the set stays correct as the catalogue changes.
$quickFilters = [
    'cheap'   => ['label' => 'Budget-Friendly', 'icon' => 'fa-tag'],
    'premium' => ['label' => 'Premium',         'icon' => 'fa-gem'],
    'rated'   => ['label' => 'Highly Rated',    'icon' => 'fa-star'],
];

$typeIcons = [
    'adventure' => 'fa-hiking',    'beach'    => 'fa-umbrella-beach', 'island'  => 'fa-water',
    'mountain'  => 'fa-mountain',  'heritage' => 'fa-landmark',       'nature'  => 'fa-tree',
    'wildlife'  => 'fa-paw',       'religious'=> 'fa-place-of-worship','city'   => 'fa-city',
    'wetland'   => 'fa-water',     'scenic drive' => 'fa-road',       'family'  => 'fa-users',
    'couple'    => 'fa-heart',     'group'    => 'fa-user-friends',
];
try {
    $typeRows = $dbh->query(
        "SELECT PackageType, COUNT(*) AS n FROM tbltourpackages
         WHERE Status=1 AND PackageType IS NOT NULL AND PackageType <> ''
         GROUP BY PackageType HAVING n > 0 ORDER BY n DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($typeRows as $t) {
        $slug  = 'type:' . strtolower($t['PackageType']);
        $first = strtolower(trim(explode('/', $t['PackageType'])[0]));
        $quickFilters[$slug] = [
            'label' => $t['PackageType'] . ' (' . $t['n'] . ')',
            'icon'  => $typeIcons[$first] ?? 'fa-suitcase',
        ];
    }
} catch (Exception $e) {
    error_log('[TMS] package type chips failed: ' . $e->getMessage());
}

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
        case 'rated':
            $where[] = "AvgRating >= 4";
            break;
        default:
            // Chips generated from the catalogue's own types (see above).
            if (str_starts_with($quick, 'type:')) {
                $where[] = "LOWER(PackageType) = :quicktype";
                $params[':quicktype'] = substr($quick, 5);
            }
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

if ($durationMin !== null) {
    // PackageDuration is free text ("3-4 Days", "Half Day"), not a numeric
    // column, so it can't be pushed into the SQL WHERE the way price/type
    // are -- see NLSearchParser::durationOverlaps(). With ~400 packages in
    // this catalog, fetching every row matching the OTHER filters and doing
    // the duration check + pagination in PHP is simple and fast (Rule 73);
    // it would need revisiting well before the catalog reached a size where
    // that stopped being true.
    require_once dirname(__DIR__) . '/backend/ai/NLSearchParser.php';
    $sql   = "SELECT * FROM tbltourpackages $whereSQL $orderSQL";
    $query = $dbh->prepare($sql);
    foreach ($params as $k => $v) { $query->bindValue($k, $v, PDO::PARAM_STR); }
    $query->execute();
    $allMatches = array_values(array_filter(
        $query->fetchAll(PDO::FETCH_OBJ),
        fn($r) => NLSearchParser::durationOverlaps($r->PackageDuration ?? '', $durationMin, $durationMax)
    ));
    $totalRows  = count($allMatches);
    $totalPages = (int)ceil($totalRows / $perPage);
    $results    = array_slice($allMatches, $offset, $perPage);
} else {
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
}

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
// see tms_translate_batch() in backend/i18n.php). Falls back to the original
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
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Package List</title>
<?php
// NEW (Round 118, master prompt item 53 -- SEO): this page is explicitly
// public/indexable (see the "LIVE BUG FIX" comment at the top of this
// file -- it's allowed in robots.txt and listed in sitemap.php) yet had
// no canonical/OpenGraph tags at all, unlike destinations.php's already-
// wired equivalent. Same convention as that page: canonical is stripped
// of the query string by tms_seo_meta()'s own default (so
// ?type=X&sort=Y&page=2 all canonicalize to the base listing rather than
// counting as separate duplicate-content pages), only title/description
// vary with whatever real filter is active, and nothing is fabricated --
// $totalRows/$search/$filterType are the same real values already
// computed above for the on-page "Showing N results" text.
$seoTitleParts = array_filter([$filterType ?: null, $search !== '' ? ('"' . $search . '"') : null]);
tms_seo_meta([
    'title'       => $seoTitleParts ? ('Packages: ' . implode(', ', $seoTitleParts)) : 'Tour Packages',
    'description' => $totalRows > 0
        ? "Browse {$totalRows} tour packages" . ($filterType ? " in {$filterType}" : '') . '.'
        : 'Browse our tour packages.',
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Package List'],
    ],
]);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
.results-info{color:var(--text-muted);font-size:13px;margin-bottom:16px;}
/* ── No results ── */
.no-results{text-align:center;padding:60px 20px;color:var(--text-muted);}
.no-results i{font-size:50px;display:block;margin-bottom:16px;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="banner-3">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo tms_t('heading_tms_package_list','TMS – Package List'); ?></h1>
    </div>
</div>

<div class="rooms">
    <div class="container">
        <!-- ── Smart Search quick filters ── -->
        <div class="wow fadeInDown" data-wow-delay=".05s" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
            <a href="package-list.php<?php echo ($search!=='' || $filterType!=='') ? '?search='.urlencode($search).'&type='.urlencode($filterType) : ''; ?>"
               class="nearby-tab<?php echo $quick===''?' active':''; ?>"><?php echo tms_t('chip_all_packages','All Packages'); ?></a>
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
        <!-- ── Natural-language search (Round 36, item 30) ── -->
        <div class="pkg-toolbar wow fadeInDown" data-wow-delay=".05s" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:240px;">
                <label for="nlSearchInput" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;display:block;">
                    <i class="fa fa-magic"></i> <?php echo tms_t('label_describe_trip','Describe the trip you want'); ?>
                </label>
                <input type="text" id="nlSearchInput" class="form-control"
                       placeholder="<?php echo tms_t('nl_search_placeholder','e.g. cheap 3-day beach trip under &#2547;20,000'); ?>"
                       maxlength="300">
            </div>
            <button type="button" id="nlSearchBtn" class="btn-search" onclick="tmsNlSearch()">
                <i class="fa fa-magic"></i> <?php echo tms_t('btn_find_it','Find it'); ?>
            </button>
            <div id="nlSearchStatus" style="width:100%;font-size:12.5px;color:var(--text-muted);"></div>
        </div>
        <script>
        const TMS_NL_CSRF = <?php echo json_encode(tms_csrf_token()); ?>;
        function tmsNlSearch() {
            var input = document.getElementById('nlSearchInput');
            var status = document.getElementById('nlSearchStatus');
            var q = (input.value || '').trim();
            if (!q) { status.textContent = 'Type a description of the trip you want first.'; return; }
            status.textContent = 'Reading your request…';
            fetch('api/ai.php?action=nl_search', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'query=' + encodeURIComponent(q) + '&csrf_token=' + encodeURIComponent(TMS_NL_CSRF)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) { status.textContent = res.error || 'Could not read that request. Try the filters below instead.'; return; }
                var f = res.data.filters;
                var params = new URLSearchParams();
                // The AI only ever chooses filter VALUES -- this page's own
                // existing, trusted query (above) is what actually runs.
                // Deliberately NOT falling back to the raw sentence as a
                // keyword search: most valid requests ("cheap 3-day beach
                // trip") have no place name in them at all, and LIKE-matching
                // the whole sentence against PackageName/Location/Country
                // would just return zero results instead of using type/price/
                // duration, which are exactly the filters that DO apply.
                if (f.keyword)    { params.set('search', f.keyword); }
                if (f.type)       { params.set('type', f.type); }
                if (f.max_price)  { params.set('max_price', f.max_price); }
                if (f.duration_min !== null && f.duration_min !== undefined) {
                    params.set('dur_min', f.duration_min);
                    params.set('dur_max', f.duration_max);
                }
                if (f.rated_only) { params.set('quick', 'rated'); }
                var note = res.data.note;
                if (!note && params.toString() === '') {
                    note = "Couldn't pick out specific filters from that description -- showing all packages instead.";
                }
                if (note) { params.set('nl_note', note); }
                window.location.href = 'package-list.php?' + params.toString();
            })
            .catch(function() { status.textContent = 'Something went wrong -- try the filters below instead.'; });
        }
        document.getElementById('nlSearchInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); tmsNlSearch(); }
        });
        </script>

        <?php if ($nlNote !== ''): ?>
        <div class="wow fadeIn" style="background:#fff8e1;border:1px solid #ffe082;color:#8d6e00;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:13px;">
            <i class="fa fa-info-circle"></i> <?php echo htmlspecialchars($nlNote, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <!-- ── Search & filter bar ── -->
        <div class="pkg-toolbar wow fadeInDown" data-wow-delay=".1s">
            <form method="get" action="package-list.php">
                <div class="row" style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px;">
                    <div class="col-md-4" style="flex:1;min-width:180px;">
                        <label for="pkgListSearch" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;">
                            <i class="fa fa-search"></i> <?php echo tms_t('btn_search','Search'); ?>
                        </label>
                        <input type="text" name="search" id="pkgListSearch" class="form-control"
                               placeholder="<?php echo tms_t('placeholder_dest_or_package','Destination or package name…'); ?>"
                               value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3" style="flex:0 0 200px;">
                        <label for="pkgListType" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;">
                            <i class="fa fa-filter"></i> <?php echo tms_t('label_type','Type'); ?>
                        </label>
                        <select name="type" id="pkgListType" class="form-control">
                            <option value=""><?php echo tms_t('all_types','All Types'); ?></option>
                            <?php foreach ($packageTypes as $t): ?>
                                <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo ($filterType === $t) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3" style="flex:0 0 170px;">
                        <label for="pkgListDuration" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;">
                            <i class="fa fa-clock-o"></i> <?php echo tms_t('label_duration','Duration'); ?>
                        </label>
                        <?php
                        // Round 36 -- buckets built from the real PackageDuration
                        // text shapes seen in the catalog (see NLSearchParser's
                        // durationOverlaps()), not invented labels.
                        $durationBuckets = [
                            ''      => tms_t('duration_any','Any Duration'),
                            '0-1'   => tms_t('duration_half_1day','Half / 1 Day'),
                            '2-3'   => tms_t('duration_2_3','2-3 Days'),
                            '4-5'   => tms_t('duration_4_5','4-5 Days'),
                            '6-8'   => tms_t('duration_6_8','6-8 Days'),
                            '9-60'  => tms_t('duration_9plus','9+ Days'),
                        ];
                        $currentBucket = ($durationMin !== null) ? ($durationMin . '-' . $durationMax) : '';
                        ?>
                        <select name="dur" id="pkgListDuration" class="form-control">
                            <?php foreach ($durationBuckets as $bval => $blabel): ?>
                                <option value="<?php echo htmlspecialchars($bval, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo ($currentBucket === $bval) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($blabel, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3" style="flex:0 0 200px;">
                        <label for="pkgListSort" style="font-size:13px;font-weight:600;color:#555;margin-bottom:4px;">
                            <i class="fa fa-sort"></i> <?php echo tms_t('label_sort_by','Sort By'); ?>
                        </label>
                        <select name="sort" id="pkgListSort" class="form-control">
                            <option value="default"    <?php echo $sortBy==='default'    ?'selected':''; ?>><?php echo tms_t('sort_newest','Newest First'); ?></option>
                            <option value="price_asc"  <?php echo $sortBy==='price_asc'  ?'selected':''; ?>><?php echo tms_t('sort_price_asc','Price: Low → High'); ?></option>
                            <option value="price_desc" <?php echo $sortBy==='price_desc' ?'selected':''; ?>><?php echo tms_t('sort_price_desc','Price: High → Low'); ?></option>
                            <option value="name"       <?php echo $sortBy==='name'       ?'selected':''; ?>><?php echo tms_t('sort_name_az','Name A–Z'); ?></option>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:0;">
                        <button type="submit" class="btn-search">
                            <i class="fa fa-search"></i> <?php echo tms_t('btn_search','Search'); ?>
                        </button>
                        <a href="package-list.php" class="btn-reset"><?php echo tms_t('btn_reset','Reset'); ?></a>
                    </div>
                </div>
            </form>
        </div>

        <div class="room-bottom">
            <!-- ── Results summary ── -->
            <div class="results-info" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div>
                <?php if ($search !== '' || $filterType !== '' || $durationMin !== null): ?>
                    Showing <strong><?php echo $totalRows; ?></strong> result<?php echo $totalRows !== 1 ? 's' : ''; ?>
                    <?php echo $search !== '' ? 'for "<strong>' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '</strong>"' : ''; ?>
                    <?php echo $filterType !== '' ? '· Type: <strong>' . htmlspecialchars($filterType, ENT_QUOTES, 'UTF-8') . '</strong>' : ''; ?>
                    <?php echo $maxPrice !== null ? '· Under <strong>' . tms_currency($maxPrice) . '</strong>' : ''; ?>
                    <?php if ($durationMin !== null): ?>
                    · <strong><?php echo ($durationMin === $durationMax) ? $durationMin : $durationMin.'–'.$durationMax; ?> day<?php echo $durationMax !== 1 ? 's' : ''; ?></strong>
                    <?php endif; ?>
                <?php else: ?>
                    <strong><?php echo $totalRows; ?></strong> package<?php echo $totalRows !== 1 ? 's' : ''; ?> available
                <?php endif; ?>
                &nbsp;·&nbsp; <?php echo tms_t('label_page','Page'); ?> <?php echo $page; ?> <?php echo tms_t('label_of_pagination','of'); ?> <?php echo max(1, $totalPages); ?>
                </div>
                <?php if (count($results) > 0): ?>
                <div style="display:flex;gap:6px;">
                    <button type="button" id="viewListBtn" class="nearby-tab active" onclick="tmsSwitchView('list')"><i class="fa fa-list"></i> <?php echo tms_t('btn_view_list','List'); ?></button>
                    <button type="button" id="viewMapBtn" class="nearby-tab" onclick="tmsSwitchView('map')"><i class="fa fa-map"></i> <?php echo tms_t('btn_view_map','Map'); ?></button>
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
                        <p><i class="fa fa-map-marker" style="color:var(--primary)"></i> <strong>Location:</strong> <?php echo htmlspecialchars($result->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><i class="fa fa-list" style="color:var(--success)"></i> <?php echo htmlspecialchars($result->PackageFetures ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (!empty($result->AvgRating) && $result->AvgRating > 0): ?>
                        <p style="margin:4px 0;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="fa fa-star<?php echo $s <= round($result->AvgRating) ? '' : '-o'; ?>" style="color:var(--brass);font-size:13px;"></i>
                            <?php endfor; ?>
                            <span style="font-size:12px;color:var(--text-muted);margin-left:4px;"><?php echo number_format($result->AvgRating, 1); ?></span>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 room-right">
                        <?php $listPricing = ((float)$result->PackagePrice > 0) ? tms_dynamic_price($dbh, (int)$result->PackageId, (float)$result->PackagePrice) : null; ?>
                        <h5><?php echo tms_currency((float)($listPricing['final'] ?? $result->PackagePrice)); ?></h5>
                        <?php if (!empty($listPricing) && $listPricing['demand_pct'] > 0): ?>
                        <div style="font-size:10.5px;font-weight:600;color:#f97316;margin:-8px 0 8px;"><i class="fa fa-bolt"></i> High demand</div>
                        <?php endif; ?>
                        <?php
                        // NEW (Round 78, master prompt item 9 -- Availability/Inventory):
                        // package-details.php already shows this exact badge (same
                        // thresholds/wording) from this same tms_dynamic_price() result's
                        // 'remaining_seats' key -- it was simply never carried over to
                        // this listing page, so a customer saw no low-availability signal
                        // until they clicked into a package. Reusing $listPricing (already
                        // computed above) rather than querying again. Packages that don't
                        // track capacity (SeatsAvailable NULL) show nothing, same as
                        // package-details.php and every other use of this data.
                        $listSeatsLeft = $listPricing['remaining_seats'] ?? null;
                        if ($listSeatsLeft !== null):
                            if ($listSeatsLeft <= 2): ?>
                        <div style="font-size:10.5px;font-weight:700;color:#dc2626;margin:-4px 0 8px;"><i class="fa fa-exclamation-triangle"></i> Only <?php echo (int)$listSeatsLeft; ?> seat<?php echo $listSeatsLeft===1?'':'s'; ?> left!</div>
                            <?php elseif ($listSeatsLeft <= 8): ?>
                        <div style="font-size:10.5px;font-weight:600;color:#b45309;margin:-4px 0 8px;"><i class="fa fa-users"></i> <?php echo (int)$listSeatsLeft; ?> seats left — filling up</div>
                            <?php endif;
                        endif; ?>
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
                                <button name="toggle_wish" style="background:<?php echo $inWish?'var(--danger)':'#fff'; ?>;color:<?php echo $inWish?'#fff':'var(--danger)'; ?>;border:1px solid var(--danger);padding:5px 12px;border-radius:6px;font-size:13px;cursor:pointer;">
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
                            <li><a href="<?php echo pageUrl(1); ?>" title="<?php echo tms_t('pagination_title_first','First'); ?>">&laquo;</a></li>
                            <li><a href="<?php echo pageUrl($page - 1); ?>" title="<?php echo tms_t('pagination_title_previous','Previous'); ?>">&lsaquo;</a></li>
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
                            <li><a href="<?php echo pageUrl($page + 1); ?>" title="<?php echo tms_t('pagination_title_next','Next'); ?>">&rsaquo;</a></li>
                            <li><a href="<?php echo pageUrl($totalPages); ?>" title="<?php echo tms_t('pagination_title_last','Last'); ?>">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="no-results">
                    <i class="fa fa-search"></i>
                    <h3><?php echo tms_t('msg_no_packages_found','No packages found'); ?></h3>
                    <p><?php echo tms_t('msg_try_different_search','Try a different search term or'); ?> <a href="package-list.php"><?php echo tms_t('link_view_all_packages','view all packages'); ?></a>.</p>
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
                mapEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-size:13px;">None of the packages on this page have map coordinates set yet.</div>';
                return;
            }
            // Master prompt item 62 (error/empty/loading states): this box
            // used to just sit there as a flat grey rectangle (its own
            // background color) with no feedback while Leaflet's JS/CSS
            // (loaded from unpkg.com, see the <script>/<link> tags above)
            // and the OpenStreetMap tiles were still loading -- and if that
            // CDN request failed/was blocked, `L` was undefined and the
            // click on "Map view" threw a silent console-only error, leaving
            // the same blank grey box with zero explanation. Show a brief
            // spinner immediately, and a clear message instead of a crash
            // if Leaflet itself never loaded.
            mapEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-size:13px;"><i class="fa fa-spinner fa-spin" style="margin-right:8px;"></i> Loading map…</div>';
            if (typeof L === 'undefined') {
                mapEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-size:13px;text-align:center;padding:20px;"><i class="fa fa-exclamation-triangle" style="display:block;font-size:24px;margin-bottom:8px;"></i>Map couldn\'t load (offline or blocked script). Use List view to browse these packages instead.</div>';
                return;
            }
            mapEl.innerHTML = '';
            tmsPkgMap = L.map('pkgMapView');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 18
            }).addTo(tmsPkgMap);
            var bounds = [];
            TMS_MAP_POINTS.forEach(function(p) {
                var m = L.marker([p.lat, p.lng]).addTo(tmsPkgMap)
                    .bindPopup('<strong>' + p.name.replace(/</g,'&lt;') + '</strong><br>' + p.price +
                        '<br><a href="package-details.php?pkgid=' + p.id + '">View package</a>');
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

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
<?php include('backend/signin.php'); ?>
</body>
</html>

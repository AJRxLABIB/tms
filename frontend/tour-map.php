<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once __DIR__ . '/../backend/MapConfig.php';
tms_require_user();   // protected travel service: sign in first, then return here (login.php?redirect=...)
/**
 * tour-map.php — the tourism map explorer (public page).
 *
 * Rebuilt on MapLibre GL + OpenFreeMap vector styles (js/tms-maplibre.js) with the explorer app in js/tms-explorer.js.
 * The page only provides the mount points; everything else (search, filters, cards, details, nearby, directions,
 * itineraries) is rendered by the explorer from api/map.php.
 *
 * Preserved from the previous Leaflet page:
 *  - the public URL (/tour-map.php via the root shim) and its PUBLIC access (it is allowed in robots.txt and listed in the
 *    sitemap, so it must not require a login; saving itineraries still needs a signed-in session, checked server-side);
 *  - the ?focus=<PackageId> deep link used by package-details.php ("View on map"): the package's real MapLat/MapLng are
 *    passed to the explorer, which flies there and selects the matching place;
 *  - the standard page chrome (manifest, theme, header, footer, sign-in and write-us modals, dark/light theme variables).
 * Added: ?place=<slug-or-id> is turned into the explorer's share-link hash (tour-map.php#p=...).
 * Dropped on purpose: the old hard-coded fictional type colours and the full-catalogue dump into the page (the explorer
 * now loads only what is in view). Package pins appear once packages are synced into tbl_map_places
 * (php scripts/map_sync_places.php --apply).
 */

// ---- ?place=slug|id -> hash deep link (the explorer reads #p=...)
if (isset($_GET['place']) && is_string($_GET['place']) && preg_match('/^[A-Za-z0-9._\-]{1,120}$/', $_GET['place'])) {
    header('Location: tour-map.php#p=' . rawurlencode($_GET['place']), true, 302);
    exit;
}

// ---- ?focus=<PackageId>: look up the package's REAL coordinates (never guessed)
$tmsFocus = null;
if (isset($_GET['focus']) && preg_match('/^\d{1,10}$/', (string)$_GET['focus'])) {
    try {
        $fq = $dbh->prepare('SELECT PackageId, PackageName, PackageLocation, MapLat, MapLng FROM tbltourpackages WHERE PackageId = :id LIMIT 1');
        $fq->execute([':id' => (int)$_GET['focus']]);
        $fr = $fq->fetch(PDO::FETCH_ASSOC);
        if ($fr && is_numeric($fr['MapLat']) && is_numeric($fr['MapLng'])) {
            $la = (float)$fr['MapLat']; $ln = (float)$fr['MapLng'];
            if ($la >= -90 && $la <= 90 && $ln >= -180 && $ln <= 180 && !($la == 0.0 && $ln == 0.0)
                && (!function_exists('tms_package_bookable') || tms_package_bookable($dbh, (int)$fr['PackageId']))) {
                $tmsFocus = ['id' => (int)$fr['PackageId'], 'lat' => $la, 'lng' => $ln,
                             'name' => (string)$fr['PackageName'], 'location' => (string)$fr['PackageLocation']];
            }
        }
    } catch (Throwable $e) {
        error_log('[TourBuddy] tour-map focus lookup failed: ' . $e->getMessage());
    }
}

// ---- is there anything to show yet? (message only; never fabricate pins)
$tmsPlacesReady = true;
try {
    $tmsPlacesReady = ((int)$dbh->query("SELECT COUNT(*) FROM tbl_map_places WHERE status = 'active'")->fetchColumn()) > 0;
} catch (Throwable $e) {
    $tmsPlacesReady = false;
}
$tmsIsAdmin = !empty($_SESSION['alogin']);

// Public packages that have no usable map location yet (shown in a collapsed list inside the explorer, like the old
// "no map location" items, so they are not silently missing). Real rows only; names are escaped on output.
$tmsUnmapped = [];
try {
    $uq = $dbh->query("SELECT PackageId, PackageName, PackageLocation FROM tbltourpackages
                       WHERE " . (defined('TMS_PKG_PUBLIC_SQL') ? TMS_PKG_PUBLIC_SQL : '1=1') . "
                         AND (MapLat IS NULL OR MapLat = '' OR MapLng IS NULL OR MapLng = '' OR (MapLat+0 = 0 AND MapLng+0 = 0))
                       ORDER BY PackageId DESC LIMIT 30");
    $tmsUnmapped = $uq ? $uq->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    error_log('[TourBuddy] tour-map unmapped list failed: ' . $e->getMessage());
}

$tmsLang   = tms_active_language();
$tmsBase   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$tmsSignedIn = !empty($_SESSION['login']);

// Labels handed to the explorer (it has English defaults for every key it does not receive).
$tmsLabels = [
    'search_ph'     => tms_t('map_search_ph', 'Search places, areas, hotels… (English / বাংলা)'),
    'search_label'  => tms_t('map_search_label', 'Search places'),
    'filters'       => tms_t('map_filters', 'Filters'),
    'tab_explore'   => tms_t('map_tab_explore', 'Explore'),
    'tab_route'     => tms_t('map_tab_route', 'Directions'),
    'tab_trip'      => tms_t('map_tab_trip', 'My trip'),
];
$tmsJson = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars($tmsLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | <?php echo htmlspecialchars(tms_t('map_title', 'Tourism Map'), ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="description" content="<?php echo htmlspecialchars(tms_t('map_intro', 'Find attractions, stays and places to eat, see what is nearby, get directions and plan a multi-stop trip.'), ENT_QUOTES, 'UTF-8'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="<?php echo MapConfig::maptilerKey() !== '' ? 'https://api.maptiler.com' : 'https://tiles.openfreemap.org'; ?>" crossorigin>
<link rel="stylesheet" href="js/vendor/maplibre/maplibre-gl.css">
<link rel="stylesheet" href="<?php echo tms_asset_ver('css/tms-map-ui.css'); ?>">
<link rel="stylesheet" href="<?php echo tms_asset_ver('css/tms-trips.css'); ?>">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/vendor/maplibre/maplibre-gl-csp.js" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-maplibre.js'); ?>" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-poi-tiles.js'); ?>" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-trips.js'); ?>" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-explorer.js'); ?>" defer></script>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="tms-map-page">
    <div class="container tms-map-intro">
        <h1><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo htmlspecialchars(tms_t('map_title', 'Tourism Map'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars(tms_t('map_intro', 'Find attractions, stays and places to eat, see what is nearby, get directions and plan a multi-stop trip.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if (!$tmsPlacesReady): ?>
            <p class="tms-note" role="status"><?php echo htmlspecialchars($tmsIsAdmin
                ? tms_t('map_empty_admin', 'No map places have been imported yet. Run scripts/map_sync_places.php --apply from the command line.')
                : tms_t('map_empty_public', 'Map places are being prepared. Please check back soon.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <div class="container">
        <noscript><p class="tms-note tms-note-err"><?php echo htmlspecialchars(tms_t('map_noscript', 'The interactive map needs JavaScript. Please enable it, or browse the destinations from the Tour Packages page.'), ENT_QUOTES, 'UTF-8'); ?> <a href="package-list.php"><?php echo htmlspecialchars(tms_t('nav_packages', 'Tour Packages'), ENT_QUOTES, 'UTF-8'); ?></a></p></noscript>

        <div id="tmsExplorer" class="tms-explorer"
             data-api="api/map.php"
             data-base="<?php echo htmlspecialchars($tmsBase, ENT_QUOTES, 'UTF-8'); ?>"
             data-lang="<?php echo htmlspecialchars($tmsLang, ENT_QUOTES, 'UTF-8'); ?>"
             data-signed-in="<?php echo $tmsSignedIn ? '1' : '0'; ?>"
             data-login-url="login.php">
            <aside id="tmsPanel" data-sheet="half" aria-label="<?php echo htmlspecialchars(tms_t('map_search_label', 'Search places'), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="button" id="tmsSheetHandle" aria-expanded="true" aria-controls="tmsPanelInner"
                        aria-label="<?php echo htmlspecialchars(tms_t('map_sheet_handle', 'Resize the results panel'), ENT_QUOTES, 'UTF-8'); ?>"></button>
                <div id="tmsPanelInner"></div>
            </aside>
            <div id="tmsMapWrap">
                <div id="tmsMap" role="region" aria-label="<?php echo htmlspecialchars(tms_t('map_title', 'Tourism Map'), ENT_QUOTES, 'UTF-8'); ?>"></div>
            </div>
        </div>

        <p class="tms-map-attrib-note">
            <?php echo htmlspecialchars(tms_t('map_attrib', 'Map data © OpenStreetMap contributors. Base map by OpenFreeMap. Routes by OSRM / FOSSGIS.'), ENT_QUOTES, 'UTF-8'); ?>
            <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> ·
            <?php if (MapConfig::maptilerKey() !== '') { ?><a href="https://www.maptiler.com/copyright/" target="_blank" rel="noopener noreferrer">MapTiler</a><?php } else { ?><a href="https://openfreemap.org/" target="_blank" rel="noopener noreferrer">OpenFreeMap</a><?php } ?>
        </p>
    </div>
</div>

<?php if ($tmsUnmapped): ?>
<section id="tmsUnmapped" class="tms-block tms-unmapped" hidden>
    <h3 class="tms-h3"><?php echo htmlspecialchars(tms_t('map_unmapped_title', 'Packages without a map location'), ENT_QUOTES, 'UTF-8'); ?></h3>
    <p class="tms-hint"><?php echo htmlspecialchars(tms_t('map_unmapped_hint', 'These packages have no verified coordinates yet, so they cannot be pinned.'), ENT_QUOTES, 'UTF-8'); ?></p>
    <ul class="tms-trip-list">
        <?php foreach ($tmsUnmapped as $u): ?>
            <li class="tms-saved-li"><a class="tms-link" href="package-details.php?pkgid=<?php echo (int)$u['PackageId']; ?>"><?php echo htmlspecialchars((string)$u['PackageName'], ENT_QUOTES, 'UTF-8'); ?></a>
                <?php if (trim((string)$u['PackageLocation']) !== ''): ?><span class="tms-dist"> — <?php echo htmlspecialchars((string)$u['PackageLocation'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<script type="application/json" id="tmsExplorerLabels"><?php echo json_encode($tmsLabels, $tmsJson); ?></script>
<script type="application/json" id="tmsExFocus"><?php echo json_encode($tmsFocus, $tmsJson); ?></script>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

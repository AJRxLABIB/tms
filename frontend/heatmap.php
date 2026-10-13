<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user(); // Batch 1 (landing/auth gating): whole app now requires login

/**
 * heatmap.php — Travel Popularity Heat Map
 *
 * Shows real demand across destinations, not just a pin per package. Weight
 * per destination = ViewCount (page views, already tracked in
 * package-details.php) plus confirmed bookings (tblbooking.status = 1),
 * with confirmed bookings counted far more heavily since an actual booking
 * is a much stronger demand signal than a page view.
 *
 * Only packages with real MapLat/MapLng are plotted (same precondition
 * tour-map.php already relies on), so this never invents coordinates.
 */

$sql = "SELECT p.PackageId, p.PackageName, p.PackageLocation, p.PackageType,
               p.MapLat, p.MapLng, p.ViewCount,
               COUNT(CASE WHEN b.status = 1 THEN 1 END) AS BookingCount
        FROM tbltourpackages p
        LEFT JOIN tblbooking b ON b.PackageId = p.PackageId
        WHERE p.MapLat IS NOT NULL AND p.MapLat <> '' AND p.MapLng IS NOT NULL AND p.MapLng <> ''
        GROUP BY p.PackageId";
$rows = $dbh->query($sql)->fetchAll(PDO::FETCH_OBJ);

$points = [];
$ranked = [];
foreach ($rows as $r) {
    $lat = (float)$r->MapLat;
    $lng = (float)$r->MapLng;
    if ($lat === 0.0 && $lng === 0.0) continue;

    $views    = max(0, (int)$r->ViewCount);
    $bookings = max(0, (int)$r->BookingCount);
    // Bookings are the strongest real-demand signal; views are supporting
    // evidence. Weighting is intentionally simple and transparent rather
    // than a black-box score, since this feeds a visual, not a ranking
    // decision with money attached.
    $weight = $views + ($bookings * 15);

    $points[] = [$lat, $lng, $weight];
    $ranked[] = (object)[
        'PackageId'       => (int)$r->PackageId,
        'PackageName'     => $r->PackageName,
        'PackageLocation' => $r->PackageLocation,
        'PackageType'     => $r->PackageType,
        'Views'           => $views,
        'Bookings'        => $bookings,
        'Weight'          => $weight,
    ];
}

usort($ranked, fn($a, $b) => $b->Weight <=> $a->Weight);
$topDestinations = array_slice($ranked, 0, 10);

// Normalize heat intensity to 0–1 for Leaflet.heat (it expects small floats,
// not raw counts, or one runaway popular package washes out every other dot).
$maxWeight = 1;
foreach ($points as $p) { if ($p[2] > $maxWeight) $maxWeight = $p[2]; }
foreach ($points as &$p) { $p[2] = round(min(1, ($p[2] / $maxWeight) * 0.9 + 0.1), 3); }
unset($p);
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
<title>TMS | Travel Popularity Heat Map</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.heat-hero { background: linear-gradient(135deg,#0f2027,#203a43,#2c5364); color:#fff; padding:50px 0 30px; text-align:center; }
.heat-hero h1 { font-size:2.3em; margin-bottom:8px; }
.heat-hero h1 i { color:#ff6b35; }
.heat-hero p { opacity:0.85; font-size:1.1em; }
#heatMap { height: 520px; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.2); margin:25px 0; }
.heat-sidebar { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:20px; height:520px; overflow-y:auto; margin:25px 0; }
.heat-sidebar h4 { color:var(--text); font-size:1em; border-bottom:2px solid #eee; padding-bottom:10px; margin-bottom:15px; }
.heat-rank-item { display:flex; align-items:center; gap:12px; padding:10px; border-radius:8px; margin-bottom:8px; border:1px solid var(--border); text-decoration:none; color:inherit; transition:0.2s; }
.heat-rank-item:hover { background: linear-gradient(135deg,#176B5011,#104B3A11); border-color:#176B50; color:inherit; text-decoration:none; }
.heat-rank-num { flex-shrink:0; width:26px; height:26px; border-radius:50%; background:#C2410C; color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; }
/* Round 14: these were :nth-child(1..3), but the sidebar's first child is the
   <h4> heading — so ranks 1 and 2 got the highlight and rank 3 did not.
   :nth-of-type counts only the rank rows. */
.heat-rank-item:nth-of-type(1) .heat-rank-num, .heat-rank-item:nth-of-type(2) .heat-rank-num, .heat-rank-item:nth-of-type(3) .heat-rank-num { background:#176B50; }
.heat-rank-name { font-size:13px; font-weight:700; margin:0 0 2px; color:var(--text); }
.heat-rank-meta { font-size:11px; color:var(--text-muted); }
.heat-legend { display:flex; gap:16px; flex-wrap:wrap; align-items:center; margin:0 0 15px; font-size:12.5px; color:var(--text); }
html[data-theme="dark"] .heat-sidebar h4,
html[data-theme="dark"] .heat-rank-name { color: var(--text) !important; }
html[data-theme="dark"] .heat-rank-meta,
html[data-theme="dark"] .heat-legend { color: var(--text-muted) !important; }
.heat-legend .bar { width:120px; height:10px; border-radius:5px; background:linear-gradient(90deg,#3b82f6,#22d3ee,#facc15,#f97316,#ef4444); }
.heat-empty { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:50px 30px; text-align:center; margin:25px 0; }
.heat-empty i { font-size:42px; color:#e2e8f0; margin-bottom:14px; display:block; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="heat-hero">
    <div class="container">
        <h1><i class="fa fa-fire"></i> Travel Popularity Heat Map</h1>
        <p>See where travelers are exploring and booking most, based on real page views and confirmed bookings.</p>
    </div>
</div>

<div class="container">
    <?php if (empty($points)): ?>
    <div class="heat-empty">
        <i class="fa fa-map-o"></i>
        <h3>Not enough location data yet</h3>
        <p style="color:var(--text-muted);">Packages need map coordinates before they can appear here. Check back once more destinations have been geocoded.</p>
    </div>
    <?php else: ?>

    <div class="heat-legend" style="margin-top:25px;">
        <span>Low demand</span>
        <span class="bar"></span>
        <span>High demand</span>
        <span style="margin-left:auto;color:var(--text-muted);">Based on page views + confirmed bookings, updated live</span>
    </div>

    <div class="row">
        <div class="col-md-9">
            <div id="heatMap"></div>
        </div>
        <div class="col-md-3">
            <div class="heat-sidebar">
                <h4><i class="fa fa-trophy" style="color:#ff6b35"></i> Top 10 Destinations</h4>
                <?php if (empty($topDestinations)): ?>
                <p style="color:var(--text-muted);font-size:13px;">No demand data yet.</p>
                <?php else: foreach ($topDestinations as $i => $d): ?>
                <a href="package-details.php?pkgid=<?php echo $d->PackageId; ?>" class="heat-rank-item">
                    <span class="heat-rank-num"><?php echo $i + 1; ?></span>
                    <span>
                        <div class="heat-rank-name"><?php echo htmlspecialchars($d->PackageName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="heat-rank-meta">
                            <i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($d->PackageLocation, ENT_QUOTES, 'UTF-8'); ?>
                            &nbsp;•&nbsp; <?php echo $d->Bookings; ?> booking<?php echo $d->Bookings === 1 ? '' : 's'; ?>
                            &nbsp;•&nbsp; <?php echo $d->Views; ?> view<?php echo $d->Views === 1 ? '' : 's'; ?>
                        </div>
                    </span>
                </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($points)): ?>
<script>
var heatMap = L.map('heatMap').setView([20, 70], 3);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors', maxZoom: 18
}).addTo(heatMap);

var heatPoints = <?php echo json_encode($points); ?>;

L.heatLayer(heatPoints, {
    radius: 30,
    blur: 22,
    maxZoom: 10,
    max: 1.0,
    gradient: { 0.1: '#3b82f6', 0.3: '#22d3ee', 0.5: '#facc15', 0.7: '#f97316', 1.0: '#ef4444' }
}).addTo(heatMap);

if (heatPoints.length === 1) {
    heatMap.setView([heatPoints[0][0], heatPoints[0][1]], 6);
} else if (heatPoints.length > 1) {
    var bounds = L.latLngBounds(heatPoints.map(function (p) { return [p[0], p[1]]; }));
    heatMap.fitBounds(bounds.pad(0.3));
}
</script>
<?php endif; ?>

<?php include('backend/footer.php'); ?>
</body>
</html>

<?php
require_once __DIR__ . '/../backend/session-boot.php';
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
$heatRows = [];
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
    $heatRows[] = ['id' => (int)$r->PackageId, 'name' => (string)$r->PackageName, 'lat' => $lat, 'lng' => $lng, 'weight' => $weight,
                   'views' => $views, 'bookings' => $bookings, 'type' => (string)$r->PackageType];
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

// Intensity is normalised in the browser (js/tms-map-pages.js buildHeatPoints) so the raw numbers stay available for tooltips.
$heatTypes = array_values(array_unique(array_filter(array_map(fn($h) => $h['type'], $heatRows), fn($t) => $t !== '')));
sort($heatTypes);
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | Travel Demand Heat Map</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="js/vendor/maplibre/maplibre-gl.css">
<link rel="stylesheet" href="<?php echo tms_asset_ver('css/tms-map-ui.css'); ?>">
<script src="js/vendor/maplibre/maplibre-gl-csp.js" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-maplibre.js'); ?>" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-map-pages.js'); ?>" defer></script>
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
.heat-controls { display:flex; flex-wrap:wrap; gap:14px; align-items:center; margin:0 0 12px; font-size:12.5px; color:var(--text); }
.heat-controls .seg { display:inline-flex; border:1px solid var(--border); border-radius:20px; overflow:hidden; }
.heat-controls .seg button { border:0; background:transparent; color:var(--text); padding:8px 16px; min-height:44px; font-weight:600; cursor:pointer; }
.heat-controls .seg button.active { background:#176B50; color:#fff; }
.heat-controls select, .heat-controls input[type=range] { vertical-align:middle; }
.heat-controls select { min-height:44px; padding:4px 8px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--text); }
.heat-loading { display:flex; height:100%; align-items:center; justify-content:center; color:var(--text-muted); }
#heatMap { position:relative; }
.heat-empty { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:50px 30px; text-align:center; margin:25px 0; }
.heat-empty i { font-size:42px; color:#e2e8f0; margin-bottom:14px; display:block; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="heat-hero">
    <div class="container">
        <h1><i class="fa fa-fire"></i> Travel Demand Heat Map</h1>
        <p>Demand for our listed packages, measured as real page views plus confirmed bookings. This shows interest in what we sell, not how many people visit a place.</p>
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
        <span style="margin-left:auto;color:var(--text-muted);">Demand = page views + 15 &times; confirmed bookings, per package with map coordinates</span>
    </div>

    <div class="heat-controls" role="group" aria-label="Heat map controls">
        <div class="seg" role="radiogroup" aria-label="Display mode">
            <button type="button" id="heatModeHeat" class="active" role="radio" aria-checked="true">Heat</button>
            <button type="button" id="heatModePins" role="radio" aria-checked="false">Markers</button>
        </div>
        <?php if (count($heatTypes) > 1): ?>
        <label>Category
            <select id="heatType">
                <option value="">All</option>
                <?php foreach ($heatTypes as $t): ?><option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>Radius <input type="range" id="heatRadius" min="10" max="60" value="30" aria-label="Heat radius"></label>
        <label>Intensity <input type="range" id="heatIntensity" min="5" max="30" value="12" aria-label="Heat intensity"></label>
        <span id="heatCount" role="status" aria-live="polite"></span>
    </div>

    <div class="row">
        <div class="col-md-9">
            <div id="heatMap" data-base="<?php echo htmlspecialchars(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/', ENT_QUOTES, 'UTF-8'); ?>" data-lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><div class="heat-loading" role="status">Loading map&hellip;</div></div>
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
(function () {
    var ROWS = <?php echo json_encode($heatRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var box = document.getElementById('heatMap');
    var src = 'tms-heat-src', LH = 'tms-heat-layer', LP = 'tms-heat-pins';
    var state = { mode: 'heat', type: '', radius: 30, intensity: 12, ctl: null, popup: null, built: null };

    function say(t) { var c = document.getElementById('heatCount'); if (c) c.textContent = t; }
    function fail(msg) {
        box.innerHTML = '';
        var d = document.createElement('div'); d.className = 'heat-loading'; d.setAttribute('role', 'alert');
        d.textContent = msg; box.appendChild(d);
    }
    function current() {
        var rows = ROWS.map(function (r) { return { id: r.id, name: r.name, lat: r.lat, lng: r.lng, weight: r.weight, type: r.type, views: r.views, bookings: r.bookings }; })
            .filter(function (r) { return !state.type || r.type === state.type; });
        return TmsMapPages.utils.buildHeatPoints(rows, { max: 2000 });
    }
    function fc(built) {
        return { type: 'FeatureCollection', features: built.points.map(function (p) {
            var src_ = ROWS.filter(function (r) { return r.id === p.id; })[0] || {};
            return { type: 'Feature', properties: { w: p.weight, name: p.name, id: p.id, views: src_.views || 0, bookings: src_.bookings || 0, type: p.type },
                     geometry: { type: 'Point', coordinates: [p.lng, p.lat] } };
        }) };
    }
    function draw() {
        var map = state.ctl && state.ctl.map; if (!map || !map.getStyle()) return;
        var built = current(); state.built = built;
        var data = fc(built);
        if (map.getSource(src)) map.getSource(src).setData(data); else map.addSource(src, { type: 'geojson', data: data });
        var r = state.radius, k = state.intensity / 10;
        if (!map.getLayer(LH)) {
            map.addLayer({ id: LH, type: 'heatmap', source: src, paint: {
                'heatmap-weight': ['get', 'w'],
                'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 0, 0.6 * k, 9, 1.6 * k],
                'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 0, r * 0.4, 9, r * 1.4],
                'heatmap-opacity': 0.85,
                'heatmap-color': ['interpolate', ['linear'], ['heatmap-density'], 0, 'rgba(59,130,246,0)', 0.1, '#3b82f6', 0.3, '#22d3ee', 0.5, '#facc15', 0.7, '#f97316', 1, '#ef4444']
            } });
        } else {
            map.setPaintProperty(LH, 'heatmap-radius', ['interpolate', ['linear'], ['zoom'], 0, r * 0.4, 9, r * 1.4]);
            map.setPaintProperty(LH, 'heatmap-intensity', ['interpolate', ['linear'], ['zoom'], 0, 0.6 * k, 9, 1.6 * k]);
        }
        if (!map.getLayer(LP)) {
            map.addLayer({ id: LP, type: 'circle', source: src, paint: { 'circle-radius': 8, 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff',
                'circle-color': ['interpolate', ['linear'], ['get', 'w'], 0.1, '#3b82f6', 0.5, '#facc15', 1, '#ef4444'] } });
            map.on('click', LP, function (e) {
                var f = e.features && e.features[0]; if (!f) return;
                var p = f.properties, w = document.createElement('div'); w.style.cssText = 'max-width:220px;font:13px/1.4 system-ui,sans-serif;color:#1b2a22';
                var t = document.createElement('strong'); t.textContent = p.name; w.appendChild(t);
                var m = document.createElement('div'); m.textContent = p.bookings + ' confirmed booking(s) \u00b7 ' + p.views + ' view(s)'; w.appendChild(m);
                var a = document.createElement('a'); a.href = 'package-details.php?pkgid=' + encodeURIComponent(p.id); a.textContent = 'View package \u2192'; a.style.cssText = 'display:inline-block;margin-top:6px;font-weight:600;color:#0f5a43'; w.appendChild(a);
                if (state.popup) state.popup.remove();
                state.popup = new maplibregl.Popup({ maxWidth: '260px', offset: 10 }).setLngLat(f.geometry.coordinates.slice()).setDOMContent(w).addTo(map);
            });
            map.on('mouseenter', LP, function () { map.getCanvas().style.cursor = 'pointer'; });
            map.on('mouseleave', LP, function () { map.getCanvas().style.cursor = ''; });
        }
        map.setLayoutProperty(LH, 'visibility', state.mode === 'heat' ? 'visible' : 'none');
        map.setLayoutProperty(LP, 'visibility', state.mode === 'pins' ? 'visible' : 'none');
        say(built.points.length + ' package location' + (built.points.length === 1 ? '' : 's') + ' shown' +
            (built.skipped ? ' (' + built.skipped + ' skipped: invalid coordinates)' : '') + (built.truncated ? ' (top 2000 by demand)' : '') + '.');
        if (!built.points.length) say('No packages with coordinates in this category.');
    }
    function fit() {
        var b = state.built; if (!b || !b.points.length) return;
        state.ctl.fitToItems(b.points, { padding: 60, maxZoom: 8 });
    }
    function setMode(m) {
        state.mode = m;
        document.getElementById('heatModeHeat').classList.toggle('active', m === 'heat');
        document.getElementById('heatModePins').classList.toggle('active', m === 'pins');
        document.getElementById('heatModeHeat').setAttribute('aria-checked', m === 'heat' ? 'true' : 'false');
        document.getElementById('heatModePins').setAttribute('aria-checked', m === 'pins' ? 'true' : 'false');
        draw();
    }
    function wire() {
        document.getElementById('heatModeHeat').addEventListener('click', function () { setMode('heat'); });
        document.getElementById('heatModePins').addEventListener('click', function () { setMode('pins'); });
        var t = document.getElementById('heatType'); if (t) t.addEventListener('change', function () { state.type = t.value; draw(); fit(); });
        document.getElementById('heatRadius').addEventListener('input', function (e) { state.radius = +e.target.value; draw(); });
        document.getElementById('heatIntensity').addEventListener('input', function (e) { state.intensity = +e.target.value; draw(); });
    }
    function boot() {
        if (!window.TmsMapPages) { fail('The map library could not be loaded. Reload the page to try again.'); return; }
        box.innerHTML = '';
        TmsMapPages.mount(box, { base: box.getAttribute('data-base') || '', lang: box.getAttribute('data-lang') || 'en', center: [90.4, 23.8], zoom: 5 })
            .then(function (ctl) {
                state.ctl = ctl; wire();
                ctl.onStyleReady(function () { draw(); if (!state.fitted) { state.fitted = true; fit(); } });
            }, function () { fail('The map could not be loaded right now. Check your connection and reload.'); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
<?php endif; ?>

<?php include('backend/footer.php'); ?>
</body>
</html>

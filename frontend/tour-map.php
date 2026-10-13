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

// BUG FIX (customer-facing audit): this used to only require MapLat, not
// MapLng — a package with one coordinate set and the other blank would
// still show up here, get a marker attempt, silently fail the JS
// "if(!pkg.lat || !pkg.lng) return;" guard below, and then be unclickable
// from both the sidebar and search with no feedback. Now both are required.
$sql = "SELECT PackageId, PackageName, PackageLocation, PackagePrice, PackageType, PackageImage, MapLat, MapLng FROM tbltourpackages WHERE MapLat IS NOT NULL AND MapLat != '' AND MapLng IS NOT NULL AND MapLng != ''";
$query = $dbh->prepare($sql);
$query->execute();
$mapPackages = $query->fetchAll(PDO::FETCH_OBJ);

// Also get all packages for list
$sql2 = "SELECT * FROM tbltourpackages ORDER BY PackageId DESC";
$q2 = $dbh->prepare($sql2);
$q2->execute();
$allPackages = $q2->fetchAll(PDO::FETCH_OBJ);

// LIVE BUG FIX (customer-facing audit): the quick-filter pills, the map
// legend, and every marker/sidebar color used to be keyed on fictional
// PackageType values ("Family Package", "Couple Package", "Group Package",
// "Domestic Packages") that do not exist anywhere in this catalogue's real
// data (real types are things like Nature, Heritage, City, Beach, Island,
// Adventure, Wildlife...). The practical effect, confirmed live: every
// marker and every sidebar heading rendered in the same fallback orange
// (nothing ever matched), the legend's 4 colored categories described
// packages that don't exist, and clicking "Family"/"Couple"/"Group"/
// "Domestic" always returned zero results -- a completely dead filter row.
// Replaced with the real top types (same pattern as package-list.php's
// quick filters), each given a real color, so the legend/pills/markers
// all describe the actual catalogue.
$tmsMapTypeColors = [];
$tmsMapTypePalette = ['#27ae60', '#8e44ad', '#3498db', '#f1c40f', '#1abc9c', '#e67e22'];
try {
    $typeRows = $dbh->query(
        "SELECT PackageType, COUNT(*) AS n FROM tbltourpackages
         WHERE PackageType IS NOT NULL AND PackageType <> ''
         GROUP BY PackageType HAVING n > 0 ORDER BY n DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($typeRows as $i => $t) {
        $tmsMapTypeColors[$t['PackageType']] = [
            'color' => $tmsMapTypePalette[$i % count($tmsMapTypePalette)],
            'count' => (int)$t['n'],
        ];
    }
} catch (Exception $e) {
    error_log('[TMS] tour-map type colors failed: ' . $e->getMessage());
}
$tmsMapOtherColor = '#95a5a6';
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
<title>TMS | Interactive Map</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.map-hero { background: linear-gradient(135deg,#0f2027,#203a43,#2c5364); color:#fff; padding:50px 0 30px; text-align:center; }
.map-hero h1 { font-size:2.3em; margin-bottom:8px; }
.map-hero p { opacity:0.85; font-size:1.1em; }
#tourMap { height: 520px; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.2); margin:25px 0; }
.map-sidebar { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:20px; height:520px; overflow-y:auto; margin:25px 0; }
.map-sidebar h4 { color:#333; font-size:1em; border-bottom:2px solid #eee; padding-bottom:10px; margin-bottom:15px; }
.dest-item { padding:10px; border-radius:8px; cursor:pointer; transition:0.2s; margin-bottom:8px; border:1px solid #f0f0f0; }
.dest-item:hover, .dest-item.active { background: linear-gradient(135deg,#667eea11,#764ba211); border-color:#667eea; }
.dest-item h5 { font-size:13px; font-weight:700; margin:0 0 3px; color:#333; }
.dest-item p { font-size:12px; color:#777; margin:0; }
.dest-item span { font-size:12px; font-weight:700; color:#667eea; }
.dest-item-nomap { opacity:0.6; cursor:help; }
.dest-item-nomap:hover { background:#f7f7f7; border-color:#f0f0f0; }
.dest-nomap-badge { display:inline-block; margin-left:6px; padding:1px 7px; border-radius:10px; background:#fef3c7; color:#92400e; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.popup-card h4 { font-size:14px; font-weight:700; margin:0 0 5px; }
.popup-card p { font-size:12px; margin:2px 0; color:#555; }
.popup-card a { display:inline-block; background:#667eea; color:#fff; padding:5px 12px; border-radius:5px; font-size:12px; margin-top:8px; text-decoration:none; }
.map-legend { background:var(--white); border-radius:10px; padding:15px 20px; box-shadow:0 2px 15px rgba(0,0,0,0.1); margin-bottom:20px; }
.map-legend h5 { font-size:14px; font-weight:700; margin-bottom:10px; }
.legend-item { display:inline-block; margin-right:15px; font-size:13px; }
.legend-dot { width:12px; height:12px; border-radius:50%; display:inline-block; margin-right:5px; vertical-align:middle; }
.filter-pills { margin-bottom:20px; }
.filter-pill { display:inline-block; padding:6px 15px; border-radius:20px; font-size:13px; border:2px solid #667eea; color:#667eea; cursor:pointer; margin:3px; transition:0.2s; }
.filter-pill.active, .filter-pill:hover { background:#667eea; color:#fff; }

/* ── Destination search (live autocomplete) ── */
.map-search-wrap { position:relative; margin-bottom:20px; }
.map-search-input { width:100%; padding:12px 16px 12px 42px; border-radius:10px; border:2px solid #e5e7eb; font-size:14px; }
.map-search-input:focus { outline:none; border-color:#667eea; }
.map-search-icon { position:absolute; left:15px; top:13px; color:#999; }
.map-search-results { position:absolute; top:calc(100% + 6px); left:0; right:0; background:var(--white); border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,0.18); max-height:320px; overflow-y:auto; z-index:1000; display:none; }
.map-search-result { padding:10px 16px; cursor:pointer; border-bottom:1px solid #f2f2f2; }
.map-search-result:last-child { border-bottom:none; }
.map-search-result:hover { background:#f5f6fb; }
.map-search-result strong { display:block; font-size:13px; color:#333; }
.map-search-result span { font-size:12px; color:#888; }

/* ── Get Directions (Task 4 fix): this used to render the mode buttons and
     route stats INSIDE the marker's Leaflet popup, so the popup ballooned
     in height and Leaflet's own auto-pan/fitBounds behavior fought with it,
     covering most of the screen. It now lives in one compact panel docked
     to a corner of the map itself — closer to how Google's DirectionsRenderer
     info panel behaves — while the popup stays small and just triggers it. ── */
.directions-btn { display:inline-block; background:#27ae60 !important; margin-left:6px; }
.tms-dir-panel {
    position:absolute; left:12px; bottom:12px; z-index:900;
    width:250px; max-width:calc(100% - 24px);
    background:var(--white); border-radius:10px; box-shadow:0 6px 24px rgba(0,0,0,0.22);
    padding:12px 14px; display:none; font-size:13px;
}
.tms-dir-panel.open { display:block; }
.tms-dir-panel-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.tms-dir-panel-title { font-weight:700; font-size:13px; color:#333; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:190px; }
.tms-dir-panel-close { background:none; border:none; color:#999; font-size:16px; cursor:pointer; line-height:1; padding:2px 4px; }
.tms-dir-panel-close:hover { color:#e74c3c; }
.mode-btn { display:inline-block; padding:5px 9px; border-radius:6px; border:1px solid #ddd; font-size:11.5px; cursor:pointer; margin-right:5px; background:var(--white); }
.mode-btn.active { background:#667eea; color:#fff; border-color:#667eea; }
.directions-stats { font-size:12px; margin-top:8px; color:#333; }
html[data-theme="dark"] .map-sidebar h4,
html[data-theme="dark"] .dest-item h5,
html[data-theme="dark"] .map-search-result strong,
html[data-theme="dark"] .tms-dir-panel-title,
html[data-theme="dark"] .directions-stats { color: var(--text) !important; }
html[data-theme="dark"] .dest-item p,
html[data-theme="dark"] .map-search-icon,
html[data-theme="dark"] .map-search-result span { color: var(--text-muted) !important; }
.directions-stats strong { color:#667eea; }
@media (max-width:600px) { .tms-dir-panel { width:calc(100% - 24px); } }
.tms-you-are-here { position:relative; width:22px; height:22px; background:#4285f4; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; border:2px solid #fff; box-shadow:0 1px 4px rgba(0,0,0,0.4); }
.tms-you-are-here-pulse { position:absolute; inset:-8px; border-radius:50%; background:rgba(66,133,244,0.35); animation:tmsPulse 1.8s ease-out infinite; }
@keyframes tmsPulse { 0% { transform:scale(0.5); opacity:1; } 100% { transform:scale(1.6); opacity:0; } }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>


<div class="map-hero">
    <div class="container">
        <h1><i class="fa fa-map-marker"></i> Interactive Tour Map</h1>
        <p>Explore all tour destinations on a live interactive map</p>
    </div>
</div>

<div class="container" style="padding-top:10px;">
    <div class="map-search-wrap">
        <i class="fa fa-search map-search-icon"></i>
        <input type="text" class="map-search-input" id="destSearch" placeholder="Search by destination, district, division, country, or package name..." autocomplete="off">
        <div class="map-search-results" id="destSearchResults"></div>
    </div>

    <div class="map-legend">
        <h5><i class="fa fa-info-circle" style="color:#667eea"></i> Map Legend</h5>
        <?php foreach ($tmsMapTypeColors as $tName => $tInfo): ?>
        <span class="legend-item"><span class="legend-dot" style="background:<?php echo $tInfo['color']; ?>"></span> <?php echo htmlentities($tName); ?></span>
        <?php endforeach; ?>
        <span class="legend-item"><span class="legend-dot" style="background:<?php echo $tmsMapOtherColor; ?>"></span> Other</span>
    </div>

    <div class="filter-pills">
        <span class="filter-pill active" onclick="filterMap('all',this)">All</span>
        <?php foreach ($tmsMapTypeColors as $tName => $tInfo): ?>
        <span class="filter-pill" onclick="filterMap('<?php echo strtolower(htmlentities($tName)); ?>',this)"><?php echo htmlentities($tName); ?> (<?php echo $tInfo['count']; ?>)</span>
        <?php endforeach; ?>
        <?php
        // FIX (Round 114, package/places/map upgrade): the legend has always
        // shown an "Other" swatch for every PackageType outside the top 6
        // (line ~178), but there was no matching filter pill -- a visitor
        // could see the legend promise an "Other" category exists yet had no
        // way to actually isolate it. $tmsOtherCount mirrors getColor()'s own
        // fallback logic: any package whose type isn't one of the named top-6
        // (including a blank/NULL PackageType) falls into "Other".
        $tmsOtherCount = 0;
        foreach ($allPackages as $p) {
            if (!isset($tmsMapTypeColors[$p->PackageType ?? ''])) $tmsOtherCount++;
        }
        ?>
        <?php if ($tmsOtherCount > 0): ?>
        <span class="filter-pill" onclick="filterMap('other',this)">Other (<?php echo $tmsOtherCount; ?>)</span>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-md-9">
            <div style="position:relative;">
                <!-- ACCESSIBILITY FIX (Round 68, master prompt item 32): this
                     Leaflet map container had no accessible name -- a screen
                     reader announced it as a bare, unlabeled <div> (WCAG
                     1.1.1). role="region" + aria-label give it a landmark
                     name without asserting Leaflet's own keyboard/pan/zoom
                     interaction as an opaque "application" (which would
                     change how assistive tech reads everything inside it).
                     The map legend and destination sidebar next to it are
                     unaffected -- they already pair color with real text
                     labels. -->
                <div id="tourMap" role="region" aria-label="Interactive map of tour destinations"></div>
                <div class="tms-dir-panel" id="tmsDirPanel">
                    <div class="tms-dir-panel-head">
                        <span class="tms-dir-panel-title" id="tmsDirPanelTitle">Directions</span>
                        <button type="button" class="tms-dir-panel-close" onclick="closeDirections()" aria-label="Close directions" title="Close">&times;</button>
                    </div>
                    <div>
                        <span class="mode-btn active" data-mode="driving" onclick="setTravelMode(this)"><i class="fa fa-car"></i> Drive</span>
                        <span class="mode-btn" data-mode="cycling" onclick="setTravelMode(this)"><i class="fa fa-bicycle"></i> Bike</span>
                        <span class="mode-btn" data-mode="walking" onclick="setTravelMode(this)"><i class="fa fa-walking"></i> Walk</span>
                    </div>
                    <div class="directions-stats" id="tmsDirStats">Locating you…</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="map-sidebar">
                <h4><i class="fa fa-list" style="color:#667eea"></i> Destinations (<span id="destCount"><?php echo count($allPackages); ?></span>)</h4>
                <?php foreach($allPackages as $p):
                    $c = $tmsMapTypeColors[$p->PackageType]['color'] ?? $tmsMapOtherColor;
                    // BUG FIX: a package with no (or only partial) map coordinates
                    // used to render an identical, clickable-looking sidebar row
                    // that silently did nothing when clicked — no error, no
                    // feedback, just a dead click. Now it's visibly marked and
                    // clicking it explains why instead of doing nothing.
                    $hasCoords = !empty($p->MapLat) && !empty($p->MapLng);
                ?>
                <div class="dest-item<?php echo $hasCoords ? '' : ' dest-item-nomap'; ?>" id="dest-<?php echo $p->PackageId; ?>"
                    title="<?php echo $hasCoords ? '' : 'Location not mapped yet'; ?>"
                    onclick="focusDestination(<?php echo $p->PackageId; ?>, <?php echo $p->MapLat?:0; ?>, <?php echo $p->MapLng?:0; ?>)">
                    <h5 style="color:<?php echo $c; ?>"><?php echo htmlentities($p->PackageName); ?></h5>
                    <p><i class="fa fa-map-marker"></i> <?php echo htmlentities($p->PackageLocation); ?>
                        <?php if (!$hasCoords): ?><span class="dest-nomap-badge">Not mapped</span><?php endif; ?>
                    </p>
                    <span><?php echo tms_currency((float)$p->PackagePrice); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
var map = L.map('tourMap').setView([20, 70], 3);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors', maxZoom: 18
}).addTo(map);

var markers = {};
var allMarkerData = [];

var tmsTypeColors = <?php echo json_encode(array_map(function($t){ return $t['color']; }, $tmsMapTypeColors)); ?>;
var tmsOtherColor = <?php echo json_encode($tmsMapOtherColor); ?>;
function getColor(type) {
    return tmsTypeColors[type] || tmsOtherColor;
}

function createMarkerIcon(color) {
    return L.divIcon({
        html: '<div style="background:'+color+';width:14px;height:14px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>',
        className: '', iconSize:[14,14], iconAnchor:[7,7]
    });
}

// FIX (Round 14): 'price' used to be the raw number, and both JS render paths
// (map popup, search dropdown) prefixed a hardcoded '৳' — so a visitor who had
// selected USD saw "$ 55.00" in the sidebar and "৳6,000" in the popup for the
// SAME package. The price is now formatted server-side through tms_currency(),
// exactly like the sidebar, so there is a single source of truth. 'type' is
// also normalised to a string here, because PackageType is nullable and
// filterMap() called .toLowerCase() on it directly (a NULL row threw a
// TypeError mid-forEach and left the map half-filtered with no visible error).
var packages = <?php echo json_encode(array_map(function($p) {
    return [
        'id' => (int)$p->PackageId,
        'name' => (string)$p->PackageName,
        'location' => (string)($p->PackageLocation ?? ''),
        'price_display' => tms_currency((float)$p->PackagePrice),
        'type' => (string)($p->PackageType ?? ''),
        'lat' => floatval($p->MapLat),
        'lng' => floatval($p->MapLng),
        'image' => $p->PackageImage
    ];
}, $mapPackages), JSON_UNESCAPED_UNICODE); ?>;

// LIVE BUG FIX (customer-facing audit): `packages` above only holds
// destinations that HAVE map coordinates (~404 of 413). The sidebar lists
// ALL destinations, including the ~9 without coordinates (shown greyed out
// with a "Not mapped" badge). filterMap() used to loop over `packages` to
// decide which sidebar rows to hide, so those unmapped rows were silently
// skipped by every filter and stayed visible no matter what was selected,
// and destCount showed a different total ("all" -> only the mappable
// count) than the number the page loaded with. This full id+type list lets
// filtering and counting cover every sidebar row, matching what the visitor
// actually sees.
// FIX (Round 114, package/places/map upgrade): originally id+type only,
// added name/location/price_display/lat/lng/hasCoords here too, so the
// destination search box below can search (and show) every sidebar row --
// see the note on the search box itself for why that used to disagree with
// the sidebar.
var allDestMeta = <?php echo json_encode(array_map(function($p) {
    $hasCoords = !empty($p->MapLat) && !empty($p->MapLng);
    return [
        'id' => (int)$p->PackageId,
        'type' => (string)($p->PackageType ?? ''),
        'name' => (string)$p->PackageName,
        'location' => (string)($p->PackageLocation ?? ''),
        'price_display' => tms_currency((float)$p->PackagePrice),
        'lat' => $hasCoords ? floatval($p->MapLat) : null,
        'lng' => $hasCoords ? floatval($p->MapLng) : null,
        'hasCoords' => $hasCoords,
    ];
}, $allPackages), JSON_UNESCAPED_UNICODE); ?>;

// Escape anything that goes into innerHTML. Package names come from the admin
// form, which only strip_tags()es them — so a name containing a quote used to
// break the "Get Directions" onclick attribute and silently kill that button,
// and anything script-like went straight into the DOM. js/explore.js already
// had this helper; the map didn't.
function tmEsc(v) {
    var d = document.createElement('div');
    d.textContent = (v === null || v === undefined) ? '' : String(v);
    return d.innerHTML;
}

// FIX (Round 114, package/places/map upgrade): a coordinate of exactly 0
// (the equator, or the prime meridian) is a legitimate real-world latitude
// or longitude -- but `!pkg.lat`/`!lat` treats the JS number 0 as falsy,
// same as null/undefined/NaN. Every "does this package have a usable
// coordinate" check in this file used that pattern, so a destination whose
// admin-entered MapLat or MapLng happened to be exactly 0 would silently get
// no marker at all and be unfocusable, indistinguishable from having no
// coordinates set -- with no error, exactly the kind of silent failure this
// page's other BUG FIX comments already went after. No real TMS destination
// is currently at 0,0, so this is a correctness fix for a case that hasn't
// bitten yet, not a live bug being reported.
function tmHasCoord(v) {
    return typeof v === 'number' && !isNaN(v);
}

packages.forEach(function(pkg) {
    if(!tmHasCoord(pkg.lat) || !tmHasCoord(pkg.lng)) return;
    var color = getColor(pkg.type);
    var popupId = 'popup-' + pkg.id;
    var marker = L.marker([pkg.lat, pkg.lng], {icon: createMarkerIcon(color)})
        .addTo(map)
        .bindPopup('<div class="popup-card" id="'+popupId+'">'+
            '<h4>'+tmEsc(pkg.name)+'</h4>'+
            '<p><i class="fa fa-map-marker"></i> '+tmEsc(pkg.location)+'</p>'+
            (pkg.type ? '<p><i class="fa fa-tag"></i> '+tmEsc(pkg.type)+'</p>' : '')+
            '<p><strong>'+tmEsc(pkg.price_display)+'</strong></p>'+
            '<a href="package-details.php?pkgid='+pkg.id+'">View &amp; Book</a> '+
            '<a href="javascript:void(0)" class="directions-btn" data-dir-id="'+pkg.id+'" style="color:#fff;padding:5px 12px;border-radius:5px;font-size:12px;text-decoration:none;"><i class="fa fa-route"></i> Get Directions</a>'+
        '</div>', {maxWidth:240});
    marker.pkgType = pkg.type;
    markers[pkg.id] = {marker: marker, data: pkg};
    allMarkerData.push({id: pkg.id, marker: marker, type: pkg.type});
});

// Directions buttons live inside Leaflet popups that are created and destroyed
// on demand, so the handler is delegated from the map container rather than
// written into each popup's HTML.
document.getElementById('tourMap').addEventListener('click', function(e) {
    var btn = e.target.closest ? e.target.closest('[data-dir-id]') : null;
    if (!btn) return;
    e.preventDefault();
    var rec = markers[btn.getAttribute('data-dir-id')];
    if (rec) startDirections(rec.data.id, rec.data.lat, rec.data.lng, rec.data.name);
});

function focusDestination(id, lat, lng) {
    if(markers[id] && tmHasCoord(lat) && tmHasCoord(lng)) {
        map.setView([lat, lng], 7);
        markers[id].marker.openPopup();
        document.querySelectorAll('.dest-item').forEach(function(el){ el.classList.remove('active'); });
        document.getElementById('dest-'+id).classList.add('active');
    } else if (typeof tmsToast === 'function') {
        // BUG FIX: this used to fail the check above and do absolutely
        // nothing — no message, no highlight — for any package without map
        // coordinates. Now it tells the visitor why instead of looking broken.
        tmsToast("We don't have a map location for this package yet — check its package page for full details.", 'info');
    }
}

// FIX (Round 114): 'other' isn't a real PackageType -- it's the legend/pill
// name for "not one of the top-6 named types" (mirrors getColor()'s own
// fallback to tmsOtherColor). Before this fix, filterMap('other', ...) fell
// into the same substring-match branch as every real type, so clicking the
// new "Other" pill only matched a type whose name literally contained the
// substring "other" -- i.e. matched nothing in this catalogue. This checks
// membership in the real named-type set instead, the same test getColor()
// implicitly makes.
var tmsNamedTypesLower = Object.keys(tmsTypeColors).map(function(t){ return t.toLowerCase(); });
function tmsTypeMatches(itemType, filterType) {
    var t = String(itemType || '').toLowerCase();
    if (filterType === 'all') return true;
    if (filterType === 'other') return tmsNamedTypesLower.indexOf(t) === -1;
    return t.indexOf(filterType) !== -1;
}

function filterMap(type, el) {
    document.querySelectorAll('.filter-pill').forEach(function(p){ p.classList.remove('active'); });
    el.classList.add('active');

    var visible = 0;
    allMarkerData.forEach(function(item){
        var match = tmsTypeMatches(item.type, type);
        if (match) { item.marker.addTo(map); visible++; }
        else { map.removeLayer(item.marker); }
    });

    // Keep the sidebar in step with the map, so every row you can see is a row
    // you can actually click. Uses allDestMeta (every sidebar row, mapped or
    // not) rather than `packages` (mappable rows only) -- see the note above
    // allDestMeta's declaration.
    var sidebarVisible = 0;
    allDestMeta.forEach(function(pkg) {
        var row = document.getElementById('dest-' + pkg.id);
        if (!row) return;
        var match = tmsTypeMatches(pkg.type, type);
        row.style.display = match ? '' : 'none';
        if (match) sidebarVisible++;
    });

    var counter = document.getElementById('destCount');
    if (counter) counter.textContent = sidebarVisible;
}

// Auto-focus a destination passed via ?focus=ID (e.g. from a package details page)
(function(){
    var params = new URLSearchParams(window.location.search);
    var focusId = params.get('focus');
    if (focusId && markers[focusId]) {
        var d = markers[focusId].data;
        window.addEventListener('load', function() {
            focusDestination(parseInt(focusId, 10), d.lat, d.lng);
        });
    }
})();

/* ══════════════════════ DESTINATION SEARCH (live autocomplete) ══════════════════════
   Searches package name + location text (which already contains district/division/
   country, e.g. "Gazipur, Bangladesh" or "Sylhet, Bangladesh") client-side.
   FIX (Round 114, package/places/map upgrade): this used to search `packages`
   -- the ~404 destinations WITH map coordinates only -- while the sidebar
   right next to it lists all ~413 destinations, showing the ~9 unmapped ones
   greyed out with a "Not mapped" badge instead of hiding them. A visitor
   typing the name of one of those ~9 into search got "No matching
   destinations", flatly contradicting what the sidebar showed one scroll
   away. Now searches `allDestMeta` (every destination, mapped or not, same
   list the sidebar itself renders from) and marks unmapped results the same
   way the sidebar does; selecting one shows the same "not mapped yet" toast
   selectSearchResult()/focusDestination() already give the sidebar, instead
   of silently doing nothing or crashing on a null lat/lng. */
var searchInput = document.getElementById('destSearch');
var searchResults = document.getElementById('destSearchResults');

searchInput.addEventListener('input', function() {
    var q = this.value.trim().toLowerCase();
    if (q.length < 2) { searchResults.style.display = 'none'; return; }
    var matches = allDestMeta.filter(function(pkg) {
        return (pkg.name + ' ' + pkg.location + ' ' + pkg.type).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 12);
    if (!matches.length) {
        searchResults.innerHTML = '<div class="map-search-result" style="color:#999;">No matching destinations</div>';
        searchResults.style.display = 'block';
        return;
    }
    searchResults.innerHTML = matches.map(function(pkg) {
        return '<div class="map-search-result'+(pkg.hasCoords ? '' : ' dest-item-nomap')+'" onclick="selectSearchResult('+pkg.id+','+(pkg.hasCoords ? pkg.lat : 'null')+','+(pkg.hasCoords ? pkg.lng : 'null')+')">' +
               '<strong>'+tmEsc(pkg.name)+(pkg.hasCoords ? '' : ' <span class="dest-nomap-badge">Not mapped</span>')+'</strong><span><i class="fa fa-map-marker"></i> '+
               tmEsc(pkg.location)+' · '+tmEsc(pkg.price_display)+'</span></div>';
    }).join('');
    searchResults.style.display = 'block';
});
document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        searchResults.style.display = 'none';
    }
});
function selectSearchResult(id, lat, lng) {
    searchResults.style.display = 'none';
    searchInput.value = markers[id] ? markers[id].data.name : '';
    focusDestination(id, lat, lng);
}

/* ══════════════════════ GET DIRECTIONS (real routing via OSRM) ══════════════════════
   Uses the public OSRM demo instances hosted by the OpenStreetMap.de project,
   which (unlike the main router.project-osrm.org demo) expose separate car /
   bike / foot profiles — giving real turn-by-turn-quality routes rather than
   a straight line, for all three transport modes the brief asked for. */
var routeLine = null;
var startMarker = null;
var userStart = null; // {lat, lng}
var activeDestination = null; // {id, lat, lng}
var manualPickMode = false;

var OSRM_ENDPOINTS = {
    driving: { base: 'https://routing.openstreetmap.de/routed-car/route/v1/driving/', profile: 'driving' },
    cycling: { base: 'https://routing.openstreetmap.de/routed-bike/route/v1/bike/', profile: 'cycling' },
    walking: { base: 'https://routing.openstreetmap.de/routed-foot/route/v1/foot/', profile: 'walking' }
};
var currentMode = 'driving';

function startDirections(id, lat, lng, name) {
    activeDestination = { id: id, lat: lat, lng: lng };
    currentMode = 'driving';
    map.closePopup(); // the popup only triggers directions now — it stays small and out of the way
    document.querySelectorAll('#tmsDirPanel .mode-btn').forEach(function(b){ b.classList.remove('active'); });
    document.querySelector('#tmsDirPanel .mode-btn[data-mode="driving"]').classList.add('active');
    document.getElementById('tmsDirPanelTitle').textContent = name || 'Directions';
    var panel = document.getElementById('tmsDirPanel');
    var statsEl = document.getElementById('tmsDirStats');
    panel.classList.add('open');

    if (userStart) {
        computeRoute();
        return;
    }
    if (!navigator.geolocation) {
        statsEl.innerHTML = 'Geolocation not supported. Click anywhere on the map to set your starting point.';
        enableManualPick();
        return;
    }
    statsEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Getting your location…';
    navigator.geolocation.getCurrentPosition(function(pos) {
        userStart = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        placeStartMarker();
        computeRoute();
    }, function() {
        statsEl.innerHTML = 'Location permission denied. Click anywhere on the map to set your starting point instead.';
        enableManualPick();
    }, { timeout: 8000 });
}

function placeStartMarker() {
    if (startMarker) map.removeLayer(startMarker);
    startMarker = L.marker([userStart.lat, userStart.lng], {
        icon: L.divIcon({ html:'<div class="tms-you-are-here"><div class="tms-you-are-here-pulse"></div><i class="fa fa-user"></i></div>', className:'', iconSize:[22,22], iconAnchor:[11,11] })
    }).addTo(map).bindPopup('Your current location');
}

function closeDirections() {
    document.getElementById('tmsDirPanel').classList.remove('open');
    if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
    activeDestination = null;
    manualPickMode = false;
    map.getContainer().style.cursor = '';
}

function enableManualPick() {
    manualPickMode = true;
    map.getContainer().style.cursor = 'crosshair';
}
map.on('click', function(e) {
    if (!manualPickMode) return;
    userStart = { lat: e.latlng.lat, lng: e.latlng.lng };
    manualPickMode = false;
    map.getContainer().style.cursor = '';
    placeStartMarker();
    computeRoute();
});

function setTravelMode(el) {
    document.querySelectorAll('#tmsDirPanel .mode-btn').forEach(function(b){ b.classList.remove('active'); });
    el.classList.add('active');
    currentMode = el.getAttribute('data-mode');
    if (!userStart) {
        // FIX (Round 14): this called computeRoute(), which returns immediately
        // without a start point — so after a geolocation denial these buttons
        // visibly responded while doing nothing at all. Re-state what's needed.
        var statsEl = document.getElementById('tmsDirStats');
        if (statsEl) {
            statsEl.innerHTML = '<i class="fa fa-hand-pointer-o"></i> Pick your starting point first — use "Locate me", or click anywhere on the map.';
        }
        return;
    }
    computeRoute();
}

function computeRoute() {
    if (!userStart || !activeDestination) return;
    var statsEl = document.getElementById('tmsDirStats');
    statsEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Calculating ' + currentMode + ' route…';

    var ep = OSRM_ENDPOINTS[currentMode];
    var url = ep.base + userStart.lng + ',' + userStart.lat + ';' + activeDestination.lng + ',' + activeDestination.lat + '?overview=full&geometries=geojson';

    fetch(url).then(function(r){ return r.json(); }).then(function(data){
        if (!data.routes || !data.routes.length) throw new Error('no route');
        var route = data.routes[0];
        if (routeLine) map.removeLayer(routeLine);
        var latlngs = route.geometry.coordinates.map(function(c){ return [c[1], c[0]]; });
        var routeColors = { driving:'#667eea', cycling:'#27ae60', walking:'#f39c12' };
        routeLine = L.polyline(latlngs, { color: routeColors[currentMode] || '#667eea', weight: 5, opacity: 0.85 }).addTo(map);
        // Padding is asymmetric on the left/bottom so the fitted route doesn't
        // duck under the directions panel docked in that corner (Task 4 fix —
        // "the route should remain visible" while the info panel stays put).
        map.fitBounds(routeLine.getBounds(), { paddingTopLeft: [40,40], paddingBottomRight: [40,180] });

        var km = (route.distance / 1000).toFixed(1);
        var mins = Math.round(route.duration / 60);
        var timeLabel = mins >= 60 ? Math.floor(mins/60) + 'h ' + (mins%60) + 'm' : mins + ' min';
        statsEl.innerHTML = '<strong>' + km + ' km</strong> · <strong>' + timeLabel + '</strong> by ' + currentMode +
            '<br><span style="color:#999;">Route shown on map · OpenStreetMap data</span>';
    }).catch(function(){
        statsEl.innerHTML = 'Could not calculate a route right now. You can still get directions manually via <a href="https://www.openstreetmap.org/directions?from='+userStart.lat+'%2C'+userStart.lng+'&to='+activeDestination.lat+'%2C'+activeDestination.lng+'" target="_blank" rel="noopener">OpenStreetMap</a>.';
    });
}
</script>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_admin_require_role($dbh, ['superadmin', 'package_manager']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Popular Destinations</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-map-marker" style="color:#1d4ed8;margin-right:10px;"></i>Popular Destinations</h2>
</div>
<p style="color:#64748b;font-size:13px;margin-top:-12px;margin-bottom:22px;">
  Three different signals of "popular," each from real usage data — not just one blended guess.
</p>

<?php
function safe_q(PDO $db, string $sql, array $p=[]) {
    try { $q=$db->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_OBJ); }
    catch(Exception $e){ return []; }
}

// Signal 1: what people actually look up on the Explore page (tbl_explore_history
// logs every AI-explore lookup with a display_name). This is browsing interest,
// independent of whether it ever turns into a booking.
$mostExplored = safe_q($dbh, "SELECT display_name, COUNT(*) as cnt, MAX(created_at) as last_seen
    FROM tbl_explore_history WHERE display_name IS NOT NULL AND display_name != ''
    GROUP BY display_name ORDER BY cnt DESC LIMIT 10");

// Signal 2: wishlisted packages — intent to book, not yet converted.
$mostWishlisted = safe_q($dbh, "SELECT p.PackageName, p.PackageLocation, COUNT(*) as cnt
    FROM tblwishlist w JOIN tbltourpackages p ON p.PackageId = w.PackageId
    GROUP BY w.PackageId ORDER BY cnt DESC LIMIT 10");

// Signal 3: actual confirmed bookings, grouped by the package's destination
// (PackageLocation) rather than by individual package — several packages can
// share a destination, and this rolls them up the way "popular destinations"
// usually means it, distinct from "Top Packages by Revenue" on the Revenue
// Analytics page (that's per-package, this is per-place).
$mostBookedPlaces = safe_q($dbh, "SELECT p.PackageLocation, COUNT(*) as cnt,
    AVG(p.MapLat) as lat, AVG(p.MapLng) as lng
    FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
    WHERE b.status = 1 AND p.PackageLocation IS NOT NULL AND p.PackageLocation != ''
    GROUP BY p.PackageLocation ORDER BY cnt DESC LIMIT 10");

// Coordinates for the map view — only destinations where at least one
// package in that location actually has MapLat/MapLng set. Bubble size is
// proportional to real booking count, not a guess.
$mapBookedPlaces = array_values(array_filter($mostBookedPlaces, fn($r) => !empty($r->lat) && !empty($r->lng)));
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;"><i class="fa fa-search" style="color:#1F8A66;margin-right:8px;"></i>Most Explored</h3>
    <div style="font-size:11.5px;color:#94a3b8;margin-bottom:14px;">From Explore page AI lookups</div>
    <div class="table-responsive"><table class="tms-table" style="font-size:12px;">
      <thead><tr><th>#</th><th>Destination</th><th>Lookups</th></tr></thead>
      <tbody>
      <?php if (empty($mostExplored)): ?>
      <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px;">No explore activity logged yet.</td></tr>
      <?php else: $i=1; foreach ($mostExplored as $r): ?>
      <tr>
        <td><?=$i++?></td>
        <td><?=htmlspecialchars($r->display_name)?></td>
        <td><?=$r->cnt?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;"><i class="fa fa-heart" style="color:#e11d48;margin-right:8px;"></i>Most Wishlisted</h3>
    <div style="font-size:11.5px;color:#94a3b8;margin-bottom:14px;">Saved but not yet booked</div>
    <div class="table-responsive"><table class="tms-table" style="font-size:12px;">
      <thead><tr><th>#</th><th>Package</th><th>Saves</th></tr></thead>
      <tbody>
      <?php if (empty($mostWishlisted)): ?>
      <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px;">No wishlist activity yet.</td></tr>
      <?php else: $i=1; foreach ($mostWishlisted as $r): ?>
      <tr>
        <td><?=$i++?></td>
        <td><?=htmlspecialchars($r->PackageName)?> <small style="color:#94a3b8;">(<?=htmlspecialchars($r->PackageLocation)?>)</small></td>
        <td><?=$r->cnt?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;"><i class="fa fa-check-circle" style="color:#16a34a;margin-right:8px;"></i>Most Booked</h3>
    <div style="font-size:11.5px;color:#94a3b8;margin-bottom:14px;">Confirmed bookings, by destination</div>
    <div class="table-responsive"><table class="tms-table" style="font-size:12px;">
      <thead><tr><th>#</th><th>Destination</th><th>Confirmed Bookings</th></tr></thead>
      <tbody>
      <?php if (empty($mostBookedPlaces)): ?>
      <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px;">No confirmed bookings yet.</td></tr>
      <?php else: $i=1; foreach ($mostBookedPlaces as $r): ?>
      <tr>
        <td><?=$i++?></td>
        <td><?=htmlspecialchars($r->PackageLocation)?></td>
        <td><?=$r->cnt?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php if (!empty($mapBookedPlaces)): ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-top:20px;">
  <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;"><i class="fa fa-globe" style="color:#0891b2;margin-right:8px;"></i>Booking Heat Map</h3>
  <div style="font-size:11.5px;color:#94a3b8;margin-bottom:14px;">Bubble size = confirmed bookings at that destination. Only destinations with a package that has map coordinates set are shown — no estimated pins.</div>
  <div id="destHeatMap" style="height:380px;border-radius:10px;background:#eef2f5;"></div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function(){
    var places = <?php echo json_encode(array_map(fn($r) => [
        'name' => $r->PackageLocation,
        'count' => (int)$r->cnt,
        'lat' => (float)$r->lat,
        'lng' => (float)$r->lng,
    ], $mapBookedPlaces), JSON_UNESCAPED_SLASHES); ?>;
    if (!places.length) return;
    var map = L.map('destHeatMap');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 18
    }).addTo(map);
    var maxCount = Math.max.apply(null, places.map(function(p){ return p.count; }));
    var bounds = [];
    places.forEach(function(p){
        var radius = 10 + (p.count / maxCount) * 30; // 10–40px, scaled to the busiest destination
        L.circleMarker([p.lat, p.lng], {
            radius: radius,
            fillColor: '#e11d48',
            fillOpacity: 0.35 + (p.count / maxCount) * 0.4,
            color: '#e11d48',
            weight: 1.5,
        }).addTo(map).bindPopup('<strong>' + p.name.replace(/</g,'&lt;') + '</strong><br>' + p.count + ' confirmed booking' + (p.count===1?'':'s'));
        bounds.push([p.lat, p.lng]);
    });
    map.fitBounds(bounds, { padding: [30,30], maxZoom: 6 });
})();
</script>
<?php endif; ?>

<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user(); // Batch 1 (landing/auth gating): whole app now requires login
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<title>TMS | Flight Tracker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.ft-card{background:var(--white);border-radius:12px;box-shadow:0 3px 20px rgba(0,0,0,0.07);padding:24px;margin-bottom:24px;}
.scope-note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12.5px;padding:12px 16px;border-radius:8px;margin-bottom:20px;display:flex;gap:10px;align-items:flex-start;}
#flightMap{height:380px;border-radius:10px;margin-top:16px;display:none;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:14px;margin-top:16px;}
.stat-box{background:var(--bg);border-radius:10px;padding:14px;text-align:center;}
.stat-box .val{font-size:20px;font-weight:800;color:var(--text);}
.stat-box .lbl{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-top:2px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-plane"></i> Flight Tracker</h2>
  <p style="margin:6px 0 0;opacity:.9;font-size:13.5px;">Live aircraft position tracking, powered by OpenSky Network's community ADS-B data.</p></div>
</div>
<div class="container" style="padding-bottom:50px;max-width:800px;">

<div class="scope-note">
  <i class="fa fa-info-circle" style="margin-top:2px;"></i>
  <span><strong>What this shows:</strong> real live aircraft position (altitude, speed, heading) if the flight is currently
  airborne and broadcasting ADS-B. <strong>What it doesn't show:</strong> gate numbers, check-in status, or delay minutes —
  that requires a paid airline-data subscription this project doesn't use. Coverage also varies by region (denser over
  Europe/North America, sparser over South Asia), so a real flight may simply not appear right now — that's normal, not an error.</span>
</div>

<div class="ft-card">
  <form id="flightForm" onsubmit="return trackFlight(event)">
    <div class="form-group">
      <label for="flightNumberInput">Flight Number</label>
      <div style="display:flex;gap:10px;">
        <input type="text" id="flightNumberInput" class="form-control" placeholder="e.g. BAF123, SIA318" required style="text-transform:uppercase;">
        <button type="submit" class="btn btn-primary" style="white-space:nowrap;" id="trackBtn"><i class="fa fa-search"></i> Track</button>
      </div>
    </div>
  </form>

  <div id="resultBox"></div>
  <div id="flightMap"></div>
</div>

</div>
<?php include('backend/footer.php'); ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let flightMap = null, flightMarker = null;

async function trackFlight(e) {
  e.preventDefault();
  const btn = document.getElementById('trackBtn');
  const resultBox = document.getElementById('resultBox');
  const flightNumber = document.getElementById('flightNumberInput').value.trim();
  if (!flightNumber) return false;

  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Searching...';
  resultBox.innerHTML = '<div style="margin-top:16px;color:var(--text-muted);font-size:13px;"><i class="fa fa-circle-o-notch fa-spin"></i> Checking live aircraft positions...</div>';
  document.getElementById('flightMap').style.display = 'none';

  try {
    const body = new URLSearchParams({ action: 'flight_status', flight_number: flightNumber });
    const res = await fetch('api/ai.php?action=flight_status', { method: 'POST', body: body });
    const data = await res.json();

    btn.disabled = false;
    btn.innerHTML = '<i class="fa fa-search"></i> Track';

    if (!data.success) {
      resultBox.innerHTML = '<div class="alert alert-warning" style="margin-top:16px;">' + (data.error || data.message || 'Could not check flight status right now.') + '</div>';
      return false;
    }

    if (!data.data.found) {
      resultBox.innerHTML = '<div class="alert alert-info" style="margin-top:16px;"><i class="fa fa-info-circle"></i> ' + data.data.message + '</div>';
      return false;
    }

    const f = data.data.flight;
    let statusHtml = '<div class="alert alert-success" style="margin-top:16px;">';
    statusHtml += '<strong><i class="fa fa-plane"></i> ' + f.callsign + '</strong> — ';
    statusHtml += f.on_ground ? 'On the ground' : (f.climbing ? 'Climbing' : (f.descending ? 'Descending' : 'Cruising'));
    if (f.origin_country) statusHtml += ' &middot; Operator country: ' + f.origin_country;
    statusHtml += '</div>';
    statusHtml += '<div class="stat-grid">';
    statusHtml += '<div class="stat-box"><div class="val">' + (f.altitude_ft !== null ? f.altitude_ft.toLocaleString() : '—') + '</div><div class="lbl">Altitude (ft)</div></div>';
    statusHtml += '<div class="stat-box"><div class="val">' + (f.speed_kmh !== null ? f.speed_kmh : '—') + '</div><div class="lbl">Speed (km/h)</div></div>';
    statusHtml += '<div class="stat-box"><div class="val">' + (f.heading_deg !== null ? Math.round(f.heading_deg) + '°' : '—') + '</div><div class="lbl">Heading</div></div>';
    statusHtml += '</div>';
    // Master prompt item 64 (source provenance): this is a live, perishable
    // position -- the aircraft has moved on by the time it's read -- so show
    // where it came from and when, the same "Live via X · updated HH:MM"
    // pattern already used on weather.php, instead of the previous generic,
    // page-level "powered by OpenSky" blurb that wasn't tied to this actual
    // result.
    if (f.source || f.fetched_at) {
      statusHtml += '<p style="font-size:10.5px;margin-top:10px;opacity:0.75;">';
      statusHtml += 'Source: ' + (f.source === 'opensky' ? 'OpenSky Network' : (f.source || 'unknown'));
      if (f.fetched_at) {
        const t = new Date(f.fetched_at);
        if (!isNaN(t.getTime())) {
          statusHtml += ' &middot; position as of ' + t.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        }
      }
      statusHtml += '</p>';
    }
    resultBox.innerHTML = statusHtml;

    if (f.latitude && f.longitude) {
      const mapEl = document.getElementById('flightMap');
      mapEl.style.display = 'block';
      if (!flightMap) {
        flightMap = L.map('flightMap');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(flightMap);
      }
      flightMap.setView([f.latitude, f.longitude], 7);
      if (flightMarker) flightMap.removeLayer(flightMarker);
      flightMarker = L.marker([f.latitude, f.longitude]).addTo(flightMap)
        .bindPopup('<strong>' + f.callsign + '</strong><br>' + (f.altitude_ft || '—') + ' ft &middot; ' + (f.speed_kmh || '—') + ' km/h').openPopup();
      setTimeout(() => flightMap.invalidateSize(), 100);
    }
  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa fa-search"></i> Track';
    resultBox.innerHTML = '<div class="alert alert-danger" style="margin-top:16px;">Something went wrong — please try again.</div>';
  }
  return false;
}
</script>
</body></html>

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

// Add/remove from compare
if (isset($_POST['add_compare'])) {
    tms_csrf_validate();
    $pkgId = tms_int($_POST['pkgid'] ?? 0, 1);
    if (!isset($_SESSION['compare'])) $_SESSION['compare'] = [];
    if (!in_array($pkgId, $_SESSION['compare']) && count($_SESSION['compare']) < 3) {
        $_SESSION['compare'][] = $pkgId;
    }
    if (isset($_SERVER['HTTP_REFERER'])) { header('Location: '.$_SERVER['HTTP_REFERER']); exit; }
}
if (isset($_POST['remove_compare'])) {
    tms_csrf_validate();
    $pkgId = tms_int($_POST['pkgid'] ?? 0, 1);
    $_SESSION['compare'] = array_values(array_diff($_SESSION['compare'] ?? [], [$pkgId]));
    header('Location: compare.php'); exit;
}
if (isset($_GET['clear'])) { $_SESSION['compare'] = []; header('Location: compare.php'); exit; }

$compare = $_SESSION['compare'] ?? [];
$packages = [];
if (!empty($compare)) {
    $in = implode(',', array_map('intval', $compare));
    $q = $dbh->query("SELECT * FROM tbltourpackages WHERE PackageId IN ($in)");
    $packages = $q->fetchAll(PDO::FETCH_OBJ);
}

$fields = [
    'PackageName'        => tms_t('label_package_name','Package Name'),
    'PackageType'        => tms_t('label_type','Type'),
    'PackageLocation'    => tms_t('label_destination','Destination'),
    'PackageDuration'    => tms_t('label_duration','Duration'),
    'PackagePrice'       => tms_t('label_price_per_person','Price (per person)'),
    'AvgRating'          => tms_t('label_rating','Rating'),
    'HotelName'          => tms_t('label_hotel','Hotel'),
    'MealPlan'           => tms_t('label_meals','Meals'),
    'TransportMode'      => tms_t('label_transport','Transport'),
    'PackageFetures'     => tms_t('label_highlights','Highlights'),
    'PackageDescription' => tms_t('label_description','Description'),
];

// Live current weather per destination, only for packages that actually have
// coordinates set (MapLat/MapLng) — honestly shown as "Not available" rather
// than faked for packages without them. Uses the same tms_get_weather()
// helper already proven on weather.php/package-details.php (OpenWeatherMap
// if a key is configured, otherwise the free Open-Meteo fallback — never
// simulated data, see the comments in backend/config.php).
// Round 14: this used to collapse "this package has no map location" and "the
// weather API just timed out" into the same null, and the cell then said
// "not available for this destination YET" — telling the user the destination
// is missing data when the real cause may be a transient outage that a refresh
// would fix. Keep the reason and let the cell say which it is.
$compareWeather = [];
foreach ($packages as $p) {
    if (!empty($p->MapLat) && !empty($p->MapLng)) {
        $w = tms_get_weather($dbh, (float)$p->MapLat, (float)$p->MapLng, $p->PackageLocation ?? '');
        $compareWeather[$p->PackageId] = $w['ok'] ? $w : ['ok' => false, 'error' => $w['error'] ?? 'fetch_failed'];
    } else {
        $compareWeather[$p->PackageId] = ['ok' => false, 'error' => 'no_coords'];
    }
}
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Compare Packages</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.compare-table{width:100%;border-collapse:separate;border-spacing:0;background:var(--white);border-radius:var(--radius-md);overflow:hidden;box-shadow:var(--shadow-sm);}
.compare-table th,.compare-table td{border-bottom:1px solid rgba(15,76,129,0.08);padding:16px 18px;vertical-align:top;font-size:13.5px;color:var(--text);}
.compare-table th{background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-size:14px;text-align:center;font-family:var(--font-display);font-weight:600;}
.compare-table tbody tr:hover td{background:var(--bg);}
.compare-table .field-name{font-weight:700;color:var(--text-muted);background:var(--bg) !important;width:150px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
.compare-table td img{width:100%;height:150px;object-fit:cover;border-radius:var(--radius-sm);margin-bottom:8px;}
.badge-price{background:var(--secondary);color:#fff;padding:4px 12px;border-radius:20px;font-size:14px;font-weight:700;font-family:var(--font-mono);}
.compare-stars i{color:var(--brass);font-size:13px;}
.compare-weather{display:flex;align-items:center;gap:8px;}
.compare-weather .temp{font-family:var(--font-mono);font-weight:700;font-size:16px;color:var(--primary);}
.compare-weather .cond{font-size:12px;color:var(--text-muted);}
.compare-na{color:var(--text-light);font-style:italic;font-size:12px;}
.empty-state{text-align:center;padding:60px 20px;background:var(--white);border-radius:var(--radius-md);box-shadow:var(--shadow-sm);}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-columns"></i> <?php echo tms_t('heading_compare_packages','Compare Packages'); ?></h2></div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php if (count($packages) === 0): ?>
<div class="empty-state">
  <i class="fa fa-columns" style="font-size:64px;color:#ddd;"></i>
  <h3 style="margin-top:16px;"><?php echo tms_t('msg_no_packages_to_compare','No packages to compare'); ?></h3>
  <p style="color:#888;"><?php echo tms_t('dest_compare_instructions','Go to <a href="package-list.php">Tour Packages</a> and click "Compare" on up to 3 packages.'); ?></p>
</div>
<?php else: ?>
<div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
  <span style="color:#666;"><?= count($packages) ?> <?php echo tms_t('label_packages_selected_suffix','package(s) selected (max 3)'); ?></span>
  <a href="compare.php?clear=1" class="btn btn-default btn-sm"><i class="fa fa-times"></i> <?php echo tms_t('btn_clear_all','Clear All'); ?></a>
</div>
<div class="table-responsive">
<table class="compare-table">
  <thead>
    <tr>
      <th style="width:160px;"><?php echo tms_t('label_feature','Feature'); ?></th>
      <?php foreach ($packages as $p): ?>
      <th>
        <img loading="lazy" src="<?= htmlspecialchars(tms_package_image_src($p->PackageImage),ENT_QUOTES,'UTF-8') ?>"
             onerror="this.src='images/no-image-placeholder.jpg'" alt="pkg"><br>
        <?= htmlspecialchars($p->PackageName,ENT_QUOTES,'UTF-8') ?>
        <form method="post" style="margin-top:6px;display:inline;">
        <?php tms_csrf_field(); ?>
          <input type="hidden" name="pkgid" value="<?= (int)$p->PackageId ?>">
          <button name="remove_compare" class="btn btn-xs btn-danger"><i class="fa fa-times"></i></button>
        </form>
      </th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($fields as $col => $label): ?>
    <tr>
      <td class="field-name"><?= $label ?></td>
      <?php foreach ($packages as $p): ?>
      <td>
        <?php if ($col === 'PackagePrice'): ?>
          <?php $cmpPricing = ((float)$p->PackagePrice > 0) ? tms_dynamic_price($dbh, (int)$p->PackageId, (float)$p->PackagePrice) : null; ?>
          <span class="badge-price"><?= tms_currency((float)($cmpPricing['final'] ?? $p->$col)) ?></span>
          <?php if (!empty($cmpPricing) && $cmpPricing['demand_pct'] > 0): ?>
          <div style="font-size:10px;color:#f97316;font-weight:600;margin-top:4px;"><i class="fa fa-bolt"></i> <?php echo tms_t('msg_high_demand','High demand'); ?></div>
          <?php endif; ?>
        <?php elseif ($col === 'AvgRating'): ?>
          <?php if (!empty($p->AvgRating) && $p->AvgRating > 0): ?>
            <div class="compare-stars">
              <?php for ($s = 1; $s <= 5; $s++): ?>
                <i class="fa fa-star<?= $s <= round($p->AvgRating) ? '' : '-o'; ?>"></i>
              <?php endfor; ?>
              <span style="color:var(--text-muted);font-size:12px;margin-left:4px;"><?= number_format($p->AvgRating,1) ?></span>
            </div>
          <?php else: ?>
            <span class="compare-na"><?php echo tms_t('msg_no_reviews_yet','No reviews yet'); ?></span>
          <?php endif; ?>
        <?php elseif ($col === 'PackageDuration'): ?>
          <?= !empty($p->PackageDuration) ? '<i class="fa fa-clock-o" style="color:var(--secondary)"></i> '.htmlspecialchars($p->PackageDuration,ENT_QUOTES,'UTF-8') : '<span class="compare-na">'.tms_t('msg_not_specified','Not specified').'</span>' ?>
        <?php elseif ($col === 'HotelName'): ?>
          <?php if (!empty($p->HotelName)): ?>
            <i class="fa fa-building" style="color:var(--secondary)"></i> <?= htmlspecialchars($p->HotelName,ENT_QUOTES,'UTF-8') ?>
            <?php if (!empty($p->HotelRating)): ?>
              <div class="compare-stars" style="margin-top:2px;">
                <?php for ($s=1;$s<=5;$s++): ?><i class="fa fa-star<?= $s <= round($p->HotelRating) ? '' : '-o'; ?>" style="font-size:11px;"></i><?php endfor; ?>
                <span style="color:var(--text-muted);font-size:11px;margin-left:3px;"><?= number_format($p->HotelRating,1) ?></span>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <span class="compare-na"><?php echo tms_t('msg_not_specified','Not specified'); ?></span>
          <?php endif; ?>
        <?php elseif ($col === 'MealPlan'): ?>
          <?= !empty($p->MealPlan) ? '<i class="fa fa-cutlery" style="color:var(--secondary)"></i> '.htmlspecialchars($p->MealPlan,ENT_QUOTES,'UTF-8') : '<span class="compare-na">'.tms_t('msg_not_specified','Not specified').'</span>' ?>
        <?php elseif ($col === 'TransportMode'): ?>
          <?= !empty($p->TransportMode) ? '<i class="fa fa-bus" style="color:var(--secondary)"></i> '.htmlspecialchars($p->TransportMode,ENT_QUOTES,'UTF-8') : '<span class="compare-na">'.tms_t('msg_not_specified','Not specified').'</span>' ?>
        <?php elseif ($col === 'PackageFetures'): ?>
          <?= !empty($p->PackageFetures) ? nl2br(htmlspecialchars(substr($p->PackageFetures, 0, 160),ENT_QUOTES,'UTF-8')) : '<span class="compare-na">'.tms_t('msg_not_listed','Not listed').'</span>' ?>
        <?php elseif ($col === 'PackageDescription'): ?>
          <?= nl2br(htmlspecialchars(substr($p->$col ?? '', 0, 200),ENT_QUOTES,'UTF-8')) ?>...
        <?php else: ?>
          <?= htmlspecialchars($p->$col ?? tms_t('msg_na','N/A'),ENT_QUOTES,'UTF-8') ?>
        <?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <tr>
      <td class="field-name"><?php echo tms_t('label_current_weather','Current Weather'); ?></td>
      <?php foreach ($packages as $p): $w = $compareWeather[$p->PackageId] ?? null; ?>
      <td>
        <?php if ($w && !empty($w['ok'])): ?>
          <div class="compare-weather">
            <?php if (($w['source'] ?? '') === 'openweathermap'): ?>
              <img src="https://openweathermap.org/img/wn/<?= htmlspecialchars($w['icon'],ENT_QUOTES,'UTF-8') ?>.png" alt="" width="32" height="32">
            <?php else: ?>
              <i class="fa <?= htmlspecialchars($w['icon'] ?? 'fa-cloud', ENT_QUOTES, 'UTF-8') ?>" style="font-size:22px;color:var(--secondary);"></i>
            <?php endif; ?>
            <div>
              <div class="temp"><?= isset($w['temp']) && $w['temp'] !== null ? (int)$w['temp'] . '&deg;C' : '&mdash;' ?></div>
              <div class="cond"><?= htmlspecialchars($w['description'] ?? $w['condition'] ?? '', ENT_QUOTES,'UTF-8') ?></div>
            </div>
          </div>
        <?php else: ?>
          <span class="compare-na"><?= (($w['error'] ?? '') === 'no_coords')
              ? tms_t('msg_no_coords_weather','No map location saved for this destination yet')
              : tms_t('msg_weather_unavailable','Live weather unavailable right now — try refreshing') ?></span>
        <?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <tr>
      <td class="field-name"><?php echo tms_t('label_seats_available','Seats Available'); ?></td>
      <?php foreach ($packages as $p): $seatsLeft = tms_remaining_seats($dbh, (int)$p->PackageId); ?>
      <td>
        <?php if ($seatsLeft === null): ?>
          <span class="compare-na"><?php echo tms_t('msg_not_tracked_package','Not tracked for this package'); ?></span>
        <?php elseif ($seatsLeft <= 2): ?>
          <span style="color:#dc2626;font-weight:700;"><i class="fa fa-exclamation-triangle"></i> <?php echo tms_t('msg_only_prefix','Only'); ?> <?= $seatsLeft ?> <?php echo tms_t('msg_left_suffix','left!'); ?></span>
        <?php else: ?>
          <span style="color:var(--text);font-weight:600;"><i class="fa fa-users" style="color:var(--secondary);"></i> <?= $seatsLeft ?> <?php echo tms_t('label_seats_left_suffix','seats left'); ?></span>
        <?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <tr>
      <td class="field-name"><?php echo tms_t('label_action','Action'); ?></td>
      <?php foreach ($packages as $p): ?>
      <td><a href="package-details.php?pkgid=<?= (int)$p->PackageId ?>" class="btn btn-primary btn-sm"><?php echo tms_t('btn_book_now','Book Now'); ?></a></td>
      <?php endforeach; ?>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

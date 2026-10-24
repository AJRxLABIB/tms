<?php
require_once __DIR__ . '/../backend/session-boot.php';
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

// Best travel months data for destinations
$travelSeasons = [
    'Paris' => ['best'=>['Apr','May','Sep','Oct'],'avoid'=>['Jul','Aug'],'tip'=>'Spring and early autumn offer mild weather and fewer tourists than summer.'],
    'Switzerland' => ['best'=>['Jun','Jul','Aug','Dec','Jan'],'avoid'=>['Nov','Mar'],'tip'=>'Summer for hiking, winter for skiing. Avoid shoulder months.'],
    'Bhutan' => ['best'=>['Mar','Apr','May','Oct','Nov'],'avoid'=>['Jun','Jul','Aug'],'tip'=>'Spring and autumn are ideal. Monsoon season (June–Aug) brings heavy rain.'],
    'Bali' => ['best'=>['Apr','May','Jun','Jul','Aug','Sep'],'avoid'=>['Jan','Feb','Dec'],'tip'=>'Dry season (Apr–Sep) is best. Monsoon brings heavy rains Dec–Mar.'],
    'Kerala' => ['best'=>['Oct','Nov','Dec','Jan','Feb'],'avoid'=>['Jun','Jul','Aug'],'tip'=>'Post-monsoon season is lush and beautiful. Avoid monsoon for outdoor activities.'],
    'Dubai' => ['best'=>['Nov','Dec','Jan','Feb','Mar'],'avoid'=>['Jun','Jul','Aug'],'tip'=>'Winter months are perfect. Summers are extremely hot (45°C+).'],
    'Sikkim' => ['best'=>['Mar','Apr','May','Oct','Nov'],'avoid'=>['Jun','Jul','Aug'],'tip'=>'Spring blooms and autumn clarity are best. Monsoon affects most treks.'],
    'Guwahati' => ['best'=>['Oct','Nov','Dec','Jan','Feb'],'avoid'=>['Jun','Jul','Aug'],'tip'=>'Cool dry months offer best visibility and outdoor comfort.'],
    'Thailand' => ['best'=>['Nov','Dec','Jan','Feb','Mar'],'avoid'=>['Sep','Oct'],'tip'=>'Cool season is ideal. Avoid monsoon season which brings flooding.'],
    'Indonesia' => ['best'=>['May','Jun','Jul','Aug','Sep'],'avoid'=>['Dec','Jan','Feb'],'tip'=>'Dry season offers clear skies and calm seas.'],
];

$sql = "SELECT * FROM tbltourpackages ORDER BY PackageName";
$query = $dbh->prepare($sql);
$query->execute();
$packages = $query->fetchAll(PDO::FETCH_OBJ);

$selectedPkg = null;
$weatherData = null;
$seasonInfo = null;
$liveWeather = ['ok' => false, 'error' => 'no_selection'];

if(isset($_GET['dest'])) {
    $destId = intval($_GET['dest']);
    $sqlP = "SELECT * FROM tbltourpackages WHERE PackageId=:id";
    $qP = $dbh->prepare($sqlP);
    $qP->bindParam(':id', $destId, PDO::PARAM_INT);
    $qP->execute();
    $selectedPkg = $qP->fetch(PDO::FETCH_OBJ);

    if($selectedPkg) {
        // Find season info for this location
        foreach($travelSeasons as $key => $season) {
            if(stripos($selectedPkg->PackageLocation, $key) !== false) {
                $seasonInfo = $season;
                break;
            }
        }
        if(!$seasonInfo) $seasonInfo = ['best'=>['Oct','Nov','Dec','Jan'],'avoid'=>['Jun','Jul'],'tip'=>'Generally suitable year-round. Check local forecasts before travel.'];

        // ROOT-CAUSE FIX (Round 14): this used to read
        //     $lat = $selectedPkg->MapLat ?? 20;  $lng = $selectedPkg->MapLng ?? 70;
        // Both admin forms deliberately store SQL NULL when the map fields are
        // left blank, so for every package without coordinates those defaults
        // fired and the page fetched genuine live weather for 20N 70E — open
        // ocean off the Gujarat coast — then displayed it under this
        // destination's name with a "Live via Open-Meteo" badge. It also made
        // the honest "we don't have a map location yet" branch below (which was
        // already written, and correct) unreachable. Pass the emptiness through
        // instead so that branch actually runs. compare.php already did this
        // correctly; weather.php was the odd one out.
        $hasCoords = !empty($selectedPkg->MapLat) && !empty($selectedPkg->MapLng);
        $lat = $hasCoords ? (float)$selectedPkg->MapLat : 0.0;
        $lng = $hasCoords ? (float)$selectedPkg->MapLng : 0.0;

        // Real live weather — never throws, always returns a usable array
        $liveWeather = tms_get_weather($dbh, $lat, $lng, $selectedPkg->PackageLocation);
    }
}

$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$currentMonth = date('M');
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
<title>TMS | Weather & Best Travel Time</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="css/animate.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<style>
.weather-hero { background: linear-gradient(135deg,#176B50,#104B3A); color:#fff; padding:55px 0 30px; text-align:center; }
.weather-hero h1 { font-size:2.3em; margin-bottom:8px; }
.dest-select-card { background:var(--white); border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.1); padding:30px; margin:-25px auto 30px; max-width:700px; position:relative; z-index:10; }
.form-control { border:2px solid var(--border); border-radius:8px; padding:12px 15px; font-size:15px; }
.btn-weather { background: linear-gradient(135deg,#176B50,#104B3A); color:#fff; border:none; border-radius:8px; padding:12px 30px; font-size:15px; font-weight:700; cursor:pointer; width:100%; margin-top:10px; }
.weather-card { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:25px; margin-bottom:25px; }
.weather-card h4 { color:var(--text); font-size:1.1em; font-weight:700; margin-bottom:15px; border-bottom:2px solid var(--border); padding-bottom:10px; }
.month-grid { display:flex; flex-wrap:wrap; gap:8px; }
.month-badge { padding:8px 12px; border-radius:8px; font-size:13px; font-weight:600; text-align:center; min-width:55px; }
.month-best { background:#d4edda; color:#155724; border:2px solid #c3e6cb; }
.month-avoid { background:#f8d7da; color:#721c24; border:2px solid #f5c6cb; }
.month-ok { background:#fff3cd; color:#856404; border:2px solid #ffeaa7; }
.month-current { border:3px solid #176B50 !important; }
.season-legend { display:flex; gap:15px; margin-bottom:15px; font-size:13px; flex-wrap:wrap; }
.season-dot { width:14px; height:14px; border-radius:3px; display:inline-block; margin-right:5px; vertical-align:middle; }
.weather-widget { background: linear-gradient(135deg,#176B50,#104B3A); border-radius:12px; padding:25px; color:#fff; text-align:center; }
.weather-widget .temp { font-size:3em; font-weight:700; }
.weather-widget .desc { font-size:1.1em; opacity:0.9; }
.weather-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin-top:15px; }
.weather-stat { background:rgba(255,255,255,0.2); border-radius:8px; padding:12px; }
.weather-stat .label { font-size:11px; opacity:0.8; display:block; }
.weather-stat .val { font-size:1.3em; font-weight:700; }
.tip-card { background: var(--white); border-radius:10px; padding:20px; margin-bottom:20px; }
.tip-card h5 { font-weight:700; color:#c0392b; margin-bottom:8px; }
.visit-rating { display:flex; align-items:center; gap:10px; margin:15px 0; }
.visit-stars { color:#f0ad4e; font-size:18px; }
.forecast-row { display:flex; justify-content:space-between; background:var(--bg); border-radius:10px; padding:12px 20px; margin-bottom:8px; align-items:center; }
.forecast-day { font-weight:600; color:var(--text); min-width:80px; }
.forecast-icon { font-size:18px; min-width:30px; text-align:center; }
.forecast-temps { font-size:13px; color:var(--text); }
.forecast-temps strong { color:var(--text); }
html[data-theme="dark"] .weather-card h4,
html[data-theme="dark"] .forecast-day,
html[data-theme="dark"] .forecast-temps strong { color: var(--text) !important; }
html[data-theme="dark"] .forecast-temps { color: var(--text-muted) !important; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="weather-hero">
    <div class="container">
        <h1><i class="fa fa-cloud"></i> Weather & Best Travel Time</h1>
        <p>Know the perfect season before you pack your bags</p>
    </div>
</div>

<div class="container">
    <div class="dest-select-card wow fadeInUp">
        <h4 style="color:var(--text);margin-bottom:20px;"><i class="fa fa-search" style="color:#176B50"></i> Select a Destination</h4>
        <form method="get">
            <div class="row">
                <div class="col-md-9">
                    <select name="dest" class="form-control" aria-label="Destination">
                        <option value="">-- Select Tour Destination --</option>
                        <?php foreach($packages as $p): ?>
                        <option value="<?php echo $p->PackageId; ?>" <?php echo (isset($_GET['dest'])&&$_GET['dest']==$p->PackageId)?'selected':''; ?>>
                            <?php echo htmlentities($p->PackageName); ?> — <?php echo htmlentities($p->PackageLocation); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn-weather">Check Weather</button>
                </div>
            </div>
        </form>
    </div>

    <?php if($selectedPkg && $seasonInfo): ?>
    <div class="row">
        <div class="col-md-4">
            <div class="weather-card wow fadeInLeft">
                <h4><i class="fa fa-thermometer-half" style="color:#176B50"></i> Current Weather</h4>
                <?php if($liveWeather['ok']): ?>
                <div class="weather-widget">
                    <?php if(($liveWeather['source'] ?? 'openweathermap') === 'openweathermap'): ?>
                    <img src="https://openweathermap.org/img/wn/<?php echo htmlentities($liveWeather['icon']); ?>@2x.png"
                         alt="<?php echo htmlentities($liveWeather['condition']); ?>" style="width:70px;height:70px;">
                    <?php else: ?>
                    <i class="fa <?php echo htmlentities($liveWeather['icon']); ?>" style="font-size:56px;line-height:70px;" aria-label="<?php echo htmlentities($liveWeather['condition']); ?>"></i>
                    <?php endif; ?>
                    <div class="temp"><?php echo $liveWeather['temp'] !== null ? (int)$liveWeather['temp'] . '°C' : '—'; ?></div>
                    <div class="desc"><?php echo htmlentities($liveWeather['description']); ?></div>
                    <div style="margin-top:10px;font-size:13px;opacity:0.85;">
                        <strong><?php echo htmlentities($liveWeather['city']); ?></strong><br>
                        Feels like <?php echo $liveWeather['feels_like'] !== null ? (int)$liveWeather['feels_like'] . '°C' : '—'; ?>
                    </div>
                    <div class="weather-grid">
                        <div class="weather-stat"><span class="label">Humidity</span><span class="val"><?php echo $liveWeather['humidity'] !== null ? (int)$liveWeather['humidity'] . '%' : '—'; ?></span></div>
                        <div class="weather-stat"><span class="label">Wind</span><span class="val"><?php echo $liveWeather['wind_speed'] !== null ? round((float)$liveWeather['wind_speed'] * 3.6, 1) . ' km/h' : '—'; ?></span></div>
                        <div class="weather-stat"><span class="label">Condition</span><span class="val" style="font-size:0.85em;"><?php echo htmlentities($liveWeather['condition']); ?></span></div>
                        <?php if(!empty($liveWeather['sunrise'])): ?>
                        <div class="weather-stat"><span class="label">Sunrise</span><span class="val" style="font-size:0.85em;"><i class="fa fa-sun-o"></i> <?php echo htmlentities($liveWeather['sunrise']); ?></span></div>
                        <?php endif; ?>
                        <?php if(!empty($liveWeather['sunset'])): ?>
                        <div class="weather-stat"><span class="label">Sunset</span><span class="val" style="font-size:0.85em;"><i class="fa fa-moon-o"></i> <?php echo htmlentities($liveWeather['sunset']); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <p style="font-size:10.5px;margin-top:12px;opacity:0.75;">
                        Live via <?php echo $liveWeather['source'] === 'openweathermap' ? 'OpenWeatherMap' : 'Open-Meteo'; ?><?php
                        // Round 14: 'fetched_at' was being stored on every reading
                        // and never shown, while the label said "Live" on data that
                        // can legitimately be up to 30 minutes old (WEATHER_CACHE_TTL).
                        if (!empty($liveWeather['fetched_at'])) {
                            echo ' · updated ' . htmlentities(date('H:i', strtotime($liveWeather['fetched_at'])));
                        }
                        ?>
                    </p>
                </div>
                <?php else: ?>
                <div class="weather-widget">
                    <div style="font-size:50px; margin-bottom:5px;">
                        <?php
                        $isAvoid = in_array($currentMonth, $seasonInfo['avoid']);
                        $isBest = in_array($currentMonth, $seasonInfo['best']);
                        echo $isAvoid ? '🌧️' : ($isBest ? '☀️' : '🌤️');
                        ?>
                    </div>
                    <div class="desc">
                        <?php echo $isBest ? 'Great Time to Visit!' : ($isAvoid ? 'Not Recommended' : 'Acceptable Season'); ?>
                    </div>
                    <div style="margin-top:15px;font-size:13px;opacity:0.85;">
                        <strong><?php echo htmlentities($selectedPkg->PackageLocation); ?></strong><br>
                        <?php echo date('F Y'); ?>
                    </div>
                </div>
                <p style="font-size:11.5px;color:var(--text-muted);margin-top:10px;">
                    <i class="fa fa-info-circle"></i>
                    <?php
                    // BUG FIX: this checked for error === 'no_key', a value
                    // tms_get_weather() never actually returns (it always
                    // falls through OpenWeather -> Open-Meteo -> here), so
                    // the "isn't configured yet" message could never show.
                    // The real cases are 'no_coords' (this destination has no
                    // map location to fetch weather for) vs. everything else
                    // (a real, temporary fetch failure).
                    // Round 14: one message used to cover every failure. These
                    // are genuinely different situations and a user can act on
                    // the difference (a missing map location is permanent until
                    // an admin fixes it; a timeout resolves on refresh).
                    switch ($liveWeather['error'] ?? '') {
                        case 'no_coords':
                            echo "We don't have a map location saved for this destination yet, so live weather can't be fetched. The seasonal guidance below is still accurate.";
                            break;
                        case 'bad_coords':
                            echo "This destination's saved map location looks invalid, so live weather can't be fetched. An admin can correct it in the package settings.";
                            break;
                        default:
                            echo 'Live weather is temporarily unavailable right now — please refresh in a moment. The seasonal guidance below is unaffected.';
                    }
                    ?>
                </p>
                <?php endif; ?>

                <div class="visit-rating" style="margin-top:20px;">
                    <span style="font-weight:600;color:var(--text);">Visit Rating:</span>
                    <span class="visit-stars">
                        <?php
                        $isBest = in_array($currentMonth, $seasonInfo['best']);
                        $isAvoid = in_array($currentMonth, $seasonInfo['avoid']);
                        $stars = $isBest ? 5 : ($isAvoid ? 1 : 3);
                        for($i=1;$i<=5;$i++) echo $i<=$stars ? '★' : '☆';
                        ?>
                    </span>
                    <span style="font-size:13px;color:var(--text-muted);">(<?php echo $stars; ?>/5)</span>
                </div>
            </div>

            <div class="weather-card wow fadeInLeft" style="margin-top:0;">
                <h4><i class="fa fa-calendar" style="color:#176B50"></i> <?php echo ($liveWeather['ok'] && !empty($liveWeather['forecast'])) ? 'Forecast' : 'Seasonal Guidance'; ?></h4>
                <?php if($liveWeather['ok'] && !empty($liveWeather['forecast'])): ?>
                    <?php foreach($liveWeather['forecast'] as $f): ?>
                    <div class="forecast-row">
                        <span class="forecast-day"><?php echo htmlentities($f['day_label']); ?></span>
                        <span class="forecast-icon">
                        <?php if(($liveWeather['source'] ?? 'openweathermap') === 'openweathermap'): ?>
                            <img loading="lazy" src="https://openweathermap.org/img/wn/<?php echo htmlentities($f['icon']); ?>.png" style="width:26px;height:26px;" alt="<?php echo htmlentities($f['condition']); ?>">
                        <?php else: ?>
                            <i class="fa <?php echo htmlentities($f['icon']); ?>" aria-label="<?php echo htmlentities($f['condition']); ?>"></i>
                        <?php endif; ?>
                        </span>
                        <span class="forecast-temps"><strong><?php echo $f['temp_max']; ?>°C</strong> / <?php echo $f['temp_min']; ?>°C</span>
                    </div>
                    <?php endforeach; ?>
                    <p style="font-size:11px;color:var(--text-muted);margin-top:8px;">Live forecast via <?php echo $liveWeather['source'] === 'openweathermap' ? 'OpenWeatherMap' : 'Open-Meteo'; ?></p>
                <?php else: ?>
                <?php
                // HONESTY FIX (Round 14): this block used to render seven
                // hard-coded high/low pairs in exactly the same markup as the
                // live rows — same day names, same °C styling — distinguished
                // only by a grey footnote. The numbers were keyed solely off
                // "is this a best/avoid month", so Switzerland in January and
                // Dubai in December were both presented as 28°C/22°C. That is a
                // fabricated forecast, and it appeared precisely when the real
                // one was unavailable. Now we say what we actually know.
                $isBest  = in_array($currentMonth, $seasonInfo['best']);
                $isAvoid = in_array($currentMonth, $seasonInfo['avoid']);
                ?>
                <div style="text-align:center;padding:18px 10px;">
                    <div style="font-size:38px;line-height:1;margin-bottom:10px;">
                        <?php echo $isAvoid ? '🌧️' : ($isBest ? '☀️' : '🌤️'); ?>
                    </div>
                    <p style="font-size:14px;font-weight:600;color:var(--text,#333);margin-bottom:6px;">
                        <?php echo $isBest ? 'A good month to visit' : ($isAvoid ? 'A month most travellers avoid' : 'A reasonable month to visit'); ?>
                    </p>
                    <p style="font-size:12.5px;color:var(--text-muted,#777);line-height:1.6;margin:0;">
                        We don't have a live day-by-day forecast for this destination right now,
                        and we'd rather show you nothing than invent numbers. The month-by-month
                        guidance below is based on this destination's real travel season.
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-8">
            <div class="weather-card wow fadeInRight">
                <h4><i class="fa fa-calendar-check-o" style="color:#176B50"></i> Best & Worst Months to Visit</h4>
                <div class="season-legend">
                    <span><span class="season-dot" style="background:#d4edda;border:1px solid #c3e6cb;"></span> Best Time</span>
                    <span><span class="season-dot" style="background:#f8d7da;border:1px solid #f5c6cb;"></span> Avoid</span>
                    <span><span class="season-dot" style="background:#fff3cd;border:1px solid #ffeaa7;"></span> Acceptable</span>
                    <span><span class="season-dot" style="background:var(--white);border:3px solid #176B50;"></span> Current Month</span>
                </div>
                <div class="month-grid">
                    <?php foreach($months as $m):
                        $cls = in_array($m, $seasonInfo['best']) ? 'month-best' : (in_array($m, $seasonInfo['avoid']) ? 'month-avoid' : 'month-ok');
                        $curr = ($m == $currentMonth) ? ' month-current' : '';
                    ?>
                    <div class="month-badge <?php echo $cls.$curr; ?>">
                        <?php echo $m; ?>
                        <?php if($m==$currentMonth): ?><br><small>Now</small><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tip-card wow fadeInRight">
                <h5><i class="fa fa-lightbulb-o"></i> Travel Tip for <?php echo htmlentities($selectedPkg->PackageLocation); ?></h5>
                <p><?php echo htmlentities($seasonInfo['tip']); ?></p>
            </div>

            <div class="weather-card wow fadeInRight">
                <h4><i class="fa fa-map-marker" style="color:#176B50"></i> About This Destination</h4>
                <div class="row">
                    <div class="col-sm-6">
                        <p><strong>Package:</strong> <?php echo htmlentities($selectedPkg->PackageName); ?></p>
                        <p><strong>Location:</strong> <?php echo htmlentities($selectedPkg->PackageLocation); ?></p>
                        <p><strong>Type:</strong> <?php echo htmlentities($selectedPkg->PackageType); ?></p>
                        <p><strong>Price:</strong> <?php echo tms_currency($selectedPkg->PackagePrice); ?></p>
                    </div>
                    <div class="col-sm-6">
                        <p><strong>Best Months:</strong> <?php echo implode(', ', $seasonInfo['best']); ?></p>
                        <p><strong>Avoid:</strong> <?php echo implode(', ', $seasonInfo['avoid']); ?></p>
                        <p><strong>Features:</strong> <?php echo htmlentities(substr($selectedPkg->PackageFetures ?? '', 0, 80)); ?>...</p>
                    </div>
                </div>
                <a href="package-details.php?pkgid=<?php echo $selectedPkg->PackageId; ?>" class="btn btn-primary" style="margin-top:15px;background:#176B50;border:none;border-radius:8px;">
                    <i class="fa fa-book"></i> Book This Package
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Default seasonal tips for all destinations -->
    <div class="row" style="margin-top:20px;">
        <?php
        $generalTips = [
            ['icon'=>'☀️','title'=>'Summer Travel (Jun–Aug)','color'=>'#ffecd2','tip'=>'Best for Europe and high altitudes. Hot in Middle East and South Asia. Plan indoor activities during peak heat.'],
            ['icon'=>'🍂','title'=>'Autumn Travel (Sep–Nov)','color'=>'#d4edda','tip'=>'Ideal for most Asian destinations. Clear skies and moderate temperatures. Great for festivals and cultural events.'],
            ['icon'=>'❄️','title'=>'Winter Travel (Dec–Feb)','color'=>'#cce5ff','tip'=>'Perfect for tropical destinations like Bali, Kerala, Dubai. Avoid high altitude unless skiing.'],
            ['icon'=>'🌸','title'=>'Spring Travel (Mar–May)','color'=>'#f8d7da','tip'=>'Best for Bhutan, Sikkim, and Nepal. Flowers bloom and wildlife is active. Great for photography.'],
        ];
        foreach($generalTips as $tip):
        ?>
        <div class="col-md-6 wow fadeInUp" style="margin-bottom:20px;">
            <div class="weather-card" style="background:<?php echo $tip['color']; ?>;border:none;">
                <h4 style="color:#1f2937 !important;"><?php echo $tip['icon']; ?> <?php echo $tip['title']; ?></h4>
                <p style="color:#374151 !important;"><?php echo $tip['tip']; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

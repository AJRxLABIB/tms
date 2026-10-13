<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// Round 115, Phase G (master prompt item 20 -- "Bangladesh Travel
// Intelligence"). Public content page, same pattern as ai-recommend.php/
// transport-compare.php -- a planning/research tool, not an account
// feature, so it's intentionally not gated behind tms_require_user().

// This table is created by database/migrations/011_bd_destination_guides.sql
// (tracked via tblmigrations, applied by database/migrate.php) rather than
// the self-healing schema helpers in backend/config.php -- Round 115's
// architecture-cleanup phase deliberately moved new tables onto the real
// migration system instead of adding more ad-hoc CREATE TABLE IF NOT EXISTS
// calls (Rule 8: never casually change the database). That means this page
// must fail soft, honestly, if the migration hasn't been run yet on a given
// install -- never silently show an empty page with no explanation.
$guides = [];
$loadError = null;
try {
    $stmt = $dbh->query("SELECT slug, name, known_for, best_time, how_to_get_there, practical_note, sources, researched_at FROM tbl_bd_destination_guides ORDER BY name ASC");
    $guides = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('[TMS] bd-guide.php: tbl_bd_destination_guides not available yet: ' . $e->getMessage());
    $loadError = true;
}
$researchedAt = $guides ? ($guides[0]['researched_at'] ?? null) : null;
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#006A4E">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | Bangladesh Travel Guide</title>
<?php
// NEW (Round 118, master prompt item 53 -- SEO): explicitly public content
// (see the "Public content page" comment at the top of this file) with a
// hand-written title/description but no canonical/OpenGraph tags -- same
// gap and same fix pattern as backend/landing.php and package-list.php.
// The description below uses the real live guide count instead of the
// old hardcoded "12" (which would silently go stale the moment a guide is
// added or removed), and is only shown at all if $guides actually loaded.
tms_seo_meta([
    'title'       => 'Bangladesh Travel Guide',
    'description' => $guides
        ? ('A researched guide to ' . count($guides) . " Bangladesh destinations -- how to get there, best time to visit, and live weather.")
        : 'A researched guide to Bangladesh travel destinations.',
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Bangladesh Travel Guide'],
    ],
]);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
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
<script src="<?php echo tms_asset_ver('js/explore.js'); ?>" defer></script>
<style>
.bdg-hero { background: linear-gradient(135deg, #00382a 0%, #006a4e 55%, #00875f 100%); padding: 60px 0; text-align:center; color:#fff; }
.bdg-hero h1 { font-size: 2.3em; margin-bottom:10px; }
.bdg-hero p { max-width:640px; margin:0 auto; opacity:.92; }
.bdg-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:20px; margin: 24px 0 40px; }
.bdg-card { background:var(--white); border-radius:12px; box-shadow:0 3px 16px rgba(0,0,0,.08); padding:22px; display:flex; flex-direction:column; }
.bdg-card h3 { font-size:1.15em; margin-bottom:10px; color:var(--text); }
.bdg-card h3 i { color:#006a4e; margin-right:6px; }
.bdg-card .bdg-row { margin-bottom:10px; font-size:13.5px; color:var(--text); line-height:1.55; }
.bdg-card .bdg-row .bdg-lbl { display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700; margin-bottom:2px; }
.bdg-note { background:#fffbeb; border:1px solid #fde68a; color:#735A00; border-radius:8px; padding:10px 12px; font-size:12.5px; margin-bottom:10px; }
html[data-theme="dark"] .bdg-note { background:#2a2400; border-color:#5c4a00; color:#ffd76a; }
.bdg-sources { font-size:11px; color:var(--text-light); margin-top:auto; padding-top:10px; border-top:1px solid #eef1f3; }
.bdg-sources a { color:var(--text-light); text-decoration:underline; }
.bdg-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
.bdg-btn { background: linear-gradient(135deg,#006a4e,#00875f); color:#fff; border:none; border-radius:7px; padding:9px 14px; font-weight:600; font-size:12.5px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.bdg-btn.bdg-btn-alt { background:linear-gradient(135deg,#176B50,#104B3A); }
.bdg-btn:disabled { opacity:.6; cursor:default; }
.bdg-weather-result { margin-top:8px; font-size:12.5px; }
.bdg-weather-mini { background:#f4f9f7; border-radius:6px; padding:8px 10px; color:var(--text); }
html[data-theme="dark"] .bdg-weather-mini { background:#0f2a22; }
.bdg-empty { max-width:700px; margin:0 auto; padding:30px; text-align:center; color:var(--text-muted); }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="bdg-hero">
    <div class="container">
        <h1><i class="fa fa-map-marker"></i> <?php echo tms_t('heading_bd_guide','Bangladesh Travel Guide'); ?></h1>
        <p><?php echo tms_t('dest_bd_guide_subtitle','Beaches, hill tracts, mangrove forest and heritage sites -- twelve researched destinations across Bangladesh, with real sourcing for every claim.'); ?></p>
    </div>
</div>

<div class="container">
    <?php
    tms_render_breadcrumb_nav([
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Bangladesh Travel Guide'],
    ]);
    ?>

    <div class="tms-sample-banner wow fadeIn">
        <i class="fa fa-info-circle"></i>
        This is curated, researched content<?php echo $researchedAt ? ' compiled on ' . htmlspecialchars(date('F j, Y', strtotime($researchedAt)), ENT_QUOTES, 'UTF-8') : ''; ?> --
        not a live feed. Sources are listed on each card. Time-sensitive details (permits, island access, exact transport
        schedules) can change; please verify with a licensed operator or the relevant authority before traveling,
        especially for Saint Martin's Island and the Chittagong Hill Tracts (Sajek Valley, Bandarban, Rangamati).
    </div>

    <?php if ($loadError): ?>
        <div class="bdg-empty">
            <i class="fa fa-wrench" style="font-size:2em;margin-bottom:10px;display:block;"></i>
            This guide's content is being set up for this install. If you're the site administrator,
            run <code>php database/migrate.php</code> to apply the pending Bangladesh destination guide migration.
        </div>
    <?php elseif (!$guides): ?>
        <div class="bdg-empty"><?php echo tms_t('msg_no_guides_yet','No destination guides are available yet. Please check back soon.'); ?></div>
    <?php else: ?>
        <div class="bdg-grid">
            <?php foreach ($guides as $i => $g): ?>
                <?php
                $name = $g['name'] ?? '';
                // Round 115 hotfix: sources (and every other text column here) are
                // pipe-separated, not semicolon-separated -- database/migrate.php's
                // statement splitter cuts the raw SQL file on every literal ';'
                // with no regard for whether it's inside a quoted string, so a
                // semicolon-separated URL list (or any prose semicolon) inside an
                // INSERT's string literal corrupted the statement and made the
                // whole migration fail on first run. See gen_bd_guide_migration.py.
                $sources = array_values(array_filter(array_map('trim', explode('|', (string)($g['sources'] ?? '')))));
                ?>
                <div class="bdg-card wow fadeInUp" data-wow-delay="<?php echo min(0.4, 0.03 * $i); ?>s">
                    <h3><i class="fa fa-map-pin"></i> <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h3>

                    <div class="bdg-row">
                        <span class="bdg-lbl"><?php echo tms_t('label_known_for','Known for'); ?></span>
                        <?php echo htmlspecialchars($g['known_for'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="bdg-row">
                        <span class="bdg-lbl"><?php echo tms_t('label_best_time_visit','Best time to visit'); ?></span>
                        <?php echo htmlspecialchars($g['best_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="bdg-row">
                        <span class="bdg-lbl"><?php echo tms_t('label_how_to_get_there','How to get there'); ?></span>
                        <?php echo htmlspecialchars($g['how_to_get_there'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <?php if (!empty($g['practical_note'])): ?>
                        <div class="bdg-note"><i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($g['practical_note'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <div class="bdg-actions">
                        <button type="button" class="bdg-btn" onclick="bdCheckWeather(<?php echo htmlspecialchars(json_encode($name, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, this)">
                            <i class="fa fa-cloud"></i> <?php echo tms_t('btn_check_weather','Check Current Weather'); ?>
                        </button>
                        <a class="bdg-btn bdg-btn-alt" href="transport-compare.php?origin=<?php echo urlencode('Dhaka, Bangladesh'); ?>&destination=<?php echo urlencode($name . ', Bangladesh'); ?>">
                            <i class="fa fa-random"></i> <?php echo tms_t('btn_compare_transport','Compare Transport from Dhaka'); ?>
                        </a>
                    </div>
                    <div class="bdg-weather-result"></div>

                    <?php if ($sources): ?>
                        <div class="bdg-sources">
                            <?php echo tms_t('label_sources_colon','Sources:'); ?>
                            <?php foreach ($sources as $j => $src): ?>
                                <?php if ($j > 0) echo ' &middot; '; ?>
                                <a href="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo htmlspecialchars(parse_url($src, PHP_URL_HOST) ?: $src, ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Round 115 (master prompt item 20, "Bangladesh Travel Intelligence").
// Reuses api/explore.php's existing search+weather actions (via the
// exploreGet() helper already defined in js/explore.js) rather than adding
// a new weather endpoint -- geocode the destination name, then fetch the
// live forecast for those coordinates. No fabricated data: if either call
// fails, that's shown honestly instead of a guessed number.
function bdCheckWeather(name, btnEl) {
    var resultBox = btnEl.closest('.bdg-card').querySelector('.bdg-weather-result');
    var originalHtml = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Checking…';
    resultBox.innerHTML = '';

    exploreGet('search', { q: name + ', Bangladesh' }).then(function (res) {
        // BUG FIX (found live-testing Phase G's own "nothing exercised this
        // live yet" caveat): this read res.results, but exploreGet's actual
        // response shape nests it under res.data.results (confirmed against
        // js/explore.js's own correct usage of the same 'search' action) --
        // res.results was always undefined, so EVERY click here failed with
        // "Could not locate this destination.", 100% of the time, even
        // though the search itself was succeeding.
        var results = (res && res.success && res.data && res.data.results) || [];
        if (!results.length) {
            throw new Error((res && res.error) || 'Could not locate this destination.');
        }
        var loc = results[0];
        return exploreGet('weather', { lat: loc.lat, lng: loc.lng });
    }).then(function (res) {
        btnEl.disabled = false;
        btnEl.innerHTML = originalHtml;
        if (!res.success) {
            resultBox.innerHTML = '<p style="color:#c0392b;">' + escapeHtml((res && res.error) || 'Weather unavailable right now.') + '</p>';
            return;
        }
        var c = (res.data.weather || {}).current || {};
        resultBox.innerHTML =
            '<div class="bdg-weather-mini"><i class="fa fa-cloud"></i> ' +
            (c.temp != null ? Math.round(c.temp) + '°C' : '—') +
            (c.description ? ' &middot; ' + escapeHtml(c.description) : '') +
            (c.humidity != null ? ' &middot; Humidity ' + c.humidity + '%' : '') +
            '</div>';
    }).catch(function (err) {
        btnEl.disabled = false;
        btnEl.innerHTML = originalHtml;
        resultBox.innerHTML = '<p style="color:#c0392b;">' + escapeHtml((err && err.message) || 'Network error — please try again.') + '</p>';
    });
}
</script>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once dirname(__DIR__) . '/backend/ExploreService.php';

// Explore Mode is a logged-in feature (matches "after a user logs in, show two options")
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see backend/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.

$service = new ExploreService($dbh);
$recent  = $service->getRecentHistory($_SESSION['login'] ?? '', 6);

$popularDestinations = [
    ['Paris, France', 48.8566, 2.3522, 'France'],
    ['Tokyo, Japan', 35.6762, 139.6503, 'Japan'],
    ['Bali, Indonesia', -8.3405, 115.0920, 'Indonesia'],
    ['Dubai, UAE', 25.2048, 55.2708, 'United Arab Emirates'],
    ['Cox\'s Bazar, Bangladesh', 21.4272, 92.0058, 'Bangladesh'],
    ['Kathmandu, Nepal', 27.7172, 85.3240, 'Nepal'],
    ['Bangkok, Thailand', 13.7563, 100.5018, 'Thailand'],
    ['New York, USA', 40.7128, -74.0060, 'USA'],
];
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
<title>TourBuddy | Explore — Travel Without Booking</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="css/animate.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/explore.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
</head>
<body>
<?php include('backend/header.php'); ?>

<section class="explore-hero">
    <h1><i class="fa fa-compass"></i> <?php echo tms_t('heading_explore_mode','Explore Mode'); ?></h1>
    <p><?php echo tms_t('dest_explore_subtitle','Discover any destination on Earth — live weather, nearby places, interactive maps &amp; an AI travel guide. No booking required.'); ?></p>
</section>

<div class="explore-search-card wow fadeInUp">
    <div class="explore-search-wrap">
        <i class="fa fa-search explore-search-icon"></i>
        <input type="text" id="exploreSearchInput" class="explore-search-input"
               placeholder="<?php echo tms_t('explore_search_placeholder',"Enter a city, country, or tourist place (e.g. 'Kyoto, Japan')"); ?>" autocomplete="off">
        <i class="fa fa-spinner fa-spin explore-search-spinner" id="exploreSearchSpinner"></i>
        <div class="explore-suggestions" id="exploreSuggestions"></div>
    </div>
    <div class="explore-popular">
        <?php foreach ($popularDestinations as $p): ?>
        <span class="explore-chip" onclick="goToDestination(<?php echo htmlspecialchars(json_encode($p[0]), ENT_QUOTES); ?>, <?php echo $p[1]; ?>, <?php echo $p[2]; ?>, <?php echo htmlspecialchars(json_encode($p[3]), ENT_QUOTES); ?>)">
            <?php echo htmlspecialchars($p[0]); ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($recent): ?>
<div class="explore-recent wow fadeInUp">
    <h5><i class="fa fa-history"></i> <?php echo tms_t('heading_recently_explored','Recently Explored'); ?></h5>
    <div class="explore-popular" style="justify-content:flex-start;">
        <?php foreach ($recent as $r): ?>
        <span class="explore-chip" onclick="goToDestination(<?php echo htmlspecialchars(json_encode($r['display_name']), ENT_QUOTES); ?>, <?php echo $r['lat']; ?>, <?php echo $r['lng']; ?>, '')">
            <i class="fa fa-clock" style="margin-right:5px;"></i><?php echo htmlspecialchars($r['display_name']); ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="explore-recent">
    <div class="explore-card" style="text-align:center;">
        <h4 style="justify-content:center;"><i class="fa fa-lightbulb"></i> <?php echo tms_t('heading_what_youll_get',"What you'll get"); ?></h4>
        <p style="color:var(--text-muted);font-size:13.5px;max-width:640px;margin:0 auto;">
            <?php echo tms_t('dest_explore_what_youll_get','A full destination overview, live interactive map with turn-by-turn navigation, nearby hotels/restaurants/hospitals &amp; more, real-time weather &amp; forecasts, and a 24/7 AI travel guide chatbot — all without creating a booking. Prefer a curated, all-inclusive trip instead?'); ?> <a href="package-list.php"><?php echo tms_t('link_browse_tour_packages','Browse our Tour Packages →'); ?></a>
        </p>
    </div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script src="<?php echo tms_asset_ver('js/explore.js'); ?>"></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

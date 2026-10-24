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

$destName = tms_clean($_GET['name'] ?? '');
$lat      = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng      = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$country  = tms_clean($_GET['country'] ?? '');

if ($destName === '' || $lat === null || $lng === null) {
    header('Location: explore.php');
    exit;
}
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
<title>TMS | Explore — <?php echo htmlspecialchars($destName); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="css/animate.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/explore.css'); ?>" rel="stylesheet">
<link rel="stylesheet" href="js/vendor/leaflet/leaflet.css" />
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<script src="js/vendor/leaflet/leaflet.js"></script>
<script src="<?php echo tms_asset_ver('js/tms-map.js'); ?>"></script>
</head>
<body data-explore-dest="<?php echo htmlspecialchars($destName, ENT_QUOTES); ?>">
<?php include('backend/header.php'); ?>

<section class="explore-view-hero">
    <div class="container">
        <a href="explore.php" class="explore-back-btn"><i class="fa fa-arrow-left"></i> Back to Explore</a>
        <h1><i class="fa fa-map-marker-alt"></i> <?php echo htmlspecialchars($destName); ?></h1>
        <div class="sub"><?php echo htmlspecialchars($country ?: 'Explore this destination freely — no booking required'); ?></div>
    </div>
</section>

<div class="container">
    <div class="explore-tabs">
        <button class="explore-tab-btn active" data-tab="overview" onclick="exploreSwitchTab('overview')"><i class="fa fa-info-circle"></i> Overview</button>
        <button class="explore-tab-btn" data-tab="map" onclick="exploreSwitchTab('map')"><i class="fa fa-map"></i> Map &amp; Navigation</button>
        <button class="explore-tab-btn" data-tab="places" onclick="exploreSwitchTab('places')"><i class="fa fa-location-arrow"></i> Nearby Places</button>
        <button class="explore-tab-btn" data-tab="weather" onclick="exploreSwitchTab('weather')"><i class="fa fa-cloud-sun"></i> Weather</button>
    </div>

    <div id="exploreViewRoot"
         data-name="<?php echo htmlspecialchars($destName, ENT_QUOTES); ?>"
         data-lat="<?php echo htmlspecialchars((string)$lat, ENT_QUOTES); ?>"
         data-lng="<?php echo htmlspecialchars((string)$lng, ENT_QUOTES); ?>"
         data-country="<?php echo htmlspecialchars($country, ENT_QUOTES); ?>">

        <!-- Overview Panel -->
        <div class="explore-panel active" id="panel-overview">
            <!-- NEW (Round 118, master prompt item 6 -- Destination System /
                 Rule 3 honesty): the overview/history/best-time-to-visit/
                 local-culture/estimated-costs content below is generated
                 per-destination by an AI call (ExploreService::
                 getDestinationInfo()), cached for 14 days -- it was
                 previously shown with no indication of this at all, unlike
                 bd-guide.php's own researched-content disclosure banner for
                 its 12 curated Bangladesh destinations. Reusing that same
                 .tms-sample-banner convention here rather than inventing a
                 new one. Real, live data (weather, nearby places, local
                 package/hotel/activity inventory) on the other tabs is
                 unaffected -- this banner only covers this Overview tab. -->
            <div class="tms-sample-banner" style="margin-bottom:16px;">
                <i class="fa fa-robot"></i>
                When an AI summary is shown it is generated for quick orientation, not sourced from a licensed travel database
                (a note says so when only verified catalogue information is available) --
                treat costs, culture notes, and history as estimates and verify anything time-sensitive (prices,
                customs, safety) with an official source before relying on it.
            </div>
            <div id="overviewContent">
                <div class="explore-loading"><i class="fa fa-spinner fa-spin spin"></i>Our AI travel researcher is gathering information about <?php echo htmlspecialchars($destName); ?>...</div>
            </div>
            <!-- NEW (Round 79, master prompt item 6 -- Destination System,
                 "Travel" tier): real, already-bookable packages/hotels/
                 activities TMS sells for this destination's country. See
                 js/explore.js's renderLocalInventory(). -->
            <div id="localInventoryContent"></div>
        </div>

        <!-- Map & Navigation Panel -->
        <div class="explore-panel" id="panel-map">
            <div class="explore-card">
                <h4><i class="fa fa-route"></i> Live Map &amp; Navigation</h4>
                <button class="locate-btn" id="locateBtn" onclick="exploreLocateMe()"><i class="fa fa-location-arrow"></i> Locate Me</button>
                <div class="map-mode-btns" id="routeModeBar" style="display:none;">
                    <button class="map-mode-btn active" data-mode="driving" onclick="exploreDrawRoute('driving')"><i class="fa fa-car"></i> Driving</button>
                    <button class="map-mode-btn" data-mode="walking" onclick="exploreDrawRoute('walking')"><i class="fa fa-walking"></i> Walking</button>
                    <button class="map-mode-btn" data-mode="cycling" onclick="exploreDrawRoute('cycling')"><i class="fa fa-bicycle"></i> Cycling</button>
                    <?php /* Round 14: this button used to return an error that promised
                             "Showing driving/walking directions instead" and then showed
                             nothing, parking the user on a mode that could never work.
                             It now genuinely falls back to the driving route
                             (ExploreService::getRoute), and is only offered at all when a
                             Google Maps key makes real transit routing possible. */ ?>
                    <?php if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== ''): ?>
                    <button class="map-mode-btn" data-mode="transit" onclick="exploreDrawRoute('transit')"><i class="fa fa-bus"></i> Public Transport</button>
                    <?php endif; ?>
                </div>
                <!-- NEW (AI-map round): the map used to hardcode attraction
                     pins only -- switching category here now plots that
                     category's real nearby places (same live data the
                     Nearby Places tab uses) instead of requiring one-at-a-time
                     "Get Directions" clicks to see anything else on the map. -->
                <div class="place-cat-tabs" id="mapCatTabs" style="margin-bottom:12px;">
                    <button class="map-cat-btn active" data-cat="attraction" onclick="exploreLoadMapCategory('attraction')"><i class="fa fa-landmark"></i> Attractions</button>
                    <button class="map-cat-btn" data-cat="hotel" onclick="exploreLoadMapCategory('hotel')"><i class="fa fa-bed"></i> Hotels</button>
                    <button class="map-cat-btn" data-cat="restaurant" onclick="exploreLoadMapCategory('restaurant')"><i class="fa fa-utensils"></i> Restaurants</button>
                    <button class="map-cat-btn" data-cat="hospital" onclick="exploreLoadMapCategory('hospital')"><i class="fa fa-hospital"></i> Hospitals</button>
                    <button class="map-cat-btn" data-cat="pharmacy" onclick="exploreLoadMapCategory('pharmacy')"><i class="fa fa-prescription-bottle-alt"></i> Pharmacies</button>
                    <button class="map-cat-btn" data-cat="atm" onclick="exploreLoadMapCategory('atm')"><i class="fa fa-money-bill-wave"></i> ATMs</button>
                    <!-- FIX (Round 114, package/places/map upgrade): the Nearby
                         Places tab below has always offered all 10 real categories
                         ExploreService/PlaceDetailsService support, but this Map tab
                         only ever had pills for 6 of them -- police, gas stations,
                         shopping malls and tourist info had full backend + JS
                         support (exploreLoadMapCategory/MAP_CATEGORY_ICONS already
                         handle all 10) with no button here to ever trigger it. Same
                         class of gap the "hotels/restaurants on the map" fix closed
                         for those two categories previously -- closing it for the
                         remaining four. -->
                    <button class="map-cat-btn" data-cat="police" onclick="exploreLoadMapCategory('police')"><i class="fa fa-shield-alt"></i> Police</button>
                    <button class="map-cat-btn" data-cat="gas_station" onclick="exploreLoadMapCategory('gas_station')"><i class="fa fa-gas-pump"></i> Gas Stations</button>
                    <button class="map-cat-btn" data-cat="shopping_mall" onclick="exploreLoadMapCategory('shopping_mall')"><i class="fa fa-shopping-bag"></i> Malls</button>
                    <button class="map-cat-btn" data-cat="tourist_info" onclick="exploreLoadMapCategory('tourist_info')"><i class="fa fa-info-circle"></i> Tourist Info</button>
                </div>
                <div id="mapStatus" class="explore-source" role="status" aria-live="polite"></div>
                <div id="exploreMap" aria-label="Interactive map"></div>
                <div class="route-summary" id="routeSummary"></div>
                <p style="font-size:12px;color:var(--text-muted);margin-top:10px;">
                    <i class="fa fa-map-pin"></i> Colored pins show real nearby places for the category selected above, from live map data. Click "Locate Me" for distance &amp; ETA to <?php echo htmlspecialchars($destName); ?>.
                </p>
            </div>
        </div>

        <!-- Nearby Places Panel -->
        <div class="explore-panel" id="panel-places">
            <div class="explore-card">
                <h4><i class="fa fa-location-arrow"></i> Nearby Places</h4>
                <div class="place-cat-tabs">
                    <button class="place-cat-btn active" data-cat="hotel" onclick="loadPlaces('hotel')"><i class="fa fa-bed"></i> Hotels</button>
                    <button class="place-cat-btn" data-cat="restaurant" onclick="loadPlaces('restaurant')"><i class="fa fa-utensils"></i> Restaurants</button>
                    <button class="place-cat-btn" data-cat="hospital" onclick="loadPlaces('hospital')"><i class="fa fa-hospital"></i> Hospitals</button>
                    <button class="place-cat-btn" data-cat="pharmacy" onclick="loadPlaces('pharmacy')"><i class="fa fa-prescription-bottle-alt"></i> Pharmacies</button>
                    <button class="place-cat-btn" data-cat="police" onclick="loadPlaces('police')"><i class="fa fa-shield-alt"></i> Police</button>
                    <button class="place-cat-btn" data-cat="atm" onclick="loadPlaces('atm')"><i class="fa fa-money-bill-wave"></i> ATMs</button>
                    <button class="place-cat-btn" data-cat="gas_station" onclick="loadPlaces('gas_station')"><i class="fa fa-gas-pump"></i> Gas Stations</button>
                    <button class="place-cat-btn" data-cat="shopping_mall" onclick="loadPlaces('shopping_mall')"><i class="fa fa-shopping-bag"></i> Malls</button>
                    <button class="place-cat-btn" data-cat="tourist_info" onclick="loadPlaces('tourist_info')"><i class="fa fa-info-circle"></i> Tourist Info</button>
                    <button class="place-cat-btn" data-cat="attraction" onclick="loadPlaces('attraction')"><i class="fa fa-landmark"></i> Attractions</button>
                </div>
                <div id="placesGrid"></div>
            </div>
        </div>

        <!-- Weather Panel -->
        <div class="explore-panel" id="panel-weather">
            <div id="weatherContent">
                <div class="explore-loading"><i class="fa fa-spinner fa-spin spin"></i>Fetching live weather...</div>
            </div>
        </div>
    </div>
</div>

<div class="place-detail-modal-overlay" id="placeDetailModal" onclick="if(event.target===this) window.closePlaceDetails();">
    <div class="place-detail-modal">
        <button type="button" class="pd-close-btn" onclick="window.closePlaceDetails()" aria-label="Close"><i class="fa fa-times"></i></button>
        <div class="pd-modal-body" id="placeDetailBody"></div>
    </div>
</div>

<?php include('backend/explore-chatbot.php'); ?>
<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script src="<?php echo tms_asset_ver('js/explore.js'); ?>"></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();   // personal plan: sign in first, then return here
require_once dirname(__DIR__) . '/backend/MapItinerary.php';
require_once dirname(__DIR__) . '/backend/trips-page.php';
MapItinerary::useDb($dbh);

/**
 * trip-planner.php?id=N — day-by-day planner for ONE personal trip, owner only.
 * The lookup is scoped by the session e-mail in SQL (MapItinerary::get), so another user's id gives the same clean 404 as an id that
 * does not exist and no data is rendered. A plan is not a booking: nothing here books, pays or creates a package.
 */
$email = (string)$_SESSION['login'];
$id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$trip = null; $problem = '';
if (!MapItinerary::plannerReady()) {
    $problem = 'not_ready';
} elseif ($id > 0) {
    try { $trip = MapItinerary::get($email, $id); } catch (Throwable $e) { error_log('[TourBuddy] trip-planner load: ' . $e->getMessage()); $problem = 'error'; }
}
if (!$trip && $problem === '') $problem = 'missing';
if ($problem === 'missing') http_response_code(404);
elseif ($problem !== '') http_response_code($problem === 'not_ready' ? 503 : 500);

$tmsJson = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
tms_trips_head($trip ? $trip['title'] . ' | Trip planner' : 'Trip planner', (bool)$trip,
    $trip ? ['js/tms-trips.js', 'js/tms-trip-logic.js', 'js/tms-trip-optimize.js', 'js/tms-guidance.js', 'js/tms-trip-planner.js'] : []);
?>
<body>
<?php include('backend/header.php'); ?>
<main class="tms-tr-page" id="main">
  <div class="tms-tr-wrap">
<?php if (!$trip): ?>
    <div class="tms-tr-card tms-tr-empty">
      <span class="fa fa-map-o" aria-hidden="true"></span>
      <?php if ($problem === 'missing'): ?>
        <h1 class="tms-tr-title">Trip not found</h1>
        <p>This trip does not exist, or it is not in your account.</p>
      <?php elseif ($problem === 'not_ready'): ?>
        <h1 class="tms-tr-title">Trip planner not set up yet</h1>
        <p>The site owner needs to apply the latest database update (migration 045).</p>
      <?php else: ?>
        <h1 class="tms-tr-title">Something went wrong</h1>
        <p>The trip could not be loaded. Please try again in a moment.</p>
      <?php endif; ?>
      <a class="tms-tr-btn tms-tr-btn-primary" href="my-trips.php">Back to My trips</a>
    </div>
<?php else: ?>
    <p class="tms-tr-muted"><a href="my-trips.php"><i class="fa fa-arrow-left" aria-hidden="true"></i> My trips</a></p>
    <h1 class="tms-tr-title" id="tripTitleH"><?php echo tms_trips_e($trip['title']); ?></h1>
    <p class="tms-tr-lead"><span class="tms-tr-badge">Personal plan, not a booking</span> Times and costs are your own notes. Distances and travel times come from the route service when it is available.</p>
    <noscript><p class="tms-tr-note">The planner needs JavaScript. You can still read your plan below.</p></noscript>

    <div id="plannerStatus" class="tms-tr-status" role="status" aria-live="polite"></div>

    <div id="planner" data-trip-id="<?php echo (int)$trip['id']; ?>">
      <details class="tms-tr-card" id="detailsBox" style="margin-bottom:14px;">
        <summary><strong>Trip details</strong> <span class="tms-tr-muted" id="detailsSummary"></span></summary>
        <form id="detailsForm" class="tms-tr-form" style="margin-top:10px;" novalidate></form>
      </details>

      <div id="offlineBox"></div>
      <div id="budgetBox" style="margin-bottom:14px;"></div>

      <div id="warnings" aria-live="polite"></div>

      <div class="tms-tr-split">
        <section aria-labelledby="dayH">
          <h2 id="dayH" class="tms-sr-only">Days</h2>
          <div id="dayTabs" role="tablist" aria-label="Days of the trip" class="tms-tr-days"></div>
          <div id="dayPanel" role="tabpanel" tabindex="-1" aria-live="off"></div>

          <section class="tms-tr-card" aria-labelledby="addH" style="margin-top:14px;">
            <h2 id="addH">Add a place to this day</h2>
            <div class="tms-tr-field"><label for="placeSearch">Search places in our map database</label>
              <input class="tms-tr-input" id="placeSearch" type="search" autocomplete="off" maxlength="100" placeholder="Beach, museum, hotel..."></div>
            <ul class="tms-tr-search-results" id="searchResults" aria-label="Search results"></ul>
            <div class="tms-tr-row" style="margin-top:10px;">
              <button type="button" class="tms-tr-btn tms-tr-btn-sm" id="nearbyBtn">Places near the last stop of this day</button>
              <button type="button" class="tms-tr-btn tms-tr-btn-sm" id="savedBtn">From my saved places</button>
            </div>
            <div id="addStatus" class="tms-tr-status" role="status" aria-live="polite"></div>
            <ul class="tms-tr-search-results" id="addList" aria-label="Places you can add"></ul>
          </section>
        </section>

        <section class="tms-tr-sticky" aria-labelledby="mapH">
          <h2 id="mapH" style="font-size:18px;margin:0 0 8px;">Route map for the selected day</h2>
          <div class="tms-tr-mapbox"><div id="tripMap" role="region" aria-label="Map of the selected day. The numbered list on the left has the same stops and travel details."></div></div>
          <p class="tms-tr-muted" id="mapNote">Loading the map...</p>
          <div id="routeText" aria-live="polite"></div>
          <div id="guidance" style="margin-top:12px;"></div>
          <p class="tms-tr-muted">Map data &copy; OpenStreetMap contributors. Base map by OpenFreeMap. Routes by OSRM / FOSSGIS.</p>
        </section>
      </div>
    </div>
    <script type="application/json" id="tripData"><?php echo json_encode($trip, $tmsJson); ?></script>
<?php endif; ?>
  </div>
</main>
<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

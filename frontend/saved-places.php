<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();   // personal list: sign in first, then return here
require_once dirname(__DIR__) . '/backend/SavedPlaces.php';
require_once dirname(__DIR__) . '/backend/trips-page.php';
SavedPlaces::useDb($dbh);

/**
 * saved-places.php — the signed-in user's unified saved places (destinations, places, food, services, stays).
 * Works without JavaScript for viewing and removing (plain POST form + CSRF). JavaScript adds the category filter,
 * the map view, "Add to trip" and instant removal. The owner is always the session e-mail.
 * Saved places are personal notes: they are not bookings and not tour packages (those are on wishlist.php / tour-history.php).
 */
$email = (string)$_SESSION['login'];
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_saved'])) {
    tms_csrf_validate();
    try {
        SavedPlaces::remove($email, is_string($_POST['place_key'] ?? null) ? $_POST['place_key'] : '');
        $msg = 'Place removed from your saved places.';
    } catch (Throwable $e) { error_log('[TourBuddy] saved-places remove: ' . $e->getMessage()); $err = 'The place could not be removed. Please try again.'; }
}
$items = []; $unavailable = false;
try { $items = SavedPlaces::list($email, null, SavedPlaces::MAX_PER_USER); }
catch (Throwable $e) { error_log('[TourBuddy] saved-places list: ' . $e->getMessage()); $unavailable = true; }

$labels = ['destination' => 'Destination', 'hotel' => 'Hotel or resort', 'restaurant' => 'Restaurant or cafe', 'hospital' => 'Hospital', 'police' => 'Police',
    'pharmacy' => 'Pharmacy', 'atm' => 'ATM', 'gas_station' => 'Gas station', 'shopping_mall' => 'Mall', 'tourist_info' => 'Tourist info', 'attraction' => 'Attraction',
    'historic' => 'Historic site', 'museum' => 'Museum', 'religious' => 'Religious site', 'beach' => 'Beach', 'mountain' => 'Hill or viewpoint',
    'waterfall' => 'Waterfall, lake or river', 'park' => 'Park or forest', 'trail' => 'Trail', 'market' => 'Market', 'transport' => 'Transport',
    'visitor_info' => 'Visitor information', 'other' => 'Other place'];
$counts = [];
foreach ($items as $it) $counts[$it['category']] = ($counts[$it['category']] ?? 0) + 1;
$tmsJson = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$pins = array_map(static fn($i) => ['key' => $i['place_key'], 'title' => $i['name'], 'lat' => $i['lat'], 'lng' => $i['lng'], 'category' => $i['category'],
    'subtitle' => $labels[$i['category']] ?? 'Place'], $items);

tms_trips_head('Saved places', true, ['js/tms-trips.js', 'js/tms-saved-places.js']);
?>
<body>
<?php include('backend/header.php'); ?>
<main class="tms-tr-page" id="main">
  <div class="tms-tr-wrap">
    <h1 class="tms-tr-title"><i class="fa fa-heart" aria-hidden="true"></i> Saved places</h1>
    <p class="tms-tr-lead">Places, food, services and stays you saved from Explore and the map. <span class="tms-tr-badge">Personal list, not bookings</span></p>
    <p class="tms-tr-muted">Looking for tour packages? See your <a href="wishlist.php">package wishlist</a>. Confirmed bookings are in your <a href="tour-history.php">booking history</a>.</p>

    <div id="savedStatus" class="tms-tr-status<?php echo $err ? ' is-err' : ($msg ? ' is-ok' : ''); ?>" role="status" aria-live="polite"><?php echo tms_trips_e($err ?: $msg); ?></div>

    <?php if ($unavailable): ?>
      <div class="tms-tr-card tms-tr-empty"><span class="fa fa-exclamation-triangle" aria-hidden="true"></span>
        <p>Saved places are not available yet. The site owner needs to apply the latest database update.</p></div>
    <?php elseif (!$items): ?>
      <div class="tms-tr-card tms-tr-empty" id="savedEmpty"><span class="fa fa-heart-o" aria-hidden="true"></span>
        <h2>You have not saved any place yet</h2>
        <p>Tap the heart on a place in Explore or on the map to keep it here.</p>
        <a class="tms-tr-btn tms-tr-btn-primary" href="explore.php">Explore destinations</a>
        <a class="tms-tr-btn" href="tour-map.php">Open the map</a>
      </div>
    <?php else: ?>
      <div id="savedApp" data-count="<?php echo count($items); ?>">
        <ul class="tms-tr-chips" id="savedChips" aria-label="Filter by category" hidden>
          <li><button type="button" class="tms-tr-chip" data-cat="" aria-pressed="true">All (<?php echo count($items); ?>)</button></li>
          <?php foreach ($counts as $c => $n): ?>
            <li><button type="button" class="tms-tr-chip" data-cat="<?php echo tms_trips_e($c); ?>" aria-pressed="false"><?php echo tms_trips_e($labels[$c] ?? $c); ?> (<?php echo (int)$n; ?>)</button></li>
          <?php endforeach; ?>
        </ul>

        <div class="tms-tr-split">
          <section aria-labelledby="savedListH">
            <h2 id="savedListH" class="tms-sr-only">Your saved places as a list</h2>
            <p class="tms-tr-muted" id="savedCount" aria-live="polite"><?php echo count($items); ?> saved place<?php echo count($items) === 1 ? '' : 's'; ?></p>
            <ul class="tms-tr-list" id="savedList">
              <?php foreach ($items as $it): $cat = $labels[$it['category']] ?? 'Place'; ?>
              <li class="tms-tr-card tms-tr-item" data-key="<?php echo tms_trips_e($it['place_key']); ?>" data-cat="<?php echo tms_trips_e($it['category']); ?>"
                  data-lat="<?php echo tms_trips_e($it['lat']); ?>" data-lng="<?php echo tms_trips_e($it['lng']); ?>" data-name="<?php echo tms_trips_e($it['name']); ?>"
                  data-dest="<?php echo tms_trips_e($it['destination_name'] ?? ''); ?>">
                <h3><?php echo tms_trips_e($it['name']); ?></h3>
                <ul class="tms-tr-meta">
                  <li><strong>Type:</strong> <?php echo tms_trips_e($cat); ?></li>
                  <?php if (!empty($it['locality']) || !empty($it['country'])): ?><li><strong>Where:</strong> <?php echo tms_trips_e(trim(($it['locality'] ?? '') . ((!empty($it['locality']) && !empty($it['country'])) ? ', ' : '') . ($it['country'] ?? ''))); ?></li><?php endif; ?>
                  <?php if (!empty($it['destination_name'])): ?><li><strong>Saved from:</strong> <?php echo tms_trips_e($it['destination_name']); ?></li><?php endif; ?>
                  <li><strong>Location:</strong> <?php echo tms_trips_e(number_format($it['lat'], 5)); ?>, <?php echo tms_trips_e(number_format($it['lng'], 5)); ?></li>
                </ul>
                <div class="tms-tr-row">
                  <span data-tms-actions class="tms-saved-add"></span>
                  <a class="tms-tr-btn tms-tr-btn-sm" href="tour-map.php#c=<?php echo tms_trips_e($it['lng']); ?>,<?php echo tms_trips_e($it['lat']); ?>,15">View on map</a>
                  <form method="post" class="tms-saved-remove-form">
                    <?php tms_csrf_field(); ?>
                    <input type="hidden" name="place_key" value="<?php echo tms_trips_e($it['place_key']); ?>">
                    <button type="submit" name="remove_saved" value="1" class="tms-tr-btn tms-tr-btn-sm tms-tr-btn-danger" aria-label="Remove <?php echo tms_trips_e($it['name']); ?> from saved places"><i class="fa fa-trash" aria-hidden="true"></i> Remove</button>
                  </form>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
            <p class="tms-tr-muted" id="savedNone" hidden>No saved place in this category.</p>
          </section>

          <section class="tms-tr-sticky" aria-labelledby="savedMapH">
            <h2 id="savedMapH" class="tms-tr-title" style="font-size:18px;">Map view</h2>
            <div class="tms-tr-mapbox" id="savedMapBox"><div id="savedMap" role="region" aria-label="Map of your saved places. The list beside it has the same places."></div></div>
            <p class="tms-tr-muted" id="savedMapNote">The map needs JavaScript. The list shows every saved place.</p>
            <p class="tms-tr-muted">Map data &copy; OpenStreetMap contributors. Base map by OpenFreeMap.</p>
          </section>
        </div>
      </div>
      <script type="application/json" id="savedPins"><?php echo json_encode($pins, $tmsJson); ?></script>
    <?php endif; ?>
  </div>
</main>
<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();   // personal plans: sign in first, then return here
require_once dirname(__DIR__) . '/backend/MapItinerary.php';
require_once dirname(__DIR__) . '/backend/trips-page.php';
MapItinerary::useDb($dbh);

/**
 * my-trips.php — the user's PERSONAL trip plans. These are notes and itineraries, not bookings: nothing here creates a booking,
 * a payment or a package record. Confirmed bookings stay on tour-history.php (linked below).
 * Create / duplicate / delete are plain POST forms (CSRF + post-redirect-get), so the page works without JavaScript.
 * Every service call is scoped by the session e-mail inside MapItinerary.
 */
$email = (string)$_SESSION['login'];
$err = ''; $ok = '';
$done = is_string($_GET['done'] ?? null) ? $_GET['done'] : '';
if ($done === 'created') $ok = 'Trip created.'; elseif ($done === 'duplicated') $ok = 'Trip duplicated.'; elseif ($done === 'deleted') $ok = 'Trip deleted.';
$ready = MapItinerary::plannerReady();
$form = ['title' => '', 'destination_name' => '', 'start_date' => '', 'end_date' => '', 'day_count' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    $id = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
    if ($act === 'create') {
        foreach ($form as $k => $_) $form[$k] = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        $in = ['title' => $form['title'], 'destination_name' => $form['destination_name'], 'start_date' => $form['start_date'], 'end_date' => $form['end_date']];
        if ($form['start_date'] === '' && $form['day_count'] !== '') $in['day_count'] = $form['day_count'];
        $res = MapItinerary::create($email, $in);
        if (!empty($res['ok'])) { header('Location: trip-planner.php?id=' . (int)$res['id']); exit; }
        $err = (string)($res['error']['message'] ?? 'The trip could not be created.');
    } elseif ($act === 'duplicate' && $id > 0) {
        $res = MapItinerary::duplicateTrip($email, $id);
        if (!empty($res['ok'])) { header('Location: my-trips.php?done=duplicated'); exit; }
        $err = (string)($res['error']['message'] ?? 'The trip could not be duplicated.');
    } elseif ($act === 'delete' && $id > 0) {
        if (MapItinerary::delete($email, $id)) { header('Location: my-trips.php?done=deleted'); exit; }
        $err = 'Trip not found.';
    }
}

$trips = []; $loadFailed = false;
try { $trips = MapItinerary::list($email); } catch (Throwable $e) { error_log('[TourBuddy] my-trips list: ' . $e->getMessage()); $loadFailed = true; }

function tms_trip_dates(array $t): string
{
    if (!empty($t['start_date']) && !empty($t['end_date'])) {
        return $t['start_date'] === $t['end_date'] ? $t['start_date'] : $t['start_date'] . ' to ' . $t['end_date'];
    }
    return 'Dates not set';
}

tms_trips_head('My trips', false, []);
?>
<body>
<?php include('backend/header.php'); ?>
<main class="tms-tr-page" id="main">
  <div class="tms-tr-wrap">
    <h1 class="tms-tr-title"><i class="fa fa-suitcase" aria-hidden="true"></i> My trips</h1>
    <p class="tms-tr-lead"><span class="tms-tr-badge">Personal plans, not bookings</span> Plan places and days on your own. Nothing here books, charges or reserves anything.</p>
    <p class="tms-tr-muted">Your confirmed bookings are in your <a href="tour-history.php">booking history</a>. Places you saved are in <a href="saved-places.php">Saved places</a>.</p>

    <?php if (!$ready): ?>
      <p class="tms-tr-note" role="status">The trip planner needs a database update that has not been applied yet. Ask the site owner to run the latest migration (045).</p>
    <?php endif; ?>
    <div class="tms-tr-status<?php echo $err ? ' is-err' : ($ok ? ' is-ok' : ''); ?>" role="status" aria-live="polite"><?php echo tms_trips_e($err ?: $ok); ?></div>

    <section class="tms-tr-card" aria-labelledby="newTripH" style="margin-bottom:20px;">
      <h2 id="newTripH">Create a trip</h2>
      <form method="post" class="tms-tr-form" action="my-trips.php">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="do" value="create">
        <div class="tms-tr-field"><label for="tTitle">Trip name</label>
          <input class="tms-tr-input" id="tTitle" name="title" type="text" maxlength="120" required value="<?php echo tms_trips_e($form['title']); ?>" placeholder="Weekend in Cox's Bazar"></div>
        <div class="tms-tr-field"><label for="tDest">Destination (optional)</label>
          <input class="tms-tr-input" id="tDest" name="destination_name" type="text" maxlength="160" value="<?php echo tms_trips_e($form['destination_name']); ?>"></div>
        <div class="tms-tr-field"><label for="tStart">Start date (optional)</label>
          <input class="tms-tr-input" id="tStart" name="start_date" type="date" value="<?php echo tms_trips_e($form['start_date']); ?>"></div>
        <div class="tms-tr-field"><label for="tEnd">End date (optional, up to 60 days)</label>
          <input class="tms-tr-input" id="tEnd" name="end_date" type="date" value="<?php echo tms_trips_e($form['end_date']); ?>"></div>
        <div class="tms-tr-field"><label for="tDays">Or number of days, if you have no dates</label>
          <input class="tms-tr-input" id="tDays" name="day_count" type="number" min="1" max="60" inputmode="numeric" value="<?php echo tms_trips_e($form['day_count']); ?>"></div>
        <div class="tms-tr-field is-wide"><button class="tms-tr-btn tms-tr-btn-primary" type="submit"<?php echo $ready ? '' : ' disabled'; ?>><i class="fa fa-plus" aria-hidden="true"></i> Create trip</button></div>
      </form>
    </section>

    <h2 class="tms-tr-title" style="font-size:20px;">Your trips</h2>
    <?php if ($loadFailed): ?>
      <div class="tms-tr-card tms-tr-empty"><span class="fa fa-exclamation-triangle" aria-hidden="true"></span><p>Your trips could not be loaded right now. Please refresh in a moment.</p></div>
    <?php elseif (!$trips): ?>
      <div class="tms-tr-card tms-tr-empty"><span class="fa fa-map-o" aria-hidden="true"></span>
        <h3>No trips yet</h3><p>Create one above, or add a place from <a href="explore.php">Explore</a> or the <a href="tour-map.php">map</a> with Add to trip.</p></div>
    <?php else: ?>
      <ul class="tms-tr-grid" style="list-style:none;padding:0;margin:0;">
      <?php foreach ($trips as $t): $isPlanner = array_key_exists('start_date', $t); ?>
        <li class="tms-tr-card">
          <h3><a href="trip-planner.php?id=<?php echo (int)$t['id']; ?>"><?php echo tms_trips_e($t['title']); ?></a></h3>
          <ul class="tms-tr-meta">
            <?php if (!empty($t['destination_name'])): ?><li><strong>Destination:</strong> <?php echo tms_trips_e($t['destination_name']); ?></li><?php endif; ?>
            <?php if ($isPlanner): ?><li><strong>Dates:</strong> <?php echo tms_trips_e(tms_trip_dates($t)); ?> (<?php echo (int)$t['days']; ?> day<?php echo (int)$t['days'] === 1 ? '' : 's'; ?>)</li><?php endif; ?>
            <li><strong>Places:</strong> <?php echo (int)$t['stop_count']; ?></li>
            <?php if ($isPlanner): ?>
              <li><strong>Estimated cost:</strong>
                <?php if ($t['est_cost'] === null): ?>unknown<?php else: echo tms_trips_e(number_format((float)$t['est_cost'], 2));
                  if ((int)$t['cost_unknown_stops'] > 0): ?> <span class="tms-tr-muted">(<?php echo (int)$t['cost_unknown_stops']; ?> place<?php echo (int)$t['cost_unknown_stops'] === 1 ? '' : 's'; ?> without a cost)</span><?php endif; endif; ?></li>
              <?php if (($t['status'] ?? '') === 'completed'): ?><li><span class="tms-tr-badge">Completed</span></li><?php endif; ?>
            <?php endif; ?>
          </ul>
          <div class="tms-tr-row">
            <a class="tms-tr-btn tms-tr-btn-primary tms-tr-btn-sm" href="trip-planner.php?id=<?php echo (int)$t['id']; ?>">Open planner</a>
            <form method="post" action="my-trips.php">
              <?php tms_csrf_field(); ?><input type="hidden" name="do" value="duplicate"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
              <button class="tms-tr-btn tms-tr-btn-sm" type="submit"<?php echo $ready ? '' : ' disabled'; ?> aria-label="Duplicate <?php echo tms_trips_e($t['title']); ?>">Duplicate</button>
            </form>
          </div>
          <details class="tms-tr-details">
            <summary>Delete this trip</summary>
            <form method="post" action="my-trips.php">
              <p class="tms-tr-muted">This deletes the plan and its places from your account. It cannot be undone. Bookings are not affected.</p>
              <?php tms_csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
              <button class="tms-tr-btn tms-tr-btn-sm tms-tr-btn-danger" type="submit">Yes, delete &ldquo;<?php echo tms_trips_e(mb_substr($t['title'], 0, 40, 'UTF-8')); ?>&rdquo;</button>
            </form>
          </details>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</main>
<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

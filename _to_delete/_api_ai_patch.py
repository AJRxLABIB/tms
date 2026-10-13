import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

repls = []

# 1. docblock
old1 = " *   POST /api/ai.php?action=ai_feedback  (Round 115, item 14 -- thumbs up/down)\n */"
new1 = (" *   POST /api/ai.php?action=ai_feedback  (Round 115, item 14 -- thumbs up/down)\n"
        " *   POST /api/ai.php?action=weather_replan_check    (Round 115, item 12)\n"
        " *   POST /api/ai.php?action=weather_replan_preview  (Round 115, item 12)\n"
        " *   POST /api/ai.php?action=weather_replan_apply    (Round 115, item 12)\n"
        " */")
repls.append((old1, new1))

# 2. match statement
old2 = "        'ai_feedback'     => handleFeedback($dbh, $userEmail),\n        default           => apiError(404, \"Unknown action: $action\"),"
new2 = ("        'ai_feedback'     => handleFeedback($dbh, $userEmail),\n"
        "        'weather_replan_check'   => handleWeatherReplanCheck($dbh, $userEmail),\n"
        "        'weather_replan_preview' => handleWeatherReplanPreview($dbh, $userEmail),\n"
        "        'weather_replan_apply'   => handleWeatherReplanApply($dbh, $userEmail),\n"
        "        default           => apiError(404, \"Unknown action: $action\"),")
repls.append((old2, new2))

# 3. the three new handler functions, inserted right before apiSuccess()
old3 = "function apiSuccess(array $data): void {"
new3 = '''/**
 * Round 115, master prompt item 12 ("AI Dynamic Trip Replanning"). Three
 * small, deliberately separate steps rather than one "smart" endpoint:
 *   1. check   -- pure read-only detection, no AI call, safe to run anytime.
 *   2. preview -- generates a proposed itinerary but does NOT save it.
 *   3. apply   -- persists exactly the proposal the traveler already saw.
 * This shape is what makes "the traveler must explicitly approve, and a
 * confirmed booking is never auto-changed" true by construction rather
 * than by a comment promising it: nothing is written to
 * tbl_ai_itineraries (and tblbooking is never touched by any of these
 * three) until step 3 runs, and step 3 only ever runs from an explicit
 * button click on the exact proposal step 2 returned.
 */
function handleWeatherReplanCheck(PDO $dbh, string $email): void {
    $bookingId = resolveOwnedBookingId($dbh, $email);
    if ($bookingId < 1) {
        apiError(403, 'This booking was not found on your account.');
    }
    $destination = tms_clean($_POST['destination'] ?? '');
    $startDate   = tms_clean($_POST['start_date'] ?? '');
    $duration    = max(1, min(30, (int)($_POST['duration'] ?? 3)));
    if ($destination === '' || $startDate === '') {
        apiError(400, 'Destination and start date are required.');
    }

    $planner = new ItineraryPlanner($dbh);
    $result  = $planner->checkWeatherRisk($bookingId, $destination, $startDate, $duration);
    apiSuccess($result);
}

function handleWeatherReplanPreview(PDO $dbh, string $email): void {
    if (function_exists('set_time_limit')) { @set_time_limit(120); }
    tms_csrf_validate();

    $bookingId = resolveOwnedBookingId($dbh, $email);
    if ($bookingId < 1) {
        apiError(403, 'This booking was not found on your account.');
    }

    $tripData = buildTripDataFromPost();
    if (empty($tripData['destination'])) {
        apiError(400, 'Destination is required.');
    }

    $day        = (int)($_POST['flagged_day'] ?? 0);
    $date       = tms_clean($_POST['flagged_date'] ?? '');
    $rainChance = (int)($_POST['rain_chance'] ?? 0);
    $feedback = ($day > 0 && $date !== '')
        ? "Live weather forecast shows a {$rainChance}% chance of rain on Day {$day} ({$date}) of this trip. "
          . "Please propose a weather-appropriate alternative specifically for that day (e.g. an indoor or "
          . "cover-friendly option), and keep every other day as close to a normal itinerary for this trip as "
          . "you reasonably can -- don't rewrite days that have no weather concern."
        : 'Please review this itinerary against current weather conditions and suggest any needed adjustments.';

    $planner   = new ItineraryPlanner($dbh);
    $itinerary = $planner->regenerate($tripData, [$feedback], $email, $bookingId, false); // save=false: preview only

    apiSuccess(['itinerary' => $itinerary, 'booking_id' => $bookingId]);
}

function handleWeatherReplanApply(PDO $dbh, string $email): void {
    tms_csrf_validate();

    $bookingId = resolveOwnedBookingId($dbh, $email);
    if ($bookingId < 1) {
        apiError(403, 'This booking was not found on your account.');
    }

    $itineraryJson = $_POST['itinerary'] ?? '';
    $itinerary = is_string($itineraryJson) ? json_decode($itineraryJson, true) : null;
    if (!is_array($itinerary) || empty($itinerary['days'])) {
        apiError(400, 'No valid proposed itinerary was provided to apply.');
    }

    $tripData = buildTripDataFromPost();
    $planner  = new ItineraryPlanner($dbh);
    $ok       = $planner->saveApprovedItinerary($itinerary, $tripData, $email, $bookingId);

    if (!$ok) {
        apiError(500, 'Could not save the updated itinerary right now.');
    }
    apiSuccess(['saved' => true]);
}

function apiSuccess(array $data): void {'''
repls.append((old3, new3))

missing = []
for i, (old, new) in enumerate(repls, 1):
    n = content.count(old)
    if n != 1:
        missing.append((i, n))
    else:
        content = content.replace(old, new)

if missing:
    print(f"[{path}] ABORTED. bad counts: {missing}")
else:
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print(f"[{path}] OK: {len(repls)} replacements applied")

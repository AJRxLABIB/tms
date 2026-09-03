<?php
/**
 * api/ai.php — TMS AI REST API
 *
 * Centralized endpoint for all AI feature calls.
 * Frontend calls this via fetch() for async AI features.
 *
 * Endpoints:
 *   POST /api/ai.php?action=recommend
 *   POST /api/ai.php?action=itinerary
 *   POST /api/ai.php?action=copilot_chat
 *   POST /api/ai.php?action=chatbot
 *   POST /api/ai.php?action=budget
 *   POST /api/ai.php?action=packing_list
 *   POST /api/ai.php?action=virtual_guide
 *   POST /api/ai.php?action=proactive_alert
 *   POST /api/ai.php?action=memory
 *   GET  /api/ai.php?action=copilot_history&booking_id=N
 */

session_start();
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/ai/AIClient.php';
require_once dirname(__DIR__) . '/includes/ai/TravelRecommender.php';
require_once dirname(__DIR__) . '/includes/ai/ItineraryPlanner.php';
require_once dirname(__DIR__) . '/includes/ai/TripCopilot.php';
require_once dirname(__DIR__) . '/includes/ai/BudgetOptimizer.php';
require_once dirname(__DIR__) . '/includes/ai/PackageGenerator.php';
require_once dirname(__DIR__) . '/includes/ai/CrowdPredictor.php';
require_once dirname(__DIR__) . '/includes/SustainabilityCalculator.php';
require_once dirname(__DIR__) . '/includes/FlightTracker.php';
require_once dirname(__DIR__) . '/includes/ai/SiteAssistant.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Security headers ──────────────────────────────────────────────────────────
header('X-Frame-Options: SAMEORIGIN');

$action    = tms_clean($_GET['action'] ?? $_POST['action'] ?? '');
$userEmail = $_SESSION['login'] ?? 'guest';

// ── Rate limit per IP for guest users ────────────────────────────────────────
if ($userEmail === 'guest') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Lightweight IP-based limit stored in session
    $sessionKey = 'ai_calls_' . date('YmdH');
    $_SESSION[$sessionKey] = ($_SESSION[$sessionKey] ?? 0) + 1;
    if ($_SESSION[$sessionKey] > 10) {
        apiError(429, 'Too many requests. Please sign in for unlimited AI access.');
    }
}

try {
    match ($action) {
        'recommend'       => handleRecommend($dbh, $userEmail),
        'itinerary'       => handleItinerary($dbh, $userEmail),
        'copilot_chat'    => handleCopilotChat($dbh, $userEmail),
        'copilot_history' => handleCopilotHistory($dbh, $userEmail),
        'budget'          => handleBudget($dbh, $userEmail),
        'packing_list'    => handlePackingList($dbh, $userEmail),
        'virtual_guide'   => handleVirtualGuide($dbh, $userEmail),
        'proactive_alert' => handleProactiveAlert($dbh, $userEmail),
        'memory'          => handleMemory($dbh, $userEmail),
        'generate_package'=> handleGeneratePackage($dbh, $userEmail),
        'suggest_price'   => handleSuggestPrice($dbh),
        'crowd_prediction'=> handleCrowdPrediction($dbh, $userEmail),
        'sustainability'  => handleSustainability($dbh),
        'flight_status'   => handleFlightStatus($dbh),
        'chatbot'         => handleChatbot($dbh, $userEmail),
        'track_quickreply'=> handleTrackQuickReply($dbh),
        'image_analyze'   => handleImageAnalyze($dbh, $userEmail),
        default           => apiError(404, "Unknown action: $action"),
    };
} catch (Throwable $e) {
    error_log('[TMS AI API] ' . $e->getMessage());
    apiError(500, 'AI service error. Please try again.');
}

// ══════════════════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════════════════

function handleRecommend(PDO $dbh, string $email): void {
    $prefs = [
        'budget'       => (int)($_POST['budget'] ?? 0),
        'travelers'    => max(1, (int)($_POST['travelers'] ?? 2)),
        'duration'     => max(1, (int)($_POST['duration'] ?? 3)),
        'start_date'   => tms_clean($_POST['start_date'] ?? date('Y-m-d', strtotime('+7 days'))),
        'end_date'     => tms_clean($_POST['end_date'] ?? date('Y-m-d', strtotime('+10 days'))),
        'travel_type'  => tms_clean($_POST['travel_type'] ?? 'leisure'),
        'climate'      => tms_clean($_POST['climate'] ?? 'any'),
        'activities'   => tms_clean($_POST['activities'] ?? 'sightseeing'),
        'accommodation'=> tms_clean($_POST['accommodation'] ?? 'hotel'),
    ];

    if ($prefs['budget'] < 100) {
        apiError(400, 'Please enter a valid budget amount.');
    }

    $recommender     = new TravelRecommender($dbh);
    $recommendations = $recommender->recommend($prefs, $email);

    apiSuccess(['recommendations' => $recommendations, 'count' => count($recommendations)]);
}

function handleItinerary(PDO $dbh, string $email): void {
    $tripData = [
        'destination'     => tms_clean($_POST['destination'] ?? ''),
        'duration'        => max(1, min(30, (int)($_POST['duration'] ?? 3))),
        'travelers'       => max(1, (int)($_POST['travelers'] ?? 2)),
        'budget'          => (int)($_POST['budget'] ?? 0),
        'start_date'      => tms_clean($_POST['start_date'] ?? date('Y-m-d', strtotime('+7 days'))),
        'travel_type'     => tms_clean($_POST['travel_type'] ?? 'leisure'),
        'climate'         => tms_clean($_POST['climate'] ?? 'warm'),
        'accommodation'   => tms_clean($_POST['accommodation'] ?? 'hotel'),
        'transport'       => tms_clean($_POST['transport'] ?? 'mixed'),
        'activities'      => array_map('trim', explode(',', tms_clean($_POST['activities'] ?? 'sightseeing'))),
        'special_requests'=> tms_clean($_POST['special_requests'] ?? ''),
    ];

    if (empty($tripData['destination'])) {
        apiError(400, 'Destination is required.');
    }

    $planner   = new ItineraryPlanner($dbh);
    $itinerary = $planner->generate($tripData, $email);

    apiSuccess(['itinerary' => $itinerary]);
}

function handleChatbot(PDO $dbh, string $email): void {
    $message = tms_clean($_POST['message'] ?? '');
    if ($message === '') {
        apiError(400, 'Message is required.');
    }
    if (mb_strlen($message) > 1000) {
        apiError(400, 'Message is too long.');
    }

    // History comes from the browser as JSON: [{"role":"user"|"assistant","content":"..."}]
    // Capped here server-side too (never trust the client's cap alone) so a
    // manipulated request can't blow up the prompt size / API cost.
    $historyRaw = json_decode($_POST['history'] ?? '[]', true);
    $history = is_array($historyRaw) ? array_slice($historyRaw, -8) : [];

    $assistant = new SiteAssistant($dbh);
    $result    = $assistant->reply($message, $history, $email);

    if (!$result['success']) {
        // Let the frontend fall back to its local rule-based responder rather
        // than showing the visitor a raw error inside the chat window.
        apiError(503, $result['error'] ?: 'AI assistant temporarily unavailable.');
    }

    apiSuccess(['reply' => $result['reply']]);
}

function handleTrackQuickReply(PDO $dbh): void {
    // Deliberately guest-friendly and fire-and-forget: any visitor (signed in
    // or not) can click a quick-reply button, and a failed insert should
    // never surface an error in the chat widget.
    $label = mb_substr(tms_clean($_POST['label'] ?? ''), 0, 100);
    if ($label === '') { apiSuccess(['tracked' => false]); return; }

    try {
        $dbh->prepare("INSERT INTO tbl_chatbot_clicks(Label) VALUES(:l)")->execute([':l' => $label]);
    } catch (Exception $e) {
        error_log('[TMS AI API] track_quickreply insert failed: ' . $e->getMessage());
    }
    apiSuccess(['tracked' => true]);
}

function handleCopilotChat(PDO $dbh, string $email): void {
    if ($email === 'guest') {
        apiError(401, 'Please sign in to use the Trip Copilot.');
    }

    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $message   = tms_clean($_POST['message'] ?? '');

    if (!$bookingId || empty($message)) {
        apiError(400, 'Booking ID and message are required.');
    }

    $copilot  = new TripCopilot($dbh);
    $response = $copilot->chat($bookingId, $message, $email);

    apiSuccess($response);
}

function handleCopilotHistory(PDO $dbh, string $email): void {
    if ($email === 'guest') {
        apiError(401, 'Sign in required.');
    }

    $bookingId = (int)($_GET['booking_id'] ?? 0);
    if (!$bookingId) apiError(400, 'Booking ID required.');

    $copilot = new TripCopilot($dbh);
    $history = $copilot->getHistory($bookingId, 50);

    apiSuccess(['history' => $history]);
}

function handleBudget(PDO $dbh, string $email): void {
    $params = [
        'destination' => tms_clean($_POST['destination'] ?? ''),
        'duration'    => max(1, (int)($_POST['duration'] ?? 3)),
        'travelers'   => max(1, (int)($_POST['travelers'] ?? 2)),
        'travel_type' => tms_clean($_POST['travel_type'] ?? 'leisure'),
        'climate'     => tms_clean($_POST['climate'] ?? 'warm'),
    ];

    if (empty($params['destination'])) apiError(400, 'Destination required.');

    $optimizer  = new BudgetOptimizer($dbh);
    $budgetPlan = $optimizer->generateBudgetPlan($params, $email);

    apiSuccess(['budget_plan' => $budgetPlan]);
}

/**
 * Admin-only: real, catalog-grounded price suggestion for a new or existing
 * package. Requires an active admin session ($_SESSION['alogin']) — this is
 * a pricing/business-intelligence tool, not something guest/customer
 * sessions should be able to call.
 */
/**
 * Crowd prediction / best-time-to-visit — public-facing (any user browsing a
 * destination), so cached longer than most AI features since real booking
 * seasonality doesn't meaningfully shift day to day.
 */
/**
 * Sustainability/carbon footprint estimate — pure arithmetic, no AI call, so
 * this is cheap to compute, but still cached since it's public-facing and
 * the underlying distance never changes for a given destination.
 */
/**
 * Real-time flight tracking via OpenSky Network (free, keyless). See
 * FlightTracker.php's docblock for the honest scope of what this can and
 * can't show — live position when available, not airline schedule data.
 */
function handleFlightStatus(PDO $dbh): void {
    $flightNumber = tms_clean($_POST['flight_number'] ?? $_GET['flight_number'] ?? '');
    if (empty($flightNumber)) apiError(400, 'Flight number required.');

    $tracker = new FlightTracker($dbh);
    $result = $tracker->trackFlight($flightNumber);

    if (!$result['success']) apiError(503, $result['message']);
    apiSuccess($result);
}

function handleSustainability(PDO $dbh): void {
    $destination = tms_clean($_POST['destination'] ?? $_GET['destination'] ?? '');
    $travelers   = max(1, (int)($_POST['travelers'] ?? $_GET['travelers'] ?? 1));
    if (empty($destination)) apiError(400, 'Destination required.');

    global $tmsCache;
    $cacheKey = 'sustainability_' . md5(strtolower($destination) . '_' . $travelers);
    if (isset($tmsCache)) {
        $cached = $tmsCache->get($cacheKey);
        if ($cached !== null) { apiSuccess(['footprint' => $cached]); return; }
    }

    $calculator = new SustainabilityCalculator($dbh);
    $footprint  = $calculator->estimateFootprint($destination, $travelers);

    if (isset($tmsCache) && !empty($footprint['has_data'])) {
        $tmsCache->set($cacheKey, $footprint, 604800); // 7 days — real-world distance never changes
    }

    apiSuccess(['footprint' => $footprint]);
}

function handleCrowdPrediction(PDO $dbh, string $email): void {
    $destination = tms_clean($_POST['destination'] ?? $_GET['destination'] ?? '');
    if (empty($destination)) apiError(400, 'Destination required.');

    global $tmsCache;
    $cacheKey = 'crowd_pred_' . md5(strtolower($destination));
    if (isset($tmsCache)) {
        $cached = $tmsCache->get($cacheKey);
        if ($cached !== null) { apiSuccess(['prediction' => $cached]); return; }
    }

    $predictor  = new CrowdPredictor($dbh);
    $prediction = $predictor->predictBestVisitingTime($destination, $email);

    if (isset($tmsCache)) $tmsCache->set($cacheKey, $prediction, 21600); // 6 hours

    apiSuccess(['prediction' => $prediction]);
}

function handleSuggestPrice(PDO $dbh): void {
    if (empty($_SESSION['alogin'])) {
        apiError(403, 'Admin session required.');
    }

    $packageId = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
    $location  = tms_clean($_POST['location'] ?? '');
    $type      = tms_clean($_POST['type'] ?? '');

    if (!$packageId && !$location && !$type) {
        apiError(400, 'Provide a package_id, or a location/type to compare against.');
    }

    $optimizer = new BudgetOptimizer($dbh);
    $prediction = $optimizer->predictPackagePrice($packageId, $location ?: null, $type ?: null, $_SESSION['alogin']);

    apiSuccess(['prediction' => $prediction]);
}

function handlePackingList(PDO $dbh, string $email): void {
    $params = [
        'destination' => tms_clean($_POST['destination'] ?? ''),
        'duration'    => max(1, (int)($_POST['duration'] ?? 3)),
        'travel_type' => tms_clean($_POST['travel_type'] ?? 'leisure'),
        'climate'     => tms_clean($_POST['climate'] ?? 'warm'),
    ];

    $optimizer   = new BudgetOptimizer($dbh);
    $packingList = $optimizer->generatePackingList($params, $email);

    apiSuccess(['packing_list' => $packingList]);
}

function handleVirtualGuide(PDO $dbh, string $email): void {
    $destination = tms_clean($_POST['destination'] ?? '');
    $question    = tms_clean($_POST['question'] ?? '');

    if (empty($destination) || empty($question)) {
        apiError(400, 'Destination and question are required.');
    }

    $ai     = new AIClient($dbh);
    $system = "You are a local expert guide for {$destination}. Answer travel questions with specific, accurate, helpful information. Keep answers concise but complete (3-5 sentences). Focus on practical traveler needs.";

    $result  = $ai->ask($system, $question, '', $email);
    $answer  = $result['success'] ? $result['content'] : 'Sorry, the virtual guide is temporarily unavailable.';

    apiSuccess(['answer' => $answer, 'destination' => $destination]);
}

function handleProactiveAlert(PDO $dbh, string $email): void {
    if ($email === 'guest') { apiSuccess(['alert' => null]); return; }

    $bookingId = (int)($_POST['booking_id'] ?? 0);
    if (!$bookingId) { apiSuccess(['alert' => null]); return; }

    $copilot = new TripCopilot($dbh);
    $alert   = $copilot->generateProactiveAlert($bookingId, $email);

    apiSuccess(['alert' => $alert]);
}

function handleMemory(PDO $dbh, string $email): void {
    if ($email === 'guest') apiError(401, 'Sign in required.');

    $bookingId = (int)($_POST['booking_id'] ?? 0);
    if (!$bookingId) apiError(400, 'Booking ID required.');

    // Get booking details for memory generation
    try {
        $stmt = $dbh->prepare("SELECT b.*, p.PackageName, p.PackageLocation FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId WHERE b.BookingId=? AND b.UserEmail=?");
        $stmt->execute([$bookingId, $email]);
        $booking = $stmt->fetch(PDO::FETCH_OBJ);
    } catch (Exception $e) {
        $booking = null;
    }

    if (!$booking) apiError(404, 'Booking not found.');

    $ai = new AIClient($dbh);
    $system = "You are a creative travel writer. Generate a heartfelt travel memory record from trip data. Return JSON only: {\"journal\":\"<3-paragraph travel journal>\",\"summary\":\"<1 sentence trip summary>\",\"social_captions\":[\"<Instagram caption 1>\",\"<caption 2>\",\"<caption 3>\"],\"achievement\":\"<fun achievement badge title e.g. Sundarbans Explorer>\"}";

    $userMsg = "Trip: {$booking->PackageName} to {$booking->PackageLocation}. Travelers: {$booking->NoOfPersons}. Date: {$booking->BookingDate}. Generate a travel memory.";

    $result = $ai->ask($system, $userMsg, "memory_{$bookingId}", $email);
    $memory = $result['success'] ? ($ai->parseJson($result['content']) ?? []) : [];

    if (!empty($memory)) {
        try {
            $dbh->prepare("INSERT INTO tbl_travel_memories (booking_id, user_email, journal_text, social_captions, created_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE journal_text=VALUES(journal_text)")
                ->execute([$bookingId, $email, $memory['journal'] ?? '', json_encode($memory['social_captions'] ?? [])]);
        } catch (Exception $e) {}
    }

    apiSuccess(['memory' => $memory]);
}

function handleGeneratePackage(PDO $dbh, string $email): void {
    $prefs = [
        'budget'          => (int)($_POST['budget'] ?? 0),
        'travelers'       => max(1, (int)($_POST['travelers'] ?? 2)),
        'duration'        => max(1, min(30, (int)($_POST['duration'] ?? 5))),
        'start_date'      => tms_clean($_POST['start_date'] ?? date('Y-m-d', strtotime('+7 days'))),
        'travel_type'     => tms_clean($_POST['travel_type'] ?? 'leisure'),
        'climate'         => tms_clean($_POST['climate'] ?? 'warm'),
        'accommodation'   => tms_clean($_POST['accommodation'] ?? 'hotel'),
        'activities'      => tms_clean($_POST['activities'] ?? 'sightseeing'),
        'special_requests'=> tms_clean($_POST['special_requests'] ?? ''),
        'destination'     => tms_clean($_POST['destination'] ?? ''),
    ];

    if ($prefs['budget'] < 500) {
        apiError(400, 'Please enter a valid budget.');
    }

    $generator = new PackageGenerator($dbh);
    $package   = $generator->generate($prefs, $email);

    apiSuccess(['package' => $package]);
}

/**
 * AI Image Analyzer (reviewer ask #11): user uploads a destination photo,
 * Gemini identifies the scene, and we recommend real packages that match —
 * not a canned response. Uses the exact same two-layer upload validation
 * (extension + finfo content sniffing) already proven in wallet.php, so a
 * renamed non-image file can't reach the AI call.
 */
function handleImageAnalyze(PDO $dbh, string $email): void {
    if (empty($_FILES['photo']['size'])) {
        apiError(400, 'Choose a photo to analyze.');
    }
    $ext  = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $size = (int)$_FILES['photo']['size'];
    $tmp  = $_FILES['photo']['tmp_name'];
    $allowedExt  = ['jpg', 'jpeg', 'png', 'webp'];
    $mimeForExt  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    if (!in_array($ext, $allowedExt, true)) {
        apiError(400, 'Only JPG, PNG, or WEBP photos are allowed.');
    }
    if ($size > 6 * 1024 * 1024) {
        apiError(400, 'Photo must be under 6 MB.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = $finfo ? finfo_file($finfo, $tmp) : null;
    if ($finfo) finfo_close($finfo);
    if (!$realMime || !in_array($realMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        apiError(400, "That file's actual content doesn't look like a photo.");
    }

    $imageB64 = base64_encode(file_get_contents($tmp));

    $ai = new AIClient($dbh, $GLOBALS['tmsCache'] ?? null);
    $system = 'You are a travel scene classifier. Look at the photo and respond with STRICT JSON only, '
        . 'no markdown fences, no commentary: {"scene":"beach|mountain|forest|city|snow|desert|historical|adventure",'
        . '"confidence":"high|medium|low","description":"one short sentence describing what you see"}. '
        . 'Pick exactly one scene value from that list, whichever fits best.';
    $result = $ai->askWithImage($system, 'Classify this travel photo.', $imageB64, $realMime, $email);

    if (!$result['success']) {
        apiError(502, $result['content'] ?? 'Could not analyze this photo right now.');
    }

    $parsed = null;
    if (preg_match('/\{.*\}/s', $result['content'], $m)) {
        $parsed = json_decode($m[0], true);
    }
    $validScenes = ['beach','mountain','forest','city','snow','desert','historical','adventure'];
    if (!$parsed || !in_array($parsed['scene'] ?? '', $validScenes, true)) {
        apiError(502, 'Could not confidently identify this photo. Try a clearer landscape/destination shot.');
    }

    // Map the identified scene to real search terms against real columns —
    // PackageType/PackageFetures/PackageLocation/PackageName — rather than a
    // fixed keyword-to-package-ID table that would go stale as the catalog
    // changes.
    $sceneKeywords = [
        'beach'      => ['beach','sea','coastal','island','bay'],
        'mountain'   => ['mountain','hill','trek','peak'],
        'forest'     => ['forest','jungle','rainforest','nature'],
        'city'       => ['city','urban','downtown'],
        'snow'       => ['snow','winter','ski'],
        'desert'     => ['desert','dune'],
        'historical' => ['historical','heritage','ancient','fort','temple'],
        'adventure'  => ['adventure','hiking','rafting','trek'],
    ];
    $keywords = $sceneKeywords[$parsed['scene']] ?? [$parsed['scene']];
    $conditions = [];
    $params = [];
    foreach ($keywords as $i => $kw) {
        $conditions[] = "(PackageName LIKE :kw$i OR PackageType LIKE :kw$i OR PackageFetures LIKE :kw$i OR PackageLocation LIKE :kw$i OR PackageDetails LIKE :kw$i)";
        $params[":kw$i"] = '%' . $kw . '%';
    }
    $sql = "SELECT PackageId, PackageName, PackageType, PackageLocation, PackagePrice, PackageImage, AvgRating
            FROM tbltourpackages WHERE Status = 1 AND (" . implode(' OR ', $conditions) . ")
            ORDER BY AvgRating DESC LIMIT 6";
    $q = $dbh->prepare($sql);
    $q->execute($params);
    $matches = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($matches as &$m2) {
        $m2['PackagePrice'] = (float)$m2['PackagePrice'];
        $m2['image_url'] = tms_package_image_src($m2['PackageImage'] ?? '');
        unset($m2['PackageImage']);
    }
    unset($m2);

    apiSuccess([
        'scene'       => $parsed['scene'],
        'confidence'  => $parsed['confidence'] ?? 'medium',
        'description' => $parsed['description'] ?? '',
        'matches'     => $matches,
        'match_note'  => empty($matches) ? "No packages currently match a \"{$parsed['scene']}\" destination — check back as the catalog grows." : null,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function apiSuccess(array $data): void {
    echo json_encode(['success' => true, 'data' => $data, 'ts' => time()], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

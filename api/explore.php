<?php
/**
 * api/explore.php — TMS Explore Mode REST API
 *
 * Centralized endpoint for all Explore Mode AJAX calls (destination search,
 * AI destination info, live weather, nearby places, routing, AI chatbot).
 * Mirrors the structure/conventions of api/ai.php.
 *
 * Endpoints:
 *   GET  /api/explore.php?action=search&q=...
 *   GET  /api/explore.php?action=info&lat=..&lng=..&name=..&country=..
 *   GET  /api/explore.php?action=weather&lat=..&lng=..
 *   GET  /api/explore.php?action=places&lat=..&lng=..&category=hotel|restaurant|...
 *   GET  /api/explore.php?action=place_details&category=..&name=..&lat=..&lng=..&address=&phone=&opening_hours=&website=&rating=&distance_m=
 *   GET  /api/explore.php?action=route&from_lat&from_lng&to_lat&to_lng&mode=driving|walking|cycling|transit
 *   GET  /api/explore.php?action=history
 *   POST /api/explore.php?action=chat
 */

session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/ai/AIClient.php';
require_once dirname(__DIR__) . '/includes/ExploreService.php';
require_once dirname(__DIR__) . '/includes/PlaceDetailsService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$action    = tms_clean($_GET['action'] ?? $_POST['action'] ?? '');
$userEmail = $_SESSION['login'] ?? 'guest';
$service   = new ExploreService($dbh);

// Lightweight per-session rate limit for guests (mirrors api/ai.php)
if ($userEmail === 'guest') {
    $key = 'explore_calls_' . date('YmdH');
    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
    if ($_SESSION[$key] > 60) {
        apiError(429, 'Too many requests. Please sign in for unlimited Explore access.');
    }
}

try {
    match ($action) {
        'search'  => handleSearch($service),
        'info'    => handleInfo($dbh, $service, $userEmail),
        'weather' => handleWeather($service),
        'places'  => handlePlaces($service),
        'place_details' => handlePlaceDetails(),
        'route'   => handleRoute($service),
        'history' => handleHistory($service, $userEmail),
        'chat'    => handleChat($dbh, $userEmail),
        default   => apiError(404, "Unknown action: $action"),
    };
} catch (Throwable $e) {
    error_log('[TMS Explore API] ' . $e->getMessage());
    apiError(500, 'Explore service error. Please try again.');
}

// ══════════════════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════════════════

function handleSearch(ExploreService $service): void {
    $q = tms_clean($_GET['q'] ?? '');
    $res = $service->searchDestinations($q);
    $res['success'] ? apiSuccess(['results' => $res['results']]) : apiError(503, $res['error'] ?? 'Search failed.');
}

function handleInfo(PDO $dbh, ExploreService $service, string $email): void {
    $name    = tms_clean($_GET['name'] ?? '');
    $lat     = (float)($_GET['lat'] ?? 0);
    $lng     = (float)($_GET['lng'] ?? 0);
    $country = tms_clean($_GET['country'] ?? '');

    if ($name === '' || ($lat === 0.0 && $lng === 0.0)) {
        apiError(400, 'A destination name and coordinates are required.');
    }

    $ai  = new AIClient($dbh);
    $res = $service->getDestinationInfo($ai, $name, $lat, $lng, $country, $email);
    $res['success'] ? apiSuccess(['info' => $res['data'], 'cached' => $res['cached'] ?? false])
                     : apiError(503, $res['error'] ?? 'Could not load destination information.');
}

function handleWeather(ExploreService $service): void {
    $lat = (float)($_GET['lat'] ?? null);
    $lng = (float)($_GET['lng'] ?? null);
    if (!$lat && !$lng) apiError(400, 'Coordinates are required.');

    $res = $service->getWeather($lat, $lng);
    $res['success'] ? apiSuccess(['weather' => $res['data']]) : apiError(503, $res['error'] ?? 'Weather service unavailable.');
}

function handlePlaces(ExploreService $service): void {
    $lat      = (float)($_GET['lat'] ?? 0);
    $lng      = (float)($_GET['lng'] ?? 0);
    $category = tms_clean($_GET['category'] ?? '');
    $radius   = tms_int($_GET['radius'] ?? 4000, 500, 20000);

    if (!$lat && !$lng) apiError(400, 'Coordinates are required.');
    if ($category === '') apiError(400, 'A place category is required.');

    $res = $service->getNearbyPlaces($lat, $lng, $category, $radius);
    $res['success']
        ? apiSuccess(['places' => $res['results'], 'source' => $res['source'] ?? 'openstreetmap'])
        : apiError(503, $res['error'] ?? 'Nearby places unavailable.');
}

function handlePlaceDetails(): void {
    $category = tms_clean($_GET['category'] ?? '');
    $name     = tms_clean($_GET['name'] ?? '');
    $lat      = (float)($_GET['lat'] ?? 0);
    $lng      = (float)($_GET['lng'] ?? 0);

    if ($category === '' || $name === '') {
        apiError(400, 'A place name and category are required.');
    }

    // Base facts already known from the Nearby Places list (real, live data) —
    // reused here so the detail page stays consistent with the card the user
    // clicked, rather than re-querying the upstream provider.
    $place = [
        'name'          => $name,
        'lat'           => $lat,
        'lng'           => $lng,
        'address'       => tms_clean($_GET['address'] ?? '') ?: null,
        'phone'         => tms_clean($_GET['phone'] ?? '') ?: null,
        'opening_hours' => tms_clean($_GET['opening_hours'] ?? '') ?: null,
        'website'       => tms_clean($_GET['website'] ?? '') ?: null,
        'rating'        => isset($_GET['rating']) && $_GET['rating'] !== '' ? (float)$_GET['rating'] : null,
        'distance_m'    => isset($_GET['distance_m']) && $_GET['distance_m'] !== '' ? (float)$_GET['distance_m'] : null,
    ];

    $detailsService = new PlaceDetailsService();
    $details = $detailsService->build($place, $category);
    apiSuccess(['place' => $details]);
}

function handleRoute(ExploreService $service): void {
    $fromLat = (float)($_GET['from_lat'] ?? 0);
    $fromLng = (float)($_GET['from_lng'] ?? 0);
    $toLat   = (float)($_GET['to_lat'] ?? 0);
    $toLng   = (float)($_GET['to_lng'] ?? 0);
    $mode    = tms_clean($_GET['mode'] ?? 'driving');

    if (!$fromLat || !$toLat) apiError(400, 'Origin and destination coordinates are required.');

    $res = $service->getRoute($fromLat, $fromLng, $toLat, $toLng, $mode);
    $res['success'] ? apiSuccess(['route' => $res['data']]) : apiError(503, $res['error'] ?? 'Route unavailable.');
}

function handleHistory(ExploreService $service, string $email): void {
    apiSuccess(['history' => $service->getRecentHistory($email)]);
}

function handleChat(PDO $dbh, string $email): void {
    $message     = tms_clean($_POST['message'] ?? '');
    $destination = tms_clean($_POST['destination'] ?? 'your destination');
    $sessionId   = tms_clean($_POST['session_id'] ?? session_id());
    $context     = tms_clean($_POST['context'] ?? ''); // short JSON-ish summary of weather/places from the client

    if ($message === '') apiError(400, 'Please type a message.');

    $system = "You are a friendly, knowledgeable local travel guide assistant inside the TMS Explore feature, "
            . "currently helping a traveler exploring \"{$destination}\". "
            . "Answer travel questions naturally like an experienced local guide would: recommend nearby attractions, "
            . "restaurants and hotels, give transportation guidance, explain local culture and customs, suggest "
            . "itineraries and activities (factor in weather if mentioned), and help with real-time trip questions. "
            . "Keep replies conversational and concise (3-6 sentences unless a list is clearly needed). "
            . "If you don't have live data for something, say so honestly rather than inventing facts.";

    if ($context !== '') {
        $system .= " Current known context for this destination: " . mb_substr($context, 0, 1500);
    }

    $ai  = new AIClient($dbh);
    $res = $ai->ask($system, $message, '', $email);

    if (!$res['success']) {
        apiError(503, $res['error'] ?? 'The AI travel assistant is temporarily unavailable.');
    }

    try {
        $dbh->prepare("INSERT INTO tbl_explore_chat (session_id, user_email, destination, role, message) VALUES (?,?,?,?,?)")
            ->execute([$sessionId, $email, $destination, 'user', $message]);
        $dbh->prepare("INSERT INTO tbl_explore_chat (session_id, user_email, destination, role, message) VALUES (?,?,?,?,?)")
            ->execute([$sessionId, $email, $destination, 'assistant', $res['content']]);
    } catch (Exception $e) {}

    apiSuccess(['reply' => $res['content']]);
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

<?php
/**
 * api/map.php — JSON API behind the tourism map (js/tms-explorer.js and the other map pages).
 *
 *   GET  ?action=config                                  map styles, categories, routing modes, CSRF token
 *   GET  ?action=places&bbox=w,s,e,n[&categories=a,b&q=&verified=1&family=1&accessible=1&free=1&limit=&offset=]
 *   GET  ?action=place&id=|slug=                         one place + gallery + related
 *   GET  ?action=search&q=[&lat=&lng=&limit=]            autocomplete over OUR OWN verified database only
 *   GET  ?action=nearby&id=|lat=&lng=[&radius_m=&categories=&limit=]
 *   GET  ?action=geocode&q=                              wider-web search (server-side Nominatim, explicit submit only)
 *   GET  ?action=route&mode=driving|walking|cycling&coords=lng,lat;lng,lat
 *   GET  ?action=itinerary_list | itinerary_get&id=      signed-in users, their own itineraries (never cached)
 *   POST ?action=itinerary_save | itinerary_delete       signed-in users, CSRF required
 *   POST ?action=suggest_correction                      queued for moderator review, never edits a place
 *
 * Every response is JSON: {ok:true,...} or {ok:false,error:{code,message,request_id}}.
 * Only status='active' places are ever returned. Optional fields that are unknown are null: nothing is defaulted.
 * Nothing here trusts coordinates or ids from the browser without validating them.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/ApiSupport.php';
require_once dirname(__DIR__) . '/backend/MapConfig.php';
require_once dirname(__DIR__) . '/backend/services/MapPlaces.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
ApiSupport::begin($_SERVER);

/** Send one JSON response and stop. $cache is the Cache-Control value. */
function mapOut(array $data, int $status = 200, string $cache = 'no-store'): void
{
    http_response_code($status);
    header('Cache-Control: ' . $cache);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json === false ? '{"ok":false,"error":{"code":"encoding_error","message":"Could not encode the response."}}' : $json;
    exit;
}

function mapFail(int $status, string $code, string $message, array $extraHeaders = []): void
{
    foreach ($extraHeaders as $h) header($h);
    mapOut(['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'request_id' => ApiSupport::requestId()]], $status);
}

/** Integer query value clamped to [min,max]. */
function mapInt($v, int $default, int $min, int $max): int
{
    if (!is_scalar($v) || !is_numeric($v)) return $default;
    return max($min, min($max, (int)$v));
}

function mapStr($v, int $maxLen): string
{
    if (!is_string($v)) return '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
    return mb_substr($v, 0, $maxLen, 'UTF-8');
}

function mapFlag($v): bool { return $v === '1' || $v === 1 || $v === true || $v === 'true'; }

/** Per-IP hourly limit (database-backed). Fails open if the counter is unavailable, like the other APIs. */
function mapLimit(PDO $dbh, string $bucket, int $perHour): void
{
    if (!tms_rate_limit($dbh, 'map-' . $bucket, tms_client_ip(), $perHour)) {
        mapFail(429, 'rate_limited', 'Too many requests. Please wait a while and try again.', ['Retry-After: 300']);
    }
}

try {
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : (is_string($_POST['action'] ?? null) ? $_POST['action'] : '');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) mapFail(405, 'method_not_allowed', 'Method not allowed.', ['Allow: GET, POST']);

    $userEmail = isset($_SESSION['login']) && is_string($_SESSION['login']) ? $_SESSION['login'] : '';
    $lang      = (($_GET['lang'] ?? '') === 'bn') ? 'bn' : 'en';
    $csrf      = tms_csrf_token();   // creates the session token if needed, so it must run before the session lock is released
    $isWrite   = in_array($action, ['itinerary_save', 'itinerary_delete', 'suggest_correction'], true);

    if ($isWrite) {
        if ($method !== 'POST') mapFail(405, 'method_not_allowed', 'This action needs POST.', ['Allow: POST']);
        $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        if ($sent === '' || !hash_equals($csrf, $sent)) mapFail(403, 'csrf', 'Your session expired. Please refresh the page and try again.');
    }
    // Reads never write to the session, so release the lock: a slow upstream call must not block the user's other requests.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $body = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw !== false && strlen($raw) > 65536) mapFail(413, 'too_large', 'Request body is too large.');
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($raw !== '' && $raw !== false && str_contains($ct, 'json')) {
            $j = json_decode($raw, true);
            if (!is_array($j)) mapFail(400, 'bad_json', 'The request body is not valid JSON.');
            $body = $j;
        } else {
            $body = $_POST;
        }
    }

    $places = new MapPlaces($dbh);

    switch ($action) {
        // ───────────────────────────────────────────── config
        case 'config':
            $cfg = MapConfig::publicConfig();
            $cfg['categories'] = MapPlaces::categories();
            $cfg['lang'] = $lang;
            $cfg['csrf'] = $csrf;
            $cfg['signed_in'] = $userEmail !== '';
            // private: the body carries this visitor's CSRF token, so shared caches must not keep it
            mapOut(['ok' => true] + $cfg, 200, 'private, max-age=60');

        // ───────────────────────────────────────────── places in view
        case 'places':
            mapLimit($dbh, 'places', 1500);
            $bbox = MapPlaces::parseBbox(mapStr($_GET['bbox'] ?? '', 120), 30.0);
            if (!$bbox) mapFail(400, 'invalid_bbox', 'bbox must be west,south,east,north in degrees.');
            $f = [
                'categories' => MapPlaces::parseCategories(mapStr($_GET['categories'] ?? '', 400)),
                'q'          => mapStr($_GET['q'] ?? '', 100),
                'verified'   => mapFlag($_GET['verified'] ?? null),
                'family'     => mapFlag($_GET['family'] ?? null),
                'accessible' => mapFlag($_GET['accessible'] ?? null),
                'free'       => mapFlag($_GET['free'] ?? null),
            ];
            $limit  = mapInt($_GET['limit'] ?? null, 200, 1, 300);
            $offset = mapInt($_GET['offset'] ?? null, 0, 0, 10000);
            $res = $places->inBbox($bbox, $f, $limit, $offset);
            $payload = ['ok' => true, 'total' => $res['total'], 'items' => $res['items']];
            $etag = '"' . md5((string)json_encode($payload)) . '"';
            header('ETag: ' . $etag);
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                http_response_code(304); header('Cache-Control: public, max-age=60'); exit;
            }
            mapOut($payload, 200, 'public, max-age=60');

        // ───────────────────────────────────────────── one place
        case 'place':
            mapLimit($dbh, 'place', 1500);
            $row = null;
            if (isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id'])) $row = $places->find((int)$_GET['id'], true);
            elseif (isset($_GET['slug'])) { $slug = mapStr($_GET['slug'], 120); if ($slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug)) $row = $places->findBySlug($slug, true); }
            else mapFail(400, 'missing_id', 'Give an id or a slug.');
            if (!$row) mapFail(404, 'not_found', 'Place not found.');
            $one = $places->toPlaces([$row])[0];
            $one['gallery'] = $places->gallery((int)$row['PlaceId']);
            $one['related'] = $places->related($row, 6);
            mapOut(['ok' => true] + $one, 200, 'public, max-age=60');

        // ───────────────────────────────────────────── autocomplete (own database only)
        case 'search':
            mapLimit($dbh, 'search', 1500);
            $q = mapStr($_GET['q'] ?? '', 100);
            if ($q === '') mapFail(400, 'missing_query', 'Type something to search for.');
            $near = null;
            if (isset($_GET['lat'], $_GET['lng']) && MapPlaces::validCoords($_GET['lat'], $_GET['lng'])) $near = [(float)$_GET['lat'], (float)$_GET['lng']];
            $items = $places->search($q, $near, mapInt($_GET['limit'] ?? null, 8, 1, 20), ['categories' => MapPlaces::parseCategories(mapStr($_GET['categories'] ?? '', 400))]);
            mapOut(['ok' => true, 'items' => $items, 'source' => 'local'], 200, 'public, max-age=30');

        // ───────────────────────────────────────────── nearby
        case 'nearby':
            mapLimit($dbh, 'nearby', 1000);
            $exclude = null; $lat = $lng = null;
            if (isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id'])) {
                $row = $places->find((int)$_GET['id'], true);
                if (!$row) mapFail(404, 'not_found', 'Place not found.');
                $lat = (float)$row['Lat']; $lng = (float)$row['Lng']; $exclude = (int)$row['PlaceId'];
            } elseif (isset($_GET['lat'], $_GET['lng']) && MapPlaces::validCoords($_GET['lat'], $_GET['lng'])) {
                $lat = (float)$_GET['lat']; $lng = (float)$_GET['lng'];
            } else mapFail(400, 'invalid_coords', 'Give a place id or valid lat and lng.');
            $radius = mapInt($_GET['radius_m'] ?? null, 5000, 100, 50000);
            $items = $places->nearby($lat, $lng, (float)$radius, ['categories' => MapPlaces::parseCategories(mapStr($_GET['categories'] ?? '', 400))],
                mapInt($_GET['limit'] ?? null, 30, 1, 100), $exclude);
            mapOut(['ok' => true, 'radius_m' => $radius, 'items' => $items], 200, 'public, max-age=60');

        // ───────────────────────────────────────────── wider-web geocoder (explicit submit)
        case 'geocode':
            mapLimit($dbh, 'geocode', 60);
            $q = mapStr($_GET['q'] ?? '', 150);
            if (mb_strlen($q, 'UTF-8') < 3) mapFail(400, 'query_too_short', 'Type at least 3 characters to search the wider map.');
            require_once dirname(__DIR__) . '/backend/MapGeocoder.php';
            $bias = ['lang' => $lang];
            if (isset($_GET['lat'], $_GET['lng']) && MapPlaces::validCoords($_GET['lat'], $_GET['lng'])) { $bias['lat'] = (float)$_GET['lat']; $bias['lng'] = (float)$_GET['lng']; }
            $res = MapGeocoder::search($q, $bias, mapInt($_GET['limit'] ?? null, 5, 1, 10));
            if (empty($res['ok'])) {
                $code = (string)($res['error']['code'] ?? 'geocoder_unavailable');
                $st = match ($code) { 'query_too_short', 'query_too_long' => 400, 'rate_limited' => 429, default => 502 };
                mapFail($st, $code, (string)($res['error']['message'] ?? 'The place search service is unavailable right now.'));
            }
            mapOut(['ok' => true, 'items' => $res['items'] ?? [], 'source' => $res['source'] ?? 'nominatim'], 200, 'private, max-age=300');

        // ───────────────────────────────────────────── route
        case 'route':
            mapLimit($dbh, 'route', 300);
            require_once dirname(__DIR__) . '/backend/MapRouting.php';
            $mode = strtolower(mapStr($_GET['mode'] ?? 'driving', 12));
            if (!in_array($mode, MapRouting::modes(), true)) mapFail(400, 'mode_unsupported', 'This travel mode is not available.');
            $pairs = MapRouting::parseCoordsParam(mapStr($_GET['coords'] ?? '', 1200));
            if (!$pairs) mapFail(400, 'invalid_coords', 'coords must look like lng,lat;lng,lat (longitude first).');
            $res = MapRouting::route($pairs, $mode);
            if (empty($res['ok'])) {
                $code = (string)($res['error']['code'] ?? 'route_unavailable');
                $st = match ($code) {
                    'mode_unsupported', 'invalid_coords', 'too_many_waypoints' => 400,
                    'no_route' => 404, 'rate_limited' => 429, default => 502,
                };
                mapFail($st, $code, (string)($res['error']['message'] ?? 'Routing is unavailable right now.'));
            }
            unset($res['ok']);
            mapOut(['ok' => true] + $res, 200, 'private, max-age=300');

        // ───────────────────────────────────────────── itineraries (signed-in owners only)
        case 'itinerary_list':
        case 'itinerary_get':
        case 'itinerary_save':
        case 'itinerary_delete':
            if ($userEmail === '') mapFail(401, 'auth_required', 'Please sign in to use saved itineraries.');
            mapLimit($dbh, 'itinerary', 300);
            require_once dirname(__DIR__) . '/backend/MapItinerary.php';
            MapItinerary::useDb($dbh);
            if ($action === 'itinerary_list') {
                $items = MapItinerary::list($userEmail);
                foreach ($items as &$it) $it['stops_count'] = $it['stop_count'] ?? null;
                unset($it);
                mapOut(['ok' => true, 'items' => $items]);
            }
            if ($action === 'itinerary_get') {
                $id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
                $it = $id > 0 ? MapItinerary::get($userEmail, $id) : null;
                if (!$it) mapFail(404, 'not_found', 'Itinerary not found.');
                mapOut(['ok' => true, 'itinerary' => $it] + $it);
            }
            if ($action === 'itinerary_save') {
                $res = MapItinerary::save($userEmail, $body);
                if (empty($res['ok'])) {
                    $code = (string)($res['error']['code'] ?? 'save_failed');
                    mapFail(match ($code) { 'not_found' => 404, 'save_failed' => 500, 'limit_reached' => 409, default => 400 }, $code, (string)($res['error']['message'] ?? 'Could not save.'));
                }
                mapOut(['ok' => true, 'id' => (int)$res['id']]);
            }
            $id = isset($body['id']) && is_numeric($body['id']) ? (int)$body['id'] : 0;
            if ($id <= 0 || !MapItinerary::delete($userEmail, $id)) mapFail(404, 'not_found', 'Itinerary not found.');
            mapOut(['ok' => true]);

        // ───────────────────────────────────────────── suggested corrections (queued only)
        case 'suggest_correction':
            mapLimit($dbh, $userEmail !== '' ? 'correction-user' : 'correction-anon', $userEmail !== '' ? 10 : 3);
            $placeId = isset($body['place_id']) && is_numeric($body['place_id']) && (int)$body['place_id'] > 0 ? (int)$body['place_id'] : null;
            $field = mapStr($body['field'] ?? 'other', 30);
            $note = is_string($body['note'] ?? null) ? mb_substr(trim($body['note']), 0, 1000, 'UTF-8') : '';
            $proposed = $body['proposed'] ?? null;
            if (is_string($proposed)) $proposed = mb_substr(trim($proposed), 0, 1000, 'UTF-8');
            elseif (!is_array($proposed)) $proposed = null;
            $res = $places->submitCorrection($placeId, $userEmail !== '' ? $userEmail : null, tms_client_ip(), $field, $proposed, $note);
            if (empty($res['ok'])) mapFail(400, 'invalid_correction', (string)($res['error'] ?? 'Invalid correction.'));
            mapOut(['ok' => true, 'id' => (int)$res['id']], 201);

        default:
            mapFail(400, 'unknown_action', 'Unknown action.');
    }
} catch (Throwable $e) {
    // never leak SQL, paths or stack traces to the browser
    error_log('[TourBuddy map API] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    mapFail(500, 'server_error', 'Something went wrong on our side. Please try again.');
}

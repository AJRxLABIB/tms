<?php
/**
 * Pure tests for MapRouting, MapGeocoder and MapItinerary::validate (no network, no database).
 *   php tests/unit/map_routing_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/MapConfig.php';
require_once __DIR__ . '/../../backend/MapRouting.php';
require_once __DIR__ . '/../../backend/MapGeocoder.php';
require_once __DIR__ . '/../../backend/MapItinerary.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok || $d === '' ? '' : "  [$d]") . "\n"; }

final class FakeCache {
    public array $d = [];
    public function get(string $k) { return $this->d[$k] ?? null; }
    public function set(string $k, $v, int $ttl): void { $this->d[$k] = $v; }
}

$okBody = json_encode(['code' => 'Ok', 'routes' => [[
    'distance' => 4322.3, 'duration' => 3457.9,
    'geometry' => ['type' => 'LineString', 'coordinates' => [[91.9843, 21.4272], [91.9662, 21.4522]]],
    'legs' => [['distance' => 4322.3, 'duration' => 3457.9]],
]]]);

// ---- MapRouting: validation ----
$v = MapRouting::validatePairs([[91.98, 21.42]]);
t('one point is refused', isset($v['error']) && $v['error']['code'] === 'invalid_coords');
$v = MapRouting::validatePairs([[91.98, 21.42], [21.45, 191.9]]);
t('latitude out of range (lng/lat swapped) is refused', isset($v['error']));
$v = MapRouting::validatePairs([[91.98, 21.42], ['x', 1]]);
t('non-numeric coordinate is refused', isset($v['error']));
$v = MapRouting::validatePairs(array_fill(0, 26, [90.0, 23.0]));
t('more than 25 waypoints is refused', ($v['error']['code'] ?? '') === 'too_many_waypoints');
$v = MapRouting::validatePairs([['91.98', '21.42'], [91.95, 21.45]]);
t('numeric strings are accepted and cast to float', isset($v['points']) && $v['points'][0] === [91.98, 21.42]);
t('parseCoordsParam keeps lng,lat order', MapRouting::parseCoordsParam('91.9,21.4;91.8,21.5') === [['91.9', '21.4'], ['91.8', '21.5']]);
t('parseCoordsParam rejects malformed input', MapRouting::parseCoordsParam('91.9;21.4') === []);

// ---- MapRouting: parse ----
$p = MapRouting::parse(200, $okBody, 'walking', 'https://routing.openstreetmap.de/routed-foot');
t('parse: distance and duration come from the engine', $p['ok'] && $p['distance_m'] === 4322 && $p['duration_s'] === 3458);
t('parse: geometry is the engine LineString', $p['geometry']['type'] === 'LineString' && count($p['geometry']['coordinates']) === 2);
t('parse: legs are returned', count($p['legs']) === 1 && $p['legs'][0]['distance_m'] === 4322);
t('parse: NoRoute maps to no_route', (MapRouting::parse(200, json_encode(['code' => 'NoRoute']), 'driving', 'https://x'))['error']['code'] === 'no_route');
t('parse: HTTP 429 maps to rate_limited', (MapRouting::parse(429, '{}', 'driving', 'https://x'))['error']['code'] === 'rate_limited');
t('parse: garbage body is an upstream error', (MapRouting::parse(200, '<html>', 'driving', 'https://x'))['error']['code'] === 'upstream_error');
t('parse: missing geometry is an error, never fabricated', !MapRouting::parse(200, json_encode(['code' => 'Ok', 'routes' => [['distance' => 1, 'duration' => 1]]]), 'driving', 'https://x')['ok']);

// ---- MapRouting: route() with fake transport + cache ----
$cache = new FakeCache(); $calls = []; $urls = [];
MapRouting::_setTestHooks(function (string $url, array $o) use (&$calls, &$urls, $okBody) {
    $calls[] = 1; $urls[] = $url; return ['status' => 200, 'body' => $okBody];
}, $cache);
t('modes() lists the three default modes', MapRouting::modes() === ['driving', 'walking', 'cycling']);
$r = MapRouting::route([[91.9833, 21.4272], [91.95, 21.45]], 'walking');
t('route: ok result', $r['ok'] === true && $r['mode'] === 'walking');
t('route: URL uses the foot endpoint, lng,lat order and geojson', str_contains($urls[0], '/routed-foot/route/v1/driving/91.9833,21.4272;91.95,21.45') && str_contains($urls[0], 'geometries=geojson'));
$r2 = MapRouting::route([[91.9833, 21.4272], [91.95, 21.45]], 'walking');
t('route: second identical call is served from cache', count($calls) === 1 && $r2['ok']);
MapRouting::route([[91.9833, 21.4272], [91.95, 21.45]], 'driving');
t('route: a different mode is a different cache key', count($calls) === 2);
$r = MapRouting::route([[91.9, 21.4], [91.9, 21.5]], 'flying');
t('route: unsupported mode is refused without a request', ($r['error']['code'] ?? '') === 'mode_unsupported' && count($calls) === 2);
t('route: cache key rounds to 5 decimals', MapRouting::cacheKey('driving', [[91.1234561, 21.0], [91.0, 21.0]]) === MapRouting::cacheKey('driving', [[91.123456, 21.0], [91.0, 21.0]]));

$cache2 = new FakeCache(); $n = 0;
MapRouting::_setTestHooks(function () use (&$n) { $n++; return null; }, $cache2);
$r = MapRouting::route([[90.0, 23.0], [90.1, 23.1]], 'driving');
t('route: network failure is an honest error', !$r['ok'] && $r['error']['code'] === 'upstream_error');
MapRouting::route([[90.0, 23.0], [90.1, 23.1]], 'driving');
t('route: failure is negative-cached (no hammering)', $n === 1);

MapRouting::_setTestHooks(function () { return ['status' => 200, 'body' => json_encode(['code' => 'NoRoute'])]; }, new FakeCache());
$r = MapRouting::route([[90.0, 23.0], [90.1, 23.1]], 'cycling');
t('route: NoRoute from the engine is reported', ($r['error']['code'] ?? '') === 'no_route');
MapRouting::_setTestHooks(null, null);

// ---- MapRouting: steps (guidance) ----
$stepBody = json_encode(['code' => 'Ok', 'routes' => [[
    'distance' => 1000.0, 'duration' => 120.0,
    'geometry' => ['type' => 'LineString', 'coordinates' => [[91.0, 21.0], [91.0, 21.01], [91.01, 21.01]]],
    'legs' => [['distance' => 1000.0, 'duration' => 120.0, 'steps' => [
        ['distance' => 600.4, 'duration' => 70.0, 'name' => 'Marine Drive', 'maneuver' => ['type' => 'depart', 'location' => [91.0, 21.0]]],
        ['distance' => 399.6, 'duration' => 50.0, 'name' => "O'Neil <b>Rd</b>", 'maneuver' => ['type' => 'turn', 'modifier' => 'right', 'location' => [91.0, 21.01]]],
        ['distance' => 0.0, 'duration' => 0.0, 'name' => '', 'maneuver' => ['type' => 'arrive', 'location' => [91.01, 21.01]]],
        ['distance' => 5, 'duration' => 1, 'name' => 'bad', 'maneuver' => ['type' => 'turn', 'location' => ['x', 1]]],
    ]]],
]]]);
$ps = MapRouting::parse(200, $stepBody, 'driving', 'https://x');
t('steps: not returned unless asked (old answer unchanged)', $ps['ok'] && !isset($ps['legs'][0]['steps']));
$ps = MapRouting::parse(200, $stepBody, 'driving', 'https://x', true);
$st = $ps['legs'][0]['steps'] ?? [];
t('steps: valid steps kept in order, step with a bad location dropped', count($st) === 3 && $st[0]['type'] === 'depart' && $st[2]['type'] === 'arrive');
t('steps: distance rounded, location is [lng,lat]', $st[0]['distance_m'] === 600 && $st[1]['location'] === [91.0, 21.01] && $st[1]['modifier'] === 'right');
t('steps: street name is stripped of markup characters', $st[1]['name'] === "O'Neil bRd/b");
$lg = MapRouting::parse(200, json_encode(['code' => 'Ok', 'routes' => [['distance' => 1, 'duration' => 1, 'geometry' => ['type' => 'LineString', 'coordinates' => [[1, 1], [2, 2]]], 'legs' => [['distance' => 1, 'duration' => 1]]]]]), 'driving', 'https://x', true);
t('steps: engine without steps gives an empty list, nothing invented', $lg['ok'] && $lg['legs'][0]['steps'] === []);
$bud = 2; $cap = MapRouting::parseSteps(json_decode($stepBody, true)['routes'][0]['legs'][0]['steps'], $bud);
t('steps: total step budget is enforced', count($cap) === 2 && $bud === 0);
t('steps: cache key differs when steps are requested', MapRouting::cacheKey('driving', [[1, 2], [3, 4]]) !== MapRouting::cacheKey('driving', [[1, 2], [3, 4]], true));
$su = []; MapRouting::_setTestHooks(function (string $url) use (&$su, $stepBody) { $su[] = $url; return ['status' => 200, 'body' => $stepBody]; }, new FakeCache());
MapRouting::route([[91.0, 21.0], [91.01, 21.01]], 'driving');
MapRouting::route([[91.0, 21.0], [91.01, 21.01]], 'driving', true);
t('steps: URL asks the engine for steps only when requested', str_contains($su[0], 'steps=false') && str_contains($su[1], 'steps=true') && count($su) === 2);
MapRouting::_setTestHooks(null, null);

// ---- MapGeocoder ----
t('normalise collapses whitespace and control chars', MapGeocoder::normalise("  Cox's \n  Bazar\t ") === "Cox's Bazar");
t('normalise keeps Bangla text', MapGeocoder::normalise(' কক্সবাজার  সমুদ্র ') === 'কক্সবাজার সমুদ্র');
$r = MapGeocoder::search('ab', null);
t('search: query shorter than 3 is refused (no autocomplete use)', ($r['error']['code'] ?? '') === 'query_too_short');
$r = MapGeocoder::search(str_repeat('x', 201), null);
t('search: overlong query is refused', ($r['error']['code'] ?? '') === 'query_too_long');

$nom = json_encode([
    ['lat' => '21.4272', 'lon' => '91.9833', 'display_name' => "Cox's Bazar, Chattogram Division, Bangladesh", 'name' => "Cox's Bazar", 'category' => 'boundary', 'type' => 'administrative', 'boundingbox' => ['21.40', '21.47', '91.95', '92.01']],
    ['lat' => '95.0', 'lon' => '91.0', 'display_name' => 'Bad latitude'],
    ['lat' => 'x', 'lon' => '91.0', 'display_name' => 'Not numeric'],
    ['lat' => '22.3', 'lon' => '91.8', 'display_name' => 'Chittagong, Bangladesh'],
]);
$pp = MapGeocoder::parse(200, $nom, 5);
t('geocoder parse: valid rows kept, bad coordinates dropped', $pp['ok'] && count($pp['items']) === 2);
t('geocoder parse: lat and lng are floats in the right fields', $pp['items'][0]['lat'] === 21.4272 && $pp['items'][0]['lng'] === 91.9833);
t('geocoder parse: bbox converted to [w,s,e,n]', $pp['items'][0]['bbox'] === [91.95, 21.4, 92.01, 21.47]);
t('geocoder parse: name falls back to first part of display_name', $pp['items'][1]['name'] === 'Chittagong');
t('geocoder parse: limit is applied', count(MapGeocoder::parse(200, $nom, 1)['items']) === 1);
t('geocoder parse: 429 is rate_limited', MapGeocoder::parse(429, '[]')['error']['code'] === 'rate_limited');
t('geocoder parse: empty array is a valid empty result', MapGeocoder::parse(200, '[]')['ok'] === true);
t('geocoder parse: non-JSON is an error', !MapGeocoder::parse(200, 'oops')['ok']);

$lock = sys_get_temp_dir() . '/tms_geo_test_' . getmypid() . '.lock'; @unlink($lock);
$now = 1000.0; $slept = []; $gUrls = []; $gc = new FakeCache();
MapGeocoder::_setTestHooks(function (string $u, array $o) use (&$gUrls, $nom) { $gUrls[] = [$u, $o]; return ['status' => 200, 'body' => $nom]; },
    $gc, function () use (&$now) { return $now; }, function (float $s) use (&$slept, &$now) { $slept[] = $s; $now += $s; }, 'test-client', $lock);
$r = MapGeocoder::search("Cox's Bazar", ['lang' => 'bn', 'lng' => 91.98, 'lat' => 21.42], 5);
t('search: ok with items', $r['ok'] && count($r['items']) === 2 && $r['source'] === 'nominatim');
t('search: request carries accept-language, countrycodes, jsonv2 and a User-Agent', str_contains($gUrls[0][0], 'accept-language=bn%2Cen') && str_contains($gUrls[0][0], 'countrycodes=bd') && str_contains($gUrls[0][0], 'format=jsonv2') && str_contains($gUrls[0][1]['user_agent'], 'TourBuddy-TourismManagement'));
t('search: viewbox built from the bias', str_contains($gUrls[0][0], 'viewbox='));
$r = MapGeocoder::search("Cox's Bazar", ['lang' => 'bn', 'lng' => 91.98, 'lat' => 21.42], 5);
t('search: repeat query is served from cache (no second request)', count($gUrls) === 1);
$now += 0.3;   // only 0.3 s after the previous external call
MapGeocoder::search('Sylhet city', null, 5);
t('search: 1 request/second throttle sleeps for the remainder', count($slept) >= 1 && $slept[0] > 0.6 && $slept[0] <= 1.0, json_encode($slept));
$bad = new FakeCache(); $ct = 0;
MapGeocoder::_setTestHooks(function () use (&$ct) { $ct++; return null; }, $bad, function () use (&$now) { return $now += 5; }, function () {}, 'c2', $lock);
$r = MapGeocoder::search('Bandarban', null);
MapGeocoder::search('Bandarban', null);
t('search: failure reported and negative-cached', !$r['ok'] && $ct === 1);
$cap = new FakeCache(); $cc = 0;
MapGeocoder::_setTestHooks(function () use (&$cc) { $cc++; return ['status' => 200, 'body' => '[]']; }, $cap, function () use (&$now) { return $now += 5; }, function () {}, 'c3', $lock);
$last = null;
for ($i = 0; $i < 12; $i++) $last = MapGeocoder::search('place number ' . $i, null);
t('search: per-client cap of 10 external lookups per minute', $cc === 10 && ($last['error']['code'] ?? '') === 'rate_limited', "calls=$cc");
MapGeocoder::_setTestHooks(null, null);
@unlink($lock);

// ---- MapItinerary::validate ----
$good = ['title' => '  Day   trip ', 'mode' => 'walking', 'total_distance_m' => 5200.4, 'stops' => [
    ['name' => 'A', 'lat' => 21.4, 'lng' => 91.9, 'place_id' => 7],
    ['name' => 'B', 'lat' => '21.5', 'lng' => '91.8', 'note' => 'Lunch'],
]];
$v = MapItinerary::validate($good);
t('itinerary: valid input accepted and title normalised', $v['ok'] && $v['data']['title'] === 'Day trip');
t('itinerary: stop order preserved', $v['data']['stops'][0]['name'] === 'A' && $v['data']['stops'][1]['name'] === 'B');
t('itinerary: totals only stored when supplied', $v['data']['total_distance_m'] === 5200 && $v['data']['total_duration_s'] === null);
t('itinerary: numeric-string coordinates cast', $v['data']['stops'][1]['lat'] === 21.5);
$bad = $good; $bad['title'] = '   ';
t('itinerary: empty title refused', ($m = MapItinerary::validate($bad))['ok'] === false && $m['error']['code'] === 'invalid_title');
$bad = $good; $bad['mode'] = 'teleport';
t('itinerary: unknown mode refused', MapItinerary::validate($bad)['error']['code'] === 'invalid_mode');
$bad = $good; $bad['stops'] = [];
t('itinerary: no stops refused', MapItinerary::validate($bad)['error']['code'] === 'invalid_stops');
$bad = $good; $bad['stops'] = array_fill(0, 26, ['name' => 'X', 'lat' => 1, 'lng' => 1]);
t('itinerary: more than 25 stops refused', MapItinerary::validate($bad)['error']['code'] === 'too_many_stops');
$bad = $good; $bad['stops'][0]['lat'] = 123;
t('itinerary: out-of-range latitude refused', MapItinerary::validate($bad)['error']['code'] === 'invalid_coords');
$bad = $good; $bad['stops'][0]['name'] = '';
t('itinerary: stop without a name refused', MapItinerary::validate($bad)['error']['code'] === 'invalid_stops');
$bad = $good; $bad['total_distance_m'] = -5;
t('itinerary: negative total ignored, not stored', MapItinerary::validate($bad)['data']['total_distance_m'] === null);
$long = $good; $long['stops'][0]['note'] = str_repeat('ক', 400);
t('itinerary: long note truncated to 300 characters', mb_strlen(MapItinerary::validate($long)['data']['stops'][0]['note'], 'UTF-8') === 300);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

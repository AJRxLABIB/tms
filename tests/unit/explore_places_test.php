<?php
/**
 * Nearby-places resilience (no network, no MySQL: SQLite in memory + a fake transport).
 *   php tests/unit/explore_places_test.php
 * Covers: Overpass mirror failover, invalid-200 rejection, stale-cache fallback, negative cache, local-catalogue
 * fallback, distance ordering. The real curl transport is NOT exercised here (no network in CI).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('pdo_sqlite')) { echo "SKIP: pdo_sqlite not available\n"; exit(0); }

define('EXPLORE_CACHE_TTL_PLACES', 21600);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../../backend/CacheService.php';
require_once __DIR__ . '/../../backend/ExploreService.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($d !== '' ? "  [$d]" : '') . "\n"; }

class ArrayCache extends CacheService {
    public array $store = [];
    public function __construct() { parent::__construct(null, false); }
    public function get(string $key): mixed { return $this->store[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttlSeconds): void { $this->store[$key] = $value; }
    public function delete(string $key): void { unset($this->store[$key]); }
}

/** ExploreService whose network is scripted: $plan is a list of per-call [mirrorKey => [success, data|error]] maps. */
class FakeExplore extends ExploreService {
    public array $plan = [];
    public int $calls = 0;
    protected function raceRequests(array $requests, int $budgetSec, callable $validate): array {
        $this->calls++;
        $script = array_shift($this->plan) ?? [];
        $out = [];
        foreach ($requests as $key => $r) {
            $s = $script[$key] ?? ['fail', 'Timed out'];
            if ($s[0] === 'ok' && $validate($s[1])) $out[$key] = ['success' => true, 'data' => $s[1]];
            elseif ($s[0] === 'ok') $out[$key] = ['success' => false, 'error' => 'Invalid or empty response'];
            else $out[$key] = ['success' => false, 'error' => $s[1] ?? 'fail'];
        }
        return $out;
    }
    public function mirrorCount(): int { return count($this->overpassMirrors()); }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE tbltourpackages (PackageId INTEGER PRIMARY KEY, PackageName TEXT, PackageLocation TEXT, Country TEXT, MapLat TEXT, MapLng TEXT, PackagePrice INT, Status INT)");
$db->exec("CREATE TABLE tblhotels (HotelId INTEGER PRIMARY KEY, HotelName TEXT, City TEXT, Country TEXT, StarRating INT, AvgRating REAL, IsSampleData INT, Status INT)");
// Cox's Bazar area: three packages with coordinates (nearest first is 'Inani'), one far away, one without coordinates.
$ins = $db->prepare("INSERT INTO tbltourpackages (PackageName,PackageLocation,Country,MapLat,MapLng,PackagePrice,Status) VALUES (?,?,?,?,?,?,1)");
$ins->execute(['Laboni Beach', "Cox's Bazar", 'Bangladesh', '21.4272', '92.0058', 9000]);
$ins->execute(['Inani Beach', "Cox's Bazar", 'Bangladesh', '21.2300', '92.0100', 8000]);   // ~22 km south
$ins->execute(['Himchari', "Cox's Bazar", 'Bangladesh', '21.3650', '92.0300', 7000]);       // ~8 km
$ins->execute(['Faraway', 'Sylhet', 'Bangladesh', '24.9', '91.8', 5000]);
$ins->execute(['NoCoords', 'Nowhere', 'Bangladesh', '', '', 100]);
$db->exec("INSERT INTO tblhotels (HotelName,City,Country,StarRating,AvgRating,IsSampleData,Status) VALUES ('Sea Pearl','Cox''s Bazar','Bangladesh',5,4.5,1,1),('Hill View','Bandarban','Bangladesh',3,4.0,0,1),('Other Land Inn','Kyoto','Japan',3,4.9,0,1)");

$lat = 21.4272; $lng = 92.0058;
$overpassOk = ['elements' => [
    ['lat' => 21.44, 'lon' => 92.01, 'tags' => ['name' => 'Far Hotel']],
    ['lat' => 21.4275, 'lon' => 92.0060, 'tags' => ['name' => 'Near Hotel', 'opening_hours' => '24/7']],
    ['lat' => 21.43, 'lon' => 92.007, 'tags' => ['name' => 'Mid Hotel']],
    ['lat' => 21.43, 'lon' => 92.007, 'tags' => []],                      // no name: dropped
]];

// ── 1. mirror failover + ordering ─────────────────────────────────────────────
$cache = new ArrayCache(); $svc = new FakeExplore($db, $cache);
t('default mirror list has at least 3 servers', $svc->mirrorCount() >= 3, (string)$svc->mirrorCount());
$svc->plan = [['m0' => ['fail', 'HTTP 504'], 'm1' => ['ok', $overpassOk], 'm2' => ['fail', 'Timed out']]];
$r = $svc->getNearbyPlaces($lat, $lng, 'hotel', 4000);
t('first mirror down, second answers -> success', $r['success'] === true);
t('origin is live and not stale', ($r['origin'] ?? '') === 'live' && ($r['stale'] ?? true) === false);
$names = array_column($r['results'], 'name');
t('results sorted nearest first, unnamed dropped', $names === ['Near Hotel', 'Mid Hotel', 'Far Hotel'], implode(',', $names));
t('distances increase', $r['results'][0]['distance_m'] < $r['results'][1]['distance_m'] && $r['results'][1]['distance_m'] < $r['results'][2]['distance_m']);
t('24/7 hours parsed as open', ($r['results'][0]['is_open_now'] ?? null) === true);

// ── 2. fresh cache: no network ───────────────────────────────────────────────
$calls = $svc->calls;
$r2 = $svc->getNearbyPlaces($lat, $lng, 'hotel', 4000);
t('second call is served from cache without network', $svc->calls === $calls && ($r2['origin'] ?? '') === 'cached');

// ── 3. stale copy used when live fails and fresh cache expired ──────────────
foreach (array_keys($cache->store) as $k) { if (strpos($k, 'explore_places_hotel') === 0) unset($cache->store[$k]); }   // fresh copy expired
$svc->plan = [['m0' => ['fail', 'HTTP 429'], 'm1' => ['fail', 'Timed out'], 'm2' => ['fail', 'Network error']]];
$r3 = $svc->getNearbyPlaces($lat, $lng, 'hotel', 4000);
t('all mirrors fail -> stale copy returned as success', $r3['success'] === true && ($r3['origin'] ?? '') === 'stale' && ($r3['stale'] ?? false) === true);
t('stale results are the earlier real results', array_column($r3['results'], 'name') === ['Near Hotel', 'Mid Hotel', 'Far Hotel']);
t('stale answer carries a user-facing notice', stripos((string)($r3['notice'] ?? ''), 'earlier') !== false);

// ── 4. negative cache: the failure is remembered, so the next call does not wait on the network again ─────────
$calls = $svc->calls;
$r4 = $svc->getNearbyPlaces($lat, $lng, 'hotel', 4000);
t('recent failure is remembered: no new network call', $svc->calls === $calls, "calls {$svc->calls}");
t('...and still answers (stale)', $r4['success'] === true && ($r4['origin'] ?? '') === 'stale');

// ── 5. Overpass 200 without "elements" is a failure, not an empty list ─────────
$cache2 = new ArrayCache(); $svc2 = new FakeExplore($db, $cache2);
$svc2->plan = [['m0' => ['ok', ['remark' => 'runtime error: Query timed out']], 'm1' => ['ok', ['remark' => 'rate_limited']], 'm2' => ['fail', 'HTTP 429']]];
$r5 = $svc2->getNearbyPlaces(35.0, 135.7, 'restaurant', 4000);   // nowhere near our catalogue
t('200 with runtime-error remark is rejected', $r5['success'] === false);
t('error message is user-readable and flagged origin none', ($r5['origin'] ?? '') === 'none' && stripos((string)$r5['error'], 'unavailable') !== false);

// ── 6. local catalogue fallback ─────────────────────────────────────────────
$svc3 = new FakeExplore($db, new ArrayCache());
$svc3->plan = [[]];   // everything times out
$r6 = $svc3->getNearbyPlaces($lat, $lng, 'attraction', 4000);
t('no stale copy -> local packages returned', $r6['success'] === true && ($r6['origin'] ?? '') === 'local' && ($r6['source'] ?? '') === 'local');
$n6 = array_column($r6['results'], 'name');
t('local attractions nearest first, far/no-coordinate rows excluded', array_slice($n6, 0, 3) === ['Laboni Beach', 'Himchari', 'Inani Beach'] && !in_array('Faraway', $n6, true) && !in_array('NoCoords', $n6, true), implode(',', $n6));
t('local items are flagged and linkable', ($r6['results'][0]['source'] ?? '') === 'local' && strpos((string)$r6['results'][0]['local_url'], 'package-details.php?pkgid=') === 0);
t('local items never claim to be open', $r6['results'][0]['is_open_now'] === null);

$svc4 = new FakeExplore($db, new ArrayCache()); $svc4->plan = [[]];
$r7 = $svc4->getNearbyPlaces($lat, $lng, 'hotel', 4000);
$h = array_column($r7['results'] ?? [], 'name');
t('hotel fallback uses the nearest packaged destination\'s country', $r7['success'] === true && in_array('Sea Pearl', $h, true) && in_array('Hill View', $h, true) && !in_array('Other Land Inn', $h, true), implode(',', $h));
$sea = null; foreach ($r7['results'] as $x) if ($x['name'] === 'Sea Pearl') $sea = $x;
t('catalogue hotels have no coordinates (not plotted) and sample rows are flagged', $sea && $sea['lat'] === null && $sea['is_sample'] === true);

$svc5 = new FakeExplore($db, new ArrayCache()); $svc5->plan = [[]];
$r8 = $svc5->getNearbyPlaces($lat, $lng, 'pharmacy', 4000);
t('category with no local equivalent -> honest failure, not invented data', $r8['success'] === false);

$r9 = $svc5->getNearbyPlaces($lat, $lng, 'spaceport', 4000);
t('unknown category rejected', $r9['success'] === false && stripos($r9['error'], 'Unknown') !== false);

// ── 7. multi-category: cached categories need no network ─────────────────────
$cache3 = new ArrayCache(); $svc6 = new FakeExplore($db, $cache3);
$svc6->plan = [['m0' => ['ok', $overpassOk]], ['m0' => ['ok', $overpassOk]]];
$svc6->getNearbyPlaces($lat, $lng, 'hotel', 6000); $svc6->getNearbyPlaces($lat, $lng, 'restaurant', 6000);
$calls = $svc6->calls;
$multi = $svc6->getNearbyPlacesMulti($lat, $lng, ['hotel', 'restaurant'], 6000);
t('multi: both categories answered from cache, no network', $svc6->calls === $calls && $multi['hotel']['success'] && $multi['restaurant']['success'] && ($multi['hotel']['origin'] ?? '') === 'cached');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

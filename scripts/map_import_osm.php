<?php
/**
 * scripts/map_import_osm.php — import named tourism points of interest from OpenStreetMap (Overpass API) into tbl_map_places.
 *
 *   php scripts/map_import_osm.php --region=sylhet                 dry run (fetches and reports, writes nothing)
 *   php scripts/map_import_osm.php --region=sylhet --apply         inserts new places as PENDING and unverified
 *   php scripts/map_import_osm.php --region=sylhet --apply --publish   inserts them as active (still unverified)
 *   php scripts/map_import_osm.php --bbox=south,west,north,east    custom area (max 4 square degrees unless --force)
 *   options: --refresh (ignore the 24 h cache), --limit=N (max features, default 4000)
 *   regions: dhaka chattogram sylhet coxsbazar bandarban rangamati khulna sundarbans (approximate search areas)
 *
 * Be a polite client of the public Overpass servers (see https://wiki.openstreetmap.org/wiki/Overpass_API):
 * one request at a time, a pause between regions, mirrors tried in order, raw answers cached for 24 hours, and a User-Agent that
 * identifies this site (set MAP_CONTACT_EMAIL in .env). Run it occasionally by hand, never per page view.
 * Licence: OpenStreetMap data is ODbL. Every imported row keeps its source reference (node/123) and attribution.
 * Imported rows are NEVER marked verified, and an existing place or a near-identical neighbour is skipped, so nothing is overwritten.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/MapConfig.php';
require_once __DIR__ . '/../backend/services/MapPlaces.php';
require_once __DIR__ . '/../backend/services/MapOsm.php';

$opts = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$apply = !empty($opts['apply']); $publish = !empty($opts['publish']); $force = !empty($opts['force']);
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 4000;

$areas = [];
if (!empty($opts['bbox']) && is_string($opts['bbox'])) {
    $b = MapOsm::parseBbox($opts['bbox']);
    if (!$b) { fwrite(STDERR, "Invalid --bbox. Use south,west,north,east.\n"); exit(1); }
    $areas['custom'] = $b;
} elseif (!empty($opts['region']) && is_string($opts['region'])) {
    foreach (explode(',', strtolower($opts['region'])) as $r) {
        $r = trim($r);
        if (!isset(MapOsm::REGIONS[$r])) { fwrite(STDERR, "Unknown region '$r'. Known: " . implode(' ', array_keys(MapOsm::REGIONS)) . "\n"); exit(1); }
        $areas[$r] = MapOsm::REGIONS[$r];
    }
} else {
    fwrite(STDERR, "Give --region=name[,name] or --bbox=south,west,north,east. Known regions: " . implode(' ', array_keys(MapOsm::REGIONS)) . "\n"); exit(1);
}
foreach ($areas as $n => $b) if (MapOsm::areaSqDeg($b) > 4.0 && !$force) { fwrite(STDERR, "Area '$n' is larger than 4 square degrees. Split it or pass --force.\n"); exit(1); }

try { $dbh->query('SELECT 1 FROM tbl_map_places LIMIT 1'); }
catch (Throwable $e) { fwrite(STDERR, "tbl_map_places is missing. Run: php database/migrate.php (migration 041)\n"); exit(1); }

$cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tms-map-osm';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);

function overpass_fetch(string $ql, array $mirrors, string $ua, string $cacheFile, bool $refresh): ?array
{
    if (!$refresh && is_file($cacheFile) && filemtime($cacheFile) > time() - 86400) {
        $j = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($j) && isset($j['elements'])) { echo "  (cached answer)\n"; return $j; }
    }
    foreach ($mirrors as $url) {
        echo "  asking $url ...\n";
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'data=' . urlencode($ql), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($body !== false && $code === 200) {
            $j = json_decode((string)$body, true);
            if (is_array($j) && isset($j['elements'])) { @file_put_contents($cacheFile, $body); return $j; }
        }
        echo "  failed (HTTP $code), trying the next mirror after a pause\n";
        sleep(5);
    }
    return null;
}

$svc = new MapPlaces($dbh);
$ua = MapConfig::userAgent();
$tot = ['seen' => 0, 'new' => 0, 'exists' => 0, 'dup' => 0, 'skipped' => 0, 'failed' => 0];
$first = true;
foreach ($areas as $name => $bbox) {
    if (!$first) sleep(3);
    $first = false;
    echo "Area {$name}: bbox " . implode(',', $bbox) . "\n";
    $ql = MapOsm::query($bbox, 90, $limit);
    $j = overpass_fetch($ql, MapConfig::overpassMirrors(), $ua, $cacheDir . DIRECTORY_SEPARATOR . md5($ql) . '.json', !empty($opts['refresh']));
    if (!$j) { echo "  could not reach any Overpass mirror. Try again later.\n"; $tot['failed']++; continue; }
    $chk = $dbh->prepare("SELECT PlaceId FROM tbl_map_places WHERE Source = 'osm' AND SourceRef = ?");
    foreach ($j['elements'] as $el) {
        $tot['seen']++;
        $in = MapOsm::parseElement($el);
        if (!$in) { $tot['skipped']++; continue; }
        $chk->execute([$in['source_ref']]);
        if ($chk->fetchColumn()) { $tot['exists']++; continue; }
        $dups = $svc->findDuplicates($in['name'], $in['lat'], $in['lng']);
        if ($dups) { $tot['dup']++; echo "  near-duplicate of #{$dups[0]['id']} {$dups[0]['name']}: skipped {$in['name']}\n"; continue; }
        $tot['new']++;
        if (!$apply) continue;
        $in['status'] = $publish ? 'active' : 'pending';
        $res = $svc->create($in, 'osm-import', ['allow_duplicates' => true]);
        if (!$res['ok']) { $tot['new']--; $tot['failed']++; echo '  FAILED ' . $in['name'] . ': ' . json_encode($res['errors']) . "\n"; }
    }
}
echo ($apply ? 'APPLIED' : 'DRY RUN (nothing written)') . ": seen={$tot['seen']} new={$tot['new']} already-imported={$tot['exists']} near-duplicates={$tot['dup']} not-relevant={$tot['skipped']} failed={$tot['failed']}\n";
if ($apply && !$publish) echo "Imported places are 'pending'. Review and publish them in the admin map tools, or re-run with --publish.\n";
echo "Attribution: " . MapOsm::ATTRIBUTION . "\n";

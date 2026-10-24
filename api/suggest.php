<?php
/**
 * Place / destination suggestions while typing.   GET api/suggest.php?q=cox[&kind=place|hotel|activity|package|all][&limit=8][&lang=bn]
 *
 * One endpoint for every text box on the site that asks for a place (destination, origin, location, search).
 * Sources, in this order:
 *   1. TourBuddy's own data: verified map places, package locations, hotel and activity cities, saved explore destinations.
 *   2. data/place_suggestions.json, a small built-in list of popular Bangladesh and world destinations (works on an empty database).
 *   3. Wider web places (Photon / Nominatim through MapGeocoder), ONLY for signed-in users and only when the first two give
 *      fewer than 4 matches. Rate limited and cached on the server; the browser never calls those services.
 * kind=place returns places only. hotel / activity / package add names from that catalogue (for search boxes); all adds every kind.
 * Returns {"ok":true,"items":[{"value":"Cox's Bazar, Bangladesh","label":"Cox's Bazar","sub":"Bangladesh","type":"place"}]}.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/ApiSupport.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
ApiSupport::begin($_SERVER);

function sgOut(array $d, int $status = 200): void
{
    http_response_code($status);
    header('Cache-Control: private, max-age=60');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $_GET['q']) ?? '') : '';
$q = mb_substr($q, 0, 80, 'UTF-8');
if (mb_strlen($q, 'UTF-8') < 2) sgOut(['ok' => true, 'items' => []]);

$kind  = is_string($_GET['kind'] ?? null) ? strtolower($_GET['kind']) : 'place';
if (!in_array($kind, ['place', 'hotel', 'activity', 'package', 'all'], true)) $kind = 'place';
$limit = max(3, min(12, (int)($_GET['limit'] ?? 8)));
$lang  = (($_GET['lang'] ?? '') === 'bn') ? 'bn' : 'en';
$signedIn = !empty($_SESSION['login']) || !empty($_SESSION['alogin']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (function_exists('tms_rate_limit') && !tms_rate_limit($dbh, 'suggest', tms_client_ip(), 1200)) {
    sgOut(['ok' => false, 'items' => [], 'error' => 'rate_limited'], 429);
}

$like   = '%' . addcslashes($q, '\\%_') . '%';
$starts = addcslashes($q, '\\%_') . '%';
$ql     = mb_strtolower($q, 'UTF-8');
$items  = [];   // key => item
$add = function (string $value, string $label, string $sub, string $type) use (&$items, $ql): void {
    $label = trim($label); if ($label === '') return;
    $key = mb_strtolower($type === 'place' ? $value : $type . '|' . $value, 'UTF-8');
    if (isset($items[$key])) return;
    $l = mb_strtolower(trim(explode(',', $label)[0]), 'UTF-8');   // rank on the place name, not on the country text after the comma
    $rank = $l === $ql ? 0 : (str_starts_with($l, $ql) ? 1 : (preg_match('/(^|[\s,(\-])' . preg_quote($ql, '/') . '/u', $l) ? 2 : 3));
    $items[$key] = ['value' => $value, 'label' => $label, 'sub' => $sub, 'type' => $type, '_r' => $rank];
};
$withCountry = function (string $name, ?string $country): string {
    $name = trim($name); $country = trim((string)$country);
    return ($country !== '' && stripos($name, $country) === false) ? $name . ', ' . $country : $name;
};
$run = function (string $sql, array $params) use ($dbh): array {
    try { $st = $dbh->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }   // a table that is not installed yet must not break typing
};

// 1a. verified map places
foreach ($run("SELECT Name, NameBn, Locality, District, Country FROM tbl_map_places
               WHERE Status = 'active' AND (Name LIKE :a OR NameBn LIKE :b OR NameNorm LIKE :c OR Locality LIKE :d)
               ORDER BY (Name LIKE :s) DESC, IsVerified DESC, Name LIMIT 8",
    [':a' => $like, ':b' => $like, ':c' => $like, ':d' => $like, ':s' => $starts]) as $r) {
    $name = $lang === 'bn' && !empty($r['NameBn']) ? $r['NameBn'] : $r['Name'];
    $sub  = implode(', ', array_filter([$r['Locality'] ?? '', $r['District'] ?? '', $r['Country'] ?? '']));
    $add($withCountry($r['Name'], $r['Country'] ?? ''), $name, $sub, 'place');
}
// 1b. package locations
foreach ($run("SELECT DISTINCT PackageLocation AS loc, Country, Region FROM tbltourpackages
               WHERE Status = 1 AND (PackageLocation LIKE :a OR Region LIKE :b OR Country LIKE :c) ORDER BY (PackageLocation LIKE :s) DESC LIMIT 8",
    [':a' => $like, ':b' => $like, ':c' => $like, ':s' => $starts]) as $r) {
    $loc = trim((string)$r['loc']); if ($loc === '') continue;
    $add($withCountry($loc, $r['Country'] ?? ''), $loc, implode(', ', array_filter([$r['Region'] ?? '', $r['Country'] ?? ''])), 'place');
}
// 1c. hotel and activity cities
foreach (['tblhotels', 'tblactivities'] as $tbl) {
    foreach ($run("SELECT DISTINCT City, Country FROM `$tbl` WHERE Status = 1 AND (City LIKE :a OR Country LIKE :b) LIMIT 6", [':a' => $like, ':b' => $like]) as $r) {
        $city = trim((string)$r['City']); if ($city === '') continue;
        $add($withCountry($city, $r['Country'] ?? ''), $city, (string)($r['Country'] ?? ''), 'place');
    }
}
// 1d. destinations people already explored
foreach ($run("SELECT display_name, country FROM tbl_explore_destinations WHERE display_name LIKE :a ORDER BY (display_name LIKE :s) DESC LIMIT 5",
    [':a' => $like, ':s' => $starts]) as $r) {
    $dn = trim((string)$r['display_name']); if ($dn === '') continue;
    $short = trim(explode(',', $dn)[0]);
    $add($withCountry($short, $r['country'] ?? ''), $short, (string)($r['country'] ?? ''), 'place');
}
// 2. built-in list
static $builtin = null;
$file = dirname(__DIR__) . '/data/place_suggestions.json';
if (is_file($file)) {
    $builtin = json_decode((string)@file_get_contents($file), true);
    if (is_array($builtin)) {
        foreach ($builtin as $b) {
            $n = (string)($b['n'] ?? ''); $c = (string)($b['c'] ?? '');
            if ($n !== '' && mb_stripos($n, $q, 0, 'UTF-8') !== false) $add($withCountry($n, $c), $n, $c, 'place');
        }
    }
}
// catalogue names for search boxes
if (in_array($kind, ['hotel', 'all'], true)) {
    foreach ($run("SELECT HotelName, City, Country FROM tblhotels WHERE Status = 1 AND HotelName LIKE :a ORDER BY (HotelName LIKE :s) DESC LIMIT 5", [':a' => $like, ':s' => $starts]) as $r)
        $add((string)$r['HotelName'], (string)$r['HotelName'], 'Hotel · ' . implode(', ', array_filter([$r['City'] ?? '', $r['Country'] ?? ''])), 'hotel');
}
if (in_array($kind, ['activity', 'all'], true)) {
    foreach ($run("SELECT ActivityName, City, Country FROM tblactivities WHERE Status = 1 AND ActivityName LIKE :a ORDER BY (ActivityName LIKE :s) DESC LIMIT 5", [':a' => $like, ':s' => $starts]) as $r)
        $add((string)$r['ActivityName'], (string)$r['ActivityName'], 'Activity · ' . implode(', ', array_filter([$r['City'] ?? '', $r['Country'] ?? ''])), 'activity');
}
if (in_array($kind, ['package', 'all'], true)) {
    foreach ($run("SELECT PackageName, PackageLocation FROM tbltourpackages WHERE Status = 1 AND PackageName LIKE :a ORDER BY (PackageName LIKE :s) DESC LIMIT 5", [':a' => $like, ':s' => $starts]) as $r)
        $add((string)$r['PackageName'], (string)$r['PackageName'], 'Package · ' . (string)($r['PackageLocation'] ?? ''), 'package');
}

// 3. wider web places for signed-in users, only when our own sources are thin
$placeCount = count(array_filter($items, fn($i) => $i['type'] === 'place'));
if ($signedIn && $placeCount < 4 && mb_strlen($q, 'UTF-8') >= 3 && is_file(dirname(__DIR__) . '/backend/MapGeocoder.php')) {
    try {
        require_once dirname(__DIR__) . '/backend/MapGeocoder.php';
        $res = MapGeocoder::search($q, ['lang' => $lang], 5);
        if (!empty($res['ok'])) {
            foreach (($res['items'] ?? []) as $g) {
                $nm  = trim((string)($g['name'] ?? ''));
                $dsp = trim((string)($g['display'] ?? ''));
                if ($nm === '' && $dsp !== '') $nm = trim(explode(',', $dsp)[0]);
                if ($nm === '') continue;
                $parts = array_values(array_filter(array_map('trim', explode(',', $dsp)), fn($x) => $x !== '' && strcasecmp($x, $nm) !== 0));
                $country = $parts ? (string)end($parts) : '';
                $add($withCountry($nm, $country), $nm, implode(', ', array_slice($parts, -2)), 'place');
            }
        }
    } catch (Throwable $e) { error_log('[TourBuddy suggest] wide search failed: ' . $e->getMessage()); }
}

$items = array_filter($items, fn($i) => $i['_r'] < 3);   // drop rows that matched only through country / region text
uasort($items, fn($a, $b) => [$a['_r'], mb_strlen($a['label'], 'UTF-8')] <=> [$b['_r'], mb_strlen($b['label'], 'UTF-8')]);
$out = [];
foreach ($items as $it) { unset($it['_r']); $out[] = $it; if (count($out) >= $limit) break; }
sgOut(['ok' => true, 'items' => $out]);

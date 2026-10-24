<?php
/**
 * Pure tests for the map admin tools (no database, no GD, no uploads): image file/metadata validation, size fitting,
 * own-path check, CSV import validation, duplicate pair detection, correction proposal text, coverage patterns.
 *   php tests/unit/map_images_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
foreach (['backend/ai/BanglaNormalizer.php', 'backend/ai/Banglish.php'] as $f) if (is_file("$root/$f")) require_once "$root/$f";
require_once $root . '/backend/services/MapPlaces.php';
require_once $root . '/backend/services/MapImages.php';
require_once $root . '/backend/services/MapAdmin.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : '  -> ' . $detail) . "\n";
}

// ── image file checks ──
$okInfo = [800, 600];
t('good jpeg passes', MapImages::validateFileInfo(0, 200000, 'image/jpeg', $okInfo)['ok'] === true);
t('good png and webp pass', MapImages::validateFileInfo(0, 1000, 'image/png', $okInfo)['ok'] && MapImages::validateFileInfo(0, 1000, 'image/webp', $okInfo)['ok']);
t('gif is rejected', MapImages::validateFileInfo(0, 1000, 'image/gif', $okInfo)['ok'] === false);
t('svg is rejected', MapImages::validateFileInfo(0, 1000, 'image/svg+xml', $okInfo)['ok'] === false);
t('php disguised as image (text/x-php) is rejected', MapImages::validateFileInfo(0, 1000, 'text/x-php', $okInfo)['ok'] === false);
t('unknown mime (null) is rejected', MapImages::validateFileInfo(0, 1000, null, $okInfo)['ok'] === false);
t('upload error code is reported', MapImages::validateFileInfo(1, 0, null, null)['ok'] === false && MapImages::validateFileInfo(4, 0, null, null)['error'] === 'Choose an image file.');
t('empty file is rejected', MapImages::validateFileInfo(0, 0, 'image/jpeg', $okInfo)['ok'] === false);
t('oversize file is rejected', MapImages::validateFileInfo(0, MapImages::MAX_BYTES + 1, 'image/jpeg', $okInfo)['ok'] === false);
t('exactly the size limit passes', MapImages::validateFileInfo(0, MapImages::MAX_BYTES, 'image/jpeg', $okInfo)['ok'] === true);
t('unreadable image (no size) is rejected', MapImages::validateFileInfo(0, 1000, 'image/jpeg', null)['ok'] === false);
t('tiny image is rejected', MapImages::validateFileInfo(0, 1000, 'image/jpeg', [MapImages::MIN_SIDE - 1, 800])['ok'] === false);
t('huge side is rejected', MapImages::validateFileInfo(0, 1000, 'image/jpeg', [MapImages::MAX_SIDE + 1, 800])['ok'] === false);
t('too many pixels is rejected', MapImages::validateFileInfo(0, 1000, 'image/jpeg', [MapImages::MAX_SIDE, MapImages::MAX_SIDE])['ok'] === (MapImages::MAX_SIDE * MapImages::MAX_SIDE <= MapImages::MAX_PIXELS));

// ── metadata: alt, credit, licence ──
$meta = ['alt' => 'Sunrise over the beach', 'credit' => 'A. Rahman', 'license' => 'own'];
t('complete metadata passes', MapImages::validateMeta($meta)['ok'] === true);
t('missing alt fails', isset(MapImages::validateMeta(['alt' => ''] + $meta)['errors']['alt']));
t('too-short alt fails', isset(MapImages::validateMeta(['alt' => 'ab'] + $meta)['errors']['alt']));
t('overlong alt fails', isset(MapImages::validateMeta(['alt' => str_repeat('a', 201)] + $meta)['errors']['alt']));
t('missing credit fails', isset(MapImages::validateMeta(['credit' => ' '] + $meta)['errors']['credit']));
t('missing licence fails', isset(MapImages::validateMeta(['license' => ''] + $meta)['errors']['license']));
t('unknown licence fails', isset(MapImages::validateMeta(['license' => 'free-for-all'] + $meta)['errors']['license']));
t('every offered licence is accepted', count(array_filter(array_keys(MapImages::LICENSES), fn($l) => MapImages::validateMeta(['license' => $l] + $meta)['ok'])) === count(MapImages::LICENSES));
t('licence is case-insensitive', MapImages::validateMeta(['license' => 'CC-BY'] + $meta)['data']['license'] === 'cc-by');
t('source link is optional', MapImages::validateMeta($meta)['data']['source_url'] === null);
t('https source link accepted', MapImages::validateMeta(['source_url' => 'https://commons.wikimedia.org/wiki/File:X.jpg'] + $meta)['ok']);
t('javascript: source link rejected', isset(MapImages::validateMeta(['source_url' => 'javascript:alert(1)'] + $meta)['errors']['source_url']));
t('control characters are stripped from alt', strpos(MapImages::validateMeta(["alt" => "Lake\x00 view\n"] + $meta)['data']['alt'], "\x00") === false);
t('Bangla alt text is accepted', MapImages::validateMeta(['alt' => 'কক্সবাজার সমুদ্র সৈকত'] + $meta)['ok']);

// ── sizing and file names ──
t('fitSize scales the long side down', MapImages::fitSize(4000, 2000, 1280) === [1280, 640]);
t('fitSize never enlarges', MapImages::fitSize(400, 300, 1280) === [400, 300]);
t('fitSize handles portrait', MapImages::fitSize(1000, 4000, 480) === [120, 480]);
t('fitSize is never zero', MapImages::fitSize(10000, 1, 480)[1] >= 1);
t('fitSize of nonsense is 1x1', MapImages::fitSize(0, 5, 100) === [1, 1]);
t('randomBase is 24 hex characters and unique', preg_match('/^[a-f0-9]{24}$/', MapImages::randomBase()) === 1 && MapImages::randomBase() !== MapImages::randomBase());
t('isOwnPath accepts our files', MapImages::isOwnPath('uploads/places/' . str_repeat('a1', 12) . '-l.webp') && MapImages::isOwnPath('uploads/places/' . str_repeat('0f', 12) . '-t.jpg'));
t('isOwnPath rejects traversal', !MapImages::isOwnPath('uploads/places/../../config.php') && !MapImages::isOwnPath('uploads/places/' . str_repeat('a1', 12) . '-l.php'));
t('isOwnPath rejects other folders', !MapImages::isOwnPath('uploads/other/' . str_repeat('a1', 12) . '-l.jpg') && !MapImages::isOwnPath('/etc/passwd'));
$ht = @file_get_contents($root . '/uploads/places/.htaccess');
t('uploads/places/.htaccess blocks scripts', $ht !== false && stripos($ht, 'php') !== false && stripos($ht, 'nosniff') !== false);

// ── CSV import validation ──
$csv = "name,name_bn,category,lat,lng,locality\nSajek Valley,সাজেক,mountain,23.3819,92.2944,Baghaihat\nLalbagh Fort,,historic,23.7188,90.3881,Old Dhaka\n";
$p = MapAdmin::parseCsv($csv);
t('valid csv parses', $p['ok'] && count($p['rows']) === 2 && $p['rows'][0]['status'] === 'ok' && $p['rows'][1]['status'] === 'ok');
t('csv keeps Bangla names', $p['rows'][0]['data']['name_bn'] === 'সাজেক');
t('csv BOM is tolerated', MapAdmin::parseCsv("\xEF\xBB\xBF" . $csv)['ok'] === true);
t('csv missing required column is rejected', MapAdmin::parseCsv("name,category,lat\nA,beach,22.1\n")['ok'] === false);
t('csv unknown column is rejected', MapAdmin::parseCsv("name,category,lat,lng,secret\nA,beach,22.1,91.1,x\n")['ok'] === false);
t('csv empty text is rejected', MapAdmin::parseCsv('')['ok'] === false);
t('csv header only is rejected', MapAdmin::parseCsv("name,category,lat,lng\n")['ok'] === false);
t('csv bad encoding is rejected', MapAdmin::parseCsv("name,category,lat,lng\n\xFF\xFE,beach,22,91\n")['ok'] === false);
t('csv oversize is rejected', MapAdmin::parseCsv(str_repeat('x', MapAdmin::CSV_MAX_BYTES + 1))['ok'] === false);
$bad = MapAdmin::parseCsv("name,category,lat,lng\n,beach,22.1,91.1\nA,nonsense,22.1,91.1\nB,beach,abc,91.1\nC,beach,0,0\nD,beach,95,91\nE,beach,22.1,91.1\nE,beach,22.1,91.1\n");
$st = array_column($bad['rows'], 'status');
t('csv flags empty name, bad category, non-numeric, 0/0, out-of-range and in-file duplicate', $st === ['error', 'error', 'error', 'error', 'error', 'ok', 'error'], implode(',', $st));
t('csv row numbers match file lines', $bad['rows'][0]['line'] === 2 && $bad['rows'][6]['line'] === 8);
$many = "name,category,lat,lng\n";
for ($i = 0; $i < MapAdmin::CSV_MAX_ROWS + 1; $i++) $many .= "P$i,beach," . (22 + $i / 1000) . ",91.1\n";
t('csv above the row limit is rejected', MapAdmin::parseCsv($many)['ok'] === false);
$inj = MapAdmin::parseCsv("name,category,lat,lng\n=cmd|' /C calc'!A0,beach,22.1,91.1\n");
t('csv formula-like names are kept as plain text, not executed (output is escaped by the page)', $inj['ok'] && is_string($inj['rows'][0]['data']['name']));
$out = MapAdmin::parseCsv("name,category,lat,lng\nSwapped,beach,91.1,22.1\n");
t('csv swapped lat/lng is flagged', $out['rows'][0]['status'] === 'error' || count($out['rows'][0]['warnings']) > 0);
$far = MapAdmin::parseCsv("name,category,lat,lng\nParis,attraction,48.8584,2.2945\n");
t('csv point outside Bangladesh carries a warning', $far['rows'][0]['status'] === 'ok' && count($far['rows'][0]['warnings']) === 1);

// ── duplicate pairs ──
$rows = [
    ['PlaceId' => 1, 'Name' => 'Lalbagh Fort', 'Lat' => 23.7188, 'Lng' => 90.3881],
    ['PlaceId' => 2, 'Name' => 'Lalbagh Fort ', 'Lat' => 23.7189, 'Lng' => 90.3882],
    ['PlaceId' => 3, 'Name' => 'Ahsan Manzil', 'Lat' => 23.7189, 'Lng' => 90.3882],
    ['PlaceId' => 4, 'Name' => 'Lalbagh Fort', 'Lat' => 24.9, 'Lng' => 91.8],
];
$pairs = MapAdmin::findDuplicatePairs($rows);
t('near, same-name places are a pair', count($pairs) === 1 && MapAdmin::pairKey((int)$pairs[0]['a']['PlaceId'], (int)$pairs[0]['b']['PlaceId']) === [1, 2]);
t('near but different names are not a pair', count(array_filter($pairs, fn($x) => in_array(3, [(int)$x['a']['PlaceId'], (int)$x['b']['PlaceId']], true))) === 0);
t('same name far apart is not a pair', count(array_filter($pairs, fn($x) => in_array(4, [(int)$x['a']['PlaceId'], (int)$x['b']['PlaceId']], true))) === 0);
t('a dismissed pair is not suggested again', MapAdmin::findDuplicatePairs($rows, ['1:2' => true]) === []);
t('pairKey is ordered', MapAdmin::pairKey(9, 3) === [3, 9] && MapAdmin::pairKey(3, 9) === [3, 9]);
t('findDuplicatePairs respects the limit', count(MapAdmin::findDuplicatePairs($rows, [], 0)) === 0);

// ── corrections and coverage ──
t('proposedText shows coordinates', MapAdmin::proposedText(['Proposed' => '{"lat":23.5,"lng":90.5}']) === '23.5, 90.5');
t('proposedText shows a plain string', MapAdmin::proposedText(['Proposed' => '"New name"']) === 'New name');
t('proposedText survives junk', MapAdmin::proposedText(['Proposed' => 'not json']) === '' || is_string(MapAdmin::proposedText(['Proposed' => 'not json'])));
t('currentValue for location joins lat and lng', MapAdmin::currentValue(['Field' => 'location', 'CurLat' => '23.1', 'CurLng' => '90.2']) === '23.1, 90.2');
t('currentValue of an unknown field is empty', MapAdmin::currentValue(['Field' => 'zzz']) === '');
t('coverage patterns include aliases and escape wildcards', in_array('%Chittagong%', MapAdmin::targetPatterns('Chattogram'), true) && MapAdmin::targetPatterns('50%')[0] === '%50\\%%');
t('coverage names every priority destination', count(array_diff(['Dhaka', 'Chattogram', 'Sylhet', "Cox's Bazar", 'Bandarban', 'Rangamati', 'Khulna', 'Sundarbans'], array_keys(MapAdmin::TARGETS))) === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

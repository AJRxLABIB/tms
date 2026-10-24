<?php
/**
 * Pure tests for MapPlaces (no database, no network): category registry, normalisation (Bangla/Banglish), ranking,
 * coordinate and bbox validation, haversine, duplicate detection, admin input validation, API-facing parsing.
 *   php tests/unit/map_places_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
foreach (['backend/ai/BanglaNormalizer.php', 'backend/ai/Banglish.php'] as $f) if (is_file("$root/$f")) require_once "$root/$f";
require_once $root . '/backend/services/MapPlaces.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : '  -> ' . $detail) . "\n";
}

// ── categories ──
$cats = MapPlaces::categories();
$ids = array_column($cats, 'id');
t('category registry has the tourism categories', count(array_diff(['attraction','historic','museum','beach','mountain','waterfall','park','hotel','restaurant','market','transport','visitor_info','hospital','police','trail','religious'], $ids)) === 0);
t('every category has label, bn label, group, icon and a hex colour', count(array_filter($cats, fn($c) => $c['label'] && $c['label_bn'] && $c['group'] && $c['icon'] && preg_match('/^#[0-9a-f]{6}$/i', $c['color']))) === count($cats));
t('parseCategories keeps known ids only', MapPlaces::parseCategories('beach, nonsense ,hotel,BEACH') === ['beach', 'hotel']);
t('parseCategories expands a group', in_array('beach', MapPlaces::parseCategories('nature')) && in_array('park', MapPlaces::parseCategories('nature')));
t('parseCategories of junk is empty', MapPlaces::parseCategories("'; DROP TABLE x;--") === []);
t('categoryLabel falls back to Other', MapPlaces::categoryLabel('zzz') === MapPlaces::categoryLabel('other'));
t('package types map to categories', MapPlaces::categoryForPackageType('Beach') === 'beach' && MapPlaces::categoryForPackageType('Heritage/Religious') === 'historic' && MapPlaces::categoryForPackageType('???') === 'attraction');

// ── normalisation ──
t('normalise lower-cases and trims', MapPlaces::normalise("  Cox's  BAZAR ") === 'coxs bazar');
t('normalise maps Bangla digits', MapPlaces::normalise('হোটেল ১২৩') === 'হোটেল 123');
t('normalise keeps Bangla letters and vowel signs', MapPlaces::normalise('কক্সবাজার') === 'কক্সবাজার');
t('normalise strips punctuation and zero-width marks', MapPlaces::normalise("Sajek\u{200C}-Valley!!") === 'sajek valley');
t('normalise of empty is empty', MapPlaces::normalise('   ') === '');
t('slugify is ascii and stable', MapPlaces::slugify("Cox's Bazar Beach") === 'coxs-bazar-beach');
t('slugify of a Bangla-only name still gives a usable slug', preg_match('/^place-[0-9a-f]{8}$/', MapPlaces::slugify('কক্সবাজার')) === 1);
t('likeEscape escapes wildcards', MapPlaces::likeEscape('50%_off') === '50\\%\\_off');

// ── ranking ──
t('exact match scores 100', MapPlaces::scoreMatch('sajek valley', 'sajek valley') === 100);
t('prefix beats contained', MapPlaces::scoreMatch('saj', 'sajek valley') > MapPlaces::scoreMatch('valley', 'the sajek valley resort'));
t('token-prefix match scores 70', MapPlaces::scoreMatch('sajek val', 'upper sajek valley') === 70);
t('typo within one edit still matches (fuzzy)', MapPlaces::scoreMatch('sylhet', 'sylhat') >= 40);
t('unrelated text scores 0', MapPlaces::scoreMatch('sajek', 'dhaka museum') === 0);
t('short tokens are not fuzzy matched', MapPlaces::scoreMatch('bar', 'bad') === 0);
t('ranking order exact > prefix > token > contains > fuzzy',
    MapPlaces::scoreMatch('bandarban', 'bandarban') > MapPlaces::scoreMatch('bandar', 'bandarban')
    && MapPlaces::scoreMatch('bandar', 'bandarban') > MapPlaces::scoreMatch('ban ban', 'old bandarban town hall') - 0
    && MapPlaces::scoreMatch('old town', 'old bandarban town') >= MapPlaces::scoreMatch('arban', 'bandarban')
    && MapPlaces::scoreMatch('arban', 'bandarban') > 0);
t('Bangla names match exactly and by prefix', MapPlaces::scoreMatch(MapPlaces::normalise('কক্সবাজার'), MapPlaces::normalise('কক্সবাজার')) === 100
    && MapPlaces::scoreMatch(MapPlaces::normalise('কক্স'), MapPlaces::normalise('কক্সবাজার সৈকত')) === 85);
if (class_exists('Banglish') && method_exists('Banglish', 'tokens')) {
    $alts = array_map([MapPlaces::class, 'normalise'], (array)Banglish::tokens('sylet'));
    t('Banglish spelling variants expand (sylet -> sylhet)', in_array('sylhet', $alts, true), json_encode($alts));
} else { t('Banglish helper available', false, 'backend/ai/Banglish.php missing'); }
t('edit distance is multibyte safe', MapPlaces::editDistance('কক্স', 'কক্স') === 0 && MapPlaces::editDistance('abc', 'abd') === 1 && MapPlaces::editDistance('', 'abc') === 3);

// ── coordinates ──
t('valid coordinates accepted', MapPlaces::validCoords(21.4272, 92.0058) && MapPlaces::validCoords('23.8', '90.4'));
t('out-of-range, non-numeric and 0,0 rejected', !MapPlaces::validCoords(91, 10) && !MapPlaces::validCoords(10, 181) && !MapPlaces::validCoords('abc', 1) && !MapPlaces::validCoords(0, 0) && !MapPlaces::validCoords(null, null) && !MapPlaces::validCoords(NAN, 5));
t('Bangladesh bounds check', MapPlaces::inBangladesh(23.7, 90.4) && !MapPlaces::inBangladesh(90.4, 23.7) && !MapPlaces::inBangladesh(51.5, -0.12));
$d = MapPlaces::haversineM(23.8103, 90.4125, 22.3569, 91.7832);   // Dhaka to Chattogram, roughly 214 km
t('haversine Dhaka-Chattogram is about 215 km', $d > 205000 && $d < 225000, (string)round($d));
t('haversine of identical points is 0', MapPlaces::haversineM(10, 20, 10, 20) < 0.001);
t('haversine is symmetric', abs(MapPlaces::haversineM(1, 2, 3, 4) - MapPlaces::haversineM(3, 4, 1, 2)) < 0.001);
$swapped = MapPlaces::validCoords(90.4, 23.8);
t('swapped lat/lng (90.4, 23.8) is rejected because latitude > 90', $swapped === false);

// ── bbox ──
t('bbox parses', MapPlaces::parseBbox('88,20,92,26') === [88.0, 20.0, 92.0, 26.0]);
t('bbox with junk is null', MapPlaces::parseBbox('a,b,c,d') === null && MapPlaces::parseBbox('1,2,3') === null && MapPlaces::parseBbox('1,2,3,4,5') === null);
t('inverted or empty bbox is null', MapPlaces::parseBbox('92,20,88,26') === null && MapPlaces::parseBbox('88,26,92,20') === null && MapPlaces::parseBbox('88,20,88,26') === null);
$big = MapPlaces::parseBbox('-500,-500,500,500', 30.0);
t('bbox is clamped to the world and to the maximum span', $big !== null && $big[0] >= -180 && $big[2] <= 180 && $big[1] >= -90 && $big[3] <= 90 && ($big[2] - $big[0]) <= 30.0001 && ($big[3] - $big[1]) <= 30.0001, json_encode($big));
$box = MapPlaces::radiusBox(23.7, 90.4, 5000);
t('radius box contains the centre and is about 10 km wide', $box[0] < 90.4 && $box[2] > 90.4 && $box[1] < 23.7 && $box[3] > 23.7 && abs(($box[3] - $box[1]) - 0.0899) < 0.005, json_encode($box));
$polar = MapPlaces::radiusBox(89.9, 0, 5000);
t('radius box near a pole does not divide by zero', is_finite($polar[0]) && is_finite($polar[2]));

// ── duplicates ──
t('same name 40 m apart is a duplicate', MapPlaces::isLikelyDuplicate('Sajek Valley', 23.3822, 92.2926, 'Sajek  valley', 23.3825, 92.2928));
t('same name 5 km apart is not a duplicate', !MapPlaces::isLikelyDuplicate('Sajek Valley', 23.3822, 92.2926, 'Sajek Valley', 23.43, 92.30));
t('different names at the same spot are not duplicates', !MapPlaces::isLikelyDuplicate('Sajek Valley', 23.3822, 92.2926, 'Hotel Rain Forest', 23.3822, 92.2926));
t('one-letter spelling difference nearby is a duplicate', MapPlaces::isLikelyDuplicate('Saint Martin Beach', 20.6275, 92.3226, 'Saint Martins Beach', 20.6276, 92.3227));

// ── admin input validation ──
$ok = MapPlaces::validate(['name' => 'Sajek Valley', 'category' => 'mountain', 'lat' => '23.3822', 'lng' => '92.2926', 'facilities' => 'Parking, Toilets, parking', 'family_friendly' => 'unknown', 'website' => 'https://example.org/x']);
t('valid input passes', $ok['ok'] === true && $ok['data']['lat'] === 23.3822 && $ok['data']['category'] === 'mountain', json_encode($ok['errors']));
t('unknown family_friendly stays NULL (never defaulted to yes or no)', array_key_exists('family_friendly', $ok['data']) && $ok['data']['family_friendly'] === null);
t('facilities are de-duplicated case-sensitively and stored as JSON', $ok['data']['facilities'] === json_encode(['Parking', 'Toilets', 'parking']));
t('missing name is an error', MapPlaces::validate(['category' => 'beach', 'lat' => 21.4, 'lng' => 92.0])['ok'] === false);
t('unknown category is an error', isset(MapPlaces::validate(['name' => 'x', 'category' => 'spaceport', 'lat' => 21.4, 'lng' => 92.0])['errors']['category']));
t('bad coordinates are an error', isset(MapPlaces::validate(['name' => 'x', 'lat' => 999, 'lng' => 92.0])['errors']['coordinates']));
$far = MapPlaces::validate(['name' => 'Eiffel', 'lat' => 48.8584, 'lng' => 2.2945]);
t('coordinates outside Bangladesh give a warning, not an error', $far['ok'] === true && count($far['warnings']) === 1);
t('javascript: website is rejected', isset(MapPlaces::validate(['name' => 'x', 'lat' => 21.4, 'lng' => 92.0, 'website' => 'javascript:alert(1)'])['errors']['website']));
t('bad phone is rejected', isset(MapPlaces::validate(['name' => 'x', 'lat' => 21.4, 'lng' => 92.0, 'phone' => '<script>'])['errors']['phone']));
t('over-long name is rejected', isset(MapPlaces::validate(['name' => str_repeat('a', 201), 'lat' => 21.4, 'lng' => 92.0])['errors']['name']));
$part = MapPlaces::validate(['summary' => 'Only the summary'], true);
t('partial validation checks only the keys present', $part['ok'] === true && array_keys($part['data']) === ['summary']);
t('control characters are stripped from text', MapPlaces::validate(["name" => "A\x00B\x07C", 'lat' => 21.4, 'lng' => 92.0])['data']['name'] === 'ABC');
t('opening hours given as JSON is stored as JSON', MapPlaces::validate(['name' => 'x', 'lat' => 21.4, 'lng' => 92.0, 'opening_hours' => '{"mon":"09:00-17:00"}'])['data']['opening_hours'] === '{"mon":"09:00-17:00"}');
t('free-text fee stays text (not coerced to free)', MapPlaces::validate(['name' => 'x', 'lat' => 21.4, 'lng' => 92.0, 'fee_info' => 'Ask at the gate'])['data']['fee_info'] === 'Ask at the gate');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

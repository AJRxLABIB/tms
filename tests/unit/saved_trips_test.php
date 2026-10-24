<?php
/**
 * Pure tests for SavedPlaces validation and the personal-trip helpers in MapItinerary (no database, no network).
 *   php tests/unit/saved_trips_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/SavedPlaces.php';
require_once __DIR__ . '/../../backend/MapItinerary.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok || $d === '' ? '' : "  [$d]") . "\n"; }
function code(array $r): string { return $r['ok'] ? 'ok' : $r['error']['code']; }

// ---- SavedPlaces ----
$p = ['place_key' => 'p:12', 'name' => '  Inani   Beach ', 'lat' => 21.2, 'lng' => 92.0, 'category' => 'beach'];
$v = SavedPlaces::validate($p);
t('saved: valid payload accepted', $v['ok'] && $v['data']['name'] === 'Inani Beach' && $v['data']['kind'] === 'place');
t('saved: owner key lowercased and trimmed', SavedPlaces::ownerKey('  A@B.Com ') === 'a@b.com');
t('saved: owner in body is ignored by validate', !isset(SavedPlaces::validate($p + ['user_email' => 'x@y.z'])['data']['user_email']));
foreach (['p:12', 'osm:node/123', 'dest:cox-s-bazar', 'geo:21.20000_92.00000_inani'] as $k) t("saved: key $k valid", SavedPlaces::validKey($k));
foreach (['', '12', 'p:', 'P:1', 'p:1 2', "p:1'; DROP", str_repeat('a', 130), 'p:<b>'] as $k) t('saved: key invalid ' . json_encode(substr($k, 0, 12)), !SavedPlaces::validKey($k));
t('saved: lat 91 refused', code(SavedPlaces::validate(['lat' => 91] + $p)) === 'invalid_coords');
t('saved: lng -181 refused', code(SavedPlaces::validate(['lng' => -181] + $p)) === 'invalid_coords');
t('saved: 0,0 placeholder refused', code(SavedPlaces::validate(['lat' => 0, 'lng' => 0] + $p)) === 'invalid_coords');
t('saved: non numeric coords refused', code(SavedPlaces::validate(['lat' => 'abc'] + $p)) === 'invalid_coords');
t('saved: empty name refused', code(SavedPlaces::validate(['name' => '  '] + $p)) === 'invalid_name');
t('saved: 161 char name refused', code(SavedPlaces::validate(['name' => str_repeat('x', 161)] + $p)) === 'invalid_name');
t('saved: unknown category refused', code(SavedPlaces::validate(['category' => 'casino'] + $p)) === 'invalid_category');
t('saved: unknown kind refused', code(SavedPlaces::validate(['kind' => 'weapon'] + $p)) === 'invalid_kind');
t('saved: kind derived from category', SavedPlaces::validate(['category' => 'hotel'] + $p)['data']['kind'] === 'stay' && SavedPlaces::validate(['category' => 'restaurant'] + $p)['data']['kind'] === 'food' && SavedPlaces::validate(['category' => 'hospital'] + $p)['data']['kind'] === 'service');
t('saved: note capped at 300', mb_strlen((string)SavedPlaces::validate(['note' => str_repeat('n', 400)] + $p)['data']['note'], 'UTF-8') === 300);
t('saved: limit constant is 500', SavedPlaces::MAX_PER_USER === 500);

// ---- MapItinerary: dates, times, costs ----
t('date: valid', MapItinerary::parseDate('2026-03-01') === '2026-03-01');
t('date: 30 February refused', MapItinerary::parseDate('2026-02-30') === false);
t('date: wrong format refused', MapItinerary::parseDate('01/03/2026') === false);
t('date: empty is null', MapItinerary::parseDate('') === null);
t('time: 09:30 valid', MapItinerary::parseTime('09:30') === '09:30');
t('time: 24:00 refused', MapItinerary::parseTime('24:00') === false);
t('time: seconds trimmed', MapItinerary::parseTime('09:30:15') === '09:30');
t('cost: negative refused', MapItinerary::parseCost('-1') === false);
t('cost: empty is unknown (null), 0 is a real zero', MapItinerary::parseCost('') === null && MapItinerary::parseCost('0') === 0.0);
t('span: same day is 1', MapItinerary::daySpan('2026-03-01', '2026-03-01') === 1);
t('span: five days', MapItinerary::daySpan('2026-03-01', '2026-03-05') === 5);
t('span: reversed is below 1', MapItinerary::daySpan('2026-03-05', '2026-03-01') < 1);
t('daysFor: dates win', MapItinerary::daysFor('2026-03-01', '2026-03-03', 9, 1) === 3);
t('daysFor: day_count then max day used', MapItinerary::daysFor(null, null, 4, 2) === 4 && MapItinerary::daysFor(null, null, null, 3) === 3 && MapItinerary::daysFor(null, null, null, 1) === 1);

// ---- trip meta ----
t('meta: end before start refused', code(MapItinerary::validateMeta(['start_date' => '2026-03-05', 'end_date' => '2026-03-01'])) === 'invalid_dates');
t('meta: 61 days refused', code(MapItinerary::validateMeta(['start_date' => '2026-01-01', 'end_date' => '2026-03-02'])) === 'invalid_dates');
t('meta: 60 days accepted', code(MapItinerary::validateMeta(['start_date' => '2026-01-01', 'end_date' => '2026-03-01'])) === 'ok');
t('meta: only one date refused', code(MapItinerary::validateMeta(['start_date' => '2026-03-05'])) === 'invalid_dates');
t('meta: bad status refused', code(MapItinerary::validateMeta(['status' => 'paid'])) === 'invalid_status');
t('meta: day_count 0 and 61 refused', code(MapItinerary::validateMeta(['day_count' => 0])) === 'invalid_days' && code(MapItinerary::validateMeta(['day_count' => 61])) === 'invalid_days');
t('meta: untouched keys stay out of meta', MapItinerary::validateMeta(['notes' => 'hi'])['meta'] === ['notes' => 'hi']);
t('meta: notes over 2000 refused', code(MapItinerary::validateMeta(['notes' => str_repeat('a', 2001)])) === 'invalid_notes');

// ---- validate with planner fields ----
function stop(string $n, ?int $day = null, array $x = []): array { return ['name' => $n, 'lat' => 21.4, 'lng' => 91.9] + ($day !== null ? ['day_index' => $day] : []) + $x; }
$trip = ['title' => 'Cox trip', 'planner' => true, 'start_date' => '2026-03-01', 'end_date' => '2026-03-03', 'stops' => [stop('B', 2), stop('A', 1), stop('C', 2), stop('D', 1)]];
$r = MapItinerary::validate($trip);
t('trip: stops grouped by day, stable inside a day', $r['ok'] && array_column($r['data']['stops'], 'name') === ['A', 'D', 'B', 'C']);
t('trip: empty trip allowed only for planner', code(MapItinerary::validate(['title' => 'x', 'planner' => true, 'stops' => []])) === 'ok' && code(MapItinerary::validate(['title' => 'x', 'stops' => []])) === 'invalid_stops');
t('trip: day beyond the dates refused', code(MapItinerary::validate(['stops' => [stop('A', 4)]] + $trip)) === 'invalid_day');
t('trip: day 0 refused', code(MapItinerary::validate(['stops' => [stop('A', 0)]] + $trip)) === 'invalid_day');
t('trip: day against existing day_count', code(MapItinerary::validate(['title' => 't', 'stops' => [stop('A', 3)]], ['day_count' => 2])) === 'invalid_day');
t('trip: dateless trip grows day_count to the highest day', MapItinerary::validate(['title' => 't', 'stops' => [stop('A', 3)]])['data']['meta']['day_count'] === 3);
t('trip: departure before arrival refused', code(MapItinerary::validate(['stops' => [stop('A', 1, ['arrive_time' => '10:00', 'depart_time' => '09:00'])]] + $trip)) === 'invalid_time');
t('trip: bad time refused', code(MapItinerary::validate(['stops' => [stop('A', 1, ['arrive_time' => '25:00'])]] + $trip)) === 'invalid_time');
t('trip: negative cost refused', code(MapItinerary::validate(['stops' => [stop('A', 1, ['est_cost' => -5])]] + $trip)) === 'invalid_cost');
t('trip: unknown cost stays null', MapItinerary::validate(['stops' => [stop('A', 1)]] + $trip)['data']['stops'][0]['est_cost'] === null);
t('trip: bad place_key dropped, not stored', MapItinerary::validate(['stops' => [stop('A', 1, ['place_key' => "x'--"])]] + $trip)['data']['stops'][0]['place_key'] === null);
t('trip: legacy 26 stops still refused', code(MapItinerary::validate(['title' => 't', 'stops' => array_map(fn($i) => stop('S' . $i), range(1, 26))])) === 'too_many_stops');
t('trip: planner allows 100 stops, refuses 101', code(MapItinerary::validate(['stops' => array_map(fn($i) => stop('S' . $i, 1), range(1, 100))] + $trip)) === 'ok'
    && code(MapItinerary::validate(['stops' => array_map(fn($i) => stop('S' . $i, 1), range(1, 101))] + $trip)) === 'too_many_stops');
t('trip: legacy payload has no planner fields flag', MapItinerary::validate(['title' => 't', 'stops' => [stop('A')]])['data']['has_planner_fields'] === false);
t('trip: reversed dates refused through validate', code(MapItinerary::validate(['title' => 't', 'start_date' => '2026-03-05', 'end_date' => '2026-03-01', 'stops' => [stop('A')]])) === 'invalid_dates');

// ---- ordering: reorder within a day, move between days ----
$s = fn(string $n, int $d) => ['name' => $n, 'lat' => 1.0, 'lng' => 1.0, 'day_index' => $d];
$base = [$s('A', 1), $s('B', 1), $s('C', 2), $s('D', 2)];
$names = fn(array $r) => implode('', array_column($r['stops'], 'name'));
t('move: B up swaps within day 1', $names(MapItinerary::applyMove($base, 1, 'up', null, 2)) === 'BACD');
t('move: A up is a no-op', ($m = MapItinerary::applyMove($base, 0, 'up', null, 2))['changed'] === false && $names($m) === 'ABCD');
t('move: B down does not cross into day 2', ($m = MapItinerary::applyMove($base, 1, 'down', null, 2))['changed'] === false && $names($m) === 'ABCD');
t('move: C up does not cross into day 1', MapItinerary::applyMove($base, 2, 'up', null, 2)['changed'] === false);
t('move: A to day 2 goes to the end of day 2', ($m = MapItinerary::applyMove($base, 0, '', 2, 2)) && $names($m) === 'BCDA' && $m['stops'][3]['day_index'] === 2);
t('move: D to day 1 goes to the end of day 1', $names(MapItinerary::applyMove($base, 3, '', 1, 2)) === 'ABDC');
t('move: to an empty day keeps day order', $names(MapItinerary::applyMove($base, 0, '', 3, 3)) === 'BCDA' && MapItinerary::applyMove($base, 0, '', 3, 3)['stops'][3]['day_index'] === 3);
t('move: day outside trip refused', code(MapItinerary::applyMove($base, 0, '', 3, 2)) === 'invalid_day' && code(MapItinerary::applyMove($base, 0, '', 0, 2)) === 'invalid_day');
t('move: bad index refused', code(MapItinerary::applyMove($base, 9, 'up', null, 2)) === 'invalid_stop');
t('move: bad direction refused', code(MapItinerary::applyMove($base, 0, 'sideways', null, 2)) === 'invalid_move');
t('move: stops without a day count as day 1', $names(MapItinerary::applyMove([['name' => 'A', 'lat' => 1, 'lng' => 1], ['name' => 'B', 'lat' => 1, 'lng' => 1]], 1, 'up', null, 1)) === 'BA');

// ---- duplicate day ----
$withCost = [$s('A', 1) + ['est_cost' => 10.0, 'is_done' => true], $s('B', 2)];
$r = MapItinerary::duplicateDayStops($withCost, 1, 2, 2);
t('dupday: copy appended after the last stop of the target day', $r['ok'] && $names($r) === 'ABA' && $r['stops'][2]['day_index'] === 2);
t('dupday: cost copied, done flag reset', $r['stops'][2]['est_cost'] === 10.0 && $r['stops'][2]['is_done'] === false);
t('dupday: original untouched', $r['stops'][0]['is_done'] === true);
t('dupday: empty source day refused', code(MapItinerary::duplicateDayStops($withCost, 3, 1, 3)) === 'empty_day');
t('dupday: day outside trip refused', code(MapItinerary::duplicateDayStops($withCost, 1, 5, 2)) === 'invalid_day');
t('dupday: stop cap enforced', code(MapItinerary::duplicateDayStops(array_map(fn($i) => $s('S' . $i, 1), range(1, 60)), 1, 2, 2)) === 'too_many_stops');

// ---- legacy merge keeps planner data ----
$old = [['name' => 'A', 'lat' => 1.0, 'lng' => 1.0, 'note' => 'keep', 'day_index' => 2, 'arrive_time' => '09:00', 'depart_time' => null, 'est_cost' => 5.0, 'is_done' => false, 'place_key' => null]];
$new = MapItinerary::mergeLegacy([['name' => 'A', 'lat' => 1.0, 'lng' => 1.0, 'note' => null], ['name' => 'Z', 'lat' => 2.0, 'lng' => 2.0, 'note' => null]], $old);
t('legacy merge: matching stop keeps day, time, cost and note', $new[0]['day_index'] === 2 && $new[0]['arrive_time'] === '09:00' && $new[0]['est_cost'] === 5.0 && $new[0]['note'] === 'keep');
t('legacy merge: new stop gets nothing invented', !isset($new[1]['day_index']) && !isset($new[1]['est_cost']));

// ---- trip budget (migration 046) ----
t('budget: empty means no budget', MapItinerary::parseBudget('') === null && MapItinerary::parseBudget(null) === null);
t('budget: zero is a valid budget', MapItinerary::parseBudget('0') === 0.0);
t('budget: rounds to 2 decimals', MapItinerary::parseBudget('1234.567') === 1234.57);
t('budget: negative refused', MapItinerary::parseBudget(-1) === false && MapItinerary::parseBudget('-0.01') === false);
t('budget: text, bool, INF refused', MapItinerary::parseBudget('abc') === false && MapItinerary::parseBudget(true) === false && MapItinerary::parseBudget('1e999') === false && MapItinerary::parseBudget(NAN) === false);
t('budget: column maximum accepted, one cent over refused', MapItinerary::parseBudget('9999999999.99') === 9999999999.99 && MapItinerary::parseBudget('10000000000') === false);
$bm = MapItinerary::validateMeta(['budget_total' => '2500.5', 'budget_currency' => ' usd ']);
t('meta: budget and currency normalised (currency upper case)', $bm['ok'] && $bm['meta']['budget_total'] === 2500.5 && $bm['meta']['budget_currency'] === 'USD');
t('meta: empty budget clears it', MapItinerary::validateMeta(['budget_total' => ''])['meta']['budget_total'] === null);
t('meta: invalid budget refused with its own code', code(MapItinerary::validateMeta(['budget_total' => 'lots'])) === 'invalid_budget');
t('meta: currency must be 3 letters', code(MapItinerary::validateMeta(['budget_currency' => 'US'])) === 'invalid_currency' && code(MapItinerary::validateMeta(['budget_currency' => 'US1'])) === 'invalid_currency' && code(MapItinerary::validateMeta(['budget_currency' => ['USD']])) === 'invalid_currency');
t('meta: no budget keys sent means no budget keys returned (old clients unchanged)', !array_key_exists('budget_total', MapItinerary::validateMeta(['notes' => 'x'])['meta']) && !array_key_exists('budget_currency', MapItinerary::validateMeta(['notes' => 'x'])['meta']));
$bv = MapItinerary::validate(['title' => 'T', 'planner' => true, 'stops' => [], 'budget_total' => 100, 'budget_currency' => 'bdt']);
t('validate: budget travels through validate in meta', $bv['ok'] && $bv['data']['meta']['budget_total'] === 100.0 && $bv['data']['meta']['budget_currency'] === 'BDT');
t('validate: invalid budget stops the whole save', code(MapItinerary::validate(['title' => 'T', 'planner' => true, 'stops' => [], 'budget_total' => -5])) === 'invalid_budget');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

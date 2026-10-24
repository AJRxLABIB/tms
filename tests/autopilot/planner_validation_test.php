<?php
/**
 * Pure tests (no DB, no model, no network) for backend/ai/PlanValidator.php. Run: php tests/autopilot/planner_validation_test.php
 * Covers: costs computed in code, catalogue vs suggestion labelling, overlap repair, mode limits, opening hours,
 * infeasible detection (missing days, known costs over budget, closed, past midnight), route reorder, weather flag,
 * and the model-free fallback builder.
 */
require_once __DIR__ . '/../../backend/ai/PlanValidator.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $detail = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : '  ' . $detail) . "\n"; }

$catalogue = [
    ['kind' => 'activity', 'id' => 1, 'name' => 'Sundarbans Boat Safari', 'price' => 1500, 'price_unit' => 'per_person', 'duration_min' => 180, 'lat' => 22.30, 'lng' => 89.60, 'opens' => '08:00', 'closes' => '17:00', 'sample' => false],
    ['kind' => 'activity', 'id' => 2, 'name' => 'Old Fort Walk', 'price' => 200, 'price_unit' => 'per_person', 'duration_min' => 90, 'lat' => 22.31, 'lng' => 89.61, 'sample' => false],
    ['kind' => 'activity', 'id' => 3, 'name' => 'River Market Tour', 'price' => 300, 'price_unit' => 'per_person', 'duration_min' => 60, 'lat' => 22.60, 'lng' => 89.90, 'sample' => true],
    ['kind' => 'activity', 'id' => 4, 'name' => 'Handicraft Museum', 'price' => 100, 'price_unit' => 'per_person', 'duration_min' => 60, 'lat' => 22.32, 'lng' => 89.62, 'indoor' => true],
    ['kind' => 'hotel', 'id' => 9, 'name' => 'Riverside Inn Khulna', 'price' => 4000, 'price_unit' => 'per_night'],
];
$trip = ['destination' => 'Khulna', 'duration' => 2, 'travelers' => 2, 'budget' => 30000, 'start_date' => '2031-02-01'];

// ── 1. cost computed in code, model figures not trusted ───────────────────────────────────────────────
$plan = ['estimated_cost' => ['total' => 999999], 'days' => [
    ['day' => 1, 'date' => '2031-02-01', 'schedule' => [
        ['time' => '09:00', 'activity' => 'Sundarbans Boat Safari', 'location' => 'Khulna', 'duration' => '3 hours', 'cost' => 1, 'type' => 'activity'],
        ['time' => '13:00', 'activity' => 'Lunch', 'location' => 'Local', 'duration' => '1 hour', 'cost' => 0, 'type' => 'meal'],
    ]],
    ['day' => 2, 'date' => '2031-02-02', 'schedule' => [
        ['time' => '10:00', 'activity' => 'Visit a secret waterfall', 'location' => 'Somewhere', 'duration' => '2 hours', 'cost' => 500, 'type' => 'sightseeing'],
    ]],
]];
$r = PlanValidator::finalize($plan, $trip, $catalogue);
$d1 = $r['days'][0]['schedule'][0];
t('catalogue price replaces the model price (1500 x 2 travellers = 3000)', $d1['line_total'] === 3000 && $d1['cost_basis'] === 'catalogue' && $d1['cost'] === 1500, json_encode($d1));
t('model total is kept only as ai_estimated_cost', ($r['ai_estimated_cost']['total'] ?? null) === 999999 && $r['estimated_cost']['total'] !== 999999);
t('total is the sum of computed parts', $r['computed_cost']['total'] === $r['computed_cost']['activities'] + $r['computed_cost']['food'] + $r['computed_cost']['transport'] + $r['computed_cost']['accommodation'], json_encode($r['computed_cost']));
t('unmatched place is labelled a suggestion, not bookable', $r['days'][1]['schedule'][0]['source'] === 'suggestion (not bookable)');
t('matched place is labelled catalogue with its id', $d1['source'] === 'catalogue' && $d1['catalogue_id'] === 1);
t('meal is an estimate, labelled so', $r['days'][0]['schedule'][1]['cost_basis'] === 'estimate' && $r['days'][0]['schedule'][1]['line_total'] === 600);
t('verified-from-catalogue vs estimated are separate figures', $r['computed_cost']['verified_from_catalogue'] === 3000 && $r['computed_cost']['estimated'] === 600 + 1000, json_encode($r['computed_cost']));
t('daily_budget is recomputed, the model figure is kept separately', array_key_exists('daily_budget_ai', $r['days'][0]) && $r['days'][0]['daily_budget'] === 3600);
t('unmatched count is reported', $r['validation']['catalogue_unmatched'] === 1 && $r['validation']['catalogue_matched'] === 1);

// ── 2. day count ────────────────────────────────────────────────────────────────────────────────────
$short = PlanValidator::finalize(['days' => [$plan['days'][0]]], $trip, $catalogue);
t('missing day => infeasible, not padded', $short['validation']['status'] === 'infeasible' && count($short['days']) === 1 && $short['validation']['reasons'][0]['code'] === 'missing_days');
$long = PlanValidator::finalize(['days' => [$plan['days'][0], $plan['days'][1], $plan['days'][1]]], $trip, $catalogue);
t('extra days are trimmed and noted', count($long['days']) === 2 && in_array('extra_days_removed', array_column($long['validation']['adjustments'], 'code'), true));

// ── 3. overlap repair ───────────────────────────────────────────────────────────────────────────────
$ov = ['days' => [['day' => 1, 'schedule' => [
    ['time' => '09:00', 'activity' => 'Old Fort Walk', 'duration' => '2 hours', 'type' => 'sightseeing'],
    ['time' => '09:30', 'activity' => 'Handicraft Museum', 'duration' => '1 hour', 'type' => 'sightseeing'],
]]]];
$o = PlanValidator::finalize($ov, ['duration' => 1, 'travelers' => 1] , $catalogue);
$s = $o['days'][0]['schedule'];
t('overlapping item is shifted after the previous one plus transfer', $s[1]['time'] >= '11:00' && ($s[1]['adjusted'] ?? false) === true, json_encode($s));
t('adjusted status reported', $o['validation']['status'] === 'adjusted');
$prevEnd = ItineraryChecker::parseTime($s[0]['time']) + 90;
t('no overlap remains', ItineraryChecker::parseTime($s[1]['time']) >= $prevEnd);

// ── 4. times: unsorted + missing time ───────────────────────────────────────────────────────────────
$us = ['days' => [['day' => 1, 'schedule' => [
    ['time' => '15:00', 'activity' => 'Afternoon thing', 'duration' => '1 hour', 'type' => 'activity'],
    ['time' => '09:00', 'activity' => 'Morning thing', 'duration' => '1 hour', 'type' => 'activity'],
    ['time' => 'sometime', 'activity' => 'Mystery', 'duration' => '1 hour', 'type' => 'activity'],
]]]];
$u = PlanValidator::finalize($us, ['duration' => 1], $catalogue);
$times = array_map(fn($x) => ItineraryChecker::parseTime($x['time']), $u['days'][0]['schedule']);
t('schedule is sorted by time', $times === array_values(array_filter($times, 'is_int')) && $times === (function ($a) { sort($a); return $a; })($times), json_encode($times));
t('an item without a valid time is placed, not dropped', count($u['days'][0]['schedule']) === 3);

// ── 5. modes change constraints ──────────────────────────────────────────────────────────────────────
$early = ['days' => [['day' => 1, 'schedule' => [['time' => '06:00', 'activity' => 'Sunrise point', 'duration' => '1 hour', 'type' => 'sightseeing']]]]];
$std = PlanValidator::finalize($early, ['duration' => 1], []);
$rel = PlanValidator::finalize($early, ['duration' => 1, 'mode' => 'relaxed'], []);
t('standard keeps 06:00', $std['days'][0]['schedule'][0]['time'] === '06:00');
t('relaxed mode moves it to 08:30', $rel['days'][0]['schedule'][0]['time'] === '08:30' && $rel['validation']['mode'] === 'relaxed');
t('mode is read from the traveller\'s words when not given', PlanValidator::normalizeMode(['special_requests' => 'we need wheelchair access']) === 'accessible' && PlanValidator::normalizeMode(['travel_type' => 'family trip with kids']) === 'family' && PlanValidator::normalizeMode([]) === 'standard');
t('explicit mode beats keywords', PlanValidator::normalizeMode(['mode' => 'budget', 'special_requests' => 'family']) === 'budget');
t('unknown explicit mode falls back safely', PlanValidator::normalizeMode(['mode' => 'turbo']) === 'standard');
$acc = PlanValidator::finalize(['days' => [['day' => 1, 'schedule' => [['time' => '10:00', 'activity' => 'Cave trek and rappel', 'duration' => '3 hours', 'type' => 'activity']]]]], ['duration' => 1, 'mode' => 'accessible'], []);
t('accessible mode flags physical-effort activities', in_array('accessibility', array_column($acc['validation']['issues'], 'code'), true));
$many = ['days' => [['day' => 1, 'schedule' => array_map(fn($i) => ['time' => sprintf('%02d:00', 8 + $i * 2), 'activity' => "Stop $i", 'duration' => '1 hour', 'type' => 'activity'], range(0, 4))]]];
$fam = PlanValidator::finalize($many, ['duration' => 1, 'mode' => 'family'], []);
t('too many items per day is flagged for family mode', in_array('too_many_items', array_column($fam['validation']['issues'], 'code'), true));

// ── 6. opening hours / infeasible ──────────────────────────────────────────────────────────────────
$closed = ['days' => [['day' => 1, 'schedule' => [['time' => '16:30', 'activity' => 'Sundarbans Boat Safari', 'duration' => '3 hours', 'type' => 'activity']]]]];
$c = PlanValidator::finalize($closed, ['duration' => 1], $catalogue);
t('visit running past closing time => infeasible with the reason', $c['validation']['status'] === 'infeasible' && in_array('closed', array_column($c['validation']['reasons'], 'code'), true));
$early2 = ['days' => [['day' => 1, 'schedule' => [['time' => '06:30', 'activity' => 'Sundarbans Boat Safari', 'duration' => '3 hours', 'type' => 'activity']]]]];
$e = PlanValidator::finalize($early2, ['duration' => 1], $catalogue);
t('visit before opening is moved to opening time, plan stays feasible', $e['days'][0]['schedule'][0]['time'] === '08:00' && $e['validation']['status'] === 'adjusted');
$night = ['days' => [['day' => 1, 'schedule' => [['time' => '23:00', 'activity' => 'Late tour', 'duration' => '3 hours', 'type' => 'activity']]]]];
$n = PlanValidator::finalize($night, ['duration' => 1], []);
t('a day that cannot finish before midnight => infeasible', $n['validation']['status'] === 'infeasible' && $n['validation']['reasons'][0]['code'] === 'past_midnight');

// ── 7. budget ──────────────────────────────────────────────────────────────────────────────────────
$tight = PlanValidator::finalize($plan, ['duration' => 2, 'travelers' => 2, 'budget' => 2000], $catalogue);
t('known catalogue costs above budget => infeasible', $tight['validation']['status'] === 'infeasible' && in_array('known_costs_over_budget', array_column($tight['validation']['reasons'], 'code'), true));
t('budget check lists trade-offs', $tight['budget_check']['status'] === 'over_budget' && count($tight['budget_check']['trade_offs']) >= 1 && $tight['budget_check']['over_by'] > 0);
$ok = PlanValidator::finalize($plan, $trip, $catalogue);
t('within budget is stated', $ok['budget_check']['status'] === 'within_budget' && $ok['budget_check']['over_by'] === 0);
t('no budget given => skipped, no budget_check', !isset(PlanValidator::finalize($plan, ['duration' => 2], $catalogue)['budget_check']));

// ── 8. route order + transfers ──────────────────────────────────────────────────────────────────────
$route = ['days' => [['day' => 1, 'schedule' => [
    ['time' => '09:00', 'activity' => 'Old Fort Walk', 'duration' => '1 hour', 'type' => 'sightseeing'],
    ['time' => '11:30', 'activity' => 'River Market Tour', 'duration' => '1 hour', 'type' => 'sightseeing'],     // far away
    ['time' => '14:00', 'activity' => 'Handicraft Museum', 'duration' => '1 hour', 'type' => 'sightseeing'],      // next to the fort
]]]];
$rt = PlanValidator::finalize($route, ['duration' => 1, 'travelers' => 1], $catalogue);
$names = array_column($rt['days'][0]['schedule'], 'activity');
t('stops are reordered to avoid backtracking, first stop fixed', $names[0] === 'Old Fort Walk' && $names[1] === 'Handicraft Museum' && $names[2] === 'River Market Tour', json_encode($names));
t('reorder is reported', in_array('route_reordered', array_column($rt['validation']['adjustments'], 'code'), true));
t('transfer minutes are recorded between located stops', isset($rt['days'][0]['schedule'][1]['transfer_min']) && $rt['days'][0]['schedule'][1]['transfer_min'] >= 10);

// ── 9. weather ─────────────────────────────────────────────────────────────────────────────────────
$w = PlanValidator::finalize($plan, $trip, $catalogue, ['weather' => ['2031-02-01' => ['rain_pct' => 80], '2031-02-02' => ['rain_pct' => 10]]]);
$wi = array_values(array_filter($w['validation']['issues'], fn($i) => $i['code'] === 'weather_outdoor'));
t('outdoor item on a rainy day is flagged, dry day is not', count($wi) === 1 && $wi[0]['day'] === 1, json_encode($wi));
$wn = PlanValidator::finalize($plan, $trip, $catalogue);
t('no forecast supplied => weather is listed as not checked, never invented', in_array('weather (no forecast data supplied)', $wn['validation']['skipped'], true) && !in_array('weather_outdoor', array_column($wn['validation']['issues'], 'code'), true));
$indoor = PlanValidator::finalize(['days' => [['date' => '2031-02-01', 'day' => 1, 'schedule' => [['time' => '10:00', 'activity' => 'Handicraft Museum', 'duration' => '1 hour', 'type' => 'sightseeing']]]]], ['duration' => 1], $catalogue, ['weather' => ['2031-02-01' => ['rain_pct' => 90]]]);
t('indoor place is not flagged for rain', !in_array('weather_outdoor', array_column($indoor['validation']['issues'], 'code'), true));

// ── 10. robustness ──────────────────────────────────────────────────────────────────────────────────
$g = PlanValidator::finalize(['days' => ['junk', null, ['schedule' => 'nope'], ['schedule' => ['x', ['activity' => 'ok', 'time' => '09:00']]]]], ['duration' => 4], $catalogue);
t('malformed model output does not throw and still yields 4 numbered days', count($g['days']) === 4 && $g['days'][3]['day'] === 4);
t('catalogue match needs the name, not a loose word', PlanValidator::matchCatalogue('a fort somewhere', $catalogue) === null && PlanValidator::matchCatalogue('Visit the OLD FORT WALK today!', $catalogue)['id'] === 2);
t('match is restricted by kind', PlanValidator::matchCatalogue('Riverside Inn Khulna', $catalogue, 'activity') === null && PlanValidator::matchCatalogue('Riverside Inn Khulna', $catalogue, 'hotel')['id'] === 9);

// ── 11. fallback builder ────────────────────────────────────────────────────────────────────────────
$fb = PlanValidator::buildFallback(['destination' => 'Khulna', 'duration' => 3, 'travelers' => 2, 'budget' => 40000, 'start_date' => '2031-02-01'], $catalogue);
t('fallback is built from the catalogue and says so', $fb !== null && $fb['is_fallback'] === true && $fb['fallback_kind'] === 'catalogue_template' && str_contains($fb['ai_notes'], 'not personalised'));
t('fallback has the requested days with dates', $fb !== null && count($fb['days']) === 3 && $fb['days'][2]['date'] === '2031-02-03');
t('fallback every activity is a real catalogue row', $fb !== null && count(array_filter(array_merge(...array_column($fb['days'], 'schedule')), fn($x) => in_array($x['type'], ['activity', 'sightseeing'], true) && $x['source'] !== 'catalogue')) === 0);
t('fallback costs are computed and hotel nights are charged', $fb !== null && $fb['computed_cost']['accommodation'] === 2 * 4000 * 1 && $fb['computed_cost']['total'] > 0, json_encode($fb['computed_cost'] ?? []));
t('fallback is validated like a model plan', $fb !== null && isset($fb['validation']['status']) && $fb['validation']['status'] !== 'infeasible', json_encode($fb['validation'] ?? []));
t('fallback is deterministic', json_encode(PlanValidator::buildFallback(['destination' => 'Khulna', 'duration' => 3, 'travelers' => 2, 'start_date' => '2031-02-01'], $catalogue)) === json_encode(PlanValidator::buildFallback(['destination' => 'Khulna', 'duration' => 3, 'travelers' => 2, 'start_date' => '2031-02-01'], $catalogue)));
t('no catalogue activities => null (caller must say so, nothing invented)', PlanValidator::buildFallback(['destination' => 'X', 'duration' => 2], [['kind' => 'hotel', 'name' => 'H', 'price' => 1]]) === null && PlanValidator::buildFallback(['destination' => 'X'], []) === null);
$rl = PlanValidator::buildFallback(['destination' => 'Khulna', 'duration' => 2, 'mode' => 'relaxed'], $catalogue);
t('relaxed fallback has fewer stops per day', $rl !== null && count(array_filter($rl['days'][0]['schedule'], fn($x) => $x['type'] === 'activity')) === 2);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

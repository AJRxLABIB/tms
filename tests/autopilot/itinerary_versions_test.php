<?php
/**
 * Pure tests (no database, no network) for ItineraryVersions, RouteOptimizer and TripAutopilot::classifySignal.
 *   php tests/autopilot/itinerary_versions_test.php
 * The DB-backed parts (propose / accept / reject / ownership) need MySQL and migration 021; see tests/integration/README.md.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require_once $root . '/backend/services/ItineraryVersions.php';
require_once $root . '/backend/services/TripAutopilot.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($d !== '' ? "  [$d]" : '') . "\n"; }

// Points around Cox's Bazar-like coordinates: A start, then B far, C near A, D near C (bad order A,B,C,D)
$pts = [
    ['id' => 'A', 'lat' => 21.4272, 'lng' => 92.0058],
    ['id' => 'B', 'lat' => 21.9500, 'lng' => 92.0500],
    ['id' => 'C', 'lat' => 21.4400, 'lng' => 92.0100],
    ['id' => 'D', 'lat' => 21.4600, 'lng' => 92.0200],
];
$r = RouteOptimizer::optimize($pts, true, false);
t('route: start stays first', $r['order'][0] === 'A');
t('route: far stop B goes last', end($r['order']) === 'B', implode('', $r['order']));
t('route: distance reduced', $r['saved_km'] > 10, (string)round($r['saved_km'], 1));
t('route: deterministic (same input twice)', RouteOptimizer::optimize($pts, true, false) === $r);
$good = [$pts[0], $pts[2], $pts[3], $pts[1]];
$r2 = RouteOptimizer::optimize($good, true, false);
t('route: already-good order is left alone', !$r2['changed'] && $r2['saved_km'] === 0.0);
t('route: never worse than the original', $r['distance_km'] <= $r['original_km'] + 1e-9);
$unp = RouteOptimizer::optimize([$pts[0], ['id' => 'X', 'lat' => null, 'lng' => null], $pts[1], $pts[2], $pts[3]], true, false);
t('route: stops without coordinates are reported, not moved', $unp['unplaced'] === ['X'] && !in_array('X', $unp['order'], true));
t('route: fewer than 3 stops => unchanged', !RouteOptimizer::optimize([$pts[0], $pts[1]])['changed']);
t('route: all permutations of 5 points: result is a permutation', (function () use ($pts) {
    $p = array_merge($pts, [['id' => 'E', 'lat' => 21.5, 'lng' => 92.3]]); $o = RouteOptimizer::optimize($p)['order']; sort($o); return $o === ['A', 'B', 'C', 'D', 'E'];
})());

// Plans
$plan = ['days' => [[
    'day' => 1, 'date' => '2031-01-10', 'title' => 'Beach',
    'schedule' => [
        ['time' => '09:00', 'duration' => '2 hours', 'activity' => 'Hotel breakfast', 'type' => 'meal', 'location' => 'Hotel', 'cost' => 500],
        ['time' => '11:30', 'duration' => '2 hours', 'activity' => 'Boat tour', 'location' => 'Jetty', 'cost' => 2000, 'cost_status' => 'quoted', 'opens' => '10:00', 'closes' => '17:00'],
        ['time' => '15:00', 'duration' => '1 hour', 'activity' => 'Museum', 'location' => 'Museum', 'cost' => 300, 'booking_ref' => 77, 'cost_status' => 'confirmed'],
    ],
]]];
$n = ItineraryVersions::normalize($plan);
t('normalize: missing cost_status becomes estimated', $n['days'][0]['schedule'][0]['cost_status'] === 'estimated');
t('normalize: valid status kept', $n['days'][0]['schedule'][1]['cost_status'] === 'quoted');
$bad = $plan; $bad['days'][0]['schedule'][0]['cost_status'] = 'bogus';
t('normalize: invalid status is not trusted (never "confirmed")', ItineraryVersions::normalize($bad)['days'][0]['schedule'][0]['cost_status'] === 'estimated');
$cs = ItineraryVersions::costSummary($plan);
t('cost: kept apart by status', $cs['estimated'] == 500 && $cs['quoted'] == 2000 && $cs['confirmed'] == 300 && $cs['total'] == 2800, json_encode($cs));

// Diff
$new = $plan; $new['days'][0]['schedule'][1]['time'] = '12:00'; unset($new['days'][0]['schedule'][2]);
$new['days'][0]['schedule'][] = ['time' => '16:00', 'duration' => '1 hour', 'activity' => 'Market', 'location' => 'Bazaar', 'cost' => 100];
$new['days'][0]['schedule'] = array_values($new['days'][0]['schedule']);
$d = ItineraryVersions::diff($plan, $new);
t('diff: counts added/removed/moved', $d['added'] === 1 && $d['removed'] === 1 && $d['moved'] === 1 && $d['changed'] === 0, $d['summary']);
t('diff: identical plans => no differences', ItineraryVersions::diff($plan, $plan)['summary'] === 'no differences');
t('diff: cost delta reflects estimated change', $d['cost_delta']['estimated'] == 100 && $d['cost_delta']['confirmed'] == -300, json_encode($d['cost_delta']));

// Affected confirmed bookings
$aff = ItineraryVersions::affectedConfirmed($plan, $new, ['77' => true]);
t('affected: removing an item tied to a confirmed booking is reported', count($aff) === 1 && $aff[0]['change'] === 'removed' && $aff[0]['booking_ref'] === '77');
t('affected: not reported when the booking is not confirmed/owned', ItineraryVersions::affectedConfirmed($plan, $new, []) === []);
$moved = $plan; $moved['days'][0]['schedule'][2]['time'] = '16:30';
$aff2 = ItineraryVersions::affectedConfirmed($plan, $moved, ['77' => true]);
t('affected: moving it is reported as moved', count($aff2) === 1 && $aff2[0]['change'] === 'moved');
t('affected: unchanged plan reports nothing', ItineraryVersions::affectedConfirmed($plan, $plan, ['77' => true]) === []);

// Assess
$a = ItineraryVersions::assess($plan, ['duration' => 1]);
t('assess: boat tour inside opening hours is fine', !array_filter($a['issues'], fn($i) => $i['code'] === 'outside_hours'));
$early = $plan; $early['days'][0]['schedule'][1]['time'] = '09:30'; $early['days'][0]['schedule'][0]['duration'] = '30 min';
$a2 = ItineraryVersions::assess($early, ['duration' => 1]);
t('assess: starting before opening time is flagged and not feasible', (bool)array_filter($a2['issues'], fn($i) => $i['code'] === 'outside_hours') && $a2['feasible'] === false);
$late = $plan; $late['days'][0]['schedule'][1]['time'] = '16:00'; $late['days'][0]['schedule'][2]['time'] = '19:00';
t('assess: ending after closing time is flagged', (bool)array_filter(ItineraryVersions::assess($late)['issues'], fn($i) => $i['code'] === 'outside_hours'));
$ov = $plan; $ov['days'][0]['schedule'][1]['time'] = '09:30';
t('assess: overlap is reported by the existing checker', (bool)array_filter(ItineraryVersions::assess($ov)['issues'], fn($i) => $i['code'] === 'overlap'));
$tr = ['days' => [['day' => 1, 'schedule' => [
    ['time' => '09:00', 'duration' => '1 hour', 'activity' => 'A', 'lat' => 21.43, 'lng' => 92.0],
    ['time' => '10:10', 'duration' => '1 hour', 'activity' => 'B', 'lat' => 21.95, 'lng' => 92.05],
]]]];
$a3 = ItineraryVersions::assess($tr);
t('assess: ~58 km in a 10 minute gap is flagged as a transfer problem', (bool)array_filter($a3['issues'], fn($i) => $i['code'] === 'transfer_time') && !$a3['feasible']);
$tr['days'][0]['schedule'][1]['time'] = '13:00';
t('assess: same trip with enough time is fine', !array_filter(ItineraryVersions::assess($tr)['issues'], fn($i) => $i['code'] === 'transfer_time'));
t('assess: result always carries the cost split', isset($a['cost']['confirmed']));

// optimizeRoutes
$day = ['days' => [['day' => 1, 'schedule' => [
    ['time' => '09:00', 'duration' => '1 hour', 'activity' => 'Hotel', 'type' => 'accommodation', 'lat' => 21.4272, 'lng' => 92.0058],
    ['time' => '10:00', 'duration' => '1 hour', 'activity' => 'Far', 'lat' => 21.9500, 'lng' => 92.0500],
    ['time' => '12:00', 'duration' => '1 hour', 'activity' => 'Near1', 'lat' => 21.4400, 'lng' => 92.0100],
    ['time' => '14:00', 'duration' => '1 hour', 'activity' => 'Near2', 'lat' => 21.4600, 'lng' => 92.0200],
    ['time' => '16:00', 'duration' => '1 hour', 'activity' => 'Booked', 'lat' => 21.9, 'lng' => 92.0, 'booking_ref' => 5],
]]]];
$o = ItineraryVersions::optimizeRoutes($day, null);
$acts = array_column($o['itinerary']['days'][0]['schedule'], 'activity');
t('optimize: far stop moved after the near ones', $o['changed'] && array_search('Far', $acts) > array_search('Near2', $acts), implode(',', $acts));
t('optimize: time slots are kept in order', array_column($o['itinerary']['days'][0]['schedule'], 'time') === ['09:00', '10:00', '12:00', '14:00', '16:00']);
t('optimize: a stop tied to a booking never moves', $acts[4] === 'Booked' && $o['itinerary']['days'][0]['schedule'][4]['time'] === '16:00');
t('optimize: accommodation stays first', $acts[0] === 'Hotel');
$flat = ItineraryVersions::optimizeRoutes(['days' => [['day' => 1, 'schedule' => [['time' => '09:00', 'activity' => 'x', 'location' => 'nowhere'], ['time' => '10:00', 'activity' => 'y', 'location' => 'nowhere2'], ['time' => '11:00', 'activity' => 'z', 'location' => 'n3']]]]], fn($p) => null);
t('optimize: nothing placeable => no change, no invented coordinates', !$flat['changed']);

// Disruption classification
$notice = ['type' => 'notice', 'title' => 'Airport closure announced', 'body' => 'Flights suspended', 'source_name' => 'Ministry', 'verified_on' => '2031-01-02'];
$c = TripAutopilot::classifySignal($notice);
t('classify: sourced notice is verified, closure is high', $c['label'] === 'verified' && $c['severity'] === 'high');
$nosrc = $notice; $nosrc['source_name'] = '';
t('classify: notice without a source is unconfirmed', TripAutopilot::classifySignal($nosrc)['label'] === 'unconfirmed');
t('classify: advisory wording is medium', TripAutopilot::classifySignal(['type' => 'notice', 'title' => 'Travel advisory', 'body' => 'Some delays', 'source_name' => 'X', 'verified_on' => '2031-01-01'])['severity'] === 'medium');
t('classify: plain information is low', TripAutopilot::classifySignal(['type' => 'notice', 'title' => 'New visa form', 'body' => 'Use the new form', 'source_name' => 'X', 'verified_on' => '2031-01-01'])['severity'] === 'low');
t('classify: weather is always a forecast, never verified', TripAutopilot::classifySignal(['type' => 'weather', 'rain_chance' => 99])['label'] === 'forecast');
$sig = TripAutopilot::buildSignals([['NoticeId' => 4, 'Title' => 'Strike', 'Body' => 'b', 'Country' => 'X', 'SourceName' => 'S', 'SourceUrl' => 'u', 'VerifiedOn' => '2031-01-02', 'ExpiresOn' => null]], [['day' => 2, 'date' => '2031-01-11', 'rain_chance' => 90]]);
t('signals carry label, severity and observed time', isset($sig[0]['label'], $sig[0]['severity'], $sig[0]['observed_at'], $sig[1]['label'], $sig[1]['observed_at']));
$k1 = TripAutopilot::signalKey($sig);
$sig2 = $sig; $sig2[0]['severity'] = 'low';
t('dedup: signal key ignores label/severity/observed time (same event => same key)', TripAutopilot::signalKey($sig2) === $k1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

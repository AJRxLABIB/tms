<?php
/** ItineraryChecker: deterministic checks, no model. Includes a realistic clean plan (no false alarms) and a plan with every defect.
 *  php tests/ai/itinerary_checker_test.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/ai/ItineraryChecker.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 80) . $d . "\n"; }
$codes = fn(array $r) => array_column($r['issues'], 'code');
$day = fn(int $n, string $date, array $sched, array $extra = []) => ['day' => $n, 'date' => $date, 'schedule' => $sched] + $extra;
$s = fn(string $time, string $dur, string $act, string $loc = 'Hotel', string $type = 'sightseeing', $cost = 0) => ['time' => $time, 'duration' => $dur, 'activity' => $act, 'location' => $loc, 'type' => $type, 'cost' => $cost];

t('parseTime: 24h, am/pm, dotted, junk', (ItineraryChecker::parseTime('09:30') === 570 && ItineraryChecker::parseTime('9:30 PM') === 1290 && ItineraryChecker::parseTime('2pm') === 840 && ItineraryChecker::parseTime('12 AM') === 0 && ItineraryChecker::parseTime('25:00') === null && ItineraryChecker::parseTime('soon') === null));
t('parseDuration: hours, minutes, ranges (lower bound), combos, unclear', ItineraryChecker::parseDuration('2 hours') === 120 && ItineraryChecker::parseDuration('90 min') === 90 && ItineraryChecker::parseDuration('1.5h') === 90 && ItineraryChecker::parseDuration('2-3 hours') === 120 && ItineraryChecker::parseDuration('1 hour 30 minutes') === 90 && ItineraryChecker::parseDuration('half day') === null && ItineraryChecker::parseDuration('overnight') === null && ItineraryChecker::parseDuration('') === null);

$clean = ['estimated_cost' => ['accommodation' => 6000, 'transport' => 2000, 'food' => 1500, 'activities' => 500, 'miscellaneous' => 0, 'total' => 10000], 'days' => [
    $day(1, '2026-12-01', [$s('08:00', '1 hour', 'Breakfast', 'Hotel', 'meal', 300), $s('09:30', '3 hours', 'Boat tour', 'Harbour', 'sightseeing', 800), $s('13:00', '1 hour', 'Lunch', 'Cafe', 'meal', 400), $s('14:30', '2 hours', 'Museum', 'Museum', 'sightseeing', 200), $s('19:00', '1.5 hours', 'Dinner', 'Restaurant', 'meal', 600)], ['daily_budget' => 3000]),
    $day(2, '2026-12-02', [$s('09:00', '4 hours', 'Hike', 'Hill', 'activity', 0), $s('14:00', '1 hour', 'Lunch', 'Hill cafe', 'meal', 300)], ['daily_budget' => 1500])]];
$r = ItineraryChecker::check($clean, ['duration' => 2, 'start_date' => '2026-12-01', 'budget' => 12000, 'travelers' => 2]);
t('a realistic, consistent plan produces NO issues (no false alarms)', $r['issues'] === [], json_encode($r['issues']));
t('...and reports what it checked and that distance was skipped (no coordinates source)', in_array('overlap', $r['checked']) && in_array('over_budget', $r['checked']) && (bool)array_filter($r['skipped'], fn($x) => str_starts_with($x, 'distance')));

$bad = ['estimated_cost' => ['accommodation' => 5000, 'transport' => 1000, 'total' => 9000], 'days' => [
    $day(1, '2026-12-01', [$s('09:00', '3 hours', 'Tour A', 'A', 'sightseeing', 500), $s('10:00', '2 hours', 'Tour B', 'B', 'sightseeing', 500), $s('08:00', '1 hour', 'Breakfast late in list', 'C', 'meal', 100)], ['daily_budget' => 400]),
    $day(3, '2026-12-05', [$s('04:00', '1 hour', 'Sunrise', 'D'), $s('23:00', '2 hours', 'Night market', 'E'), $s('10:00', '8 hours', 'X', 'F', 'activity'), $s('18:00', '6 hours', 'Y', 'G', 'activity')])]];
$r = ItineraryChecker::check($bad, ['duration' => 3, 'start_date' => '2026-12-02', 'budget' => 5000]);
$c = $codes($r);
foreach (['overlap', 'time_order', 'day_cost', 'day_numbers', 'day_dates', 'early_late', 'overloaded_day', 'day_count', 'cost_sum', 'over_budget'] as $code) t("defect detected: $code", in_array($code, $c, true));
$ov = array_values(array_filter($r['issues'], fn($i) => $i['code'] === 'overlap'))[0] ?? null;
t('overlap of 120 min is an error with the exact numbers; counts add up', $ov && $ov['severity'] === 'error' && str_contains($ov['message'], '120 min') && $r['errors'] === 1 && $r['warnings'] === count($r['issues']) - 1);
t('every issue carries severity, code and a message (day may be null)', !array_filter($r['issues'], fn($i) => !in_array($i['severity'], ['error', 'warning', 'info'], true) || $i['code'] === '' || $i['message'] === ''));
t('10-minute slop between items is tolerated', $codes(ItineraryChecker::check(['days' => [$day(1, '2026-12-01', [$s('09:00', '1 hour', 'A', 'A'), $s('09:55', '1 hour', 'B', 'B')])]])) === []);
t('accommodation entries (check-in at 02:00) are not treated as early activities', !in_array('early_late', $codes(ItineraryChecker::check(['days' => [$day(1, '2026-12-01', [$s('02:00', '8 hours', 'Sleep', 'Hotel', 'accommodation')])]])), true));
t('unparseable times/durations never crash and never invent an issue', ItineraryChecker::check(['days' => [$day(1, '2026-12-01', [['time' => 'morning', 'duration' => 'half day', 'activity' => 'x'], 'junk', ['activity' => 'y']])]])['issues'] === []);
t('empty plan is reported, not silently clean', ItineraryChecker::check([])['errors'] === 1);
t('no estimated total / no budget -> those checks are listed as skipped', (bool)array_filter(ItineraryChecker::check(['days' => [$day(1, '2026-12-01', [])]])['skipped'], fn($x) => str_starts_with($x, 'cost_sum')));

// Distance: straight-line lower bound at 120 km/h. Dhaka<->Cox's Bazar is ~ 320 km straight line.
$geo = fn(string $p) => ['dhaka' => ['lat' => 23.8103, 'lng' => 90.4125], "cox's bazar" => ['lat' => 21.4272, 'lng' => 92.0058], 'gulshan' => ['lat' => 23.7925, 'lng' => 90.4078]][mb_strtolower($p)] ?? null;
$mk = fn(string $t2) => ['days' => [$day(1, '2026-12-01', [$s('08:00', '1 hour', 'Breakfast', 'Dhaka', 'meal'), $s($t2, '2 hours', 'Beach walk', "Cox's Bazar", 'sightseeing')])]];
$r = ItineraryChecker::check($mk('10:00'), [], $geo);
t('distance: ~320 km with a 1-hour gap is flagged as impossible by road', in_array('distance', $codes($r), true) && $r['distance_pairs_checked'] === 1);
t('distance: the same trip with a 6-hour gap is NOT flagged (no travel time is ever estimated)', !in_array('distance', $codes(ItineraryChecker::check($mk('15:00'), [], $geo)), true));
t('distance: nearby places (Dhaka -> Gulshan, 15 min gap) are fine', !in_array('distance', $codes(ItineraryChecker::check(['days' => [$day(1, '2026-12-01', [$s('08:00', '1 hour', 'A', 'Dhaka'), $s('09:15', '1 hour', 'B', 'Gulshan')])]], [], $geo)), true));
$r = ItineraryChecker::check($mk('10:00'), [], fn($p) => null);
t('distance: unknown places are skipped and counted as 0 pairs checked, never guessed', !in_array('distance', $codes($r), true) && $r['distance_pairs_checked'] === 0);
t('distance: the message states the assumption instead of an ETA', str_contains(ItineraryChecker::check($mk('10:00'), [], $geo)['issues'][0]['message'] ?? '', '120 km/h'));

// cachedGeocoder against the real cache table (temporary rows, removed afterwards)
require 'backend/config.php';
$dbh->exec("DELETE FROM tbl_explore_destinations WHERE place_key IN ('zz-test-alpha','zz-test-beta')");
$ins = $dbh->prepare('INSERT INTO tbl_explore_destinations (place_key, display_name, country, lat, lng, info_json, expires_at) VALUES (?,?,?,?,?,?,?)');
$ins->execute(['zz-test-alpha', 'ZZ Test Alpha', 'XX', 23.81, 90.41, '{}', date('Y-m-d H:i:s', time() + 3600)]);
$ins->execute(['zz-test-beta', 'ZZ Test Beta', 'XX', 21.43, 92.0, '{}', date('Y-m-d H:i:s', time() + 3600)]);
$g = ItineraryChecker::cachedGeocoder($dbh);
t('cachedGeocoder: finds cached coordinates case-insensitively, null for unknown, no network used', ($g('zz test alpha')['lng'] ?? 0) == 90.41 && ($g('ZZ TEST ALPHA')['lat'] ?? 0) == 23.81 && $g('definitely not cached 12345') === null);
$r = ItineraryChecker::check(['days' => [$day(1, '2026-12-01', [$s('08:00', '1 hour', 'A', 'ZZ Test Alpha'), $s('10:00', '1 hour', 'B', 'ZZ Test Beta')])]], [], $g);
t('end to end with the DB-backed geocoder: flags the impossible hop', in_array('distance', $codes($r), true));
$dbh->exec("DELETE FROM tbl_explore_destinations WHERE place_key IN ('zz-test-alpha','zz-test-beta')");

// Planner integration: generate() attaches plan_check to a real (non-fallback) plan and never to the fallback template.
require_once 'backend/ai/AIClient.php'; require_once 'backend/ai/ItineraryPlanner.php';
$planner = new ItineraryPlanner($dbh);
$rp = new ReflectionProperty($planner, 'ai'); $rp->setAccessible(true);
$ai = new AIClient($dbh, null, new GeminiProvider('k', 'm')); $ai->setProviderChain([new GeminiProvider('k', 'm')]);
$reply = json_encode(['trip_title' => 'T', 'destination' => 'Nowhereville Test', 'days' => [['day' => 1, 'date' => '2026-12-01', 'schedule' => [['time' => '09:00', 'duration' => '3 hours', 'activity' => 'A', 'location' => 'A', 'type' => 'sightseeing'], ['time' => '10:00', 'duration' => '2 hours', 'activity' => 'B', 'location' => 'B', 'type' => 'sightseeing']]]], 'estimated_cost' => ['total' => 9000]]);
$ai->setTransport(fn($req, $p, $to) => [200, json_encode(['candidates' => [['content' => ['parts' => [['text' => $reply]]]]]]), null]);
$rp->setValue($planner, $ai);
$dbh->exec("DELETE FROM tbl_ai_usage_log WHERE user_email='plan-check-test@example.test'");
$res = $planner->generate(['destination' => 'Nowhereville Test', 'duration' => 1, 'budget' => 5000, 'travelers' => 2, 'start_date' => '2026-12-01'], 'plan-check-test@example.test', null, false);
t('planner: a real plan carries plan_check, with the overlap and the over-budget finding', isset($res['plan_check']) && in_array('overlap', array_column($res['plan_check']['issues'], 'code'), true) && in_array('over_budget', array_column($res['plan_check']['issues'], 'code'), true));
$ai->setTransport(fn($req, $p, $to) => [503, '{}', null]);
$dbh->exec("DELETE FROM tbl_ai_usage_log WHERE user_email='plan-check-test@example.test'");
$res = $planner->generate(['destination' => 'Nowhereville Test 2', 'duration' => 1, 'travelers' => 2, 'start_date' => '2026-12-01'], 'plan-check-test@example.test', null, false);
t('planner: the AI-unavailable fallback template is not dressed up with a plan_check', !empty($res['is_fallback']) && !isset($res['plan_check']));
$dbh->exec("DELETE FROM tbl_ai_usage_log WHERE user_email='plan-check-test@example.test'"); $dbh->exec("DELETE FROM tbl_ai_telemetry WHERE user_email='plan-check-test@example.test'");
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);

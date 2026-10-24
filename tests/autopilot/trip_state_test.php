<?php
/** Pure tests for backend/ai/TripState.php (no DB). Run: php tests/autopilot/trip_state_test.php */
require_once __DIR__ . '/../../backend/ai/TripState.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok ? '' : '  ' . $d) . "\n"; }

$booking = ['PackageLocation' => "Cox's Bazar", 'FromDate' => '2031-02-01', 'ToDate' => '2031-02-04', 'NoOfPersons' => 2, 'TotalCost' => 24000,
    'itinerary' => ['computed_cost' => ['total' => 8000, 'verified_from_catalogue' => 3000], 'validation' => ['mode' => 'relaxed']]];
$s = TripState::fromBooking($booking);
t('booking fields are confirmed', $s['destination']['basis'] === 'confirmed' && $s['travellers']['value'] === 2 && $s['confirmed_cost']['value'] === 24000 && $s['dates']['from'] === '2031-02-01');
t('plan cost is only ever "estimated"', $s['estimated_cost']['basis'] === 'estimated' && $s['estimated_cost']['value'] === 8000 && $s['estimated_cost']['verified_from_catalogue'] === 3000);
t('plan mode is carried as estimated', $s['mode']['value'] === 'relaxed' && $s['mode']['basis'] === 'estimated');

$s = TripState::applyMessage($s, 'keep it under 50k please, and no hiking. We are vegetarian', 1800000000);
t('budget stated in chat is recorded as stated', ($s['budget']['value'] ?? 0) === 50000 && $s['budget']['basis'] === 'stated', json_encode($s['budget']));
$texts = array_column($s['constraints'], 'text');
t('constraints are captured', in_array('no hiking', $texts, true) && in_array('vegetarian', $texts, true), json_encode($texts));
$s = TripState::applyMessage($s, 'no hiking, remember?');
t('the same constraint is not duplicated', count(array_filter(array_column($s['constraints'], 'text'), fn($x) => $x === 'no hiking')) === 1);

$s = TripState::applyMessage($s, 'what if we are 4 people?');
t('different party size is a what-if and never overwrites the confirmed one', $s['travellers']['value'] === 2 && $s['travellers']['basis'] === 'confirmed' && $s['travellers_whatif']['value'] === 4);
$bn = TripState::applyMessage(TripState::empty(), '৫০ হাজার টাকার মধ্যে');
t('Bengali budget is understood', ($bn['budget']['value'] ?? 0) === 50000, json_encode($bn['budget']));
$st = TripState::applyMessage(TripState::empty(), 'we are 3 people');
t('party size with no booking is stated, not confirmed', $st['travellers']['value'] === 3 && $st['travellers']['basis'] === 'stated');
$fam = TripState::applyMessage(TripState::empty(), 'trip with kids and a toddler');
$acc = TripState::applyMessage(TripState::empty(), 'my mother uses a wheelchair');
t('style is read from the traveller\'s words', $fam['mode']['value'] === 'family' && $acc['mode']['value'] === 'accessible');
t('a message with nothing to extract changes nothing but the timestamp', array_diff_key(TripState::applyMessage($s, 'hello there'), ['updated' => 1]) == array_diff_key($s, ['updated' => 1]));

$r = TripState::render($s);
t('render labels every value with its basis', str_contains($r, '[confirmed by booking]') && str_contains($r, '[estimated by the platform, not a quote]') && str_contains($r, '[stated]') && str_contains($r, 'NOT the booked number'));
t('render warns against presenting estimates as confirmed', str_contains($r, 'Never present an [estimated]'));
t('empty state renders nothing', TripState::render(TripState::empty()) === '');

$many = TripState::empty();
for ($i = 0; $i < 30; $i++) { $many['constraints'][] = ['text' => "c$i", 'basis' => 'stated', 'at' => '2031-01-01']; }
$many = TripState::applyMessage($many, 'no boat');
t('constraints list is capped', count($many['constraints']) <= 10);
$long = TripState::applyMessage(TripState::empty(), 'we have an allergic to ' . str_repeat('x', 400));
t('constraint text is length-limited', array_sum(array_map(fn($c) => mb_strlen($c['text']), $long['constraints'])) <= 120 * 3);

class FakeDb extends PDO { public function __construct() {} #[\ReturnTypeWillChange] public function prepare($q, $o = []) { throw new PDOException('no table'); } }
$db = new FakeDb();
t('load degrades to an empty state when the table is missing', TripState::load($db, 1, 'a@b.c') === TripState::empty());
TripState::save($db, 1, 'a@b.c', $s); t('save never throws when the table is missing', true);

echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);

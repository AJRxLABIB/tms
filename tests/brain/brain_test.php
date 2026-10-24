<?php
// TourBuddy Brain unit tests (no DB, no network). Run: php tests/brain/brain_test.php
require_once __DIR__ . '/../../backend/brain/BrainChat.php';
$fail = 0; $n = 0;
function ok(bool $c, string $m): void { global $fail, $n; $n++; if (!$c) { $fail++; echo "FAIL: $m\n"; } }

// intents
foreach (['hello' => 'greeting', 'how to reach sylhet' => 'how_to_reach', 'best time for sundarbans' => 'best_time', 'what to eat in sreemangal' => 'food', 'thanks' => 'thanks', 'weather in saint martin' => 'weather'] as $q => $i) {
    $r = BrainChat::reply($q); ok($r && $r['intent'] === $i, "intent of '$q' is $i, got " . ($r['intent'] ?? 'null'));
}
ok(BrainChat::reply('what is the price of package 5') === null, 'catalogue question is left to the catalogue');
$c = BrainChat::reply('cox bazar or saint martin?'); ok($c && $c['intent'] === 'compare', 'compare detected');
ok(BrainKnowledge::find('bandarbn', 1)[0]['id'] === 'bandarban', 'typo matches bandarban');
ok(BrainKnowledge::find('bandarban', 1)[0]['id'] !== 'sundarbans', 'bandarban is not sundarbans');

// planner invariants
foreach (['coxs-bazar' => [3, 4, 40000], 'bandarban' => [3, 4, 30000], 'sylhet' => [1, 2, 0], 'sundarbans' => [4, 2, 0], 'sonargaon' => [1, 2, 0], 'saint-martin' => [3, 2, 0]] as $d => [$days, $tr, $bud]) {
    $p = BrainPlanner::plan(['destination' => $d, 'duration' => $days, 'travelers' => $tr, 'budget' => $bud, 'start_date' => '2026-12-12', 'travel_type' => 'family']);
    ok($p !== null, "$d plans"); if (!$p) continue;
    ok(count($p['days']) === $days, "$d has $days days");
    foreach ($p['days'] as $day) {
        $t = array_map(fn($r) => $r['time'], $day['schedule']);
        $s = $t; sort($s); ok($s === $t, "$d day {$day['day']} is in time order");
        ok(max($t) <= '23:59' && min($t) >= '00:00', "$d times valid");
        $hasSight = (bool)array_filter($day['schedule'], fn($r) => in_array($r['type'], ['sightseeing', 'activity']));
        ok($hasSight || $days >= 3 || $day['day'] === 1 || $day['day'] === $days, "$d day {$day['day']} has something to do");
    }
    ok($p['estimated_cost']['grand_total'] > 0, "$d has a cost");
    if ($bud > 0) ok($p['estimated_cost']['grand_total'] <= $bud * 1.1, "$d fits budget $bud, got {$p['estimated_cost']['grand_total']}");
    ok(!empty($p['getting_there']) && !empty($p['emergency_info']['local_police']), "$d has journey and emergency info");
}
ok(BrainPlanner::plan(['destination' => 'atlantis', 'duration' => 3]) === null, 'unknown destination returns null');
$q = BrainPlanner::quote('sylhet', 3, 2); ok($q && $q['budget']['total'] < $q['mid']['total'] && $q['mid']['total'] < $q['luxury']['total'], 'quote tiers are ordered');
// conversation memory
$c = []; $r = BrainChat::reply('plan 3 days in sylhet for 2 people', $c); $c = $r['state'];
$r = BrainChat::reply('add a day', $c); ok($r && str_contains($r['reply'], '4-Day') && str_contains($r['reply'], 'Sylhet'), 'add a day refines the plan'); $c = $r['state'];
$r = BrainChat::reply('what about 4 people', $c); ok($r && str_contains($r['reply'], '4 people'), 'travellers follow-up'); $c = $r['state'];
$r = BrainChat::reply('make it cheaper', $c); ok($r && $r['state']['last_total'] > 0, 'cheaper refines');
$r = BrainChat::reply('how to reach', $c); ok($r && $r['intent'] === 'how_to_reach' && str_contains($r['reply'], 'Sylhet'), 'place remembered');
$r = BrainChat::reply('plan a trip to rome', $c); ok($r && $r['intent'] === 'unknown_place', 'unknown place is not guessed');
$r = BrainChat::reply('best time and how to reach bandarban', []); ok($r && $r['intent'] === 'multi' && str_contains($r['reply'], 'Bandarban'), 'two questions answered');
ok(count(BrainKnowledge::all()) >= 80, 'knowledge base has 80+ places');
$r = BrainChat::reply('kolkata trip cost'); ok($r && str_contains($r['reply'], 'Kolkata'), 'international cost');
$r = BrainChat::reply('safety in dubai'); ok($r && str_contains($r['reply'], 'Emergency: police'), 'international emergency numbers');
echo $fail ? "$fail of $n FAILED\n" : "All $n checks passed\n";
exit($fail ? 1 : 0);

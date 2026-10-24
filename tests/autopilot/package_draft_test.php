<?php
/** Pure tests for backend/ai/PackageDraft.php. Run: php tests/autopilot/package_draft_test.php */
require_once __DIR__ . '/../../backend/ai/PackageDraft.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok ? '' : '  ' . $d) . "\n"; }

$prefs = ['budget' => 40000, 'travelers' => 4, 'duration' => 3, 'start_date' => '2031-03-01', 'destination' => 'Sylhet', 'travel_type' => 'family'];
$good = ['package_name' => '<b>Tea Hills</b> Escape', 'tagline' => 'x', 'destination' => 'Sylhet', 'duration' => 99, 'travelers' => 1, 'start_date' => '1999-01-01',
    'pricing' => ['total' => 5, 'accommodation_cost' => 12000, 'transport_cost' => 8000, 'activities_cost' => 6000, 'food_cost' => 8000, 'miscellaneous' => 1000, 'emergency_fund' => 1000],
    'itinerary_preview' => [['day' => 1, 'title' => 'A', 'highlights' => ['x']], ['day' => 2, 'title' => 'B', 'highlights' => ['y']], ['day' => 3, 'title' => 'C', 'highlights' => []], ['day' => 4, 'title' => 'extra', 'highlights' => []]],
    'cancellation_policy' => 'whatever', 'difficulty_level' => 'Easy', 'eco_score' => 250, 'included' => ['<script>alert(1)</script>Breakfast']];
$r = PackageDraft::validate($good, $prefs); $p = $r['package'];
t('valid shape is accepted', $r['ok'] === true);
t('markup is stripped from text', $p['package_name'] === 'Tea Hills Escape' && $p['included'][0] === 'alert(1)Breakfast');
t('duration, travellers and start date come from the request, not the model', $p['duration'] === 3 && $p['travelers'] === 4 && $p['start_date'] === '2031-03-01');
t('total is the sum of the cost lines, not the model number', $p['pricing']['total'] === 36000 && $p['pricing']['per_person'] === 9000);
t('the mismatch is reported', (bool)array_filter($p['validation_notes'], fn($n) => str_contains($n, 'did not match its own cost lines')));
t('itinerary preview is trimmed to the real duration and renumbered', count($p['itinerary_preview']) === 3 && $p['itinerary_preview'][2]['day'] === 3);
t('unknown enum dropped, known one normalised', $p['cancellation_policy'] === null && $p['difficulty_level'] === 'easy');
t('eco score clamped', $p['eco_score'] === 100);
t('always a draft, never bookable, price labelled a suggestion', $p['is_draft'] === true && $p['bookable'] === false && $p['status_label'] === 'AI draft' && str_contains($p['price_basis'], 'not a quote'));
t('within budget is stated', $p['budget_check']['status'] === 'within_budget');
$over = PackageDraft::validate(['package_name' => 'X', 'pricing' => ['accommodation_cost' => 50000]], $prefs)['package'];
t('over budget is stated with the amount', $over['budget_check']['status'] === 'over_budget' && $over['budget_check']['over_by'] === 10000);
t('model total alone (no lines) is accepted but flagged', PackageDraft::validate(['package_name' => 'X', 'pricing' => ['total' => 1000]], $prefs)['package']['validation_notes'][0] !== null);
t('no price at all is rejected', PackageDraft::validate(['package_name' => 'X', 'pricing' => []], $prefs)['ok'] === false);
t('no name is rejected', PackageDraft::validate(['pricing' => ['total' => 100]], $prefs)['ok'] === false);
t('garbage types do not throw', PackageDraft::validate(['package_name' => ['a'], 'pricing' => 'x'], $prefs)['ok'] === false && PackageDraft::validate(['package_name' => 'N', 'pricing' => ['total' => 'abc', 'food_cost' => -5, 'transport_cost' => 100], 'highlights' => 'nope', 'accommodation' => 'x'], $prefs)['ok'] === true);
t('negative cost lines are treated as zero', PackageDraft::validate(['package_name' => 'N', 'pricing' => ['food_cost' => -500, 'transport_cost' => 100]], $prefs)['package']['pricing']['total'] === 100);
$ph = PackageDraft::placeholder(['budget' => 30000, 'travelers' => 2, 'duration' => 5]);
t('placeholder is honest: not AI generated, not bookable, no invented price', $ph['is_ai_generated'] === false && $ph['bookable'] === false && $ph['is_fallback'] === true && $ph['pricing']['total'] === 30000 && str_contains($ph['price_basis'], 'not a price'));
t('text() caps length and strips control characters', mb_strlen(PackageDraft::text(str_repeat('a', 900), 50)) === 50 && PackageDraft::text("a\x00b\nc") === 'a b c');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);

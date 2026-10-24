<?php
// Plans every destination for 2/3/5 days and flags anything odd.
require_once __DIR__ . '/../../backend/brain/BrainChat.php';
$bad = 0;
foreach (BrainKnowledge::all() as $id => $d) foreach ([[1, 2], [3, 2], [5, 4]] as [$days, $tr]) {
    try { $p = BrainPlanner::plan(['destination' => $d['name'], 'duration' => $days, 'travelers' => $tr, 'start_date' => '2026-12-15', 'travel_type' => 'leisure']); }
    catch (Throwable $e) { echo "$id/$days EXC " . $e->getMessage() . "\n"; $bad++; continue; }
    if (!$p) { echo "$id/$days null\n"; $bad++; continue; }
    $empty = 0; $stops = 0;
    foreach ($p['days'] as $day) { $c = count(array_filter($day['schedule'], fn($r) => in_array($r['type'], ['sightseeing', 'activity']))); $stops += $c; if (!$c) $empty++; }
    $pp = $p['estimated_cost']['grand_total'] / $tr;
    $flag = ($days >= 3 && $empty > 1) || $stops === 0 || $pp < 300 || $pp > 400000;
    if ($flag) { echo "$id/$days odd: stops=$stops emptyDays=$empty perPerson=" . (int)$pp . "\n"; $bad++; }
}
echo $bad ? "$bad odd\n" : "sweep clean\n";

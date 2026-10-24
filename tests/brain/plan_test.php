<?php
require_once __DIR__ . '/../../backend/brain/BrainPlanner.php';
$p = BrainPlanner::plan(['destination'=>"Cox's Bazar",'duration'=>3,'travelers'=>4,'budget'=>40000,'start_date'=>'2026-12-10','travel_type'=>'family','activities'=>['beach','seafood']]);
echo $p['trip_title']," total=",$p['estimated_cost']['total'],"\n",json_encode($p['estimated_cost']),"\n";
foreach($p['days'] as $d){echo "Day {$d['day']} {$d['title']}\n";foreach($d['schedule'] as $r)echo "  {$r['time']} [{$r['type']}] {$r['activity']} cost={$r['cost']}\n";}
echo $p['ai_notes'],"\n";
$p=BrainPlanner::plan(['destination'=>'bandarban','duration'=>1,'travelers'=>2,'origin'=>'Sylhet','start_date'=>'2026-11-20']);
echo $p['trip_title'],' ',$p['estimated_cost']['total'],"\n";
echo json_encode(BrainPlanner::quote('sylhet',3,2)),"\n";

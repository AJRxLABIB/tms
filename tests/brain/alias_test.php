<?php
require_once __DIR__ . '/../../backend/brain/BrainKnowledge.php';
$seen = [];
foreach (BrainKnowledge::all() as $id => $d) foreach (array_merge([$d['name']], $d['aliases']) as $a) { $k = BrainText::norm($a); if (isset($seen[$k]) && $seen[$k] !== $id) echo "ALIAS COLLISION '$k': {$seen[$k]} vs $id\n"; $seen[$k] = $id; }
echo count(BrainKnowledge::all()), " destinations\n";
$t0 = microtime(true); BrainKnowledge::setDataDir(null);
foreach (['kolkata trip','jaflong','jaflong ratargul','goa','bangkok or phuket','sundarbans','dubai','mecca umrah','kathmandu','coxs bazar','bali','singapor','tokyo','sri lanka','hanoi'] as $q) { $f = BrainKnowledge::find($q, 2); echo str_pad($q, 20), implode(', ', array_map(fn($x)=>$x['id'], $f)), "\n"; }

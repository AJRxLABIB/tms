<?php
require_once __DIR__ . '/../../backend/brain/BrainChat.php';
foreach (["hello","3 day trip to bandarban for 4 people under 30k","how to reach sylhet","best time for sundarbans","cox bazar or saint martin?","sylhet trip cost for 2","what to eat in sreemangal","where should I go in winter","thanks","what is the price of package 5","safety in sajek","weather in saint martin","who are you"] as $m) {
 $r = BrainChat::reply($m); echo "=== $m\n"; echo $r ? "[{$r['intent']}] ".mb_substr($r['reply'],0,420)."\n" : "NULL\n"; }

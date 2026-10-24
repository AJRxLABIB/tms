<?php
require_once __DIR__ . '/../../backend/brain/BrainChat.php';
$ctx = [];
foreach (["plan 3 days in sylhet for 2 people","make it cheaper","add a day","what about 4 people","how to reach","and cox's bazar?","best time and how to reach bandarban","plan a trip to rome","আমি সিলেট যেতে চাই কিভাবে যাবো","more food","in june","hi"] as $m) {
  $r = BrainChat::reply($m, $ctx); echo "=== $m\n";
  if ($r) { $ctx = $r['state']; echo "[{$r['intent']}] ".mb_substr(str_replace("\n",' | ',$r['reply']),0,260)."\n"; } else echo "NULL\n";
}

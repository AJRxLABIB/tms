<?php
$base = dirname(__DIR__, 2);
require $base . '/backend/brain/BrainKnowledge.php'; require $base . '/backend/brain/BrainIntent.php'; require $base . '/backend/brain/BrainEntities.php';
foreach (["how to go to coxs bazar from dhaka","best time for sylhet","3 day trip to bandarban for 4 people under 30k","kivabe jabo sajek","ki ki dekhbo srimangal","weather in saint martin","hello","asdf qwerty","কক্সবাজার যাওয়ার উপায়","cox bazaar or saint martin?","suggest honeymoon place in winter","trip cost kuakata 3 days","sylhet er khabar"] as $q) {
  $i = BrainIntent::classify($q); $e = BrainEntities::parse($q);
  echo str_pad($q, 52), ' => ', $i['intent'], ' ', $i['confidence'], ' | ', json_encode($e, JSON_UNESCAPED_UNICODE), "\n";
}

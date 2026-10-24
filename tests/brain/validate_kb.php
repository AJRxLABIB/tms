<?php
// Validates data/brain/destinations/*.json. Usage: php tests/brain/validate_kb.php [file ...]
require_once __DIR__ . '/../../backend/brain/BrainKbValidator.php';
$files = array_slice($argv ?? [], 1) ?: glob(__DIR__ . '/../../data/brain/destinations/*.json');
$bad = 0; $ids = [];
foreach ($files as $f) {
    $d = json_decode((string)file_get_contents($f), true); $id = basename($f, '.json');
    $p = BrainKbValidator::check($d, $id);
    if (isset($ids[$id])) $p[] = 'duplicate id'; $ids[$id] = 1;
    if ($p) { echo "$id: " . implode('; ', $p) . "\n"; $bad++; }
}
echo $bad ? "$bad file(s) invalid\n" : count($files) . " file(s) valid\n";
exit($bad ? 1 : 0);

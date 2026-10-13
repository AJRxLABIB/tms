<?php
/**
 * Remove the generated sample tour packages (data/packages_seed.json).
 *   php tools/remove-sample-data.php           # dry run: lists what would go
 *   php tools/remove-sample-data.php --apply   # deletes them
 *
 * Safety: only packages whose name is in the seed file are considered, and any
 * package that has at least one booking, review or wishlist/cart reference to it
 * is KEPT, so customer history is never orphaned (wishlist rows are not checked).
 * Back up the database before using --apply. Never web-reachable.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/..');
require 'backend/config.php';
$apply = in_array('--apply', $argv, true);
$seed = json_decode((string)file_get_contents('data/packages_seed.json'), true) ?: [];
$names = [];
foreach ($seed as $r) { if (!empty($r['PackageName'])) $names[$r['PackageName']] = true; }
$rows = $dbh->query("SELECT PackageId, PackageName FROM tbltourpackages")->fetchAll(PDO::FETCH_ASSOC);
$bk = $dbh->prepare("SELECT (SELECT COUNT(*) FROM tblbooking WHERE PackageId = :a) + (SELECT COUNT(*) FROM tblreviews WHERE PackageId = :b)");
$del = $dbh->prepare("DELETE FROM tbltourpackages WHERE PackageId = :id");
$remove = 0; $kept = 0;
foreach ($rows as $r) {
    if (!isset($names[$r['PackageName']])) continue;
    $bk->execute([':a' => $r['PackageId'], ':b' => $r['PackageId']]);
    if ((int)$bk->fetchColumn() > 0) { $kept++; echo "KEEP   #{$r['PackageId']} {$r['PackageName']} (has bookings/reviews)\n"; continue; }
    $remove++;
    if ($apply) { $del->execute([':id' => $r['PackageId']]); }
    elseif ($remove <= 15) echo "WOULD DELETE #{$r['PackageId']} {$r['PackageName']}\n";
}
echo ($apply ? "Deleted" : "Would delete") . " $remove sample package(s); kept $kept with history.\n";
if (!$apply) echo "Dry run only. Re-run with --apply after backing up the database.\n";
echo "Sample packages are no longer re-created unless TMS_SEED_SAMPLE_PACKAGES=1 is set in .env.\n";

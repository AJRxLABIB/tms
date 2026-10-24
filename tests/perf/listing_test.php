<?php
/**
 * Listing performance + correctness against the REAL sandbox DB (temporary packages 9401-9406 and bookings are created and removed).
 *  1. tms_dynamic_price_batch() returns EXACTLY what tms_dynamic_price() returns, package by package (capacity-tracked, untracked,
 *     sold-out, free, demand tiers, cancelled bookings ignored, old bookings ignored).
 *  2. It needs 2 queries for the whole page instead of ~4 per package (counted with the server's own Questions counter).
 *  3. The composite indexes exist.
 *   php tests/perf/listing_test.php [base-url]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 90) . $d . "\n"; }
$clean = function () use ($dbh) { $dbh->exec('DELETE FROM tblbooking WHERE PackageId BETWEEN 9401 AND 9406'); $dbh->exec('DELETE FROM tbltourpackages WHERE PackageId BETWEEN 9401 AND 9406'); };
$clean();
$mk = $dbh->prepare("INSERT INTO tbltourpackages (PackageId, PackageName, PackageType, PackageLocation, Country, PackagePrice, PackageDuration, PackageFetures, PackageDetails, PackageImage, Status, ApprovalStatus, SeatsAvailable) VALUES (?,?,'Tour','L','C',?, '2 Days','f','d','x.jpg',1,'approved',?)");
$mk->execute([9401, 'Perf untracked', 10000, null]);
$mk->execute([9402, 'Perf cap10', 20000, 10]);
$mk->execute([9403, 'Perf soldout', 15000, 4]);
$mk->execute([9404, 'Perf free', 0, null]);
$mk->execute([9405, 'Perf demand', 8000, 100]);
$mk->execute([9406, 'Perf cap0', 9000, 0]);
$uem = (string)$dbh->query('SELECT EmailId FROM tblusers LIMIT 1')->fetchColumn();
if ($uem === '') { echo "SKIP: no user row for the booking foreign key\n"; $clean(); exit(0); }
$ib = $dbh->prepare("INSERT INTO tblbooking (PackageId, UserEmail, FromDate, ToDate, status, NoOfPersons, RegDate) VALUES (?,?,'2030-01-01','2030-01-03',?,?,?)");
$now = date('Y-m-d H:i:s'); $old = date('Y-m-d H:i:s', strtotime('-60 days'));
$ib->execute([9402, $uem, 1, 5, $now]);               // 5 of 10 booked -> fill 0.5
$ib->execute([9402, $uem, 2, 4, $now]);               // cancelled: ignored
$ib->execute([9403, $uem, 0, 4, $now]);               // sold out
for ($i = 0; $i < 6; $i++) $ib->execute([9405, $uem, $i % 2, 1, $now]);   // 6 recent -> demand tier
$ib->execute([9405, $uem, 1, 1, $old]);               // old booking: seats yes, demand no

$rows = $dbh->query('SELECT PackageId, PackagePrice, SeatsAvailable FROM tbltourpackages WHERE PackageId BETWEEN 9401 AND 9406 ORDER BY PackageId')->fetchAll(PDO::FETCH_ASSOC);
foreach ([null, date('Y-m-d', strtotime('+3 days')), date('Y-m-d', strtotime('+200 days'))] as $dep) {
    $batch = tms_dynamic_price_batch($dbh, $rows, $dep);
    $same = true; $why = '';
    foreach ($rows as $r) {
        $one = tms_dynamic_price($dbh, (int)$r['PackageId'], (float)$r['PackagePrice'], $dep);
        if (json_encode($one) !== json_encode($batch[(int)$r['PackageId']])) { $same = false; $why .= ' ' . $r['PackageId']; }
    }
    t('batch == single for every package, departure ' . ($dep ?? 'none'), $same, $why);
}
$b = tms_dynamic_price_batch($dbh, $rows);
t('sold-out package: 0 seats left and the +18% scarcity price applied', $b[9403]['remaining_seats'] === 0 && $b[9403]['seats_pct'] === 18);
t('capacity 0 treated as tracked with 0 seats (matches single path), no division by zero', $b[9406]['remaining_seats'] === 0 && $b[9406]['seats_pct'] === 0);
t('cancelled bookings do not use seats (9402: 10 - 5 = 5)', $b[9402]['remaining_seats'] === 5);
t('demand counts only the last 30 days (6 recent bookings -> +6%)', $b[9405]['demand_pct'] === 6 && $b[9405]['remaining_seats'] === 100 - 7);
t('free package: price 0, no signals', $b[9404]['final'] === 0 && $b[9404]['label'] === '');
t('untracked capacity: remaining_seats is null', $b[9401]['remaining_seats'] === null);

$q = function () use ($dbh) { return (int)$dbh->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1]; };
$q0 = $q(); tms_dynamic_price_batch($dbh, $rows); $batchQ = $q() - $q0 - 1;
$q0 = $q(); foreach ($rows as $r) tms_dynamic_price($dbh, (int)$r['PackageId'], (float)$r['PackagePrice']); $singleQ = $q() - $q0 - 1;
t('queries for 6 packages: batch uses 2', $batchQ === 2, "batch=$batchQ single-path=$singleQ");
t('single path needs at least 3x more queries (this is what the listing used to do per card)', $singleQ >= 3 * $batchQ);

$idx = $dbh->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ('idx_booking_pkg_status_reg','idx_pkg_status_price')")->fetchAll(PDO::FETCH_COLUMN);
t('composite indexes created by the migration', count(array_unique($idx)) === 2, implode(',', $idx));
$plan = $dbh->query("EXPLAIN SELECT COUNT(*) FROM tblbooking WHERE PackageId=9405 AND status IN (0,1) AND RegDate >= (NOW() - INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC);
t('demand query uses an index (not a full scan)', ($plan['key'] ?? '') !== '' && ($plan['type'] ?? '') !== 'ALL', 'key=' . ($plan['key'] ?? 'none'));

$base = rtrim($argv[1] ?? '', '/');
if ($base !== '') {
    $q0 = $q(); $t0 = microtime(true);
    $html = (string)file_get_contents($base . '/package-list.php');
    $ms = (int)round((microtime(true) - $t0) * 1000); $pageQ = $q() - $q0;
    t('package-list.php renders (HTTP) and shows prices', str_contains($html, 'room-right') && !str_contains($html, 'Fatal error'), "queries=$pageQ time={$ms}ms");
}
$clean();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

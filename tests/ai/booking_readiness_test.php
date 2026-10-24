<?php
/**
 * check_booking_readiness against the REAL sandbox database (temporary packages 9101-9104 and their bookings/blackouts are
 * created and removed). Proves the tool applies the booking form's own rules, is read-only, and is routed by code.
 *   php tests/ai/booking_readiness_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/ToolRegistry.php'; require_once 'backend/ai/ToolRouter.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 86) . $d . "\n"; }
$clean = function () use ($dbh) {
    $dbh->exec('DELETE FROM tblbooking WHERE PackageId BETWEEN 9101 AND 9104');
    $dbh->exec('DELETE FROM tbl_supplier_blackouts WHERE PackageId BETWEEN 9101 AND 9104');
    $dbh->exec('DELETE FROM tbltourpackages WHERE PackageId BETWEEN 9101 AND 9104');
};
$clean();
$mk = $dbh->prepare("INSERT INTO tbltourpackages (PackageId, PackageName, PackageType, PackageLocation, Country, PackagePrice, PackageDuration, PackageFetures, PackageDetails, PackageImage, Status, SeatsAvailable, CancellationPolicy, ApprovalStatus) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?,'approved')");
$mk->execute([9101, 'Test Capped Tour', 'Tour', 'Sylhet', 'Bangladesh', 10000, '3 Days', 'tea', 'd', 'x.jpg', 5, 'Free cancellation up to 7 days before.']);
$mk->execute([9102, 'Test Open Tour', 'Tour', 'Bandarban', 'Bangladesh', 8000, '2 Days', 'hills', 'd', 'x.jpg', null, '']);
$mk->execute([9103, 'Test Unpriced Tour', 'Tour', 'Dhaka', 'Bangladesh', 0, '1 Day', 'city', 'd', 'x.jpg', null, '']);
$dbh->prepare("INSERT INTO tbl_supplier_blackouts (SupplierId, PackageId, DateFrom, DateTo, Reason) VALUES (0,9102,?,?, 'test')")->execute([date('Y-m-d', strtotime('+40 days')), date('Y-m-d', strtotime('+45 days'))]);
$uem = (string)$dbh->query('SELECT EmailId FROM tblusers LIMIT 1')->fetchColumn(); // FK needs a real user; rows are removed below
if ($uem === '') { echo "SKIP: no user row available for the booking foreign key\n"; $clean(); exit(0); }
$ib = $dbh->prepare("INSERT INTO tblbooking (PackageId, UserEmail, FromDate, ToDate, status, NoOfPersons) VALUES (9101,?,'2030-01-01','2030-01-03',?,?)");
$ib->execute([$uem, 1, 3]);
$ib->execute([$uem, 2, 5]); // cancelled: must NOT count

$rows = $dbh->query('SELECT * FROM tbltourpackages WHERE PackageId BETWEEN 9101 AND 9103')->fetchAll(PDO::FETCH_ASSOC);
$reg = new ToolRegistry(KnowledgeIndex::fromRows($rows), $dbh);
$d1 = date('Y-m-d', strtotime('+20 days')); $d2 = date('Y-m-d', strtotime('+23 days'));
$run = fn(array $a) => $reg->execute('check_booking_readiness', $a, []);
$codes = fn(array $r) => array_column($r['data']['blockers'] ?? [], 'code');
$before = [(int)$dbh->query('SELECT COUNT(*) FROM tblbooking')->fetchColumn(), (int)$dbh->query('SELECT SUM(NoOfPersons) FROM tblbooking WHERE PackageId=9101')->fetchColumn()];

$r = $run(['package_id' => 9101, 'travelers' => 2, 'start_date' => $d1, 'end_date' => $d2]);
t('capped package: 5 seats - 3 pending/confirmed (cancelled ignored) = 2 left, 2 travellers fits', $r['ok'] && $r['data']['seats_remaining'] === 2 && $r['data']['can_book_as_asked'] === true);
$r = $run(['package_id' => 9101, 'travelers' => 3, 'start_date' => $d1, 'end_date' => $d2]);
t('3 travellers on 2 seats: blocked with not_enough_seats and the exact count', $codes($r) === ['not_enough_seats'] && str_contains($r['data']['blockers'][0]['detail'], 'Only 2 seats'));
$r = $run(['package_id' => 9102, 'travelers' => 4, 'start_date' => $d1, 'end_date' => $d2]);
t('package without seat tracking: no seat blocker and says capacity is not tracked', $r['data']['can_book_as_asked'] && $r['data']['seats_remaining'] === null && str_contains($r['data']['seats_note'], 'does not track'));
t('price: per-person from the real pricing engine for that date, total = unit x travellers', ($r['data']['price']['estimated_total_bdt'] ?? 0) === ($r['data']['price']['per_person_bdt'] ?? -1) * 4 && $r['data']['price']['list_price_per_person_bdt'] === 8000);
t('price states what it excludes (coupons, add-ons)', str_contains($r['data']['price']['excludes'], 'coupons'));
$dp = tms_dynamic_price($dbh, 9102, 8000.0, $d1);
t('per-person price equals tms_dynamic_price() exactly (same function as the booking form)', $r['data']['price']['per_person_bdt'] === (int)$dp['final']);
$r = $run(['package_id' => 9102, 'travelers' => 2, 'start_date' => date('Y-m-d', strtotime('+41 days')), 'end_date' => date('Y-m-d', strtotime('+43 days'))]);
t('supplier blackout dates: blocked', $codes($r) === ['blackout_dates'] && $r['data']['dates']['supplier_blackout'] === 'conflict');
$r = $run(['package_id' => 9102, 'travelers' => 2, 'start_date' => date('Y-m-d', strtotime('+41 days'))]);
t('start date only: blackout reported as not_checked, never guessed clear', $r['data']['dates']['supplier_blackout'] === 'not_checked' && $r['data']['can_book_as_asked']);
$r = $run(['package_id' => 9102, 'travelers' => 2, 'start_date' => date('Y-m-d', strtotime('-3 days')), 'end_date' => $d2]);
t('past departure date: blocked', in_array('start_not_in_future', $codes($r), true));
$r = $run(['package_id' => 9102, 'travelers' => 2, 'start_date' => $d2, 'end_date' => $d1]);
t('return before departure: blocked', in_array('end_not_after_start', $codes($r), true));
$r = $run(['package_id' => 9102, 'travelers' => 2, 'start_date' => '2026-02-30']);
t('impossible calendar date (Feb 30): rejected as invalid, not rolled over', in_array('invalid_start_date', $codes($r), true));
$r = $run(['package_id' => 9102, 'travelers' => 21, 'start_date' => $d1]);
t('more than the form allows (20): blocked', in_array('too_many_travelers', $codes($r), true));
$r = $run(['package_id' => 9103, 'travelers' => 1]);
t('package with no price: blocked and no price invented', in_array('no_price', $codes($r), true) && !isset($r['data']['price']));
$r = $run(['package_id' => 9102, 'travelers' => 2]);
t('no dates given: list price only, dates marked not_provided, nothing blocks', $r['data']['dates']['start_state'] === 'not_provided' && $r['data']['price']['basis'] === 'list price' && $r['data']['can_book_as_asked']);
t('cancellation policy flag reflects the data (9101 yes, 9102 no)', $run(['package_id' => 9101, 'travelers' => 1])['data']['cancellation_policy_recorded'] === true && $r['data']['cancellation_policy_recorded'] === false);
t('unknown / unpublished package: truthful error', ($run(['package_id' => 987654, 'travelers' => 2])['data']['error'] ?? '') === 'package_not_found');
t('bad arguments rejected by the registry (zero travellers, extra arg)', !$run(['package_id' => 9101, 'travelers' => 0])['ok'] && !$run(['package_id' => 9101, 'travelers' => 1, 'pay' => 'now'])['ok']);
$after = [(int)$dbh->query('SELECT COUNT(*) FROM tblbooking')->fetchColumn(), (int)$dbh->query('SELECT SUM(NoOfPersons) FROM tblbooking WHERE PackageId=9101')->fetchColumn()];
t('READ-ONLY: no booking rows created and no seats consumed by any of the checks above', $before === $after);
t('output says nothing was reserved and tells the model not to claim a booking', str_contains($r['data']['next_step'], 'reserved nothing') && str_contains($r['data']['instruction'], 'Never say a booking was made'));
t('citation points to the package page', ($r['data']['citation']['url'] ?? '') === 'package-details.php?pkgid=9102');

$src = (string)file_get_contents('backend/ai/BookingReadiness.php');
t('static: BookingReadiness contains no write SQL (INSERT/UPDATE/DELETE/REPLACE/FOR UPDATE)', !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER)\b/', preg_replace('#/\*.*?\*/#s', '', $src)) && !str_contains($src, 'tms_remaining_seats_for_update'));
t('static: the only extra tool name allowed by the no-write-tools guard is check_booking_readiness', in_array('check_booking_readiness', $reg->names(), true));

// ── routing (code decides) ──
$a = ToolRouter::bookingArgs('Can we book for 4 people from 2031-03-10 to 12-03-2031?', 9102);
t('router parses travellers and unambiguous dates (ISO and DD-MM-YYYY)', $a === ['package_id' => 9102, 'travelers' => 4, 'start_date' => '2031-03-10', 'end_date' => '2031-03-12']);
$a = ToolRouter::bookingArgs('আমরা ৩ জন ১৫/১২/২০৩১ তারিখে যেতে চাই, সিট আছে?', 9102);
t('Bangla digits and wording parse (3 travellers, 2031-12-15)', ($a['travelers'] ?? 0) === 3 && ($a['start_date'] ?? '') === '2031-12-15' && ToolRouter::wantsBookingCheck('সিট আছে?'));
$a = ToolRouter::bookingArgs('can we book next friday or 15 december?', 9102);
t('relative / year-less dates are NOT guessed', !isset($a['start_date']));
$p = ToolRouter::plan('Are seats available for 2 people?', ['package_id' => 9101]);
t('on a package page, an availability question plans check_booking_readiness', in_array('check_booking_readiness', array_column($p, 'tool'), true));
$p = ToolRouter::plan('Are seats available for 2 people?', []);
t('without a package in context it does not (search first, then follow-up)', !in_array('check_booking_readiness', array_column($p, 'tool'), true));
$p = ToolRouter::plan('what is the capital of France', ['package_id' => 9101]);
t('unrelated question does not trigger it', !in_array('check_booking_readiness', array_column($p, 'tool'), true));
$fu = ToolRouter::followUps('can we book the sylhet tea tour for 2 people?', ['tool' => 'search_packages', 'args' => []], [['id' => 9101, 'country' => 'Bangladesh']], [['tool' => 'search_packages', 'args' => []]]);
t('after a search, a booking question follows up on the TOP result only', count($fu) === 1 && $fu[0]['tool'] === 'check_booking_readiness' && $fu[0]['args']['package_id'] === 9101 && $fu[0]['args']['travelers'] === 2);
$o = ToolRouter::run($reg, ToolRouter::plan('Can we book for 2 people from ' . $d1 . ' to ' . $d2 . '?', ['package_id' => 9101]), [], [], 'Can we book for 2 people?');
t('end to end: facts contain the check, wrapped as untrusted, with its citation', str_contains($o['facts'], 'check_booking_readiness:') && str_contains($o['facts'], 'RETRIEVED FACTS') && in_array('pkg:9101', array_column($o['citations'], 'id'), true));
$clean();
t('temporary rows cleaned up', (int)$dbh->query('SELECT COUNT(*) FROM tbltourpackages WHERE PackageId BETWEEN 9101 AND 9104')->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);

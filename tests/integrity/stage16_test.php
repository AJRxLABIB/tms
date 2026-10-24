<?php
/**
 * Stage 16 regression: booking / money integrity. Loyalty award+reversal, Book Now double-submit and last-seat race,
 * checkout parallel submit, payments only for live unpaid bookings, retired apply-coupon endpoint.
 * Temporary rows (…@s16-test.example.test, packages 9301-9302) are created and removed.
 *     php tests/integrity/stage16_test.php [base-url]     (site must be served, default :8000)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/payments/PaymentService.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 90) . $d . "\n"; }
$A = 'a@s16-test.example.test'; $B = 'b@s16-test.example.test'; $C = 'c@s16-test.example.test'; $PW = 'S16-test-pass-1!';
$clean = function () use ($dbh) {
    $dbh->exec("DELETE FROM tblbooking WHERE UserEmail LIKE '%@s16-test.example.test'");
    $dbh->exec("DELETE FROM tblcart WHERE UserEmail LIKE '%@s16-test.example.test'");
    foreach (['tblloyalty_log', 'tblloyalty'] as $lt) { try { $dbh->exec("DELETE FROM $lt WHERE UserEmail LIKE '%@s16-test.example.test'"); } catch (Throwable $e) {} }
    try { $dbh->exec("DELETE FROM tblpayments WHERE BookingId NOT IN (SELECT BookingId FROM tblbooking)"); } catch (Throwable $e) {}
    $dbh->exec("DELETE FROM tbltourpackages WHERE PackageId BETWEEN 9301 AND 9302");
    $dbh->exec("DELETE FROM tblusers WHERE EmailId LIKE '%@s16-test.example.test'");
};
$clean();
$h = password_hash($PW, PASSWORD_BCRYPT);
$insU = $dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES (?,?,?,?)');
foreach ([[$A, 'A S16'], [$B, 'B S16'], [$C, 'C S16']] as [$e, $n]) $insU->execute([$n, '01700000000', $e, $h]);
$mk = $dbh->prepare("INSERT INTO tbltourpackages (PackageId, PackageName, PackageType, PackageLocation, Country, PackagePrice, PackageDuration, PackageFetures, PackageDetails, PackageImage, Status, ApprovalStatus, SeatsAvailable) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
$mk->execute([9301, 'S16 Last Seat Tour', 'Tour', 'Sylhet', 'Bangladesh', 10000, '3 Days', 'f', 'd', 'x.jpg', 1, 'approved', 1]);
$mk->execute([9302, 'S16 Open Tour', 'Tour', 'Sylhet', 'Bangladesh', 10000, '3 Days', 'f', 'd', 'x.jpg', 1, 'approved', null]);
$d1 = date('Y-m-d', strtotime('+40 days')); $d2 = date('Y-m-d', strtotime('+43 days'));
$cnt = fn(string $sql) => (int)$dbh->query($sql)->fetchColumn();
$pts = fn(string $e) => (int)$dbh->query("SELECT COALESCE((SELECT Points FROM tblloyalty WHERE UserEmail='$e'),0)")->fetchColumn();

// ── 1. loyalty helpers ──
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost) VALUES (9302,?,?,?,1,1,10000)")->execute([$A, $d1, $d2]); $b1 = (int)$dbh->lastInsertId();
t('loyalty: award credits 100 points', tms_loyalty_award_booking($dbh, $A, $b1) === true && $pts($A) === 100);
t('loyalty: awarding the same booking again credits nothing (idempotent)', tms_loyalty_award_booking($dbh, $A, $b1) === false && $pts($A) === 100);
t('loyalty: reversal takes back exactly the 100 awarded', tms_loyalty_reverse_booking($dbh, $b1) === 100 && $pts($A) === 0);
t('loyalty: reversal happens once only', tms_loyalty_reverse_booking($dbh, $b1) === 0 && $pts($A) === 0 && $cnt("SELECT COUNT(*) FROM tblloyalty_log WHERE UserEmail='$A' AND Description LIKE '%cancelled - points reversed'") === 1);
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost) VALUES (9302,?,?,?,1,1,10000)")->execute([$A, $d1, $d2]); $b2 = (int)$dbh->lastInsertId();
tms_loyalty_award_booking($dbh, $A, $b2); $dbh->exec("UPDATE tblloyalty SET Points=Points-100 WHERE UserEmail='$A'");   // customer already spent them
tms_loyalty_reverse_booking($dbh, $b2);
t('loyalty: points already spent -> balance goes negative (no earn-spend-cancel farming)', $pts($A) === -100);
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost) VALUES (9302,?,?,?,1,1,10000)")->execute([$B, $d1, $d2]); $b3 = (int)$dbh->lastInsertId();
t('loyalty: a booking with no award record is untouched by reversal', tms_loyalty_reverse_booking($dbh, $b3) === 0 && $pts($B) === 0);
t('loyalty: unknown booking id is a harmless no-op', tms_loyalty_reverse_booking($dbh, 99999999) === 0);

// ── HTTP helpers ──
$jars = [];
$sess = function (string $email) use ($base, $PW, &$jars) {
    $jar = tempnam(sys_get_temp_dir(), 's16'); $jars[] = $jar;
    $c = function ($u, $p = null) use ($jar) { $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40]); if ($p !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p)); } $b = (string)curl_exec($ch); curl_close($ch); return $b; };
    preg_match('/name="csrf_token" value="([^"]+)"/', $c("$base/login.php"), $m);
    $c("$base/login.php", ['EmailId' => $email, 'Password' => $PW, 'csrf_token' => $m[1] ?? '']);
    return [$jar, $c];
};
$tokens = function ($html) { preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m); preg_match('/name="booking_nonce" value="([^"]+)"/', $html, $n); return [$m[1] ?? '', $n[1] ?? '']; };
$postCh = function ($jar, string $url, array $p) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($p), CURLOPT_TIMEOUT => 40]); return $ch; };
$multi = function (array $chs) { $mh = curl_multi_init(); foreach ($chs as $x) curl_multi_add_handle($mh, $x); do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0); $out = []; foreach ($chs as $x) { $out[] = (string)curl_multi_getcontent($x); curl_multi_remove_handle($mh, $x); } curl_multi_close($mh); return $out; };

// ── 2. Book Now: nonce ──
[$jA, $cA] = $sess($A);
$form = fn($tok, $non, $extra = []) => array_merge(['csrf_token' => $tok, 'booking_nonce' => $non, 'fromdate' => $d1, 'todate' => $d2, 'persons' => 1, 'mobile' => '01712345678', 'submit2' => '1'], $extra);
$pg = $cA("$base/package-details.php?pkgid=9302"); [$tok, $non] = $tokens($pg);
t('Book Now: the form carries a one-time booking_nonce', $non !== '' && $tok !== '');
$n0 = $cnt("SELECT COUNT(*) FROM tblbooking WHERE UserEmail='$A' AND PackageId=9302");
$cA("$base/package-details.php?pkgid=9302", $form($tok, ''));
t('Book Now: a POST with no nonce creates nothing', $cnt("SELECT COUNT(*) FROM tblbooking WHERE UserEmail='$A' AND PackageId=9302") === $n0);
$cA("$base/package-details.php?pkgid=9302", $form($tok, $non));
t('Book Now: a valid nonce creates exactly one booking', $cnt("SELECT COUNT(*) FROM tblbooking WHERE UserEmail='$A' AND PackageId=9302") === $n0 + 1);
$cA("$base/package-details.php?pkgid=9302", $form($tok, $non));
t('Book Now: replaying the same nonce (reload / double click) creates nothing more', $cnt("SELECT COUNT(*) FROM tblbooking WHERE UserEmail='$A' AND PackageId=9302") === $n0 + 1);
$award = $cnt("SELECT COUNT(*) FROM tblloyalty_log WHERE UserEmail='$A' AND Description='Booking #BK" . (int)$dbh->query("SELECT MAX(BookingId) FROM tblbooking WHERE UserEmail='$A'")->fetchColumn() . " confirmed'");
t('Book Now: the new booking earned loyalty points exactly once', $award === 1);

// ── 3. cancel via the customer page reverses loyalty, once ──
$bk = (int)$dbh->query("SELECT MAX(BookingId) FROM tblbooking WHERE UserEmail='$A'")->fetchColumn();
$before = $pts($A);
$hist = $cA("$base/tour-history.php"); [$tokH] = $tokens($hist);
$c1 = $postCh($jA, "$base/tour-history.php", ['cancel_booking' => '1', 'bkid' => $bk, 'csrf_token' => $tokH]);
$c2 = $postCh($jA, "$base/tour-history.php", ['cancel_booking' => '1', 'bkid' => $bk, 'csrf_token' => $tokH]);
$multi([$c1, $c2]); curl_close($c1); curl_close($c2);
t('cancel: booking is cancelled', $cnt("SELECT status FROM tblbooking WHERE BookingId=$bk") === 2);
t('cancel: two parallel cancels reverse the 100 points exactly once', $pts($A) === $before - 100, "before=$before after={$pts($A)}");
t('cancel: one reversal row in the history', $cnt("SELECT COUNT(*) FROM tblloyalty_log WHERE UserEmail='$A' AND Description='Booking #BK$bk cancelled - points reversed'") === 1);

// ── 4. last-seat race ──
[$jB, $cB] = $sess($B); [$jC, $cC] = $sess($C);
[$tB, $nB] = $tokens($cB("$base/package-details.php?pkgid=9301")); [$tC, $nC] = $tokens($cC("$base/package-details.php?pkgid=9301"));
$multi([$postCh($jB, "$base/package-details.php?pkgid=9301", $form($tB, $nB)), $postCh($jC, "$base/package-details.php?pkgid=9301", $form($tC, $nC))]);
t('last-seat RACE: two users book the only seat at once -> exactly ONE booking', $cnt("SELECT COUNT(*) FROM tblbooking WHERE PackageId=9301 AND status<>2") === 1);
t('last-seat RACE: the loser got no loyalty points and no stray coupon/booking', $cnt("SELECT COUNT(*) FROM tblloyalty_log WHERE Description LIKE 'Booking #BK%' AND UserEmail IN ('$B','$C')") === 1);

// ── 5. checkout parallel submit ──
$dbh->exec("DELETE FROM tblbooking WHERE PackageId=9302 AND UserEmail='$B'");
$dbh->prepare("INSERT INTO tblcart (UserEmail, PackageId, FromDate, ToDate, NoOfPersons) VALUES (?,9302,?,?,1)")->execute([$B, $d1, $d2]);
[$tCo] = $tokens($cB("$base/checkout.php"));
$co = fn() => $postCh($jB, "$base/checkout.php", ['confirm_checkout' => '1', 'accept_terms' => '1', 'mobile' => '01712345678', 'csrf_token' => $tCo]);
$multi([$co(), $co(), $co()]);
t('checkout RACE: three parallel submits of one cart -> exactly ONE booking, cart emptied', $cnt("SELECT COUNT(*) FROM tblbooking WHERE UserEmail='$B' AND PackageId=9302") === 1 && $cnt("SELECT COUNT(*) FROM tblcart WHERE UserEmail='$B'") === 0);

// ── 6. payments only for live unpaid bookings ──
$ps = new PaymentService($dbh);
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost,PaymentStatus) VALUES (9302,?,?,?,2,1,10000,'unpaid')")->execute([$C, $d1, $d2]); $bc = (int)$dbh->lastInsertId();
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost,PaymentStatus) VALUES (9302,?,?,?,1,1,10000,'paid')")->execute([$C, $d1, $d2]); $bp = (int)$dbh->lastInsertId();
$r = function (int $id) use ($ps) { try { $ps->createPendingPayment($id, 10000.0); return 'ok'; } catch (DomainException $e) { return $e->getMessage(); } catch (Throwable $e) { return 'other:' . get_class($e); } };
t('payments: a cancelled booking cannot be paid', $r($bc) === 'booking_cancelled');
t('payments: an already-paid booking cannot be paid again', $r($bp) === 'booking_already_paid');
t('payments: a missing booking is refused', $r(99999999) === 'booking_not_found');

// ── 7. retired endpoint ──
$ch = curl_init("$base/apply-coupon.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'code=WELCOME10']); curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
t('apply-coupon.php answers 410 Gone', $code === 410, "code=$code");

foreach ($jars as $j) @unlink($j);
$clean();
t('temporary rows cleaned up', $cnt("SELECT COUNT(*) FROM tblusers WHERE EmailId LIKE '%@s16-test.example.test'") === 0 && $cnt("SELECT COUNT(*) FROM tbltourpackages WHERE PackageId BETWEEN 9301 AND 9302") === 0);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);

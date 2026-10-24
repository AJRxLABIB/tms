<?php
/**
 * Payment-flow tests against a REAL database (uses your configured DB; creates
 * and then deletes its own rows tagged pay-test@example.test). CLI only.
 *   php tests/payments/run.php
 * Covers: sandbox gating, cancelled-booking resurrection, IDOR, idempotency,
 * webhook fail-closed + dedup. Does NOT test real gateways (none configured).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/payments/PaymentService.php';

$E = 'pay-test@example.test'; $OTHER = 'pay-other@example.test';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 52) . $d . "\n"; }
function cleanup(PDO $d, string $e): void {
    $ids = $d->prepare("SELECT BookingId FROM tblbooking WHERE UserEmail = ?"); $ids->execute([$e]); $b = $ids->fetchAll(PDO::FETCH_COLUMN);
    foreach ($b as $id) {
        $d->prepare("DELETE FROM tblpayment_events WHERE PaymentId IN (SELECT PaymentId FROM tblpayments WHERE BookingId=?)")->execute([$id]);
        $d->prepare("DELETE FROM tblpayment_refunds WHERE PaymentId IN (SELECT PaymentId FROM tblpayments WHERE BookingId=?)")->execute([$id]);
        $d->prepare("DELETE FROM tblpayments WHERE BookingId=?")->execute([$id]);
    }
    $d->prepare("DELETE FROM tblbooking WHERE UserEmail=?")->execute([$e]);
    $d->prepare("DELETE FROM tblusers WHERE EmailId=? AND FullName='Pay Test'")->execute([$e]);
}
function mk(PDO $d, string $e, int $status): int {
    // tblbooking.UserEmail has a foreign key to tblusers (migration 004), so the test customer must exist.
    $d->prepare("INSERT IGNORE INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES ('Pay Test','01700000001',?, 'x')")->execute([$e]);
    $pk = (int)$d->query("SELECT PackageId FROM tbltourpackages WHERE Status=1 LIMIT 1")->fetchColumn();
    $d->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost,PaymentStatus) VALUES (?,?,?,?,?,1,5000,'unpaid')")
      ->execute([$pk, $e, '2030-01-01', '2030-01-03', $status]);
    return (int)$d->lastInsertId();
}
function row(PDO $d, int $id): array { $s = $d->prepare("SELECT status, PaymentStatus FROM tblbooking WHERE BookingId=?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); }

cleanup($dbh, $E); $svc = new PaymentService($dbh);

// 1. Gating: remote client (not loopback), no flag -> sandbox must NOT confirm.
putenv('TMS_ALLOW_SANDBOX_PAYMENTS'); unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
t('sandbox disallowed for remote client', PaymentService::sandboxAllowed() === false);
$b1 = mk($dbh, $E, 0); $p = $svc->createPendingPayment($b1, 5000.0);
t('remote: confirm refused', $svc->confirmSandboxPayment((int)$p['PaymentId'], $E) === false);
t('remote: booking stays unpaid/pending', row($dbh, $b1)['status'] == 0 && row($dbh, $b1)['PaymentStatus'] !== 'paid');
t('remote: Host header spoof does not matter', (function () { $_SERVER['HTTP_HOST'] = 'localhost'; return PaymentService::sandboxAllowed() === false; })());
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
t('loopback via proxy header disallowed', PaymentService::sandboxAllowed() === false);

// 2. Allowed (local dev)
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
t('loopback allowed', PaymentService::sandboxAllowed() === true);
t('other user cannot confirm (IDOR)', $svc->confirmSandboxPayment((int)$p['PaymentId'], $OTHER) === false);
t('owner confirms', $svc->confirmSandboxPayment((int)$p['PaymentId'], $E) === true);
t('booking now paid + confirmed', row($dbh, $b1) === ['status' => 1, 'PaymentStatus' => 'paid']);
t('second confirm idempotent', $svc->confirmSandboxPayment((int)$p['PaymentId'], $E) === true);
$cnt = $dbh->prepare("SELECT COUNT(*) FROM tblpayments WHERE BookingId=?"); $cnt->execute([$b1]);
t('only one payment row exists for the booking', (int)$cnt->fetchColumn() === 1);
$thr = function (callable $f): string { try { $f(); return ''; } catch (DomainException $e) { return $e->getMessage(); } };
t('a booking that is already PAID cannot get a new payment', $thr(fn() => $svc->createPendingPayment($b1, 5000.0)) === 'booking_already_paid');
t('a booking that does not exist cannot get a payment', $thr(fn() => $svc->createPendingPayment(999999999, 5000.0)) === 'booking_not_found');

// 3. Cancelled booking must not be resurrected
$bc = mk($dbh, $E, 2);
t('a CANCELLED booking cannot get a new payment', $thr(fn() => $svc->createPendingPayment($bc, 5000.0)) === 'booking_cancelled');
$b2 = mk($dbh, $E, 0); $p2 = $svc->createPendingPayment($b2, 5000.0);
t('createPendingPayment is idempotent while the booking is unpaid', $svc->createPendingPayment($b2, 5000.0)['PaymentId'] == $p2['PaymentId']);
$dbh->prepare('UPDATE tblbooking SET status=2 WHERE BookingId=?')->execute([$b2]);   // customer cancels after the payment row was created
t('cancelled booking: confirm refused', $svc->confirmSandboxPayment((int)$p2['PaymentId'], $E) === false);
t('cancelled booking stays cancelled & unpaid', row($dbh, $b2)['status'] == 2 && row($dbh, $b2)['PaymentStatus'] !== 'paid');

// 4. Env flag overrides
putenv('TMS_ALLOW_SANDBOX_PAYMENTS=0'); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
t('flag=0 disables even on loopback', PaymentService::sandboxAllowed() === false);
putenv('TMS_ALLOW_SANDBOX_PAYMENTS');

// 5. Webhooks fail closed, dedup
$r = $svc->handleWebhook('sslcommerz', json_encode(['tran_id' => 'T-FORGED-1', 'status' => 'VALID']), []);
t('forged webhook not accepted', $r['status'] >= 400, 'status=' . $r['status']);
$r2 = $svc->handleWebhook('sslcommerz', json_encode(['tran_id' => 'T-FORGED-1', 'status' => 'VALID']), []);
t('a REJECTED (unsigned) event does not poison the id: the redelivery is processed again, not swallowed as a duplicate', ($r2['body']['status'] ?? '') !== 'duplicate' && $r2['status'] >= 400);
t('...and it reused the same event row (retry counted, no second row)', (int)$dbh->query("SELECT COUNT(*) FROM tblpayment_events WHERE ProviderEventId='T-FORGED-1'")->fetchColumn() === 1 && (int)$dbh->query("SELECT RetryCount FROM tblpayment_events WHERE ProviderEventId='T-FORGED-1'")->fetchColumn() >= 1);
$dbh->prepare("UPDATE tblpayment_events SET ProcessingStatus='processed' WHERE ProviderEventId='T-FORGED-1'")->execute();
$r2b = $svc->handleWebhook('sslcommerz', json_encode(['tran_id' => 'T-FORGED-1', 'status' => 'VALID']), []);
t('an event already PROCESSED is acknowledged as a duplicate (no reprocessing)', ($r2b['body']['status'] ?? '') === 'duplicate' && $r2b['status'] === 200);
$big = $svc->handleWebhook('sslcommerz', json_encode(['tran_id' => 'T-BIG', 'pad' => str_repeat('x', 300000)]), []);
t('oversized webhook body is refused (413) and not stored', $big['status'] === 413 && (int)$dbh->query("SELECT COUNT(*) FROM tblpayment_events WHERE ProviderEventId='T-BIG'")->fetchColumn() === 0);
$r3 = $svc->handleWebhook('nosuch', '{}', []);
t('unknown provider rejected', $r3['status'] >= 400);
$paidViaHook = $dbh->query("SELECT COUNT(*) FROM tblpayments WHERE ProviderTransactionId='T-FORGED-1'")->fetchColumn();
t('forged webhook created no payment', (int)$paidViaHook === 0);
$dbh->exec("DELETE FROM tblpayment_events WHERE ProviderEventId='T-FORGED-1' OR Provider='nosuch'");

cleanup($dbh, $E);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);

<?php
/**
 * Supplier-marketplace tests against a REAL database (creates then deletes its own rows, all tagged
 * @supplier-test.example.test). CLI only:   php tests/suppliers/run.php
 * Covers: registration validation, login lockout, verification gate, package review, tenant isolation,
 * blackout overlap, booking responses + PII masking, settlement maths / double-settle / void, state machines.
 * Does NOT cover the browser pages (see SUPPLIERS.md for how those were checked) or any real payout.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/services/SupplierService.php';
SupplierService::migrate($dbh);

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 62) . $d . "\n"; }
function cleanup(PDO $d): void {
    $ids = $d->query("SELECT SupplierId FROM tbl_suppliers WHERE Email LIKE '%@supplier-test.example.test'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pk = $d->prepare('SELECT PackageId FROM tbltourpackages WHERE SupplierId=?'); $pk->execute([$id]); $pids = $pk->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pids as $p) {
            $d->prepare('DELETE FROM tbl_supplier_booking_responses WHERE BookingId IN (SELECT BookingId FROM tblbooking WHERE PackageId=?)')->execute([$p]);
            $d->prepare('DELETE FROM tbl_supplier_settlement_items WHERE BookingId IN (SELECT BookingId FROM tblbooking WHERE PackageId=?)')->execute([$p]);
            $d->prepare('DELETE FROM tblbooking WHERE PackageId=?')->execute([$p]);
        }
        $d->prepare('DELETE FROM tbl_supplier_settlement_items WHERE SettlementId IN (SELECT SettlementId FROM tbl_supplier_settlements WHERE SupplierId=?)')->execute([$id]);
        foreach (['tbl_supplier_settlements', 'tbl_supplier_blackouts', 'tbltourpackages', 'tbl_supplier_audit', 'tbl_suppliers'] as $tb) {
            try { $d->prepare("DELETE FROM $tb WHERE SupplierId=?")->execute([$id]); } catch (Throwable $e) {}
        }
    }
}
cleanup($dbh);

$good = fn(string $em) => ['business_name' => 'Test Tours ' . $em, 'contact_name' => 'Tester', 'email' => $em, 'phone' => '+8801711000000', 'password' => 'correct-horse-1', 'password2' => 'correct-horse-1'];
$A = 'a@supplier-test.example.test'; $B = 'b@supplier-test.example.test';

// --- registration ---
$r = SupplierService::register($dbh, array_merge($good($A), ['password2' => 'nope']));
t('register rejects mismatched passwords', !$r['ok'] && isset($r['errors']['password2']));
$r = SupplierService::register($dbh, array_merge($good($A), ['password' => 'short', 'password2' => 'short']));
t('register rejects short password', !$r['ok'] && isset($r['errors']['password']));
$r = SupplierService::register($dbh, array_merge($good($A), ['email' => 'not-an-email']));
t('register rejects bad email', !$r['ok'] && isset($r['errors']['email']));
$ra = SupplierService::register($dbh, $good($A)); $a = (int)$ra['id'];
t('register succeeds, starts pending', $ra['ok'] && SupplierService::find($dbh, $a)['Status'] === 'pending');
$dup = SupplierService::register($dbh, $good(strtoupper($A)));
t('duplicate email (any case) rejected', !$dup['ok'] && isset($dup['errors']['email']));
$rb = SupplierService::register($dbh, $good($B)); $b = (int)$rb['id'];
t('password stored hashed, not plain', !str_contains((string)$dbh->query("SELECT PasswordHash FROM tbl_suppliers WHERE SupplierId=$a")->fetchColumn(), 'correct-horse'));

// --- login ---
$l = SupplierService::login($dbh, $A, 'wrong');
$l2 = SupplierService::login($dbh, 'ghost@supplier-test.example.test', 'wrong');
t('wrong password and unknown email give the same message', !$l['ok'] && $l['error'] === $l2['error']);
t('correct login works (pending can sign in)', SupplierService::login($dbh, $A, 'correct-horse-1')['ok']);
for ($i = 0; $i < 5; $i++) SupplierService::login($dbh, $B, 'bad' . $i);
$lk = SupplierService::login($dbh, $B, 'correct-horse-1');
t('5 failures lock the account, even for the right password', !$lk['ok'] && str_contains($lk['error'], 'Too many'));
$dbh->prepare('UPDATE tbl_suppliers SET LockedUntil=NULL WHERE SupplierId=?')->execute([$b]);

// --- verification gate ---
$pk = ['name' => 'Sundarbans Boat Trip', 'type' => 'Nature', 'location' => 'Khulna', 'country' => 'Bangladesh', 'price' => 12000, 'seats' => 10, 'details' => 'Three days.', 'cancellation' => 'Free until 7 days before.'];
t('unverified supplier cannot list packages', !SupplierService::savePackage($dbh, $a, null, $pk)['ok']);
t('pending -> verified allowed', SupplierService::canTransitionSupplier('pending', 'verified'));
t('rejected -> verified NOT allowed', !SupplierService::canTransitionSupplier('rejected', 'verified'));
t('reject needs a reason', !SupplierService::setSupplierStatus($dbh, $a, 'rejected', 'adm')['ok']);
t('verify A', SupplierService::setSupplierStatus($dbh, $a, 'verified', 'adm')['ok']);
t('verify B', SupplierService::setSupplierStatus($dbh, $b, 'verified', 'adm')['ok']);

// --- packages + review ---
$sp = SupplierService::savePackage($dbh, $a, null, $pk); $pa = (int)$sp['id'];
t('package saved as pending_review and NOT live', $sp['ok'] && $dbh->query("SELECT CONCAT(Status,ApprovalStatus) FROM tbltourpackages WHERE PackageId=$pa")->fetchColumn() === '0pending_review');
t('package without cancellation terms rejected', !SupplierService::savePackage($dbh, $a, null, array_merge($pk, ['cancellation' => '']))['ok']);
t('non-integer price rejected', !SupplierService::savePackage($dbh, $a, null, array_merge($pk, ['price' => '12.5x']))['ok']);
t('review queue contains it', in_array($pa, array_column(SupplierService::reviewQueue($dbh), 'PackageId')));
t('reject needs a note', !SupplierService::reviewPackage($dbh, $pa, 'reject', '', 'adm')['ok']);
t('approve publishes it', SupplierService::reviewPackage($dbh, $pa, 'approve', '', 'adm')['ok'] && (int)$dbh->query("SELECT Status FROM tbltourpackages WHERE PackageId=$pa")->fetchColumn() === 1);
t('approving twice is refused', !SupplierService::reviewPackage($dbh, $pa, 'approve', '', 'adm')['ok']);
SupplierService::savePackage($dbh, $a, $pa, array_merge($pk, ['price' => 13000]));
t('editing a live package sends it back to review (goes offline)', $dbh->query("SELECT CONCAT(Status,ApprovalStatus) FROM tbltourpackages WHERE PackageId=$pa")->fetchColumn() === '0pending_review');
SupplierService::reviewPackage($dbh, $pa, 'approve', '', 'adm');

// --- tenant isolation ---
t('B cannot read A\'s package', SupplierService::package($dbh, $b, $pa) === null);
t('B cannot edit A\'s package', !SupplierService::savePackage($dbh, $b, $pa, array_merge($pk, ['name' => 'HIJACKED']))['ok'] && $dbh->query("SELECT PackageName FROM tbltourpackages WHERE PackageId=$pa")->fetchColumn() === 'Sundarbans Boat Trip');
t('B\'s package list excludes A\'s', !in_array($pa, array_column(SupplierService::packages($dbh, $b), 'PackageId')));
t('B cannot add a blackout to A\'s package', !SupplierService::addBlackout($dbh, $b, $pa, date('Y-m-d', strtotime('+10 days')), date('Y-m-d', strtotime('+12 days')), 'x')['ok']);

// --- blackouts ---
$f = date('Y-m-d', strtotime('+30 days')); $to = date('Y-m-d', strtotime('+35 days'));
t('past blackout refused', !SupplierService::addBlackout($dbh, $a, $pa, '2020-01-01', '2020-01-05', '')['ok']);
t('end before start refused', !SupplierService::addBlackout($dbh, $a, $pa, $to, $f, '')['ok']);
t('blackout added', SupplierService::addBlackout($dbh, $a, $pa, $f, $to, 'Boat maintenance')['ok']);
$d = fn(int $o) => date('Y-m-d', strtotime("+$o days"));
t('stay fully inside blackout is blocked', SupplierService::blackoutConflict($dbh, $pa, $d(31), $d(33)) !== null);
t('stay overlapping the start is blocked', SupplierService::blackoutConflict($dbh, $pa, $d(28), $d(30)) !== null);
t('stay overlapping the end is blocked', SupplierService::blackoutConflict($dbh, $pa, $d(35), $d(38)) !== null);
t('stay wrapping the blackout is blocked', SupplierService::blackoutConflict($dbh, $pa, $d(20), $d(50)) !== null);
t('stay ending the day before is allowed', SupplierService::blackoutConflict($dbh, $pa, $d(25), $d(29)) === null);
t('stay starting the day after is allowed', SupplierService::blackoutConflict($dbh, $pa, $d(36), $d(40)) === null);
t('other package unaffected', SupplierService::blackoutConflict($dbh, $pa + 99999, $d(31), $d(33)) === null);
$bo = SupplierService::blackouts($dbh, $a)[0]['BlackoutId'];
t('B cannot delete A\'s blackout', !SupplierService::deleteBlackout($dbh, $b, (int)$bo));
t('A can delete own blackout; dates free again', SupplierService::deleteBlackout($dbh, $a, (int)$bo) && SupplierService::blackoutConflict($dbh, $pa, $d(31), $d(33)) === null);

// --- bookings, responses, PII ---
$mk = function (string $from, string $toD, int $status, string $pay, int $cost) use ($dbh, $pa): int {
    $dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost,PaymentStatus,MobileNumber) VALUES (?,?,?,?,?,1,?,?,'01700000000')")
        ->execute([$pa, 'cust@supplier-test.example.test', $from, $toD, $status, $cost, $pay]);
    return (int)$dbh->lastInsertId();
};
$old = fn(int $o) => date('Y-m-d', strtotime("-$o days"));
$b1 = $mk($old(40), $old(38), 1, 'paid', 10000);          // eligible
$b2 = $mk($old(40), $old(38), 1, 'paid', 5000);           // eligible
$b3 = $mk($old(40), $old(38), 0, 'paid', 7000);           // not confirmed
$b4 = $mk($old(40), $old(38), 1, 'unpaid', 7000);         // unpaid
$b5 = $mk($d(50), $d(52), 1, 'paid', 7000);               // future trip
$b6 = $mk($old(40), $old(38), 1, 'partially_refunded', 7000); // needs manual review
$b7 = $mk($old(40), $old(38), 2, 'refunded', 7000);       // cancelled
$rows = SupplierService::bookings($dbh, $a);
$byId = array_column($rows, null, 'BookingId');
t('supplier sees own bookings', isset($byId[$b1], $byId[$b3]));
t('customer name/mobile hidden until confirmed', $byId[$b3]['CustomerMobile'] === null && $byId[$b1]['CustomerMobile'] === '01700000000');
t('B sees none of A\'s bookings', SupplierService::bookings($dbh, $b) === []);
t('B cannot respond to A\'s booking', !SupplierService::respondToBooking($dbh, $b, $b1, 'accepted', '')['ok']);
t('decline needs a reason', !SupplierService::respondToBooking($dbh, $a, $b3, 'declined', '')['ok']);
t('decline recorded and flagged for admin', SupplierService::respondToBooking($dbh, $a, $b3, 'declined', 'Boat unavailable')['ok'] && in_array($b3, array_column(SupplierService::declinedNeedingAttention($dbh), 'BookingId')));
t('responding does not change booking status', (int)$dbh->query("SELECT status FROM tblbooking WHERE BookingId=$b3")->fetchColumn() === 0);
t('cannot respond to a cancelled booking', !SupplierService::respondToBooking($dbh, $a, $b7, 'accepted', '')['ok']);

// --- settlements ---
$s0 = $old(60); $s1 = $old(1);
$pv = SupplierService::previewSettlement($dbh, $a, $s0, $s1);
t('preview includes only confirmed+paid+finished trips', array_column($pv['eligible'], 'BookingId') == [$b1, $b2] && $pv['gross'] === 15000);
t('partially-refunded counted for manual review, not settled', $pv['review'] === 1);
t('B previews nothing for A\'s bookings', SupplierService::previewSettlement($dbh, $b, $s0, $s1)['eligible'] === []);
t('bad period refused', !SupplierService::createSettlement($dbh, $a, 'x', $s1, 'fin')['ok'] && !SupplierService::createSettlement($dbh, $a, $s1, $s0, 'fin')['ok']);
t('commission above 50% refused', !SupplierService::setCommission($dbh, $a, 51, 'adm'));
SupplierService::setCommission($dbh, $a, 12.5, 'adm');
$cs = SupplierService::createSettlement($dbh, $a, $s0, $s1, 'fin'); $sid = (int)$cs['id'];
$row = $dbh->query("SELECT * FROM tbl_supplier_settlements WHERE SettlementId=$sid")->fetch(PDO::FETCH_ASSOC);
t('settlement maths: 15000 gross, 12.5% => 1875 / 13125', $cs['ok'] && (float)$row['GrossAmount'] === 15000.0 && (float)$row['CommissionAmount'] === 1875.0 && (float)$row['NetAmount'] === 13125.0, " {$row['InvoiceNo']}");
SupplierService::setCommission($dbh, $a, 30, 'adm');
t('later commission change does not alter an existing settlement', (float)$dbh->query("SELECT CommissionPct FROM tbl_supplier_settlements WHERE SettlementId=$sid")->fetchColumn() === 12.5);
t('same bookings cannot be settled twice', !SupplierService::createSettlement($dbh, $a, $s0, $s1, 'fin')['ok']);
t('draft -> paid not allowed (must be issued first)', !SupplierService::transitionSettlement($dbh, $sid, 'paid', 'fin', 'REF1')['ok']);
t('issue works', SupplierService::transitionSettlement($dbh, $sid, 'issued', 'fin')['ok']);
t('paid needs a payment reference', !SupplierService::transitionSettlement($dbh, $sid, 'paid', 'fin', '')['ok']);
t('paid with reference works', SupplierService::transitionSettlement($dbh, $sid, 'paid', 'fin', 'BKASH-123')['ok']);
t('paid settlement cannot be voided or changed', !SupplierService::transitionSettlement($dbh, $sid, 'void', 'fin')['ok'] && !SupplierService::transitionSettlement($dbh, $sid, 'issued', 'fin')['ok']);
$cs2 = SupplierService::createSettlement($dbh, $a, $s0, $s1, 'fin');
t('paid bookings stay excluded from a new settlement', !$cs2['ok']);
$b8 = $mk($old(30), $old(29), 1, 'paid', 4000);
$cs3 = SupplierService::createSettlement($dbh, $a, $s0, $s1, 'fin');
t('a newly eligible booking can be settled on its own', $cs3['ok'] && (float)$dbh->query("SELECT GrossAmount FROM tbl_supplier_settlements WHERE SettlementId={$cs3['id']}")->fetchColumn() === 4000.0);
t('voiding a draft releases its bookings', SupplierService::transitionSettlement($dbh, (int)$cs3['id'], 'void', 'fin')['ok'] && SupplierService::createSettlement($dbh, $a, $s0, $s1, 'fin')['ok']);
t('settlements list scoped by supplier', SupplierService::settlements($dbh, $b) === []);

// --- suspension ---
t('suspend needs a reason', !SupplierService::setSupplierStatus($dbh, $a, 'suspended', 'adm')['ok']);
t('suspend works and takes packages offline', SupplierService::setSupplierStatus($dbh, $a, 'suspended', 'adm', 'Policy breach')['ok'] && $dbh->query("SELECT CONCAT(Status,ApprovalStatus) FROM tbltourpackages WHERE PackageId=$pa")->fetchColumn() === '0pending_review');
t('suspended supplier cannot sign in', !SupplierService::login($dbh, $A, 'correct-horse-1')['ok']);
t('suspended supplier cannot list packages', !SupplierService::savePackage($dbh, $a, null, $pk)['ok']);
t('suspended package cannot be re-approved while suspended', !SupplierService::reviewPackage($dbh, $pa, 'approve', '', 'adm')['ok']);
t('suspended -> rejected/pending NOT allowed', !SupplierService::canTransitionSupplier('suspended', 'rejected') && !SupplierService::canTransitionSupplier('suspended', 'pending'));
t('reinstating keeps packages offline until re-approved', SupplierService::setSupplierStatus($dbh, $a, 'verified', 'adm', 'Resolved')['ok'] && (int)$dbh->query("SELECT Status FROM tbltourpackages WHERE PackageId=$pa")->fetchColumn() === 0 && SupplierService::reviewPackage($dbh, $pa, 'approve', '', 'adm')['ok']);

cleanup($dbh);
t('cleanup left no test rows', (int)$dbh->query("SELECT COUNT(*) FROM tbl_suppliers WHERE Email LIKE '%@supplier-test.example.test'")->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

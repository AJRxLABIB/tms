<?php
/**
 * Booking-flow helpers of InventoryService against the REAL configured database (needs migration 023).
 * Covers what hotel-details.php / activity-details.php / my-marketplace-bookings.php / admin/manage-marketplace-bookings.php
 * now do: reserve every night in ONE transaction, price from the slot, convert to a booking ref, release exactly once.
 * Uses entity ids that are not real listings (987654331/987654332) on far-future dates and deletes everything it made.
 *   php tests/integration/marketplace_inventory_flow_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/services/InventoryService.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 72) . $d . "\n"; }
foreach (['tbl_inventory_slots', 'tbl_inventory_holds'] as $tbl) {
    try { $dbh->query("SELECT 1 FROM `$tbl` LIMIT 1"); }
    catch (Throwable $e) { echo "SKIP: table $tbl missing -- run `php database/migrate.php` first (migration 023).\n"; exit(2); }
}
const H = 987654331; const A = 987654332;
function cleanup(PDO $d): void { $d->exec("DELETE FROM tbl_inventory_slots WHERE EntityId IN (987654331, 987654332)"); }   // holds cascade
function slot(PDO $d, string $type, int $id, string $date, int $cap, ?int $price = null, int $blackout = 0): int {
    $d->prepare("INSERT INTO tbl_inventory_slots (EntityType,EntityId,SlotDate,StartTime,Capacity,Booked,PriceOverride,CutoffMinutes,IsBlackout,Source) VALUES (?,?,?,'00:00:00',?,0,?,0,?,'manual')")
      ->execute([$type, $id, $date, $cap, $price, $blackout]);
    return (int)$d->lastInsertId();
}
function booked(PDO $d, int $sid): int { $s = $d->prepare('SELECT Booked FROM tbl_inventory_slots WHERE SlotId=?'); $s->execute([$sid]); return (int)$s->fetchColumn(); }
function holdStatuses(PDO $d, string $ref): array { $s = $d->prepare('SELECT Status FROM tbl_inventory_holds WHERE ConvertedRef=? ORDER BY HoldId'); $s->execute([$ref]); return $s->fetchAll(PDO::FETCH_COLUMN); }

cleanup($dbh);

// unmanaged: caller keeps its legacy path
$u = InventoryService::reserveForBooking($dbh, 'hotel', H, ['2099-02-01'], 1, 'u@test#X1', 5000);
t('unmanaged entity: reserveForBooking says unmanaged, touches nothing', $u['status'] === 'unmanaged' && $u['hold_ids'] === []);
t('unmanaged entity: releaseBooking is a harmless no-op', InventoryService::releaseBooking($dbh, 'hotel', 999999999) === 0);

// 3-night stay, 2 rooms, middle night has a price override
$n1 = slot($dbh, 'hotel', H, '2099-02-01', 5); $n2 = slot($dbh, 'hotel', H, '2099-02-02', 5, 7000); $n3 = slot($dbh, 'hotel', H, '2099-02-03', 5);
$dates = ['2099-02-01', '2099-02-02', '2099-02-03'];
$dbh->beginTransaction();
$r = InventoryService::reserveForBooking($dbh, 'hotel', H, $dates, 2, 'a@test#C1', 5000);
t('reserve: ok, 3 holds', $r['status'] === 'ok' && count($r['hold_ids']) === 3);
t('reserve: total uses the slot price override (2 rooms x (5000+7000+5000))', $r['total'] === 34000, 'total=' . $r['total']);
t('reserve: units counted on every night', booked($dbh, $n1) === 2 && booked($dbh, $n2) === 2 && booked($dbh, $n3) === 2);
$ok = InventoryService::confirmReservation($dbh, $r['hold_ids'], 'a@test#C1', InventoryService::bookingRef('hotel', 4242));
$dbh->commit();
t('confirm: all holds converted to the booking ref', $ok && holdStatuses($dbh, 'hotel:4242') === ['converted', 'converted', 'converted']);

// rollback leaves no units behind (whole booking fails together)
$dbh->beginTransaction();
$r2 = InventoryService::reserveForBooking($dbh, 'hotel', H, $dates, 1, 'b@test#C2', 5000);
$dbh->rollBack();
t('rolled-back reservation leaves nothing held', $r2['status'] === 'ok' && booked($dbh, $n1) === 2 && booked($dbh, $n2) === 2);

// sold out on one night refuses the whole stay and rolls back the earlier nights
$dbh->beginTransaction();
$r3 = InventoryService::reserveForBooking($dbh, 'hotel', H, $dates, 4, 'c@test#C3', 5000);   // 2 + 4 > 5
$dbh->rollBack();
t('over-capacity stay is refused as sold_out/insufficient with the date', $r3['status'] === 'unavailable' && in_array($r3['reason'], ['sold_out', 'insufficient'], true) && $r3['date'] === '2099-02-01');
t('refused stay changed nothing', booked($dbh, $n1) === 2 && booked($dbh, $n3) === 2);

// blackout / missing slot
slot($dbh, 'hotel', H, '2099-02-10', 5, null, 1);
$dbh->beginTransaction(); $r4 = InventoryService::reserveForBooking($dbh, 'hotel', H, ['2099-02-10'], 1, 'd@test#C4', 5000); $dbh->rollBack();
t('blackout night is refused', $r4['status'] === 'unavailable' && $r4['reason'] === 'blackout');
$dbh->beginTransaction(); $r5 = InventoryService::reserveForBooking($dbh, 'hotel', H, ['2099-03-30'], 1, 'd@test#C5', 5000); $dbh->rollBack();
t('night with no published slot is refused (no_slot)', $r5['status'] === 'unavailable' && $r5['reason'] === 'no_slot');
t('reasonMessage gives a readable sentence', stripos(InventoryService::reasonMessage('sold_out', '2099-02-01'), 'sold out') !== false);

// cancel: release exactly once, even if cancelled twice (customer + admin) or retried
t('cancel: releases the 3 held nights', InventoryService::releaseBooking($dbh, 'hotel', 4242) === 3);
t('cancel: units returned on every night', booked($dbh, $n1) === 0 && booked($dbh, $n2) === 0 && booked($dbh, $n3) === 0);
t('cancel again (admin after customer): releases nothing more', InventoryService::releaseBooking($dbh, 'hotel', 4242) === 0);
t('cancel again: Booked did not go negative or double-decrement', booked($dbh, $n1) === 0);
t('holds are marked released', holdStatuses($dbh, 'hotel:4242') === ['released', 'released', 'released']);

// two bookings, same customer, same night: independent holds (holder is unique per booking)
$dbh->beginTransaction(); $a = InventoryService::reserveForBooking($dbh, 'hotel', H, ['2099-02-01'], 1, 'same@test#K1', 5000); InventoryService::confirmReservation($dbh, $a['hold_ids'], 'same@test#K1', 'hotel:5001'); $dbh->commit();
$dbh->beginTransaction(); $b = InventoryService::reserveForBooking($dbh, 'hotel', H, ['2099-02-01'], 1, 'same@test#K2', 5000); InventoryService::confirmReservation($dbh, $b['hold_ids'], 'same@test#K2', 'hotel:5002'); $dbh->commit();
t('same customer, two bookings: both counted (2 units)', booked($dbh, $n1) === 2);
InventoryService::releaseBooking($dbh, 'hotel', 5001);
t('cancelling one returns only its own unit', booked($dbh, $n1) === 1);
InventoryService::releaseBooking($dbh, 'hotel', 5002);

// activity: one date, people as qty, last seats
$s = slot($dbh, 'activity', A, '2099-04-01', 3, 900);
$dbh->beginTransaction(); $x = InventoryService::reserveForBooking($dbh, 'activity', A, ['2099-04-01'], 3, 'p@test#A1', 1000); InventoryService::confirmReservation($dbh, $x['hold_ids'], 'p@test#A1', 'activity:7001'); $dbh->commit();
t('activity: price from slot override (3 x 900)', $x['status'] === 'ok' && $x['total'] === 2700);
$dbh->beginTransaction(); $y = InventoryService::reserveForBooking($dbh, 'activity', A, ['2099-04-01'], 1, 'q@test#A2', 1000); $dbh->rollBack();
t('activity: full -> next buyer is refused', $y['status'] === 'unavailable' && $y['reason'] === 'sold_out');
t('activity: cancel frees the seats once', InventoryService::releaseBooking($dbh, 'activity', 7001) === 1 && booked($dbh, $s) === 0 && InventoryService::releaseBooking($dbh, 'activity', 7001) === 0);
t('hotel ref and activity ref with the same id do not collide', InventoryService::releaseBooking($dbh, 'hotel', 7001) === 0);

cleanup($dbh);
t('cleanup removed every test slot', (int)$dbh->query("SELECT COUNT(*) FROM tbl_inventory_slots WHERE EntityId IN (987654331, 987654332)")->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

<?php
/**
 * InventoryService against the REAL configured database (needs migration 023: `php database/migrate.php`).
 * Creates one test slot per case on a far-future date for hotel id 987654321 / activity id 987654322 (ids that do not
 * exist as listings) and deletes every row it made. CLI only.
 *   php tests/integration/inventory_hold_test.php [http-base-url]
 * The race test needs a web server only for nothing: it uses real parallel PHP processes via proc_open.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === '--worker') {          // child process: try to hold 1 unit, print the status
    chdir(__DIR__ . '/../..');
    require 'backend/config.php';
    require_once 'backend/services/InventoryService.php';
    $r = InventoryService::hold($dbh, 'hotel', 987654321, '2099-01-05', 1, $argv[2]);
    echo $r['status'], "\n";
    exit(0);
}
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/services/InventoryService.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 70) . $d . "\n"; }

foreach (['tbl_inventory_slots', 'tbl_inventory_holds', 'tbl_inventory_sync_log'] as $tbl) {
    try { $dbh->query("SELECT 1 FROM `$tbl` LIMIT 1"); }
    catch (Throwable $e) { echo "SKIP: table $tbl missing -- run `php database/migrate.php` first (migration 023).\n"; exit(2); }
}

const H = 987654321; const A = 987654322;
function cleanup(PDO $d): void {
    $d->exec("DELETE FROM tbl_inventory_slots WHERE EntityId IN (987654321, 987654322)");   // holds cascade
    $d->exec("DELETE FROM tbl_inventory_sync_log WHERE Source LIKE 'inv-test-%'");
}
function slot(PDO $d, string $type, int $id, string $date, int $cap, array $x = []): int {
    $d->prepare("INSERT INTO tbl_inventory_slots (EntityType,EntityId,SlotDate,StartTime,Capacity,Booked,PriceOverride,CutoffMinutes,IsBlackout,Source) VALUES (?,?,?,'00:00:00',?,0,?,?,?, 'manual')")
      ->execute([$type, $id, $date, $cap, $x['price'] ?? null, $x['cutoff'] ?? 0, $x['blackout'] ?? 0]);
    return (int)$d->lastInsertId();
}
function booked(PDO $d, int $sid): int { $s = $d->prepare('SELECT Booked FROM tbl_inventory_slots WHERE SlotId=?'); $s->execute([$sid]); return (int)$s->fetchColumn(); }

cleanup($dbh);

// 1. unmanaged entity: no slots -> 'unmanaged', existing flows unchanged
t('entity with no slots is unmanaged', InventoryService::isManaged($dbh, 'hotel', H) === false);
t('hold on unmanaged entity returns unmanaged (not an error)', InventoryService::hold($dbh, 'hotel', H, '2099-01-05', 1, 'a@test')['status'] === 'unmanaged');
t('availability on unmanaged entity returns unmanaged', InventoryService::availability($dbh, 'hotel', H, '2099-01-01', '2099-01-10')['status'] === 'unmanaged');

// 2. basic hold / idempotency / release
$s1 = slot($dbh, 'hotel', H, '2099-01-05', 2);
$h = InventoryService::hold($dbh, 'hotel', H, '2099-01-05', 1, 'a@test');
t('hold succeeds', $h['status'] === 'held' && booked($dbh, $s1) === 1);
$h2 = InventoryService::hold($dbh, 'hotel', H, '2099-01-05', 1, 'a@test');
t('same holder again: same hold, no extra unit taken (idempotent)', $h2['status'] === 'held' && $h2['hold_id'] === $h['hold_id'] && booked($dbh, $s1) === 1);
t('other holder cannot release it', InventoryService::release($dbh, $h['hold_id'], 'b@test') === false && booked($dbh, $s1) === 1);
t('holder releases: unit returned', InventoryService::release($dbh, $h['hold_id'], 'a@test') === true && booked($dbh, $s1) === 0);
t('release twice is a no-op', InventoryService::release($dbh, $h['hold_id'], 'a@test') === false && booked($dbh, $s1) === 0);

// 3. capacity, blackout, cutoff
InventoryService::hold($dbh, 'hotel', H, '2099-01-05', 2, 'a@test');
$r = InventoryService::hold($dbh, 'hotel', H, '2099-01-05', 1, 'b@test');
t('sold out slot refuses the next buyer', $r['status'] === 'unavailable' && $r['reason'] === 'sold_out' && booked($dbh, $s1) === 2);
slot($dbh, 'hotel', H, '2099-01-06', 5, ['blackout' => 1]);
t('blackout date refused', InventoryService::hold($dbh, 'hotel', H, '2099-01-06', 1, 'a@test')['reason'] === 'blackout');
slot($dbh, 'hotel', H, '2000-01-01', 5);
t('past date refused (cutoff)', InventoryService::hold($dbh, 'hotel', H, '2000-01-01', 1, 'a@test')['reason'] === 'cutoff');
t('date with no slot refused', InventoryService::hold($dbh, 'hotel', H, '2099-02-02', 1, 'a@test')['reason'] === 'no_slot');

// 4. expiry sweep: lapsed hold frees units; converted hold is never touched
cleanup($dbh);
$s2 = slot($dbh, 'activity', A, '2099-03-03', 3);
$hx = InventoryService::hold($dbh, 'activity', A, '2099-03-03', 2, 'a@test');
$hc = InventoryService::hold($dbh, 'activity', A, '2099-03-03', 1, 'c@test');
$dbh->prepare('UPDATE tbl_inventory_holds SET ExpiresAt = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE HoldId IN (?, ?)')->execute([$hx['hold_id'], $hc['hold_id']]);
t('convert an expired-but-unswept hold is still honoured (units still counted)', InventoryService::convert($dbh, $hc['hold_id'], 'c@test', 'AB1') === 'converted');
t('convert is idempotent', InventoryService::convert($dbh, $hc['hold_id'], 'c@test', 'AB1') === 'already_converted');
t('other holder cannot convert', InventoryService::convert($dbh, $hx['hold_id'], 'zzz@test') === 'forbidden');
$dry = InventoryService::sweepExpired($dbh, true);
t('dry run lists the lapsed hold but changes nothing', in_array($hx['hold_id'], $dry['ids'], true) && booked($dbh, $s2) === 3);
$real = InventoryService::sweepExpired($dbh, false);
t('sweep expires the lapsed hold and returns its 2 units', booked($dbh, $s2) === 1 && $real['expired'] >= 1);
t('converted hold kept its unit', booked($dbh, $s2) === 1);
t('convert after the sweep released it is refused', InventoryService::convert($dbh, $hx['hold_id'], 'a@test') === 'expired');
t('sweep is repeatable', InventoryService::sweepExpired($dbh, false)['expired'] === 0 && booked($dbh, $s2) === 1);
t('a lapsed (unswept) hold is replaced when the same holder asks again',
    (function () use ($dbh, $s2) {
        $h = InventoryService::hold($dbh, 'activity', A, '2099-03-03', 1, 'd@test');
        $dbh->prepare('UPDATE tbl_inventory_holds SET ExpiresAt = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE HoldId = ?')->execute([$h['hold_id']]);
        $n = InventoryService::hold($dbh, 'activity', A, '2099-03-03', 1, 'd@test');
        return $n['status'] === 'held' && $n['hold_id'] !== $h['hold_id'] && booked($dbh, $s2) === 2;
    })());

// 5. price revalidation
cleanup($dbh);
slot($dbh, 'hotel', H, '2099-04-04', 5, ['price' => 9000]);
slot($dbh, 'hotel', H, '2099-04-05', 5);
$p = InventoryService::revalidatePrice($dbh, 'hotel', H, '2099-04-04', 5000, 5000);
t('seasonal override is authoritative; a stale quote does not match', $p['price'] === 9000 && $p['matches'] === false);
t('matching quote accepted', InventoryService::revalidatePrice($dbh, 'hotel', H, '2099-04-04', 5000, 9000)['matches'] === true);
t('no override: listing price', InventoryService::revalidatePrice($dbh, 'hotel', H, '2099-04-05', 5000, 5000)['price'] === 5000);
t('unmanaged entity: listing price, flow unchanged', InventoryService::revalidatePrice($dbh, 'activity', A, '2099-04-04', 700, 700)['status'] === 'unmanaged');

// 6. the last unit: 6 parallel processes, 1 unit -> exactly one holds it
cleanup($dbh);
$s3 = slot($dbh, 'hotel', H, '2099-01-05', 1);
$procs = [];
for ($i = 0; $i < 6; $i++) {
    $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--worker', "racer$i@test"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$won = 0; $lost = 0;
foreach ($procs as $i => $p) {
    $out = trim((string)stream_get_contents($pipes[$i][1])); proc_close($p);
    if ($out === 'held') $won++; elseif ($out === 'unavailable') $lost++;
}
t('last-unit RACE: exactly one of 6 parallel buyers holds it', $won === 1 && $lost === 5, "won=$won lost=$lost");
t('last-unit RACE: slot is not oversold', booked($dbh, $s3) === 1);

// 7. sync log
$sid = InventoryService::syncStart($dbh, 'inv-test-feed', 'hotel');
$run = array_values(array_filter(InventoryService::syncHealth($dbh), fn($x) => $x['source'] === 'inv-test-feed'))[0] ?? null;
t('a started run is shown as running', $run && $run['health']['state'] === 'running');
InventoryService::syncFinish($dbh, $sid, 'failed', 10, 0, 'HTTP 502');
$run = array_values(array_filter(InventoryService::syncHealth($dbh), fn($x) => $x['source'] === 'inv-test-feed'))[0] ?? null;
t('a failed run is flagged with its error', $run && $run['health']['state'] === 'failed' && $run['health']['stale'] && str_contains($run['health']['message'], '502'));
$sid2 = InventoryService::syncStart($dbh, 'inv-test-feed', 'hotel'); InventoryService::syncFinish($dbh, $sid2, 'ok', 10, 3);
$run = array_values(array_filter(InventoryService::syncHealth($dbh), fn($x) => $x['source'] === 'inv-test-feed'))[0] ?? null;
t('latest run wins: a later ok run clears the flag', $run && $run['health']['state'] === 'ok' && !$run['health']['stale'] && $run['items_changed'] === 3);

cleanup($dbh);
t('temporary rows cleaned up', (int)$dbh->query("SELECT COUNT(*) FROM tbl_inventory_slots WHERE EntityId IN (987654321, 987654322)")->fetchColumn() === 0);
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

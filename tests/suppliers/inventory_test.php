<?php
/**
 * Pure-logic tests for InventoryService (no database): capacity/cutoff/blackout rules, price override, sync-run
 * classification and badge escaping/labels.   php tests/suppliers/inventory_test.php
 */
if (!in_array(PHP_SAPI, ['cli', 'wasm'], true)) { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
foreach (['InventorySource', 'InventoryService'] as $c) {
    $src = preg_replace('/^require_once .*$/m', '', file_get_contents("$root/backend/services/$c.php"));
    eval('?>' . $src);
}
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 72) . $d . "\n"; }

$now = strtotime('2030-06-10 12:00:00 UTC');
$slot = ['SlotDate' => '2030-06-12', 'StartTime' => '09:00:00', 'Capacity' => 4, 'Booked' => 1, 'CutoffMinutes' => 120, 'IsBlackout' => 0];

t('remaining = capacity - booked', InventoryService::remaining($slot) === 3);
t('remaining never negative', InventoryService::remaining(['Capacity' => 2, 'Booked' => 5]) === 0);
t('open slot, qty within remaining: ok', InventoryService::check($slot, 3, $now) === ['ok' => true, 'reason' => 'ok']);
t('qty above remaining: insufficient', InventoryService::check($slot, 4, $now)['reason'] === 'insufficient');
t('full slot: sold_out', InventoryService::check(['Booked' => 4] + $slot, 1, $now)['reason'] === 'sold_out');
t('qty 0 / negative / huge: bad_qty', InventoryService::check($slot, 0, $now)['reason'] === 'bad_qty' && InventoryService::check($slot, -2, $now)['reason'] === 'bad_qty' && InventoryService::check($slot, 51, $now)['reason'] === 'bad_qty');
t('blackout beats free capacity', InventoryService::check(['IsBlackout' => 1] + $slot, 1, $now)['reason'] === 'blackout');
t('bad date: bad_slot', InventoryService::check(['SlotDate' => '2030-13-45'] + $slot, 1, $now)['reason'] === 'bad_slot' || InventoryService::check(['SlotDate' => 'tomorrow'] + $slot, 1, $now)['reason'] === 'bad_slot');

// cutoff: slot 09:00 on the 12th, cutoff 120 min -> closes 07:00 on the 12th
t('1 minute before cutoff: ok', InventoryService::check($slot, 1, strtotime('2030-06-12 06:59:00 UTC'))['ok'] === true);
t('exactly at cutoff: closed', InventoryService::check($slot, 1, strtotime('2030-06-12 07:00:00 UTC'))['reason'] === 'cutoff');
t('after the slot started: closed', InventoryService::check($slot, 1, strtotime('2030-06-12 10:00:00 UTC'))['reason'] === 'cutoff');
// all-day slot measured from end of day: same-day booking allowed with cutoff 0
$night = ['SlotDate' => '2030-06-10', 'StartTime' => '00:00:00', 'Capacity' => 5, 'Booked' => 0, 'CutoffMinutes' => 0, 'IsBlackout' => 0];
t('all-day slot is still bookable during that day (cutoff 0)', InventoryService::check($night, 1, strtotime('2030-06-10 15:00:00 UTC'))['ok'] === true);
t('all-day slot is closed the next day', InventoryService::check($night, 1, strtotime('2030-06-11 00:00:01 UTC'))['reason'] === 'cutoff');
t('all-day slot with 6h cutoff closes at 18:00 that day', InventoryService::check(['CutoffMinutes' => 360] + $night, 1, strtotime('2030-06-10 18:30:00 UTC'))['reason'] === 'cutoff');
t('slotStartTs: HH:MM accepted', InventoryService::slotStartTs('2030-06-12', '09:00') === strtotime('2030-06-12 09:00:00 UTC'));
t('slotStartTs: junk date -> null', InventoryService::slotStartTs('12/06/2030') === null);

// price
t('override wins', InventoryService::effectivePrice(7500, 5000) === 7500);
t('null / 0 / empty override falls back to listing price', InventoryService::effectivePrice(null, 5000) === 5000 && InventoryService::effectivePrice(0, 5000) === 5000 && InventoryService::effectivePrice('', 5000) === 5000);
t('string override from the DB is cast', InventoryService::effectivePrice('6200', 5000) === 6200);

// sync health
$n = strtotime('2030-06-10 12:00:00');
$c = fn($row) => InventoryService::classifySyncRun($row, $n);
t('no run ever: never + stale', ($x = $c(null))['state'] === 'never' && $x['stale'] === true);
t('recent ok run: healthy', ($x = $c(['Status' => 'ok', 'StartedAt' => '2030-06-10 10:00:00', 'FinishedAt' => '2030-06-10 10:05:00']))['state'] === 'ok' && $x['stale'] === false);
t('ok run 30 h ago: stale', $c(['Status' => 'ok', 'StartedAt' => '2030-06-09 05:00:00', 'FinishedAt' => '2030-06-09 06:00:00'])['stale'] === true);
t('failed run: failed + stale + carries error', ($x = $c(['Status' => 'failed', 'StartedAt' => '2030-06-10 11:00:00', 'FinishedAt' => '2030-06-10 11:01:00', 'Error' => 'HTTP 502']))['state'] === 'failed' && $x['stale'] && str_contains($x['message'], 'HTTP 502'));
t('partial run is flagged for attention', ($x = $c(['Status' => 'partial', 'StartedAt' => '2030-06-10 11:00:00', 'FinishedAt' => '2030-06-10 11:01:00']))['state'] === 'partial' && $x['stale'] === true);
t('running for 5 h: stuck', ($x = $c(['Status' => 'running', 'StartedAt' => '2030-06-10 07:00:00']))['state'] === 'running' && $x['stale'] === true);
t('running for 10 min: not stuck', $c(['Status' => 'running', 'StartedAt' => '2030-06-10 11:50:00'])['stale'] === false);

// badge: labels and escaping; demo is never "Live"
$demo = InventorySource::classify(['Source' => 'live_sync', 'LastSyncedAt' => '2030-06-10 11:00:00', 'LastSyncStatus' => 'ok', 'IsSampleData' => 1], $n);
$live = InventorySource::classify(['Source' => 'live_sync', 'LastSyncedAt' => '2030-06-10 11:00:00', 'LastSyncStatus' => 'ok'], $n);
t('sample flag forces Demo even with a fresh live-sync record', $demo['badge'] === 'demo' && !$demo['live']);
t('badge HTML for demo says Demo data and not Live', str_contains(InventoryService::badgeHtml($demo), 'Demo data') && !str_contains(InventoryService::badgeHtml($demo), '>Live<'));
t('badge HTML for a fresh synced record says Live', str_contains(InventoryService::badgeHtml($live), '>Live<'));
$xss = InventoryService::badgeHtml(['label' => '<script>alert(1)</script>', 'reason' => '"><img src=x onerror=1>', 'badge' => 'manual']);
t('badge HTML escapes label and tooltip', !str_contains($xss, '<script>') && !str_contains($xss, '"><img'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

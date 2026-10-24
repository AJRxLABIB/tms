<?php
/**
 * Pure-logic tests for SupplierQuality::score() and InventorySource::classify() - no database needed.
 *   php tests/suppliers/quality_test.php
 */
if (!in_array(PHP_SAPI, ['cli', 'wasm'], true)) { http_response_code(404); exit; }
// Minimal stand-in so the classes load without the app bootstrap (the real SupplierService is only needed for DB methods).
if (!class_exists('SupplierService')) { class SupplierService { public const STATUS_VERIFIED = 'verified'; public static function audit(...$a) {} public static function find(...$a) { return null; } } }
$root = dirname(__DIR__, 2);
// Load the classes without their require_once of the real SupplierService (already stubbed above).
foreach (['InventorySource', 'SupplierQuality'] as $c) {
    $src = preg_replace('/^require_once .*$/m', '', file_get_contents("$root/backend/services/$c.php"));
    eval('?>' . $src);
}
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 70) . $d . "\n"; }

$good = ['status' => 'verified', 'total_bookings' => 40, 'completed' => 30, 'reviews_n' => 12, 'reviews_avg' => 4.6, 'declined' => 1, 'complaints' => 1,
         'response_n' => 20, 'response_avg_hours' => 3.0, 'freshness' => 1.0, 'open_disputes' => 0];
$r = SupplierQuality::score($good);
t('healthy supplier gets a score', $r['status'] === 'scored' && $r['score'] > 85, 'score=' . $r['score']);
t('healthy supplier is not flagged', $r['needs_review'] === false);
t('every criterion reports weight and explanation', count(array_filter($r['criteria'], fn($c) => $c['weight'] > 0 && $c['explanation'] !== '')) === 7);
t('weights sum to 100', array_sum(array_column(SupplierQuality::CRITERIA, 0)) === 100);

$new = ['status' => 'verified', 'total_bookings' => 2, 'completed' => 1, 'reviews_n' => 1, 'reviews_avg' => 5.0, 'declined' => 1, 'complaints' => 0, 'response_n' => 1, 'response_avg_hours' => 1.0, 'freshness' => null, 'open_disputes' => 0];
$r = SupplierQuality::score($new);
t('tiny sample: no score (insufficient data)', $r['score'] === null && $r['status'] === 'insufficient_data');
t('tiny sample: 1 decline of 2 is NOT treated as a cancellation rate', $r['criteria']['cancellation']['measured'] === false && $r['needs_review'] === false);
t('tiny sample: 1 five-star review is not measured', $r['criteria']['reviews']['measured'] === false);

$unv = $good; $unv['status'] = 'pending';
$r = SupplierQuality::score($unv);
t('unverified supplier gets no score', $r['score'] === null && $r['status'] === 'insufficient_data');

$bad = $good; $bad['declined'] = 12; $bad['complaints'] = 8; $bad['reviews_avg'] = 2.0; $bad['freshness'] = 0.2; $bad['open_disputes'] = 1;
$r = SupplierQuality::score($bad);
t('poor supplier is flagged for HUMAN review', $r['needs_review'] === true && count($r['review_reasons']) >= 3, implode(' | ', $r['review_reasons']));
t('poor supplier still just gets a number, no status field to act on', $r['score'] !== null && !array_key_exists('suspend', $r) && !array_key_exists('penalty', $r));
t('note states the score never changes status automatically', str_contains($r['note'], 'never changes'));

$mid = $good; $mid['reviews_n'] = 4; $mid['response_n'] = 2; $mid['freshness'] = null;
$r = SupplierQuality::score($mid);
t('unmeasured criteria are left out, not counted as zero', $r['status'] === 'scored' && $r['score'] > 70 && $r['criteria']['freshness']['measured'] === false, 'score=' . $r['score']);

t('dispute: open -> under_review -> resolved allowed', SupplierQuality::canTransitionDispute('open', 'under_review') && SupplierQuality::canTransitionDispute('under_review', 'resolved'));
t('dispute: resolved is terminal, cannot go back', !SupplierQuality::canTransitionDispute('resolved', 'open') && !SupplierQuality::canTransitionDispute('resolved', 'under_review'));

$now = strtotime('2026-10-22 12:00:00');
$c = InventorySource::classify(['Source' => 'live_sync', 'LastSyncedAt' => '2026-10-22 06:00:00', 'LastSyncStatus' => 'ok'], $now);
t('live feed synced 6 h ago is Live', $c['label'] === 'Live' && $c['live'] && !$c['stale']);
$c = InventorySource::classify(['Source' => 'live_sync', 'LastSyncedAt' => '2026-10-19 06:00:00', 'LastSyncStatus' => 'ok'], $now);
t('live feed synced 3 days ago is NOT Live', !$c['live'] && $c['stale'], $c['label']);
$c = InventorySource::classify(['Source' => 'live_sync', 'LastSyncedAt' => '2026-10-22 11:00:00', 'LastSyncStatus' => 'failed'], $now);
t('live feed whose last sync failed is NOT Live', !$c['live'] && $c['stale']);
$c = InventorySource::classify(['Source' => 'live_sync', 'LastSyncedAt' => null], $now);
t('live feed that never synced is NOT Live', !$c['live'] && str_contains($c['reason'], 'Never'));
$c = InventorySource::classify(['Source' => 'live_sync', 'LastSyncedAt' => '2026-10-22 11:00:00', 'LastSyncStatus' => 'ok', 'IsSampleData' => 1], $now);
t('sample data is ALWAYS demo, even if marked live and fresh', $c['label'] === 'Demo data' && !$c['live']);
$c = InventorySource::classify(['Source' => 'imported', 'LastSyncedAt' => '2026-09-01 00:00:00'], $now);
t('imported data is labelled may be stale', $c['label'] === 'Imported – may be stale' && $c['stale']);
$c = InventorySource::classify(['Source' => 'manual', 'LastSyncedAt' => '2026-10-20 00:00:00'], $now);
t('recent manual data is Manually maintained, not stale', $c['label'] === 'Manually maintained' && !$c['stale']);
$c = InventorySource::classify(['Source' => 'manual', 'LastSyncedAt' => '2026-07-01 00:00:00'], $now);
t('old manual data is flagged stale', $c['stale']);
$c = InventorySource::classify(null, $now);
t('no record at all never claims Live', !$c['live'] && $c['label'] === 'Manually maintained');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

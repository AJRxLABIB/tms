<?php
/**
 * scripts/expire_inventory_holds.php — returns the units held by expired inventory holds (hotels/activities).
 *
 * Usage:  php scripts/expire_inventory_holds.php           (dry run: lists lapsed holds)
 *         php scripts/expire_inventory_holds.php --apply   (expires them and frees the units)
 * Schedule: every 5 minutes. Safe to re-run: each hold is re-checked under a row lock.
 * Needs migration 023 (php database/migrate.php). Converted holds are never touched.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/services/InventoryService.php';

try {
    $dbh->query('SELECT 1 FROM tbl_inventory_holds LIMIT 1');
} catch (Throwable $e) {
    echo "tbl_inventory_holds does not exist yet: run `php database/migrate.php` first.\n";
    exit(0);
}

$apply = in_array('--apply', $argv ?? [], true);
$r = InventoryService::sweepExpired($dbh, !$apply);
echo ($apply ? 'APPLY' : 'DRY RUN') . ": {$r['found']} lapsed active hold(s)";
echo $apply ? ", {$r['expired']} expired.\n" : ".\n";
if (!$apply) foreach ($r['ids'] as $id) echo "  would expire hold #{$id}\n";

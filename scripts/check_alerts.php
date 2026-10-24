<?php
/**
 * scripts/check_alerts.php — cron entry point for Rule 26/27 (price /
 * availability alerts) and Rule 51 (background jobs kept out of normal
 * page requests).
 *
 * This project has no real queue/worker infrastructure (no Redis, no
 * Beanstalkd) and adding one for a handful of periodic checks would be
 * overengineering for its size (Rule 73) -- a plain cron-invoked PHP script
 * is the right amount of infrastructure here. On XAMPP/most shared hosts,
 * schedule it with the OS's own scheduler:
 *
 *   Windows (Task Scheduler): run every 30-60 min:
 *     php.exe "C:\xampp\htdocs\tourbuddy\scripts\check_alerts.php"
 *   Linux/shared hosting (cron):
 *     every 30 minutes: php /path/to/tourbuddy/scripts/check_alerts.php >> /path/to/tourbuddy/scripts/alerts.log 2>&1
 *
 * CLI-only by design -- this does real writes (fires notifications, flips
 * alerts inactive) and must never be reachable over HTTP.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is CLI-only.');
}

require __DIR__ . '/../backend/config.php';
require __DIR__ . '/../backend/AlertService.php';

// Rule 26 ("Track historical prices where legitimate data is available"):
// snapshot today's price for every active package on the same cadence this
// script already runs at, before checking alerts against it -- no separate
// cron entry needed (Rule 73).
$snapshotCount = tms_snapshot_all_package_prices($dbh);

$result = tms_check_and_fire_alerts($dbh);

echo date('c') . " check_alerts: recorded {$snapshotCount} price snapshot(s), fired {$result['price']} price alert(s), {$result['availability']} availability alert(s).\n";

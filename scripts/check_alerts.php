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
 *     php.exe "C:\xampp\htdocs\tms\scripts\check_alerts.php"
 *   Linux/shared hosting (cron):
 *     every 30 minutes: php /path/to/tms/scripts/check_alerts.php >> /path/to/tms/scripts/alerts.log 2>&1
 *
 * CLI-only by design -- this does real writes (fires notifications, flips
 * alerts inactive) and must never be reachable over HTTP.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is CLI-only.');
}

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/AlertService.php';

$result = tms_check_and_fire_alerts($dbh);

echo date('c') . " check_alerts: fired {$result['price']} price alert(s), {$result['availability']} availability alert(s).\n";

<?php
/**
 * scripts/run_jobs.php — background worker for the MySQL outbox (EventBus) and workflow rules.
 *
 *   php scripts/run_jobs.php --once              process due events once and exit (use this from cron / Task Scheduler)
 *   php scripts/run_jobs.php                     keep running; sleep when idle (supervise with Task Scheduler/systemd)
 *   php scripts/run_jobs.php --max=50            events per batch (default 20, max 200)
 *   php scripts/run_jobs.php --rules             also evaluate enabled workflow rules each cycle
 *   php scripts/run_jobs.php --rules --dry-run   evaluate rules WITHOUT acting (writes dry_run audit rows only)
 *   php scripts/run_jobs.php --stats             print queue counts and exit
 *   php scripts/run_jobs.php --replay=ID         put a dead/failed event back in the queue and exit
 *
 * Graceful stop: Ctrl+C / SIGTERM finish the current batch first (when the pcntl extension exists; otherwise create
 * the file uploads/.stop-worker to stop after the current cycle).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/services/EventBus.php';
require_once __DIR__ . '/../backend/services/NotificationConsumer.php';
require_once __DIR__ . '/../backend/services/RulesEngine.php';

$opt = static function (string $name, $default = null) use ($argv) {
    foreach ($argv ?? [] as $a) {
        if ($a === "--$name") return true;
        if (str_starts_with($a, "--$name=")) return substr($a, strlen($name) + 3);
    }
    return $default;
};

if ($opt('stats')) { print_r(EventBus::stats($dbh)); exit(0); }
if (($rid = $opt('replay')) !== null && $rid !== true) {
    echo EventBus::replay($dbh, (int)$rid) ? "Event {$rid} queued for replay.\n" : "Event {$rid} not found or not in a replayable state.\n";
    exit(0);
}

$once   = (bool)$opt('once', false);
$limit  = max(1, min(200, (int)$opt('max', 20)));
$rules  = (bool)$opt('rules', false);
$dry    = (bool)$opt('dry-run', false);
$worker = gethostname() . ':' . getmypid();
$stopFlag = __DIR__ . '/../uploads/.stop-worker';

EventBus::registerConsumer(new NotificationConsumer($dbh));

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) pcntl_signal($sig, static function () use (&$running) { $running = false; });
}

echo "[run_jobs] worker {$worker} started" . ($once ? ' (once)' : '') . "\n";
$idle = 0;
do {
    try {
        $c = EventBus::runBatch($dbh, $worker, $limit);
        $handled = $c['done'] + $c['failed'] + $c['dead'];
        if ($handled || $c['recovered']) echo sprintf("[run_jobs] done=%d failed=%d dead=%d recovered=%d\n", $c['done'], $c['failed'], $c['dead'], $c['recovered']);
        if ($rules) {
            $r = RulesEngine::evaluate($dbh, $dry);
            if ($r['matched']) echo sprintf("[run_jobs] rules: matched=%d acted=%d dry=%d skipped=%d failed=%d\n", $r['matched'], $r['acted'], $r['dry'], $r['skipped'], $r['failed']);
        }
    } catch (Throwable $e) {
        // Most likely the tables are not migrated yet (php database/migrate.php). Never crash-loop silently.
        error_log('[TourBuddy run_jobs] cycle failed: ' . $e->getMessage());
        echo '[run_jobs] cycle failed: ' . $e->getMessage() . "\n";
        $handled = 0;
        if ($once) exit(1);
    }
    if ($once) break;
    $idle = $handled ? 0 : min($idle + 1, 6);
    sleep($handled ? 1 : (int)min(30, 2 ** $idle));              // 2,4,8,16,30,30s when idle
    if (is_file($stopFlag)) { @unlink($stopFlag); $running = false; }
} while ($running);
echo "[run_jobs] stopped\n";

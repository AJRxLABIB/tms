<?php
/**
 * scripts/check_trip_disruptions.php — Trip Autopilot (see TRIP_AUTOPILOT.md). CLI/cron only.
 *
 * For every non-cancelled, not-yet-finished booking that starts within --days (default 30) or is under way:
 * if an admin-verified disruption notice (or a flagged forecast day) applies, store ONE pending proposal and notify the
 * customer. It never edits an itinerary or booking; the customer must approve on the My Trip page.
 *
 *   php scripts/check_trip_disruptions.php [--days=30] [--limit=50] [--no-ai]
 *
 * --no-ai skips the planner entirely (alerts only, no revised plan, no weather lookup). --limit caps AI calls per run.
 * Schedule daily, e.g. Windows Task Scheduler: php.exe "D:\XAMPP\htdocs\tourbuddy\scripts\check_trip_disruptions.php"
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/services/TripAutopilot.php';

$opt = ['days' => 30, 'limit' => 50, 'no-ai' => false];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--days=(\d+)$/', $a, $m)) $opt['days'] = max(1, (int)$m[1]);
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m)) $opt['limit'] = max(1, (int)$m[1]);
    elseif ($a === '--no-ai') $opt['no-ai'] = true;
}

$planner = null;
if (!$opt['no-ai']) {
    require_once __DIR__ . '/../backend/ai/ItineraryPlanner.php';
    $planner = new ItineraryPlanner($dbh);
}

$closed = TripAutopilot::expireStale($dbh);
$st = $dbh->prepare("SELECT b.*, p.PackageLocation FROM tblbooking b LEFT JOIN tbltourpackages p ON p.PackageId=b.PackageId
    WHERE b.status <> 2 AND b.ToDate >= CURDATE() AND b.FromDate <= DATE_ADD(CURDATE(), INTERVAL :d DAY) ORDER BY b.FromDate LIMIT :l");
$st->bindValue(':d', $opt['days'], PDO::PARAM_INT);
$st->bindValue(':l', $opt['limit'], PDO::PARAM_INT);
$st->execute();
$counts = ['no_disruption' => 0, 'proposal_ready' => 0, 'alert_only' => 0];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
    try {
        $r = TripAutopilot::checkBooking($dbh, $b, $planner, true);
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
    } catch (Throwable $e) {
        error_log('[TourBuddy Autopilot] booking ' . $b['BookingId'] . ': ' . $e->getMessage());
        fwrite(STDERR, "booking {$b['BookingId']} failed: " . $e->getMessage() . "\n");
    }
}
echo "Closed stale: $closed. " . json_encode($counts) . "\n";

<?php
/**
 * scripts/check_budget_overspend.php — AI Budget Autopilot ("TMS V2" prompt).
 * CLI/cron only, same reasoning as scripts/check_wallet_expiry.php: a plain
 * periodic script is the right amount of infrastructure for this project's
 * size (Rule 73), no queue broker needed.
 *
 * For every active (non-cancelled), not-yet-finished booking with a real
 * budget (TotalCost > 0), computes the real spend-vs-budget comparison
 * (backend/ai/ExpenseAnalyst::getBudgetComparison() -- exact SQL aggregates
 * over what the traveler actually logged, never invented) and notifies once
 * per meaningful threshold crossing via BudgetAutopilot::shouldNotify(),
 * using tblbudget_alerts.LastNotifiedAt to avoid re-notifying every run
 * (same dedup idiom as check_wallet_expiry.php's ExpiryNotifiedAt).
 *
 * Schedule: daily, e.g.
 *   Windows Task Scheduler: php.exe "C:\xampp\htdocs\tms\scripts\check_budget_overspend.php"
 *   Linux cron: 0 9 * * * php /path/to/tms/scripts/check_budget_overspend.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is CLI-only.');
}

require __DIR__ . '/../backend/config.php';
require __DIR__ . '/../backend/ai/ExpenseAnalyst.php';
require __DIR__ . '/../backend/services/BudgetAutopilot.php';

$analyst = new ExpenseAnalyst($dbh);

// status IN (0,1): not cancelled. ToDate >= today: don't keep alerting
// about a trip that's already over -- the traveler can't change their
// spending on a finished trip.
$bookings = $dbh->query(
    "SELECT b.BookingId, b.UserEmail, b.TotalCost, b.ToDate, p.PackageName
     FROM tblbooking b
     JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.status IN (0,1) AND b.ToDate >= CURDATE() AND b.TotalCost > 0"
)->fetchAll(PDO::FETCH_OBJ);

$notified = 0;
foreach ($bookings as $b) {
    $comparison = $analyst->getBudgetComparison($b->UserEmail, (int)$b->BookingId);

    $alertRow = $dbh->prepare("SELECT LastNotifiedAt FROM tblbudget_alerts WHERE BookingId = :bid");
    $alertRow->execute([':bid' => $b->BookingId]);
    $lastNotifiedAt = $alertRow->fetchColumn();
    $lastNotifiedAt = $lastNotifiedAt !== false ? $lastNotifiedAt : null;

    if (!BudgetAutopilot::shouldNotify($comparison, $lastNotifiedAt)) {
        continue;
    }

    $ref = 'BK' . str_pad((string)$b->BookingId, 3, '0', STR_PAD_LEFT);
    $insight = $analyst->generateInsight($b->UserEmail, (int)$b->BookingId);
    $message = $comparison['over_budget']
        ? "Heads up — your spending for \"{$b->PackageName}\" (#{$ref}) is now over budget. {$insight}"
        : "Your spending for \"{$b->PackageName}\" (#{$ref}) is at {$comparison['percent_used']}% of budget. {$insight}";

    tms_notify($dbh, $b->UserEmail, $message, 'expense-tracker.php?booking=' . (int)$b->BookingId);

    $dbh->prepare(
        "INSERT INTO tblbudget_alerts (BookingId, LastNotifiedAt, LastPercentUsed) VALUES (:bid, NOW(), :pct)
         ON DUPLICATE KEY UPDATE LastNotifiedAt = NOW(), LastPercentUsed = :pct2"
    )->execute([':bid' => $b->BookingId, ':pct' => $comparison['percent_used'], ':pct2' => $comparison['percent_used']]);

    $notified++;
}

echo date('c') . " check_budget_overspend: notified {$notified} booking(s).\n";

<?php
/**
 * BudgetAutopilot.php — "TourBuddy V2" prompt, AI Budget Autopilot.
 *
 * Confirmed genuinely new before this: backend/ai/BudgetOptimizer.php and
 * frontend/expense-tracker.php already do real, REACTIVE budget tracking
 * (an over/under-budget bar, "over budget by X" messaging) computed from
 * backend/ai/ExpenseAnalyst::getBudgetComparison() -- but nothing
 * proactively tells a traveler their spending is trending over budget
 * without them opening the page themselves. This adds exactly that one
 * missing piece: a periodic check (scripts/check_budget_overspend.php,
 * same cron idiom as scripts/check_wallet_expiry.php) that notifies once
 * per meaningful threshold crossing, using tblbudget_alerts to avoid
 * re-notifying every run.
 *
 * Split into a pure shouldNotify() (no DB, fully unit-testable) and the
 * CLI script does the DB loop + tms_notify() calls, same shape as
 * BookingStateMachine / TripReadinessScore.
 */
class BudgetAutopilot
{
    /**
     * @param array{budget:int, spent:int, percent_used:?int, over_budget:bool} $comparison  From ExpenseAnalyst::getBudgetComparison().
     * @param string|null $lastNotifiedAt  'Y-m-d H:i:s' or null if never notified.
     * @param int $thresholdPct  Percent-used that counts as "trending over budget" even if not yet over (default 90).
     * @param int $cooldownDays  Minimum days between notifications for the same booking (default 3, avoids spamming a traveler who's genuinely over budget on every run).
     */
    public static function shouldNotify(
        array $comparison,
        ?string $lastNotifiedAt,
        int $thresholdPct = 90,
        int $cooldownDays = 3,
        ?int $nowTimestamp = null
    ): bool {
        // No real budget to compare against (TotalCost 0/null) -- nothing
        // meaningful to alert on, never fabricate a threshold.
        if (empty($comparison['budget']) || $comparison['budget'] <= 0) {
            return false;
        }
        if ($comparison['percent_used'] === null) {
            return false;
        }

        $overOrTrending = !empty($comparison['over_budget']) || $comparison['percent_used'] >= $thresholdPct;
        if (!$overOrTrending) {
            return false;
        }

        if ($lastNotifiedAt === null) {
            return true;
        }

        $now = $nowTimestamp ?? time();
        $daysSinceLastNotify = ($now - strtotime($lastNotifiedAt)) / 86400;
        return $daysSinceLastNotify >= $cooldownDays;
    }
}

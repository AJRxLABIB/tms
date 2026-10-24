<?php
/**
 * scripts/expire_pending_bookings.php — releases seats held by bookings that were never paid.
 *
 * Why: tms_remaining_seats() counts status IN (0,1), so an abandoned pending booking holds its seats (and pushes
 * dynamic prices up) forever. This job cancels a booking only when ALL of these hold:
 *   - status = 0 (pending), never confirmed;
 *   - PaymentStatus is empty / unpaid / failed (nothing paid, nothing in flight: a 'pending' payment is skipped so a
 *     late gateway confirmation is never racing a cancellation);
 *   - no tblpayments row is paid or pending for it;
 *   - registered more than TMS_BOOKING_HOLD_MINUTES ago.
 *
 * OFF BY DEFAULT: set TMS_BOOKING_HOLD_MINUTES in .env (e.g. 60) to enable. Whether unpaid pending bookings should
 * expire is a business policy, so nothing changes until the operator chooses a value.
 *
 * Usage:  php scripts/expire_pending_bookings.php            (dry run: lists what would expire)
 *         php scripts/expire_pending_bookings.php --apply    (cancels them)
 * Schedule: every 10-15 minutes (see SCHEDULED_JOBS.md). Safe to re-run; each booking is re-checked under a row lock.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';

$minutes = (int) tms_env('TMS_BOOKING_HOLD_MINUTES', 0);
if ($minutes <= 0) {
    echo "Disabled: set TMS_BOOKING_HOLD_MINUTES (minutes, e.g. 60) in .env to enable.\n";
    exit(0);
}
$minutes = max(10, $minutes);                    // never expire a booking younger than 10 minutes
$apply   = in_array('--apply', $argv ?? [], true);

$unpaid = "(b.PaymentStatus IS NULL OR b.PaymentStatus IN ('', 'unpaid', 'failed'))";
$noMoney = "NOT EXISTS (SELECT 1 FROM tblpayments p WHERE p.BookingId = b.BookingId AND p.Status IN ('paid','pending'))";

$ids = $dbh->prepare(
    "SELECT b.BookingId FROM tblbooking b
     WHERE b.status = 0 AND $unpaid AND $noMoney
       AND b.RegDate IS NOT NULL AND b.RegDate < DATE_SUB(NOW(), INTERVAL :m MINUTE)
     ORDER BY b.BookingId LIMIT 500"
);
$ids->bindValue(':m', $minutes, PDO::PARAM_INT);
$ids->execute();
$candidates = $ids->fetchAll(PDO::FETCH_COLUMN);

echo ($apply ? 'APPLY' : 'DRY RUN') . ": " . count($candidates) . " booking(s) older than {$minutes} min, pending and unpaid.\n";
$done = 0;
foreach ($candidates as $bid) {
    $bid = (int)$bid;
    if (!$apply) { echo "  would expire BK{$bid}\n"; continue; }
    try {
        $dbh->beginTransaction();
        // Re-check under a lock: a payment or an admin action may have landed since the list was read.
        $chk = $dbh->prepare("SELECT b.BookingId, b.UserEmail FROM tblbooking b WHERE b.BookingId = :id AND b.status = 0 AND $unpaid AND $noMoney FOR UPDATE");
        $chk->execute([':id' => $bid]);
        $locked = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$locked) { $dbh->rollBack(); continue; }
        $dbh->prepare(
            "UPDATE tblbooking SET status = 2, CancelledBy = 'sys', RequestedFromDate = NULL, RequestedToDate = NULL,
                    ModificationStatus = NULL, ModificationNote = NULL
             WHERE BookingId = :id AND status = 0"
        )->execute([':id' => $bid]);
        if (function_exists('tms_loyalty_reverse_booking')) tms_loyalty_reverse_booking($dbh, $bid);   // joins this transaction
        $dbh->commit();
        $done++;
        // Durable event for the worker (customer notification etc.). After commit; idempotent per booking; never throws.
        require_once __DIR__ . '/../backend/services/EventBus.php';
        EventBus::publish($dbh, 'BookingExpired', 'booking', $bid, ['booking_id' => $bid, 'email' => (string)$locked['UserEmail'], 'minutes' => $minutes], 'BookingExpired:BK' . $bid);
        echo "  expired BK{$bid}\n";
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log('[TourBuddy expire_pending_bookings] BK' . $bid . ': ' . $e->getMessage());
        echo "  FAILED BK{$bid} (see error log)\n";
    }
}
if ($apply) echo "Expired {$done} booking(s).\n";

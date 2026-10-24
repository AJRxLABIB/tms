<?php
/**
 * backend/system-events.php — master prompt item 56 ("MONITORING": track
 * error rate/failures for Database, AI, Weather, Maps, Payments, Email,
 * External APIs, including explicitly "booking failures" and "payment
 * failures").
 *
 * Most of item 56 already existed before this file, just spread across
 * different places: AI success-rate/latency/token-usage already has its
 * own dedicated table (tbl_ai_telemetry, see backend/ai/AIClient.php) and
 * admin view (admin/ai-dashboard.php); payment failures/pending counts
 * already surface on admin/operations-dashboard.php via tblpayments;
 * database up/down + latency already has a safe, credential-free JSON
 * check at health.php (Rule 57). Two real gaps remained with genuinely
 * zero visibility anywhere: email send failures, and booking-checkout
 * transaction failures (both previously only reached the PHP error log,
 * which no admin page reads). tbl_system_events + tms_log_system_event()
 * below is a small, generic, reusable log for exactly those two cases
 * (and any similar one added later) -- not a duplicate of tbl_ai_telemetry
 * or tblpayment_events, which stay as they are.
 *
 * tms_log_activity()'s (backend/log-activity.php) job is admin-actor audit
 * trail ("who did what"); this is a different concern -- system-level
 * success/failure of a background operation with no human actor to blame,
 * which is why it's a separate table/function rather than overloading
 * tblactivity_log.
 */
function tms_log_system_event(PDO $dbh, string $category, string $eventType, bool $success, ?string $message = null): void {
    try {
        // Truncate defensively -- an exception message (e.g. a raw PDO
        // error) can be arbitrarily long; the column is capped at 255.
        $msg = $message !== null ? mb_substr($message, 0, 255) : null;
        $dbh->prepare(
            "INSERT INTO tbl_system_events(category, event_type, success, message) VALUES(:c, :e, :s, :m)"
        )->execute([':c' => $category, ':e' => $eventType, ':s' => $success ? 1 : 0, ':m' => $msg]);
    } catch (Exception $e) {
        // Never let monitoring itself become a new failure mode -- same
        // silently-fail-closed principle as tms_log_activity().
        error_log('[TMS SystemEvents] Failed to log event: ' . $e->getMessage());
    }
}

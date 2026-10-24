<?php
require_once __DIR__ . '/EventBus.php';

/**
 * NotificationConsumer — turns booking events into the customer's in-app notification (tms_notify -> tbl_notifications,
 * the bell icon). Idempotent through EventBus deliveries: it runs at most once per event even when other consumers fail
 * and the event is retried. Email is deliberately not sent here (no SMTP is assumed); add a MailConsumer when it is.
 */
class NotificationConsumer implements EventConsumer
{
    private PDO $dbh;
    public function __construct(PDO $dbh) { $this->dbh = $dbh; }

    public function name(): string { return 'notification'; }
    public function handles(): array { return ['BookingExpired', 'BookingCancelled', 'BookingConfirmed']; }

    public function handle(array $event): void
    {
        $p = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $email = (string)($p['email'] ?? '');
        $bid = (int)($p['booking_id'] ?? $event['aggregate_id'] ?? 0);
        if ($email === '' || $bid <= 0) return;                       // nothing to notify: success, not a retry
        $msg = match ($event['event_type']) {
            'BookingExpired'   => "Your booking #BK{$bid} was released because payment was not completed in time.",
            'BookingCancelled' => "Your booking #BK{$bid} has been cancelled.",
            'BookingConfirmed' => "Your booking #BK{$bid} is confirmed.",
            default            => '',
        };
        if ($msg === '') return;
        if (!function_exists('tms_notify')) throw new RuntimeException('tms_notify unavailable (config.php not loaded)');
        tms_notify($this->dbh, $email, $msg, 'tour-history.php');
    }
}

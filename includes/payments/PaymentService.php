<?php
require_once __DIR__ . '/PaymentProviderInterface.php';
require_once __DIR__ . '/SandboxProvider.php';

/**
 * PaymentService — the one place the rest of the app talks to for anything
 * payment-related (Rule 40: business logic in a service layer, not scattered
 * across page templates). checkout.php and payment.php are the only two
 * callers today.
 */
class PaymentService
{
    private PDO $dbh;

    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    /**
     * Which provider is actually active. Real providers (SSLCommerz/bKash/
     * Nagad) only activate themselves once real, validated credentials exist
     * in .env -- until then, this always returns the sandbox so the app
     * never pretends a real payment happened (Rule 3/11).
     */
    public function getProvider(): PaymentProviderInterface
    {
        $configured = tms_env('PAYMENT_PROVIDER', 'sandbox');
        // Real provider classes are intentionally not auto-loaded here yet --
        // see includes/payments/SslcommerzProvider.php etc. Each one throws
        // in its constructor unless real credentials are present, and none
        // are configured in this project today (no merchant account exists),
        // so defaulting to sandbox is the honest behavior, not a placeholder.
        return new SandboxProvider();
    }

    /** ৳ (or any currency) float amount -> integer minor units, the only
     *  representation tblpayments.AmountMinor accepts (Rule 8). */
    public static function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Create a pending payment record for a booking and start it with the
     * active provider. Returns the new tblpayments row (as an array) plus
     * whatever the provider's initiate() returned.
     */
    public function createPendingPayment(int $bookingId, float $amountFloat, string $currency = 'BDT'): array
    {
        $provider = $this->getProvider();
        $amountMinor = self::toMinorUnits($amountFloat);
        $idempotencyKey = hash('sha256', $bookingId . '|' . $amountMinor . '|' . $provider->name() . '|' . date('Y-m-d'));

        // Idempotent by design: re-running checkout for the same booking on
        // the same day with the same amount returns the existing pending
        // payment instead of creating a duplicate (Rule 11).
        $existing = $this->dbh->prepare("SELECT * FROM tblpayments WHERE IdempotencyKey = :k");
        $existing->execute([':k' => $idempotencyKey]);
        if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
            return $row;
        }

        $initiation = $provider->initiate($amountMinor, $currency, ['booking_id' => $bookingId]);

        $ins = $this->dbh->prepare(
            "INSERT INTO tblpayments(BookingId, Provider, ProviderMode, ProviderTransactionId, AmountMinor, Currency, Status, IdempotencyKey, RawResponse)
             VALUES(:bid, :prov, :mode, :ptid, :amt, :cur, 'pending', :idk, :raw)"
        );
        $ins->execute([
            ':bid' => $bookingId,
            ':prov' => $provider->name(),
            ':mode' => $provider->mode(),
            ':ptid' => $initiation['provider_transaction_id'],
            ':amt' => $amountMinor,
            ':cur' => $currency,
            ':idk' => $idempotencyKey,
            ':raw' => json_encode($initiation['raw'] ?? []),
        ]);
        $paymentId = (int)$this->dbh->lastInsertId();

        $this->dbh->prepare("UPDATE tblbooking SET PaymentStatus='pending', PaymentId=:pid WHERE BookingId=:bid")
            ->execute([':pid' => $paymentId, ':bid' => $bookingId]);

        $payment = $this->dbh->prepare("SELECT * FROM tblpayments WHERE PaymentId = :id");
        $payment->execute([':id' => $paymentId]);
        return $payment->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Confirm a sandbox payment. This is the ONLY path that can mark a
     * payment 'paid' right now, and it only works for Provider='sandbox' --
     * a real provider must instead go through handleWebhook() with a
     * verified signature (Rule 11: never trust a browser return URL alone).
     */
    public function confirmSandboxPayment(int $paymentId, string $ownerEmail): bool
    {
        $stmt = $this->dbh->prepare(
            "SELECT p.*, b.UserEmail FROM tblpayments p
             JOIN tblbooking b ON b.BookingId = p.BookingId
             WHERE p.PaymentId = :id"
        );
        $stmt->execute([':id' => $paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment || $payment['Provider'] !== 'sandbox') {
            return false;
        }
        // IDOR guard (Rule 44): only the booking's own owner can confirm it.
        if (strcasecmp((string)$payment['UserEmail'], (string)$ownerEmail) !== 0) {
            return false;
        }
        if ($payment['Status'] === 'paid') {
            return true; // already confirmed -- idempotent
        }

        $this->dbh->beginTransaction();
        try {
            $this->dbh->prepare("UPDATE tblpayments SET Status='paid', RawResponse=:raw WHERE PaymentId=:id")
                ->execute([':id' => $paymentId, ':raw' => json_encode(['confirmed_at' => date('c'), 'mode' => 'sandbox'])]);
            $this->dbh->prepare("UPDATE tblbooking SET PaymentStatus='paid', status=1 WHERE BookingId=:bid")
                ->execute([':bid' => $payment['BookingId']]);
            $this->dbh->prepare(
                "INSERT INTO tblpayment_events(PaymentId, Provider, EventType, SignatureValid, Payload, ProcessedAt)
                 VALUES(:pid, 'sandbox', 'sandbox.confirmed', 1, :payload, NOW())"
            )->execute([':pid' => $paymentId, ':payload' => json_encode(['booking_id' => $payment['BookingId']])]);
            $this->dbh->commit();
            return true;
        } catch (Exception $e) {
            $this->dbh->rollBack();
            error_log('[TMS Payments] confirmSandboxPayment failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Links the existing, already-working booking-cancellation refund flow
     * (tour-history.php sets tblbooking.RefundStatus='requested' when a
     * *confirmed* booking is cancelled -- see that file) to the payment
     * ledger this round added. Before this, that flow never touched
     * tblpayments/tblpayment_refunds at all, so a cancelled booking that had
     * actually been paid through the new sandbox flow showed a refund
     * request to admins with no linked payment record to reconcile against.
     * No-op (and safe to call unconditionally) when the booking was never
     * paid through this payment system -- most historical bookings weren't,
     * since payment.php is new this round.
     */
    public function logRefundRequest(int $bookingId, string $reason = 'Booking cancelled by customer'): void
    {
        try {
            $payment = $this->dbh->prepare(
                "SELECT PaymentId, AmountMinor FROM tblpayments WHERE BookingId = :bid AND Status = 'paid' LIMIT 1"
            );
            $payment->execute([':bid' => $bookingId]);
            $row = $payment->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return; // nothing paid through this system for this booking
            }

            // Idempotent: don't log a second refund request if one is
            // already pending/processed for this payment.
            $existing = $this->dbh->prepare(
                "SELECT RefundId FROM tblpayment_refunds WHERE PaymentId = :pid AND Status IN ('pending','processed') LIMIT 1"
            );
            $existing->execute([':pid' => $row['PaymentId']]);
            if ($existing->fetch()) {
                return;
            }

            $this->dbh->prepare(
                "INSERT INTO tblpayment_refunds(PaymentId, AmountMinor, Reason, Status) VALUES(:pid, :amt, :reason, 'pending')"
            )->execute([':pid' => $row['PaymentId'], ':amt' => $row['AmountMinor'], ':reason' => $reason]);
        } catch (Exception $e) {
            error_log('[TMS Payments] logRefundRequest failed for booking ' . $bookingId . ': ' . $e->getMessage());
        }
    }

    /**
     * Admin approved the refund in Manage Bookings (existing 'refund_processed'
     * action) -- if this booking had a linked sandbox payment, mark it
     * refunded too so tblpayments/tblbooking.PaymentStatus stay truthful
     * (no real money moves here; sandbox never captured any).
     */
    public function markRefundProcessed(int $bookingId): void
    {
        try {
            $payment = $this->dbh->prepare("SELECT PaymentId FROM tblpayments WHERE BookingId = :bid AND Status = 'paid' LIMIT 1");
            $payment->execute([':bid' => $bookingId]);
            $paymentId = $payment->fetchColumn();
            if (!$paymentId) {
                return;
            }
            $this->dbh->prepare("UPDATE tblpayments SET Status='refunded' WHERE PaymentId=:id")->execute([':id' => $paymentId]);
            $this->dbh->prepare("UPDATE tblbooking SET PaymentStatus='refunded' WHERE BookingId=:bid")->execute([':bid' => $bookingId]);
            $this->dbh->prepare("UPDATE tblpayment_refunds SET Status='processed', ProcessedAt=NOW() WHERE PaymentId=:id AND Status='pending'")
                ->execute([':id' => $paymentId]);
        } catch (Exception $e) {
            error_log('[TMS Payments] markRefundProcessed failed for booking ' . $bookingId . ': ' . $e->getMessage());
        }
    }

    /** Mirror of markRefundProcessed() for the 'refund_rejected' admin action. */
    public function markRefundRejected(int $bookingId, string $note): void
    {
        try {
            $payment = $this->dbh->prepare("SELECT PaymentId FROM tblpayments WHERE BookingId = :bid AND Status = 'paid' LIMIT 1");
            $payment->execute([':bid' => $bookingId]);
            $paymentId = $payment->fetchColumn();
            if (!$paymentId) {
                return;
            }
            $this->dbh->prepare("UPDATE tblpayment_refunds SET Status='failed', Reason=:n, ProcessedAt=NOW() WHERE PaymentId=:id AND Status='pending'")
                ->execute([':id' => $paymentId, ':n' => $note]);
        } catch (Exception $e) {
            error_log('[TMS Payments] markRefundRejected failed for booking ' . $bookingId . ': ' . $e->getMessage());
        }
    }

    /**
     * Entry point for real provider webhooks (Rule 43). Not reachable today
     * since no real provider is configured, but the shape is here so wiring
     * in a real provider later means writing that provider's
     * verifyWebhook()/normalizeStatus() and pointing its callback URL here --
     * not redesigning this method.
     */
    public function handleWebhook(PaymentProviderInterface $provider, array $payload, array $headers): bool
    {
        $signatureValid = $provider->verifyWebhook($payload, $headers);

        $this->dbh->prepare(
            "INSERT INTO tblpayment_events(PaymentId, Provider, EventType, SignatureValid, Payload)
             VALUES(:pid, :prov, :type, :valid, :payload)"
        )->execute([
            ':pid' => $payload['payment_id'] ?? null,
            ':prov' => $provider->name(),
            ':type' => $payload['event'] ?? 'unknown',
            ':valid' => $signatureValid ? 1 : 0,
            ':payload' => json_encode($payload),
        ]);

        if (!$signatureValid) {
            error_log('[TMS Payments] Rejected webhook with invalid/unverifiable signature from ' . $provider->name());
            return false;
        }

        $status = $provider->normalizeStatus($payload);
        // Real reconciliation logic (update tblpayments/tblbooking from a
        // verified webhook) goes here once a real provider is actually
        // configured; intentionally not built further blind, since it needs
        // that provider's real payload shape to get right (Rule 3).
        return true;
    }
}

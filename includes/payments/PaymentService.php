<?php
require_once __DIR__ . '/PaymentProviderInterface.php';
require_once __DIR__ . '/SandboxProvider.php';
require_once __DIR__ . '/SslcommerzProvider.php';
require_once __DIR__ . '/BkashProvider.php';
require_once __DIR__ . '/NagadProvider.php';

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
        // The real provider classes (SslcommerzProvider/BkashProvider/
        // NagadProvider, required at the top of this file since Round 56
        // so handleWebhook() can construct them) each throw in their own
        // constructor unless real credentials are present, and none are
        // configured in this project today (no merchant account exists),
        // so defaulting to sandbox here is the honest behavior, not a
        // placeholder.
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
                "INSERT INTO tblpayment_events(PaymentId, Provider, EventType, SignatureValid, ProcessingStatus, Payload, ProcessedAt)
                 VALUES(:pid, 'sandbox', 'sandbox.confirmed', 1, 'processed', :payload, NOW())"
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
     * Entry point for real provider webhooks (Rule 43). One shared
     * implementation for every provider (sslcommerz/bkash/nagad) -- the
     * per-provider files under backend/webhooks/ are thin dispatchers that
     * just read the raw request and call this.
     *
     * What this guarantees regardless of which provider is calling:
     *  - the event is logged (event/payload/signature/timestamp) BEFORE any
     *    verification or business logic runs, so even a forged or malformed
     *    request leaves an audit trail (Rule 43's "store: event, payload,
     *    signature, timestamp, processing status, retry count");
     *  - a provider's own redelivery retries (every major gateway retries a
     *    webhook until it gets a 2xx) are recognized and NOT reprocessed,
     *    via a dedup key on (Provider, ProviderEventId) -- Rule 43's "use
     *    idempotency";
     *  - nothing here ever marks a payment 'paid' from an unverified
     *    request -- verifyWebhook() must return true first (Rule 43: "never
     *    trust client-side success messages for payments").
     *
     * What this deliberately does NOT do: implement real signature
     * verification for sslcommerz/bkash/nagad. Each provider's own
     * verifyWebhook() (backend/payments/*.php) returns false unconditionally
     * today because that requires that provider's real current documentation
     * and a real secret to test against, neither of which exists for this
     * project (no merchant account -- Rule 3/4). Wiring a real provider
     * later means implementing that ONE method for real; this shared
     * handler does not change.
     *
     * @param string $providerName 'sslcommerz' | 'bkash' | 'nagad'
     * @param string $rawBody      exact request body bytes (php://input) --
     *                             read before any $_POST access, since some
     *                             providers sign the raw bytes and PHP's
     *                             re-parsing would not preserve that.
     * @param array  $headers      every inbound request header (getallheaders())
     * @return array{status:int, body:array} HTTP status + JSON-able body for
     *         the calling endpoint file to send back.
     */
    public function handleWebhook(string $providerName, string $rawBody, array $headers): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = [];
            // Fall back to classic form-encoded callbacks (some gateways
            // still POST application/x-www-form-urlencoded rather than JSON).
            parse_str($rawBody, $payload);
        }

        // Which header carries the signature is provider-specific (see
        // class docblock above) -- this only captures *a* signature-looking
        // header generically for the audit trail; it is never used to
        // decide trust by itself.
        $signature = null;
        foreach ($headers as $hName => $hVal) {
            if (preg_match('/sign(ature)?$/i', (string)$hName)) {
                $signature = is_array($hVal) ? implode(',', $hVal) : (string)$hVal;
                break;
            }
        }

        $providerEventId = $payload['event_id'] ?? $payload['id'] ?? $payload['transactionId']
            ?? $payload['tran_id'] ?? $payload['paymentID'] ?? null;
        if (!$providerEventId) {
            $providerEventId = 'sha256:' . hash('sha256', $rawBody);
        }
        $providerEventId = (string)$providerEventId;

        try {
            // Dedup first: has this exact event already been logged? If so
            // this is a provider retry of a delivery we've already seen --
            // acknowledge it the same way without reprocessing.
            $existing = $this->dbh->prepare(
                "SELECT EventId, ProcessingStatus FROM tblpayment_events WHERE Provider = :prov AND ProviderEventId = :peid LIMIT 1"
            );
            $existing->execute([':prov' => $providerName, ':peid' => $providerEventId]);
            if ($dupe = $existing->fetch(PDO::FETCH_ASSOC)) {
                return ['status' => 200, 'body' => ['status' => 'duplicate', 'already' => $dupe['ProcessingStatus']]];
            }

            // Log unconditionally, before verification -- a forged or
            // malformed request still leaves a record.
            $ins = $this->dbh->prepare(
                "INSERT INTO tblpayment_events(PaymentId, Provider, ProviderEventId, EventType, SignatureValid, Signature, Payload, ProcessingStatus, RetryCount)
                 VALUES(NULL, :prov, :peid, :etype, 0, :sig, :payload, 'received', 0)"
            );
            $ins->execute([
                ':prov' => $providerName,
                ':peid' => $providerEventId,
                ':etype' => (string)($payload['event'] ?? $payload['type'] ?? 'unknown'),
                ':sig' => $signature,
                ':payload' => $rawBody !== '' ? $rawBody : json_encode($payload),
            ]);
            $eventId = (int)$this->dbh->lastInsertId();
        } catch (PDOException $e) {
            error_log("[TMS Webhooks] {$providerName}: failed to log inbound event -- " . $e->getMessage());
            return ['status' => 500, 'body' => ['status' => 'error', 'message' => 'Could not record event.']];
        }

        // Real provider classes throw in their constructor until real
        // credentials exist in .env (see PAYMENTS.md) -- that is the honest,
        // current state for all three today, so this fails closed rather
        // than pretending to verify anything.
        try {
            $provider = match ($providerName) {
                'sslcommerz' => new SslcommerzProvider(),
                'bkash' => new BkashProvider(),
                'nagad' => new NagadProvider(),
                default => null,
            };
        } catch (RuntimeException $e) {
            $this->markWebhookEvent($eventId, 'failed');
            error_log("[TMS Webhooks] {$providerName}: provider not configured -- " . $e->getMessage());
            return ['status' => 503, 'body' => ['status' => 'error', 'message' => 'This payment provider is not configured on this server yet.']];
        }

        if ($provider === null) {
            $this->markWebhookEvent($eventId, 'failed');
            return ['status' => 404, 'body' => ['status' => 'error', 'message' => 'Unknown provider.']];
        }

        try {
            $signatureValid = $provider->verifyWebhook($payload, $headers);
            $this->dbh->prepare("UPDATE tblpayment_events SET SignatureValid = :v WHERE EventId = :id")
                ->execute([':v' => $signatureValid ? 1 : 0, ':id' => $eventId]);

            if (!$signatureValid) {
                $this->markWebhookEvent($eventId, 'rejected');
                error_log("[TMS Webhooks] {$providerName}: rejected webhook with invalid/unverifiable signature.");
                return ['status' => 400, 'body' => ['status' => 'error', 'message' => 'Signature could not be verified.']];
            }

            // Real reconciliation (update tblpayments/tblbooking from a
            // verified webhook) intentionally goes here once a real
            // provider is actually configured and its normalizeStatus()/
            // payload shape can be tested against real data -- not built
            // further blind (Rule 3).
            $status = $provider->normalizeStatus($payload);
            $this->dbh->prepare("UPDATE tblpayment_events SET ProcessingStatus = 'processed', ProcessedAt = NOW() WHERE EventId = :id")
                ->execute([':id' => $eventId]);

            return ['status' => 200, 'body' => ['status' => 'ok']];
        } catch (Throwable $e) {
            $this->dbh->prepare("UPDATE tblpayment_events SET ProcessingStatus = 'failed', RetryCount = RetryCount + 1 WHERE EventId = :id")
                ->execute([':id' => $eventId]);
            error_log("[TMS Webhooks] {$providerName}: processing error -- " . $e->getMessage());
            return ['status' => 500, 'body' => ['status' => 'error', 'message' => 'Internal error while processing webhook.']];
        }
    }

    private function markWebhookEvent(int $eventId, string $status): void
    {
        $this->dbh->prepare("UPDATE tblpayment_events SET ProcessingStatus = :s WHERE EventId = :id")
            ->execute([':s' => $status, ':id' => $eventId]);
    }
}

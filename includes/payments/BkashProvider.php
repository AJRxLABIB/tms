<?php
/**
 * BkashProvider — bKash Payment Gateway (mobile financial service).
 *
 * Implementation status: Implemented (interface + status-mapping shape).
 * Testing status: BLOCKED — no bKash merchant/app credentials exist for
 *   this project. Throws until BKASH_APP_KEY / BKASH_APP_SECRET /
 *   BKASH_USERNAME / BKASH_PASSWORD are set in .env (Rule 3/77).
 * To activate for real: register at bKash's merchant/PGW developer portal,
 *   get sandbox credentials first, verify the current token/create-payment/
 *   execute-payment endpoint contract against bKash's own current docs
 *   (do not trust a hard-coded endpoint here without checking), then remove
 *   the guard below.
 */
class BkashProvider implements PaymentProviderInterface
{
    public function __construct()
    {
        $required = ['BKASH_APP_KEY', 'BKASH_APP_SECRET', 'BKASH_USERNAME', 'BKASH_PASSWORD'];
        foreach ($required as $key) {
            if (tms_env($key, '') === '') {
                throw new RuntimeException("BkashProvider is not configured ({$key} missing from .env). See PAYMENTS.md.");
            }
        }
    }

    public function name(): string { return 'bkash'; }
    public function mode(): string { return tms_env('BKASH_LIVE', false) === true ? 'live' : 'sandbox'; }

    public function initiate(int $amountMinor, string $currency, array $meta): array
    {
        throw new RuntimeException('BkashProvider::initiate() needs bKash\'s current grant-token + create-payment flow built here once real credentials exist. See class docblock.');
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        // bKash's model is callback + server-side "execute payment" / query
        // API rather than a signed webhook -- the real implementation must
        // call bKash back to confirm status server-side, never trust the
        // callback query string alone.
        return false;
    }

    public function normalizeStatus(array $payload): string
    {
        return match (strtolower($payload['transactionStatus'] ?? '')) {
            'completed' => 'paid',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
}

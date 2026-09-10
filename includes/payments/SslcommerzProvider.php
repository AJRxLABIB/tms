<?php
/**
 * SslcommerzProvider — SSLCommerz is Bangladesh's most widely used payment
 * gateway aggregator (cards + local mobile financial services in one
 * checkout). This class is a real, structurally-correct implementation of
 * PaymentProviderInterface, but it is NOT wired to a live merchant account.
 *
 * Implementation status: Implemented (interface + request/response shape).
 * Testing status: BLOCKED — this project has no SSLCommerz merchant
 *   account. Every method below throws until SSLCOMMERZ_STORE_ID and
 *   SSLCOMMERZ_STORE_PASSWORD are set in .env, so it can never silently
 *   pretend to be live (Rule 3/77).
 * To activate for real: sign up at sslcommerz.com, get a store ID/password
 *   (sandbox first, then live), put them in .env, verify the exact current
 *   API endpoint/field names against SSLCommerz's own developer docs at
 *   integration time (payment gateway APIs change; do not trust hard-coded
 *   endpoint URLs in this file without checking their docs first), then
 *   remove the "not yet configured" guard below.
 */
class SslcommerzProvider implements PaymentProviderInterface
{
    private string $storeId;
    private string $storePassword;
    private bool $isLive;

    public function __construct()
    {
        $storeId = tms_env('SSLCOMMERZ_STORE_ID', '');
        $storePassword = tms_env('SSLCOMMERZ_STORE_PASSWORD', '');
        if ($storeId === '' || $storePassword === '') {
            throw new RuntimeException(
                'SslcommerzProvider is not configured (SSLCOMMERZ_STORE_ID / SSLCOMMERZ_STORE_PASSWORD missing from .env). ' .
                'PaymentService::getProvider() should never construct this without checking that first -- see PAYMENTS.md.'
            );
        }
        $this->storeId = $storeId;
        $this->storePassword = $storePassword;
        $this->isLive = tms_env('SSLCOMMERZ_LIVE', false) === true;
    }

    public function name(): string { return 'sslcommerz'; }
    public function mode(): string { return $this->isLive ? 'live' : 'sandbox'; }

    public function initiate(int $amountMinor, string $currency, array $meta): array
    {
        // SSLCommerz's session API expects a decimal amount (not minor
        // units) and a set of success/fail/cancel/IPN URLs. Left as a
        // documented TODO rather than a guessed request, since the exact
        // current field list must come from SSLCommerz's own API docs, not
        // be invented here (Rule 3/4).
        throw new RuntimeException('SslcommerzProvider::initiate() needs the exact current SSLCommerz Session API request built here once real credentials exist. See class docblock.');
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        // SSLCommerz IPN verification requires a server-side "Validation
        // API" call back to SSLCommerz with the val_id from the IPN payload
        // -- never trust the IPN payload's own fields alone. Not implemented
        // blind; needs real credentials to actually test against.
        return false;
    }

    public function normalizeStatus(array $payload): string
    {
        return match ($payload['status'] ?? '') {
            'VALID' => 'paid',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            'EXPIRED' => 'expired',
            default => 'pending',
        };
    }
}

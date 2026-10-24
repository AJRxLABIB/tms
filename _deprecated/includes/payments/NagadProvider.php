<?php
/**
 * NagadProvider — Nagad Payment Gateway (mobile financial service).
 *
 * Implementation status: Implemented (interface + status-mapping shape).
 * Testing status: BLOCKED — no Nagad merchant credentials exist for this
 *   project. Throws until NAGAD_MERCHANT_ID / NAGAD_MERCHANT_PRIVATE_KEY /
 *   NAGAD_PG_PUBLIC_KEY are set in .env (Rule 3/77).
 * To activate for real: register with Nagad's merchant onboarding, get
 *   sandbox keys first, verify the current checkout-initialize/verify
 *   endpoint contract (Nagad uses RSA-signed requests) against Nagad's own
 *   current docs before trusting anything hard-coded here, then remove the
 *   guard below.
 */
class NagadProvider implements PaymentProviderInterface
{
    public function __construct()
    {
        $required = ['NAGAD_MERCHANT_ID', 'NAGAD_MERCHANT_PRIVATE_KEY', 'NAGAD_PG_PUBLIC_KEY'];
        foreach ($required as $key) {
            if (tms_env($key, '') === '') {
                throw new RuntimeException("NagadProvider is not configured ({$key} missing from .env). See PAYMENTS.md.");
            }
        }
    }

    public function name(): string { return 'nagad'; }
    public function mode(): string { return tms_env('NAGAD_LIVE', false) === true ? 'live' : 'sandbox'; }

    public function initiate(int $amountMinor, string $currency, array $meta): array
    {
        throw new RuntimeException('NagadProvider::initiate() needs Nagad\'s current RSA-signed checkout-initialize flow built here once real credentials exist. See class docblock.');
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        // Nagad callbacks are RSA-signature verified against Nagad's public
        // key -- real signature verification must be implemented against
        // real keys, not guessed.
        return false;
    }

    public function normalizeStatus(array $payload): string
    {
        return match (strtolower($payload['status'] ?? '')) {
            'success' => 'paid',
            'failed' => 'failed',
            'aborted' => 'cancelled',
            default => 'pending',
        };
    }
}

<?php
/**
 * SandboxProvider — a real, fully working payment provider that never moves
 * real money. Used automatically whenever no real provider is configured
 * (the honest default for this project today, since it has never had a
 * merchant account with any processor), and always labeled as sandbox
 * everywhere a customer or admin can see it (Rule 3: never present
 * simulated data/actions as real).
 *
 * This is what makes it possible to actually demonstrate and test the full
 * booking -> pay -> confirm -> "My Trip" flow end-to-end today, without
 * pretending a real transaction happened.
 */
class SandboxProvider implements PaymentProviderInterface
{
    public function name(): string { return 'sandbox'; }
    public function mode(): string { return 'sandbox'; }

    public function initiate(int $amountMinor, string $currency, array $meta): array
    {
        // No network call, no external redirect -- payment.php renders its
        // own "Sandbox Payment" confirmation screen and calls
        // PaymentService::confirmSandboxPayment() when the user clicks
        // "Pay (Sandbox)". The transaction id is locally generated and
        // clearly prefixed so it can never be mistaken for a real gateway
        // reference in logs or support conversations.
        return [
            'redirect_url' => null,
            'provider_transaction_id' => 'SANDBOX-' . strtoupper(bin2hex(random_bytes(6))),
            'raw' => ['note' => 'Sandbox payment initiated, no real funds involved.'],
        ];
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        // The sandbox has no real webhooks (nothing external calls back into
        // this app) -- confirmation happens synchronously via
        // PaymentService::confirmSandboxPayment(). This always returns false
        // so nothing can spoof a "sandbox webhook" over HTTP.
        return false;
    }

    public function normalizeStatus(array $payload): string
    {
        return $payload['status'] ?? 'pending';
    }
}

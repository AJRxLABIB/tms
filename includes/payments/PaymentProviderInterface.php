<?php
/**
 * PaymentProviderInterface — Rule 41/11 provider abstraction.
 *
 * Every payment provider (sandbox, SSLCommerz, bKash, Nagad, ...) implements
 * this same interface so PaymentService and the checkout flow never need to
 * know which one is actually configured. Swapping or adding a provider means
 * writing one new class here, never touching checkout.php/payment.php.
 */
interface PaymentProviderInterface
{
    /** Machine name used in tblpayments.Provider, e.g. 'sandbox', 'sslcommerz'. */
    public function name(): string;

    /** 'sandbox' or 'live'. A provider MUST refuse to run in 'live' mode
     *  until it has real, validated merchant credentials configured. */
    public function mode(): string;

    /**
     * Start a payment for a given amount (integer minor units) and return an
     * array the caller uses to send the customer onward:
     *   ['redirect_url' => string|null, 'provider_transaction_id' => string,
     *    'raw' => array] -- 'redirect_url' is null for providers (like the
     *    sandbox) that render an in-app confirmation instead of redirecting
     *    to a hosted payment page.
     */
    public function initiate(int $amountMinor, string $currency, array $meta): array;

    /**
     * Verify an inbound webhook/callback's authenticity (signature, secret,
     * or provider-specific check). MUST return false rather than throw on
     * anything ambiguous -- callers treat false as "do not trust this event".
     */
    public function verifyWebhook(array $payload, array $headers): bool;

    /** Map a provider's own status string/code to this app's normalized
     *  status: pending|authorized|paid|failed|cancelled|refunded|
     *  partially_refunded|expired (Rule 11). */
    public function normalizeStatus(array $payload): string;
}

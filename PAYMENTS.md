# Payment architecture

## Status today (be honest about this, per the project's own Rule 77)

Before this round, TMS had **no payment capture at all** — a booking was
created directly by checkout.php with `status=0` (Pending) and a human
admin had to manually flip it to `status=1` (Confirmed) from
`admin/manage-bookings.php`. That still works exactly as before.

What's new: a **Sandbox payment provider** that is real, working code (not
a mock button) but never touches real money — it's the honest default
because this project has never had a merchant account with any real
processor. Paying through it marks the booking `PaymentStatus='paid'` and
auto-confirms it (`status=1`); skipping it leaves the booking exactly as
Pending/unpaid as it always was.

## Why sandbox instead of a real gateway

Rule 3/4 of the transformation brief this was built against says: never
present simulated data or actions as real, and never fabricate integration
details for a real external service. Wiring in a real SSLCommerz/bKash/
Nagad integration requires a real merchant account (KYC, a registered
business, approval) that this project does not have. Rather than guessing
at API endpoints/field names (which change over time and would be actively
misleading if wrong), the three real-provider classes are written against
each provider's well-known **shape** (interface, status mapping, what kind
of secret each needs) and throw a clear error until real credentials are
filled into `.env` — see the docblock at the top of each file for exactly
what to do when a real merchant account exists.

## Architecture

```
includes/payments/
  PaymentProviderInterface.php   -- the contract every provider implements
  SandboxProvider.php            -- real, working, no real money (default)
  SslcommerzProvider.php         -- real shape, throws until configured
  BkashProvider.php              -- real shape, throws until configured
  NagadProvider.php              -- real shape, throws until configured
  PaymentService.php             -- the one place the app talks to payments
```

`PaymentService::getProvider()` decides which one is active. Today it
always returns `SandboxProvider` — activating a real one is a matter of
filling in that provider's `.env` keys and updating `getProvider()` to
construct it when `PAYMENT_PROVIDER` matches (left as the very next step
once real credentials exist, rather than half-wired now).

## Database (migration `002_payments.sql`, `003_booking_payment_columns.sql`)

- `tblpayments` — one row per payment attempt. `AmountMinor` is a real
  integer (poisha for BDT) — never a float — with a unique
  `IdempotencyKey` so retrying checkout never double-charges/double-creates.
- `tblpayment_events` — every inbound webhook/callback, verified or not,
  logged before anything acts on it (Rule 43).
- `tblpayment_refunds` — refunds are their own row, never an edit to the
  original payment. Wired into the site's existing, already-working
  cancellation flow rather than getting a second, separate "request refund"
  UI: when a customer cancels a *confirmed* booking (`tour-history.php`),
  `PaymentService::logRefundRequest()` logs a pending row here if that
  booking was actually paid through this system; when an admin resolves it
  in Manage Bookings (the existing `refund_processed`/`refund_rejected`
  actions), `markRefundProcessed()`/`markRefundRejected()` keep this table
  and `tblpayments.Status`/`tblbooking.PaymentStatus` in sync with that
  decision. No new admin screen needed — Manage Bookings' current refund
  queue already covers this, it just wasn't updating the payment ledger
  before.
- `tblbooking` gained `PaymentStatus` (`unpaid|pending|paid|failed|
  refunded|partially_refunded`) and `PaymentId`. `TotalCost` on
  `tblbooking` itself is **still a float** — changing that touches every
  page that reads it (~15 files) and wasn't safe to do as a drive-by change
  here (Rule 2). The new ledger (`tblpayments.AmountMinor`) is the
  integer-money source of truth; migrating `tblbooking.TotalCost` to
  integer minor units is tracked here as real, known follow-up work, not
  silently skipped.

## Running the migration

```
php database/migrate.php            # apply
php database/migrate.php --status   # see what's pending, changes nothing
```

Can also be run from a browser at `/database/migrate.php`, but only while
signed in as a `superadmin` admin — enforced by the file itself via the
project's existing `tms_admin_require_role()`, not by `.htaccess` alone.

**I could not run this migration myself** — this session has no network
path to your local MySQL/PHP (the sandbox this runs in is isolated from
your XAMPP install). Please run it yourself (CLI or the browser URL above)
and let me know the output — happy to fix anything that comes back
unexpected.

## What to do when a real merchant account exists

1. Pick a provider, sign up, get **sandbox** credentials first.
2. Fill its `.env` keys (see `.env.example` — `SSLCOMMERZ_*` / `BKASH_*` /
   `NAGAD_*`).
3. Open that provider's class here, verify the exact current endpoint/field
   names against the provider's own current developer docs (don't trust
   anything guessed in this file), and implement `initiate()` /
   `verifyWebhook()` for real.
4. Update `PaymentService::getProvider()` to construct that class when
   `PAYMENT_PROVIDER` in `.env` matches its name.
5. Test end-to-end in the provider's own sandbox mode before ever setting
   `*_LIVE=true`.

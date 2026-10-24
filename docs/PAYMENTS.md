# Payment architecture

## Status today (be honest about this, per the project's own Rule 77)

Before this round, TourBuddy had **no payment capture at all** — a booking was
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
backend/payments/
  PaymentProviderInterface.php   -- the contract every provider implements
  SandboxProvider.php            -- real, working, no real money (default)
  SslcommerzProvider.php         -- real shape, throws until configured
  BkashProvider.php              -- real shape, throws until configured
  NagadProvider.php              -- real shape, throws until configured
  PaymentService.php             -- the one place the app talks to payments

backend/webhooks/
  sslcommerz.php                 -- inbound webhook/IPN receiver (item 43)
  bkash.php                      -- inbound webhook/callback receiver
  nagad.php                      -- inbound webhook/callback receiver
```

(Historical note: this all lived under `includes/payments/` before Round
49's `includes/` → `backend/` rename. Unlike `includes/header.php` (still
required by `backend/landing.php` and kept in sync), a project-wide search
this round found nothing that still requires `includes/payments/*.php` --
it is genuinely orphaned, not a live duplicate, so it is intentionally left
as-is rather than kept in sync.)

`PaymentService::getProvider()` decides which one is active. Today it
always returns `SandboxProvider` — activating a real one is a matter of
filling in that provider's `.env` keys and updating `getProvider()` to
construct it when `PAYMENT_PROVIDER` matches (left as the very next step
once real credentials exist, rather than half-wired now).

## Database (migrations `002_payments.sql`, `003_booking_payment_columns.sql`, `005_webhook_hardening.sql`)

- `tblpayments` — one row per payment attempt. `AmountMinor` is a real
  integer (poisha for BDT) — never a float — with a unique
  `IdempotencyKey` so retrying checkout never double-charges/double-creates.
- `tblpayment_events` — every inbound webhook/callback, verified or not,
  logged before anything acts on it (Rule 43). Migration `005` (Round 56)
  added the columns item 43 explicitly asks for that were still missing:
  `ProviderEventId` (dedup key — a provider's own event/transaction id, or a
  sha256 of the raw body when a provider gives none), `Signature` (the raw
  signature/sign header value, not just a valid/invalid flag),
  `ProcessingStatus` (`received|processed|rejected|duplicate|failed`), and
  `RetryCount`. A unique key on `(Provider, ProviderEventId)` is what makes
  a provider's own redelivery retries idempotent — see "Inbound webhooks"
  below.
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

## Inbound webhooks (master prompt item 43, Round 56)

Before this round, `PaymentService::handleWebhook()` existed but nothing
HTTP-reachable ever called it — there was no public endpoint at all. Round
56 added one real, reachable receiver per real provider:

| Provider | Callback URL to give the merchant dashboard | Real file |
|---|---|---|
| SSLCommerz | `https://<your-domain>/webhook-sslcommerz.php` | `backend/webhooks/sslcommerz.php` |
| bKash | `https://<your-domain>/webhook-bkash.php` | `backend/webhooks/bkash.php` |
| Nagad | `https://<your-domain>/webhook-nagad.php` | `backend/webhooks/nagad.php` |

Each root file is a thin pass-through shim (this project's standard
URL-preserving pattern, `SESSION_SUMMARY_INDEX-1.md` Round 50) so the
callback URL you hand a provider's dashboard stays short and never needs to
change if the real file moves again later.

All three call the same shared logic, `PaymentService::handleWebhook()`,
which for every inbound request:

1. Rejects anything that isn't `POST` (405).
2. Reads the raw request body and every header **before** any `$_POST`
   parsing happens, since some providers sign the exact raw bytes.
3. **Logs the event first** — event type, full payload, whatever
   signature-looking header was present, and a timestamp — before any
   verification or business logic runs, so even a forged or malformed
   request leaves an audit trail in `tblpayment_events`.
4. **Deduplicates** by `(Provider, ProviderEventId)`: a provider's own
   redelivery retries (every major gateway retries a webhook until it gets
   a 2xx) are recognized and acknowledged without being processed twice —
   this is the "use idempotency" instruction in item 43.
5. Only then tries to construct the real provider class
   (`SslcommerzProvider`/`BkashProvider`/`NagadProvider`) and call its
   `verifyWebhook()`. Since none of the three has real credentials
   configured yet (no merchant account exists for this project —
   see above), that constructor throws today, and the endpoint responds
   honestly with **503 "This payment provider is not configured on this
   server yet"** rather than pretending to process anything (Rule 3).
6. If/when a real provider is configured and `verifyWebhook()` can return
   true, an unverified/invalid signature is still rejected (400) and never
   trusted — nothing here ever marks a payment `paid` from an unverified
   request, and the browser-return path (`payment.php`) already never did
   either (it only auto-confirms the sandbox provider, and only
   server-side, after checking the signed-in user owns the booking).

**Implementation status:** Implemented (receiver, logging, dedup/
idempotency, fail-closed dispatch).
**Testing status:** Partially verified, partially BLOCKED (Rule 77):
- The dedup/logging/fail-closed control flow in
  `PaymentService::handleWebhook()` was exercised with an automated
  in-memory SQLite harness (12 checks, all passing) that doesn't need a
  real database — see the Round 56 write-up in
  `SESSION_SUMMARY_INDEX-1.md` for exactly what it covers.
- **Not yet verified**: an actual HTTP POST against the real endpoint on
  your XAMPP install, and the new columns/unique key against your real
  MySQL schema. This session has no network path to your local
  MySQL/Apache (same limitation noted above for `database/migrate.php`),
  and no working local shell this round either. After running
  `database/migrate.php`, you can sanity-check the live endpoint yourself,
  e.g. from PowerShell:
  ```powershell
  Invoke-WebRequest -Uri "http://localhost/tourbuddy/webhook-sslcommerz.php" -Method POST -Body '{"event":"test","tran_id":"manual-test-1"}' -ContentType "application/json"
  ```
  Expect **HTTP 503** with a JSON body saying the provider isn't
  configured (that's the correct, honest response today) and a new row in
  `tblpayment_events` with `Provider='sslcommerz'`, `ProcessingStatus='failed'`.
  Re-running the exact same command should instead come back **HTTP 200**
  with `{"status":"duplicate", ...}` and NOT add a second row — that's the
  idempotency behavior. Let me know what you see and I'll fix anything
  that doesn't match.
- Real signature verification for any of the three providers is still not
  implemented (and can't honestly be, without a real merchant account to
  test against — see "Why sandbox instead of a real gateway" above); that
  remains real, tracked follow-up work for whenever a merchant account
  exists, not a gap introduced or hidden by this round.

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
5. Give that provider's dashboard the matching callback URL from the table
   above — the receiver endpoint is already live and waiting, only
   `verifyWebhook()`/`normalizeStatus()` need real logic.
6. Test end-to-end in the provider's own sandbox mode before ever setting
   `*_LIVE=true`.

## Sandbox gating (added in the security pass)

The sandbox "Pay" button marks a booking paid without money moving, so it is
only honoured when `TMS_ALLOW_SANDBOX_PAYMENTS=true` in `.env`, or the request
comes from the server itself (loopback `REMOTE_ADDR`, no `X-Forwarded-For`).
On a public site customers see "online payment is not available yet" and the
booking stays Pending for manual confirmation. A cancelled booking can no
longer be set back to confirmed by paying it. Tests: `php tests/payments/run.php`
(needs your database; creates and removes its own rows).

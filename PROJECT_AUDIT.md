# TMS project audit (whole project)

Four independent read-only reviews were run (security, payments/bookings, AI layer, performance/UX). Findings were read from the
code; the fixes below were each re-verified and have tests. Anything not listed as fixed is **still open**. Nothing here has been run
on XAMPP/Apache, with a real payment gateway, or with a real AI model.

## Fixed in Stage 10 (tests: `tests/security/stage10_test.php`, `tests/pwa/sw_test.js`, `tests/payments/run.php`)

| Area | Problem | Fix |
|---|---|---|
| AI / privacy | Trip Copilot: any signed-in user could read any booking's chat history, write into it, and read its cached proactive alert | Ownership checked first in `chat`, `generateProactiveAlert`, `getHistory` (now requires the owner's email); alert cache key includes the owner |
| AI / privacy | Recommender cache key ignored the personalised profile text, so one user's booking-history answer could be served to another (or to someone who opted out) | Profile text is part of the cache key |
| Security | Password-reset link was built from the raw `Host` header (reset-link poisoning) | `tms_public_base_url()`: `APP_URL` if set, else a validated host |
| Security | Open redirect via `/\evil.com` on `login.php` and `language.php` | `tms_safe_redirect()` (rejects backslashes, scheme, `//`, control characters) |
| Security | REST `login` issued a 30-day token on password alone, bypassing 2FA | 2FA accounts must send `totp_code`; wrong codes feed the 2FA lockout bucket |
| Security | A correct password cleared the whole IP's failure rows, including the TOTP guess counter | Clears only the exact (key, IP) pair |
| Security | Cart add-ons could be deleted by any user via `cart.php?remove=<id>` | Ownership checked first |
| Security | Service worker cached signed-in pages (wallet, tickets, dashboard, API) and kept them after logout | New cache version removes the old one; private paths and `no-store` responses never cached; static assets stale-while-revalidate; logout wipes all caches (including the saved-trip kit) |
| Security | `data/*.json`, `*.py` downloadable | `data/.htaccess`; `py/sh/yml/yaml` added to the deny list |
| Correctness | Unpublished / pending-review / rejected supplier packages were listed, in the sitemap, openable and bookable by URL, and in the public API | `TMS_PKG_PUBLIC_SQL` / `tms_package_bookable()` applied to list, destinations, sitemap, reviews, details, checkout and the API |
| Correctness | REST `book` skipped seat limits, blackout dates, dynamic pricing and the coupon cap, consumed global coupons for everybody, and was not transactional | Same guards as the website, locked seat check, one transaction |
| Correctness | Coupon discounts were floats written into INT columns (off by 1 currency unit) | `tms_coupon_discount()`, integer-exact, used by both booking paths |
| Correctness | Loyalty redeem: parallel requests produced two coupons and a negative balance; 5,000 points were charged for a 40%-capped coupon | One transaction with a row lock; only the points the capped discount costs are deducted; history entry written |
| Correctness | Admin dashboard revenue used list price instead of what was charged | Uses `TotalCost` (list price only for legacy rows without it) |
| Payments | A rejected/failed webhook event made the gateway's genuine retry answer "duplicate" forever; unbounded body size | Only `processed` events are duplicates; others are reused and retried; 256 KB cap |

## Still open (ranked; none of these was changed in Stage 10)

**Payments / money**
1. Webhook reconciliation is not implemented: even a verified webhook never updates `tblpayments`/`tblbooking`, and amount/currency are not checked (all three gateway providers are stubs and fail closed, so nothing can be marked paid via webhook today).
2. Sandbox payment gate trusts `REMOTE_ADDR` being loopback; behind a same-host reverse proxy it is open to everyone. Use an explicit `APP_ENV` flag. Supplier settlement treats `PaymentStatus='paid'` as proof of payment.
3. Direct "Book Now" and cart checkout: not transactional / no double-submit protection; the cart read happens before the lock; cart prices never expire and are not re-priced; `FromDate` is not re-checked at checkout. **Partly fixed (Stage 16):** Book Now runs in one transaction with a row-locked seat re-check and a one-time form nonce; checkout locks and compares the cart so parallel submits create one set of bookings (`tests/integrity/stage16_test.php`). Still open: cart price expiry/re-pricing, `FromDate` re-check at checkout.
4. Loyalty points are credited at unpaid booking creation and never reversed on cancel (farmable); reviews give points without a verified purchase; referral self-farming. **Partly fixed (Stage 16):** cancelling (customer page, API or admin) reverses exactly the points that booking earned, once; awards are idempotent. Still open: points are still credited at unpaid booking creation, review points, referral farming.
5. Date-change approval bypasses pricing, capacity and blackouts. Refund math ignores the cancellation policy; admin cancel creates no refund request; no clawback after a supplier settlement; admin delete of settled bookings.
6. `createPendingPayment` accepts already-paid/cancelled bookings; refund processing is not atomic; legacy `apply-coupon.php` is still reachable (no CSRF, not atomic) and should be deleted. **Fixed (Stage 16):** payment creation refuses cancelled, paid and missing bookings; `apply-coupon.php` now answers 410. Still open: refund processing is not atomic.
7. `INT` money columns and `varchar` booking dates (`FromDate`, `ToDate`) - migration to `DECIMAL`/`DATE` not done.
8. Pricing engine: unpaid pending bookings hold seats and raise prices forever (no expiry job); comment says the multiplier cap is 1.35, code uses 1.45.

**Security**
9. 2FA secrets are stored in plaintext; the TOTP QR code is rendered by a third-party site (`api.qrserver.com`) so the secret leaves the server. **Partly fixed (Stage 17):** secrets are encrypted at rest (AES-256-GCM) when `TMS_2FA_KEY` is set (opt-in; `scripts/encrypt_2fa_secrets.php` converts existing rows). Still open: the QR image is still rendered by `api.qrserver.com`.
10. `tms_admin_role()` returns `superadmin` when the admin row is missing; no idle/absolute session timeout anywhere. **Fixed (Stage 17):** a missing admin row now gets no role (fail closed); sessions end after 120 min idle (admins 30) or 24 h total, configurable.
11. `api/chat.php` lets any admin role read every customer thread; `frontend/page.php` echoes CMS HTML unsanitised (stored XSS escalation by a content editor). **Partly fixed (Stage 17):** `page.php` runs CMS HTML through an allow-list sanitiser (`backend/HtmlSanitizer.php`) on output. Still open: chat RBAC.
12. Guest AI/explore rate limits are keyed to the session cookie (cookie-less clients are unlimited); `isRateLimited` fails open and is not atomic; some AI endpoints have no CSRF check or input bounds (`proactive_alert`, `packing_list`, `image_analyze`, `virtual_guide`).
13. Password reset does not revoke other sessions/API tokens; minimum password length is 6; no rate limits on enquiry/register/chat; email enumeration on register/availability checks. **Partly fixed (Stage 17):** minimum is now 8 everywhere; a reset or change ends the customer's other sessions and API tokens. Still open: rate limits on enquiry/register/chat, email enumeration.
14. `APP_DEBUG` defaults to true; jQuery 1.12.0 (known CVEs); some `innerHTML` uses without escaping (`my-trip.php`, `flight-status.php`); GET logout. **Partly fixed (Stage 17):** `APP_DEBUG` defaults to false. Still open: jQuery 1.12.0, `innerHTML` uses, GET logout.

**AI layer** (details in `AI_ENGINE_AUDIT.md` section 6 and the list below)
15. `InjectionGuard` misses spacing/leetspeak/fullwidth variants and "disregard the rules"/"print the text above" phrasings; chat history and `page_context` are not screened; `virtual_guide` interpolates raw input into the system prompt and caches the answer for everyone.
16. `AIClient::sanitizePromptInput` strips `<...>` from user messages, mangling "under <50000 and >4 people".
17. `BanglaNormalizer::budgetCeiling` takes only the first match ("trips under 3 days budget 50k", "20k for 3 days", "cheaper than 40000", "১০ হাজারের নিচে" return nothing); `NLSearchParser` has a second, weaker price parser ("under 1.5 lakh" becomes 1).
18. No follow-up resolution ("the second one", "cheaper"), no compare tool, no hotel/activity/guide retrieval, no filters beyond price/country; Banglish not retrieved.
19. Package index cache (10 min) can serve a repriced/unpublished package.

**Performance / UX / SEO**
20. `package-list.php`: about 20 extra queries per page (`tms_dynamic_price` per card), `SELECT *` including long text columns, uncached `AVG`/`GROUP BY`, no index for sort/search, duration filtered in PHP, 6 per page. **Partly fixed (Stage 13):** per-card pricing now uses `tms_dynamic_price_batch` (2 grouped queries per page, proven identical to the single path in `tests/perf/listing_test.php`); still open: `SELECT *`, uncached `AVG`/`GROUP BY`, duration filtered in PHP.
21. Missing indexes (price/rating sort, `tblbooking (PackageId,status,RegDate)` and `(UserEmail,status)`, reviews, alerts, blog); three redundant `tblusers` email indexes. **Partly fixed (Stage 13):** added `tblbooking (PackageId,status,RegDate)` and `tbltourpackages (Status,PackagePrice)`; the rest is still open.
22. Schema migration gate hashes `config.php` on every request, ignores other migration sources, records failed migrations as done, and has no lock; sessions are never released (`session_write_close`) and are started for every bot hit.
23. `.htaccess` has no compression or far-future caching; 288 KB `i18n.php` loaded each request; Leaflet loaded on every list view.
24. SEO: no JSON-LD on list/hotel/activity pages, landing page has no meta description, `robots.txt` uses wrong paths for a `/tms/` install, sitemap has no `lastmod`.
25. 54 pages have no translation calls; several undefined translation keys; missing 404/500 pages; `package-details.php` has a duplicated `id` on a date input; no saved searches; no browser push.

## Not verified
Real gateways, real AI providers and embedding models, Apache/`.htaccess` behaviour on XAMPP, behaviour under production load, scheduled jobs (`scripts/check_alerts.php`) being configured, and a browser run of the service worker (it is tested with a mock cache in Node).

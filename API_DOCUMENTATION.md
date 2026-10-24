# TMS REST API — v1

Base URL: `http://<your-host>/tms/api/v1/` (recommended — see "About
versioning" below), or the original `http://<your-host>/tms/api/`
(`/tms/api/index.php` also works directly if your server doesn't route
directory requests to `index.php` automatically). Both paths behave
identically.

**Interactive docs:** `/tms/api/docs.php` renders the machine-readable
OpenAPI spec (`api/openapi.yaml`) as a browsable, "Try it out"-capable
Swagger UI page — useful alongside this file, not instead of it.

All requests go through this one file with an `action` parameter — either
as a query string (`?action=packages`) or a POST field. This matches how
`api/ai.php` and `api/explore.php` already work in this project, rather
than introducing a different routing convention for just this file.

## Response shape

Every response is JSON with a `success` boolean:

```json
{ "success": true, "data": { ... }, "meta": { "version": "1" } }
```

```json
{ "success": false, "error": "Human-readable message." }
```

`meta.version` is always `"1"` right now. There's no `/v1/` prefix in the
URL — see "About versioning" below for why, and what changes if that
becomes a real need.

## Authentication

Most endpoints are public (read-only package data). Booking-related
endpoints require a bearer token, obtained via `login`:

```
Authorization: Bearer <token>
```

Tokens are opaque 64-character hex strings, valid for 30 days from issue,
and are stored server-side as a SHA-256 hash — the raw token is only ever
shown once, in the `login` response, the same way a password is never
stored in recoverable form.

**Refresh tokens (Round 115 continuation):** `login` also returns a
`refresh_token` (valid 90 days). Exchange it for a brand-new access +
refresh token pair via `refresh`, without asking the user to sign in
again. This is purely additive — a client that only reads `token` from
the `login` response and ignores `refresh_token` keeps working exactly as
before, and just calls `login` again once the 30-day access token
expires, same as always.

Refresh tokens are single-use: exchanging one immediately invalidates it
and issues a new one. If an already-used refresh token is ever presented
again — a sign it leaked and was replayed — every session descended from
that same login is revoked at once, including the live access token, and
the client must log in again from scratch.

**Scopes (Round 115 continuation):** by default a token is unrestricted,
same as before this feature existed. `login` accepts an optional `scope`
parameter (comma- or space-separated) to request a token limited to just
`profile:read`, `bookings:read`, and/or `bookings:write` — useful for a
third-party integration that should only ever read bookings, for example.
Omitting `scope` entirely keeps today's full-access behavior.

**Sessions:** every login (and its refresh-token family) shows up as one
row from `sessions`, and can be revoked individually (`revoke_session`) or
all at once (`revoke_all_sessions`) — the same "logout all devices"
concept the website already has (`SECURITY.md`, Phase 8), now available
for API tokens too.

**XAMPP/Apache note:** by default, Apache + mod_php does not pass the
`Authorization` header into PHP at all — it just vanishes, and every
authenticated call would fail with a misleading "missing header" error for
a reason invisible from the PHP side. This project's `.htaccess` now
includes the standard rewrite rule that fixes this. If you're running under
nginx or another server instead, you'll need an equivalent
`fastcgi_pass_header Authorization;`-style rule.

## Endpoints

### `GET ?action=packages` — public
List packages. Query params (all optional): `search`, `type`,
`page` (default 1), `limit` (default 12, max 50),
`sort` (`price_asc` | `price_desc` | `name`).

### `GET ?action=package&id=N` — public
Single package, including average rating and review count.

### `GET ?action=types` — public
Distinct package types, for building a filter dropdown.

### `POST ?action=check_email` — public
Body: `emailid`. Returns `{ available, message }` — used by the signup form
to check email availability before submitting.

### `POST ?action=login` — public
Body: `email`, `password`, `device` (optional label, e.g. `"iPhone app"`),
`scope` (optional — see "Scopes" above; omit for full access).

```json
{ "success": true, "data": {
    "token": "a1b2c3...", "expires_at": "2026-08-08 12:00:00",
    "refresh_token": "f6e5d4...", "refresh_expires_at": "2026-11-08 12:00:00",
    "scopes": null,
    "user": { "id": 5, "full_name": "...", "email": "..." }
}}
```

`refresh_token`/`refresh_expires_at`/`scopes` are new fields (Round 115
continuation) — a client that only reads `token`/`expires_at`/`user` is
unaffected. `scopes` is `null` unless the `scope` parameter was used.

### `POST ?action=refresh` — public (the refresh token itself is the credential)
Body: `refresh_token`.

```json
{ "success": true, "data": {
    "token": "new-access-token...", "expires_at": "...",
    "refresh_token": "new-refresh-token...", "refresh_expires_at": "...",
    "scopes": null
}}
```

Rotates the refresh token (the old one stops working the instant this
succeeds) and issues a new access token in its place — the old access
token from the same login is invalidated too. See "Refresh tokens" above
for the reuse-detection behavior on `401`.

Protected by the same brute-force lockout as the website's own sign-in form:
5 failed attempts (by email or IP) locks that email/IP out for 15 minutes,
returning `429` with a message stating the wait time. Successful login
clears the counter.

### `POST ?action=logout` — auth required
Invalidates the token used in the request.

### `GET ?action=me` — auth required
Returns the logged-in user's profile.

### `GET ?action=my_bookings` — auth required
Returns the logged-in user's bookings, same shape as My Bookings on the
website (status, dates, persons, total cost, verification code).
Optional pagination: send `page` and/or `per_page` (default 20, max 100) and
`meta` is added with `total`, `page`, `per_page`, `total_pages` and `links`.
Without them the full list is returned exactly as before.

### `POST ?action=book` — auth required
Body: `package_id`, `from_date` (`YYYY-MM-DD`), `to_date` (`YYYY-MM-DD`),
`persons` (default 1, max 20), `mobile` (optional), `comment` (optional),
`coupon_code` (optional).

This runs the exact same pricing/coupon/verification-code logic as the
website's booking form (see `package-details.php`) — a booking made through
the API gets a real `TotalCost`, has a coupon actually applied and marked
used if valid, and gets a verification code for its e-ticket QR, same as
one made through the site.

```json
{ "success": true, "data": {
    "booking_id": 42, "total_cost": 45000, "verification_code": "A1B2C3D4E5",
    "note": null, "display_currency": null, "display_amount": null, "fx_rate": null
}}
```

`note` is non-null if a coupon code was supplied but invalid/expired/used —
the booking still succeeds, just without the discount, and `note` explains
why (mirrors the website's behavior).

`display_currency`/`display_amount`/`fx_rate` (Round 115 continuation,
master prompt item 37) are non-null only if the session making this
request had a non-BDT display currency selected (see the website's
currency selector) — `total_cost` itself is always the real BDT amount
charged.

**Idempotency.** Send an `Idempotency-Key` header (8–128 characters: letters,
digits, `- _ . :`; a UUID works) with `book`. Repeating the same key with the
same body within 24 h returns the first response again (with
`Idempotent-Replayed: true`) and creates nothing new. The same key with a
different body returns `422` (`idempotency_key_reused`); a duplicate that
arrives while the first is still running returns `409`
(`idempotency_in_progress`). Failed attempts (non-2xx) release the key so you
can correct the request and retry with it. Requires migration
`019_api_idempotency.sql`; if the table is missing the header is ignored.

### `POST ?action=cancel_booking` — auth required
Body: `booking_id`. Same 24-hour-before-departure rule as the website. If
the booking was already confirmed (not just pending), the response includes
`"refund_requested": true` and a refund request is logged, visible to
admins in Manage Bookings.

### `GET ?action=sessions` — auth required
Lists this user's active API sessions/devices — `id`, `device` (the
`device` label from `login`, if any), `created_at`, `last_used_at`,
`expires_at`, and `is_current` (whether this is the token making the
request). Never returns the token itself, hashed or otherwise.

### `POST ?action=revoke_session` — auth required
Body: `session_id` (the `id` from `sessions`). Deletes that one session
and its refresh token; the session must belong to the calling user.

### `POST ?action=revoke_all_sessions` — auth required
Body: `keep_current` (optional, `1` to keep the session making this
request active — default revokes everything, including this one, i.e.
"sign out everywhere").

### `GET ?action=trip_readiness&booking_id=N` — auth required (scope: `bookings:read`)
Trip Readiness Score for one of the caller's own bookings — the same
percentage shown on the website's My Trip page (`TripReadinessScore`),
computed from real facts only: payment settled (40%), booking confirmed
(20%), a travel document on file (20%), an AI itinerary generated for this
booking (20%). `booking_id` must belong to the authenticated user or the
request 404s.

```json
{ "success": true, "data": {
    "score": 60,
    "components": [
      { "key": "paid", "label": "Payment settled", "weight": 40, "met": true },
      { "key": "confirmed", "label": "Booking confirmed", "weight": 20, "met": true },
      { "key": "document", "label": "Travel document on file", "weight": 20, "met": false },
      { "key": "itinerary", "label": "Trip itinerary generated", "weight": 20, "met": false }
    ],
    "missing": [
      { "label": "Travel document on file", "action_label": "Upload a document", "action_url": "wallet.php" },
      { "label": "Trip itinerary generated", "action_label": "Generate itinerary", "action_url": null }
    ]
}}
```

### `GET ?action=budget_status&booking_id=N` — auth required (scope: `bookings:read`)
Real budget-vs-spend comparison for one of the caller's own bookings —
same numbers as the website's Expense Tracker (`ExpenseAnalyst::getBudgetComparison`),
plus `last_alert` (the AI Budget Autopilot's last notification for this
booking, or `null` if it has never fired — see `scripts/check_budget_overspend.php`).
`booking_id` must belong to the authenticated user or the request 404s.

```json
{ "success": true, "data": {
    "budget": 12200, "spent": 8400, "remaining": 3800, "percent_used": 69,
    "by_category": [ { "category": "accommodation", "total": 5000 }, { "category": "food", "total": 3400 } ],
    "over_budget": false,
    "last_alert": { "LastNotifiedAt": "2026-09-10 08:00:00", "LastPercentUsed": 92 }
}}
```

## Error codes

| Status | Meaning |
|---|---|
| 400 | Missing/invalid parameters |
| 401 | Missing, malformed, invalid, or expired token; wrong login credentials; invalid/expired/reused refresh token |
| 403 | Token's scope doesn't permit this action (only applies to a scope-restricted token — see "Scopes" above) |
| 404 | Resource not found (package, booking, session, or unknown `action`) |
| 405 | Wrong HTTP method (most write actions require POST) |
| 409 | Valid request, but not allowed right now (e.g. cancel within 24h) |
| 429 | Too many failed login attempts for this email/IP (login), or too many requests to this endpoint in the current window (any action) — wait and retry |
| 500 | Server error — check the PHP error log |

## Request ids, error detail and pagination (additive)

- **`X-Request-Id`**: send your own (8–64 characters: letters, digits, `. _ -`)
  or let the server generate one (`req_…`). It is returned as a response header
  and in `meta.request_id`; quote it when reporting a problem.
- **Errors** keep `{ "success": false, "error": "text" }` and now also carry
  `error_code` (stable, e.g. `not_found`, `rate_limited`, `unprocessable`),
  `request_id`, and `error_detail: { code, message, request_id }`.
- **Pagination** (`packages`, and `my_bookings` when asked): `page`, and
  `per_page` (alias `limit`), clamped to the endpoint maximum. `meta` has
  `total`, `page`, `per_page`, `total_pages` and `links`
  (`self`, `first`, `prev`, `next`, `last`; `prev`/`next` are `null` at the ends).
- **Rate-limit headers** on every response: `X-RateLimit-Limit`,
  `X-RateLimit-Remaining`, `X-RateLimit-Reset` (seconds); a `429` also has `Retry-After`.

## Rate limiting (Round 71)

Every action is throttled to 120 requests/60 seconds per IP address. The
`check_email` action additionally has its own stricter limit of 20/60s,
since — unlike `login` — it had no other abuse protection and is a plain
account-enumeration oracle if hammered (email in, "already registered?"
out). Both return `429` with a human-readable message on the response
body when exceeded; the limiter fails **open** on any internal DB error
(same philosophy as the login lockout), so a limiter bug can never take
the whole API down.

## About versioning

This API is reachable at a real `/api/v1/` path (Round 71) — `api/v1/index.php`
contains nothing but a one-line `require` of the original `api/index.php`,
so the two paths run identical code by construction and can never drift
apart; there's no second copy of the routing/handler logic to maintain.
`api/ai.php` and `api/explore.php`, the two siblings a naive directory
restructure would have broken, are completely untouched. Every response
also carries `meta.version` (`"1"`) and `meta.versioned_path` (a boolean
confirming which path was actually hit) so a client can check either way.
If a real *breaking* v2 is ever needed, the plan is still a new `api/v2/`
directory with its own real implementation, living alongside this one —
`api/index.php` (and the `/api/v1/` alias) would keep serving existing
consumers unchanged, per this same requirement's instruction not to break
them.

**Round 115 continuation ("TMS V2" prompt Phase 29):** the two new
domain endpoints above (`trip_readiness`, `budget_status`) were added as
plain new actions on this same existing surface rather than as the start
of a path-based `/api/v2/` resource hierarchy — see
`TMS_V2_PHASE1_AUDIT.md` Section E for the full reasoning. In short: no
consumer has ever asked for path-based routing, the tradeoffs above (a
real path-based v1 would mean either duplicating routing logic or
rewriting `api/index.php`'s dispatch and risking `api/ai.php`/
`api/explore.php`) apply equally to a resource-hierarchy v2, and the two
services being exposed here are genuinely new leaf endpoints, not a
redesign of anything that already has consumers — so the smallest real
fix was two new `match()` arms, not a new API generation.

## Example: full booking flow (curl)

```bash
# 1. Log in
curl -X POST "http://localhost/tms/api/index.php?action=login" \
  -d "email=test@gmail.com&password=yourpassword"
# -> { "data": { "token": "TOKEN_HERE", ... } }

# 2. Book a package
curl -X POST "http://localhost/tms/api/index.php?action=book" \
  -H "Authorization: Bearer TOKEN_HERE" \
  -d "package_id=3&from_date=2026-09-01&to_date=2026-09-07&persons=2&mobile=017XXXXXXXX"

# 3. Check your bookings
curl "http://localhost/tms/api/index.php?action=my_bookings" \
  -H "Authorization: Bearer TOKEN_HERE"
```

# TMS REST API — v1

Base URL: `http://<your-host>/tms/api/index.php` (or `/api/` if your server
routes directory requests to `index.php` automatically).

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
stored in recoverable form. There's no refresh-token flow; once a token
expires, the client just calls `login` again.

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
Body: `email`, `password`, `device` (optional label, e.g. `"iPhone app"`).

```json
{ "success": true, "data": {
    "token": "a1b2c3...", "expires_at": "2026-08-08 12:00:00",
    "user": { "id": 5, "full_name": "...", "email": "..." }
}}
```

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
    "note": null
}}
```

`note` is non-null if a coupon code was supplied but invalid/expired/used —
the booking still succeeds, just without the discount, and `note` explains
why (mirrors the website's behavior).

### `POST ?action=cancel_booking` — auth required
Body: `booking_id`. Same 24-hour-before-departure rule as the website. If
the booking was already confirmed (not just pending), the response includes
`"refund_requested": true` and a refund request is logged, visible to
admins in Manage Bookings.

## Error codes

| Status | Meaning |
|---|---|
| 400 | Missing/invalid parameters |
| 401 | Missing, malformed, invalid, or expired token; wrong login credentials |
| 404 | Resource not found (package, booking, or unknown `action`) |
| 405 | Wrong HTTP method (most write actions require POST) |
| 409 | Valid request, but not allowed right now (e.g. cancel within 24h) |
| 429 | Too many failed login attempts for this email/IP — wait and retry |
| 500 | Server error — check the PHP error log |

## About versioning

This API doesn't use a `/v1/` URL prefix. `api/ai.php` and `api/explore.php`
already exist as siblings of `api/index.php`, and restructuring into
`api/v1/index.php` would mean either breaking those two or duplicating
routing logic to keep them working — more risk than benefit for a first
version. Instead, every response carries `meta.version`, so a client can
check it. If a real breaking v2 is ever needed, the plan would be a new
`api/v2/` directory living alongside this one, with `api/index.php`
continuing to serve v1 clients unchanged — not replacing this file.

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

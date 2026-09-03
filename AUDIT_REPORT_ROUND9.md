# TMS — Round 5: REST API

Last item from the original list. Following the same pattern as every prior
round: checked what already existed first, then filled the real gaps.

## What was already built

`api/index.php` already existed with four working, public, read-only
endpoints (`packages`, `package`, `types`, `check_email`) — consistent
`{success, data, meta}` JSON responses, parameterized queries, pagination,
search/filter/sort on the packages list. `api/ai.php` and `api/explore.php`
are two more working API surfaces for the AI features. This wasn't a "build
an API from scratch" round.

## What was missing, and why it mattered

Everything in `api/index.php` was read-only and anonymous. There was no way
for a mobile app, a separate React frontend, or a third-party integration —
the three things Phase 8 explicitly named — to actually do anything on
behalf of a specific user: no login, no "my bookings," no way to create a
booking. A REST API that can only browse the public catalog isn't really
usable for the stated purpose.

## Added: token-based authentication

- New `tblapi_tokens` table (self-healing migration, same pattern as every
  other table in this project). Tokens are stored as a **SHA-256 hash**,
  never the raw value — a database read alone can't be replayed as a live
  credential, same principle as how passwords are already stored here.
- `POST ?action=login` — email + password (reuses the existing
  `tms_verify_password()` helper, so it accepts the same bcrypt-or-legacy-
  MD5 accounts the website login does, including the auto-upgrade-to-
  bcrypt-on-successful-login behavior) → returns a 30-day bearer token.
- `POST ?action=logout` — revokes the token used in the request.
- Every protected endpoint checks `Authorization: Bearer <token>` via a
  shared `api_auth_user()` helper.

**Found and fixed while building this:** XAMPP's default Apache + mod_php
setup does not pass the `Authorization` header into PHP's `$_SERVER` at
all — it's a well-known gotcha, not specific to this project, but it would
have made every authenticated endpoint fail here with no clue why from the
PHP side. Added the standard rewrite-rule fix to `.htaccess` before it
could bite anyone testing this.

## Added: user + booking endpoints

- `GET ?action=me` — current user's profile.
- `GET ?action=my_bookings` — current user's bookings.
- `POST ?action=book` — create a booking. This calls the **same
  pricing/coupon/verification-code logic that Round 3 fixed** in
  `package-details.php` (persons, mobile, coupon applied inline and marked
  used, real `TotalCost` stored, verification code generated) — not a
  second, independently-written booking path that could drift out of sync
  or reintroduce the bug Round 3 just fixed.
- `POST ?action=cancel_booking` — same 24-hour rule and refund-request
  logging as the website's My Bookings cancel button (Round 3).

## Added: documentation

`API_DOCUMENTATION.md` — every endpoint, auth flow, error codes, the
XAMPP Authorization-header gotcha, and a full curl walkthrough (login →
book → check bookings).

## About "versioning"

Didn't restructure into `api/v1/index.php`. `api/ai.php` and
`api/explore.php` already exist as siblings of `api/index.php` — moving
into a versioned subdirectory would mean either breaking those two or
duplicating routing to keep them alive, for a first version that doesn't
have a v2 to differentiate from yet. Instead every response now carries
`meta.version: "1"`, so a client can detect a future v2 without the URL
needing to change today. Documented the actual plan for if/when a real v2
is needed (a new `api/v2/` directory, this file keeps serving v1 clients
unchanged) rather than guessing at a structure nothing needs yet.

## What I deliberately didn't add

- **Rate limiting**: no per-token or per-IP throttling. Would matter more
  once this has real external traffic; adding it now would be guessing at
  limits with no usage data to base them on.
- **Refresh tokens**: 30-day expiry, then log in again. A refresh-token flow
  is more moving parts than a first API version with no known mobile client
  yet needs.
- **Admin API endpoints** (managing bookings, packages, etc. via REST): the
  admin panel's needs are already served by the admin pages themselves;
  Phase 8 named mobile apps and third-party integrations, which are
  end-user-facing, not admin-facing.
- **Endpoints for every other feature** (wishlist, reviews, loyalty, AI
  trip planner, etc. via REST): scoped this round to what "Phase 8" is
  actually about — enabling a client to authenticate and manage bookings.
  Extending the same `api_auth_user()` pattern to more resources later is
  straightforward if/when a real client needs them.

## Files changed this round

`includes/config.php` (new self-healing `tblapi_tokens` table),
`api/index.php` (auth + booking endpoints, versioning note), `.htaccess`
(Authorization header passthrough), new `API_DOCUMENTATION.md`.

---

That's everything from the original "God Mode" list now addressed — either
built, or explained why it wasn't (payment gateway, Bootstrap 5 migration,
real-time infra). Five rounds, each independently verified against the
actual codebase rather than assumed. Let me know what's next — more
features, or should we go back through and harden/test what's here?

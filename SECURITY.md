# Security — current posture

This consolidates what's already been done across many prior audit rounds
(see `SECURITY_HARDENING_PHASE1.md`, `SESSION_SUMMARY_INDEX.md`, and
`AUDIT_REPORT_ROUND2.md` through `AUDIT_REPORT_ROUND14.md` for the detailed,
round-by-round history) plus what changed in this round. It is a snapshot,
not a fresh from-scratch audit of every file — claims below are either
things this session directly verified, or are attributed to the specific
prior round that verified them, per Rule 77 (don't claim something works
without having tested it).

## Verified in place (per the audit history referenced above)

- **CSRF**: `tms_csrf_validate()` actually enforced (403s a bad/missing
  token) as of the Phase 1 hardening round, after previously being
  log-only. All known forms send a token (10 previously-missing ones fixed
  in that round; `payment.php`, added this round, sends one too).
- **Admin auth**: brute-force lockout, bcrypt with auto-rehash, session
  regeneration on login, RBAC (`superadmin` / `manager` via
  `tms_admin_require_role()`).
- **Audit trail**: `tblactivity_log` + `tms_log_activity()`, viewable at
  `admin/activity-log.php` — this project's answer to Rule 47, not a
  separate new table.
- **Admin destructive actions**: POST + CSRF instead of a plain GET link
  (fixed project-wide in Round 11 per `SESSION_SUMMARY_INDEX.md`).
- **Secrets**: real API keys live in `.env` (git-ignored, `.htaccess`-
  blocked), never `.env.example`. `README.md`'s previously-published demo
  password was removed this round (Rule 5 — a working credential
  documented in a file that's about to be public was a real exposure once
  this project goes live; see `HOSTING_GUIDE.md`).
- **Sensitive documents** (Travel Wallet): ownership-checked gateway +
  `.htaccess` deny-all, not just an unguessable filename.
- **File upload validation, IDOR, SQLi (parameterized queries throughout),
  XSS escaping**: covered per the numbered `AUDIT_REPORT_ROUND*.md` files —
  not independently re-run in this session; treat as "audited as of its
  round's date," not "verified today."

## Added this round

- `database/migrate.php` (web-runnable) gated behind
  `tms_admin_require_role(['superadmin'])` — running arbitrary schema
  changes from an unauthenticated request would undo everything above.
- New `tblpayments`/`tblpayment_events`/`tblpayment_refunds` writes are all
  through `PaymentService`, never raw user input — `PaymentService::
  confirmSandboxPayment()` explicitly re-checks the requesting user owns
  the booking before touching it (IDOR guard on the new payment flow).
- Checkout's booking-creation path now wraps in a real DB transaction with
  row-level locking (`SELECT ... FOR UPDATE`) to close a check-then-act
  race that could have oversold a capacity-limited package under
  concurrent checkouts (Rule 9).

## Added in Round 17

- **Real gap found and fixed: API login had no brute-force lockout.**
  `login.php`/`admin/index.php` both check `tms_login_lockout_minutes_remaining()`
  before verifying a password; `api/index.php`'s `api_login()` only ever
  recorded failed attempts, never checked the lockout. Same account was
  fully protected on the website, completely open to unlimited password
  guessing through the REST API. Fixed with the existing helper (no new
  mechanism); returns `429` with a wait-time message; documented in
  `API_DOCUMENTATION.md`; regression test added to `tests/run.php`.
- **Baseline security response headers** (`.htaccess`): `X-Content-Type-
  Options`, `X-Frame-Options`, `Referrer-Policy`, a scoped `Permissions-
  Policy`, `Strict-Transport-Security`. No `Content-Security-Policy` yet —
  see `SESSION_SUMMARY_INDEX.md` Round 17 for why that specifically was
  deferred rather than written blind.
- **`tbl_login_attempts` unbounded growth fixed**: nothing previously
  deleted a failed-attempt row that never led to a successful login
  (`tms_clear_login_attempts` only clears the matching email/IP). Added a
  cheap probabilistic cleanup (2% chance per failed attempt, deletes rows
  older than 1 day — well past the 15-minute lockout window they're needed
  for) rather than a dedicated cron job for one housekeeping DELETE.
- **Payment refund ledger reconciled**: `tblpayment_refunds` (Round 16
  schema) had nothing reading/writing it. Wired into the existing booking-
  cancellation → admin-approval flow (not a new, separate UI) so
  `tblpayments.Status`/`tblbooking.PaymentStatus` stay accurate through a
  refund — see `PAYMENTS.md`.

## Not done this round, and why

- **A fresh, independent OWASP-style pass over every endpoint** (Rule 44) —
  this project has had 14+ prior audit rounds; re-running all of that from
  zero in this session wasn't the highest-value use of the time versus the
  payment/migration/concurrency gaps that had never been touched. Worth
  doing again periodically, especially after the payment/checkout changes
  above.
- **Automated security regression tests** (CSRF/XSS/SQLi/IDOR fuzzing) —
  see `TESTING.md`; today's coverage is manual-review-based, not automated.
- **General rate limiting on public, unauthenticated API endpoints**
  (`packages`/`types`/`check_email`) — reviewed this round; these are
  read-only or simple existence-checks with no brute-force-able secret
  behind them (unlike login, which was a real gap and is now fixed above),
  and `register.php`'s own duplicate-email check has never been rate-
  limited either. Building a general-purpose IP-based rate limiter for
  every public endpoint, when the one endpoint that actually needed lockout
  protection now has it, would be scope beyond what's broken today (Rule
  73) — revisit if real abuse is ever observed in logs.

## If you find a real vulnerability

Rotate any exposed credential immediately at its provider (don't wait for a
"proper" fix first). Then fix the code path, and add a regression check to
`tests/run.php` if the bug class is the kind a test could have caught.

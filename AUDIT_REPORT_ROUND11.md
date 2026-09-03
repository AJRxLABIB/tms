# TMS — Round 11: Admin CSRF hardening + two real security bugs found

This round did two things: (1) closed the specific known gap that Rounds
6–10 explicitly flagged and left open — admin destructive actions
triggered by a plain `GET` link with no CSRF protection — and (2) did a
fresh, independent bug hunt across the rest of the codebase rather than
assuming the prior ten rounds caught everything. Two real, previously
undocumented, high-severity bugs turned up during that hunt.

No PHP runtime is available in this environment (confirmed: no `php` or
`mysql` binary, no `sudo` to install one). Every change below was verified
by careful reading, cross-referencing the shared helper functions
(`tms_csrf_field()`/`tms_csrf_validate()`/`tms_require_admin()` in
`includes/config.php`), and a brace/paren-balance check across every
touched file — not by executing anything. Worth clicking through the admin
booking actions, package image update, and package edit form once, live.

## Bug 1 (critical): authentication bypass in `admin/edit-package.php`

The auth guard was:

```php
if(strlen($_SESSION['alogin'])==0) { header('location:index.php'); }
```

No `else`, no `exit`. By the time this runs, the page has already emitted
the admin shell's HTML (sidebar, header), so `header()` silently fails
("headers already sent") — and then **execution just continues past the
`if` regardless of whether the check passed**, straight into the code that
fetches the package and, on `$_POST`, updates it (including replacing its
image). An unauthenticated request with the right POST fields could edit
any package on the site. Every other admin page in this project either
uses the correct `tms_require_admin()` helper (which does `exit` inside a
function, unaffected by "headers already sent") or wraps the sensitive
code in a real `else` block. This one file had neither. Fixed by replacing
the check with `tms_require_admin()`.

## Bug 2 (critical): unrestricted file upload + path traversal in `admin/change-image.php`

```php
$pimage=$_FILES["packageimage"]["name"];
move_uploaded_file($_FILES["packageimage"]["tmp_name"],"pacakgeimages/".$_FILES["packageimage"]["name"]);
```

Two problems at once, and this is the *only* upload path in the project
with either:

1. **No extension check at all.** Every other upload handler in this
   codebase (`add-package.php`, `edit-package.php`, `add-blog.php`,
   `edit-blog.php`, `reviews.php`, `wallet.php`) whitelists extensions
   before writing to disk. This one didn't — an admin session (or anything
   that can ride one, e.g. a future CSRF gap) could drop a `.php` file into
   `pacakgeimages/`, a folder served directly to the public. That's remote
   code execution.
2. **Path traversal.** The destination path was built by concatenating the
   raw, attacker-controlled `$_FILES[...]['name']` directly — a filename
   like `../../shell.php` would escape the intended folder entirely.

Fixed to match the whitelist-extension + randomized-filename pattern
already used everywhere else in the project (`time() . '_' .
bin2hex(random_bytes(4)) . '.' . $ext`, extension whitelist, 5MB size cap).
Also fixed the field's success message, which said "Package Created
Successfully" for what is actually an image update.

## CSRF: admin destructive GET actions → POST + CSRF (the flagged known gap)

Rounds 6–10 (and a comment left in `manage-bookings.php` itself) explicitly
called out that admin delete/status-change links used plain `GET` with
only a client-side `confirm()` — no server-side protection, so a forged
`<img>`/auto-submitting form on any page an admin has open in another tab
could delete or alter data silently. Converted every one of these to a
same-page `<form method="post">` carrying `tms_csrf_field()`, validated
server-side with `tms_csrf_validate()`, across:

- `admin/manage-admins.php`, `manage-blog.php`, `manage-custom-trips.php`,
  `manage-emergency.php`, `manage-enquiries.php`, `manage-issues.php`,
  `manage-packages.php`, `manage-pages.php`, `manage-users.php` — `delete`.
- `admin/manage-coupons.php` — `delete` and `toggle`.
- `admin/manage-reviews.php` — `delete`, `approve`, and `reject`.
- `admin/manage-translations.php` — `delete` (cache-clear; low severity by
  design, per the code's own comment, but made consistent anyway).
- `admin/manage-bookings.php` — the whole action switch: `confirm`,
  `cancel`, `delete`, `approve_mod`, `reject_mod`, `refund_processed`,
  `refund_rejected`. This was the highest-value one: a forged link could
  previously confirm, cancel, or delete *any booking* silently. The two
  actions that prompt for a reason (`reject_mod`, `refund_rejected`) now
  build a hidden form field via JS instead of a `window.location` GET with
  the note in the query string.

Buttons use `<form style="display:contents">` so they still lay out inline
exactly like the `<a>` tags they replaced — `.action-btn` already had
`border:none;cursor:pointer` for this reason (it was clearly written to
support a `<button>` at some point, just never used that way).

Two smaller instances of the same "no server-side check" bug class:
`issuetickets.php` and `logout.php` had `header('Location: ...')` calls
with no following `exit`. Neither was independently exploitable (the
sensitive code in `issuetickets.php` is gated inside the corresponding
`else` block; `logout.php` has nothing after it), but both are the same
bug shape that caused Bug 1 above, so both got the missing `exit` added.

## Checked and found already correct (verified, not assumed)

- **SQL injection**: grepped the whole project for `->query()` calls
  built from `$_GET`/`$_POST` or string concatenation instead of
  `->prepare()`. One candidate (`compare.php`'s `IN (...)` list) is
  actually safe — every value in it is run through `tms_int()` at
  insertion into `$_SESSION['compare']` *and* through `array_map('intval', …)`
  again before being interpolated, so it can never carry anything but a
  plain integer.
- **IDOR**: `ticket.php` and `wallet-document.php` (e-ticket and travel-
  document downloads) both scope their lookup query to the logged-in
  user's own email, not just the record ID — a guessed booking/document ID
  alone can't retrieve someone else's data.
- **File-upload allowlisting** everywhere except `change-image.php` (fixed
  above) was already correct.
- **CSS/theme coverage**: re-checked every customer-facing page for
  `modern-theme.css` inclusion and for leftover raw Bootstrap-3 markup
  (`panel panel-default`, `table-bordered`, `class="well"`). Found exactly
  one trivial instance (a data-preview table in `language.php`, already
  themed otherwise) — the glassmorphism/theme-consistency pass from an
  earlier round holds up under a fresh, independent check. No redesign
  work was attempted on top of it: there's no way to render or screenshot
  the live site in this environment, so a change I can't visually verify
  isn't a safe trade against a UI that's already been deliberately built
  and audited. The Bootstrap 3 → 5 migration remains explicitly out of
  scope for the same reason it was in Round 10 — ~60 files of BS3-specific
  markup, no test environment to verify against.

## Noted, not changed

- `admin/live-chat.php` and `admin/edit-package.php` still use a native
  `alert()` for their error/not-found cases. The customer-facing site has
  a `tmsToast()` helper (Round 6) but the admin panel has no equivalent
  toast system at all — building one just to replace two `alert()` calls
  wasn't judged worth the added surface area this round.
- `includes/chat-widget.php` is dead code — nothing in the project
  `include()`s it. Left untouched rather than polishing unreachable code
  (same call Round 10 made for the orphaned `thankyou.php`); worth a
  decision on whether to wire it in or remove it.

## Files changed this round

`admin/manage-admins.php`, `manage-blog.php`, `manage-bookings.php`,
`manage-coupons.php`, `manage-custom-trips.php`, `manage-emergency.php`,
`manage-enquiries.php`, `manage-issues.php`, `manage-packages.php`,
`manage-pages.php`, `manage-reviews.php`, `manage-translations.php`,
`manage-users.php`, `change-image.php` (upload fix), `edit-package.php`
(auth fix) — all under `admin/`; `issuetickets.php`, `logout.php`.

import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

replacements = []

# 1. Directory-tree summary bullet for includes/
old1 = """├── includes/     Legacy folder. Was the project's original shared-logic
│                 home before the Round 49 rename to backend/. Mostly an
│                 inert duplicate today, but NOT fully dead — see
│                 "The backend/ vs includes/ pair" below."""
new1 = """├── _deprecated/  Holds includes/ (Round 115) — the project's original
│                 shared-logic folder, superseded by backend/ at the
│                 Round 49 rename. Moved here (not deleted) once a
│                 project-wide grep confirmed zero live code paths still
│                 reached it — see "The backend/ vs includes/ pair" below.
│                 Safe to delete outright after a release cycle with no
│                 issues traced back to it; kept for now as a rollback net."""
replacements.append((old1, new1))

# 2. The whole "backend/ vs includes/" narrative section
old2 = """## The `backend/` vs `includes/` pair

`includes/` was the project's original shared-logic folder. Round 49
renamed it to `backend/` (a purely internal, never-URL-accessed folder,
so the rename was mechanical and low-risk — confirmed via a repo-wide
grep that nothing browser- or JS-facing referenced it directly). At the
time, the session had no way to *delete* the old `includes/` folder (no
shell/file-delete access), so it was left in place — described in the
docs at the time as "a harmless, inert duplicate."

**It has not stayed purely inert, and it has not stayed purely a
duplicate.** Two things worth knowing:

1. **Several files under `backend/` and `includes/` continue to be
   edited together, "kept in sync," round after round** (`config.php`,
   `header.php`, and AI classes like `TravelRecommender.php` are called
   out this way repeatedly in `SESSION_SUMMARY_INDEX-1.md`, e.g. Rounds
   61, 77, 80). Direct comparison in this pass confirms this for
   `backend/config.php` vs `includes/config.php`: after stripping the one
   cosmetic difference (`backend/config.php` has CRLF line endings,
   `includes/config.php` has LF), the only remaining real content
   difference is a single self-contained fix from Round 51 — three
   columns (`FromDate`, `ToDate`, `Comment`) added to `tblbooking`'s
   fallback `CREATE TABLE IF NOT EXISTS` statement in `backend/config.php`
   only, found by that round's from-scratch disaster-recovery test.
   `includes/config.php` still lacks that fix. Both files otherwise carry
   identical logic, including `tms_ensure_columns()`, RBAC, and every
   later round's additions. Likewise `backend/header.php` vs
   `includes/header.php` differ by exactly one documented, deliberate
   piece: the Round 61 RTL `dir="rtl"` flip script exists only in
   `backend/header.php`.

2. **The `includes/` copy is not fully dead code, and this is worth
   flagging plainly.** The guest-facing homepage path is:
   `frontend/index.php` (reached via the root shim `index.php`) →
   `include('backend/landing.php')` for any visitor who isn't logged in.
   `backend/landing.php` itself, however, still contains bare
   `include('includes/header.php')` and `include('includes/footer.php')`
   calls (a leftover from before the Round 49 rename that was never
   updated inside this specific file). Because `frontend/index.php` was
   reached through the root shim, the working directory is the project
   root, so these bare includes resolve to the real, on-disk
   `includes/header.php` / `includes/footer.php` files — not
   `backend/`'s copies. In other words: **every signed-out visitor to the
   homepage is currently served by `includes/header.php`/`footer.php`,
   not `backend/`'s.** This works today only because those two files are
   still deliberately kept in sync with their `backend/` counterparts
   (see point 1). If a future cleanup ever deletes the `includes/` folder
   outright, the guest homepage would break unless `backend/landing.php`
   is fixed first to point at `backend/header.php`/`backend/footer.php`.
   This was previously documented for `includes/header.php` specifically
   (`SESSION_SUMMARY_INDEX-1.md` Round 53's note distinguishing it from
   the truly-orphaned `includes/config.php`); this pass confirms the
   mechanism by direct code read.

3. **Some files under the two directories have drifted further than the
   documented "kept in sync" set, and this had not been previously
   flagged in the session summaries this pass reviewed.**
   `backend/payments/PaymentService.php` (377 lines) and
   `includes/payments/PaymentService.php` (253 lines) are substantially
   different: the `backend/` copy has the shared `handleWebhook()` entry
   point (added Round 56 for the sslcommerz/bkash/nagad webhook flow) and
   the newer `tblpayment_events` columns (`SignatureValid`,
   `ProcessingStatus`); the `includes/` copy has neither. `admin/*.php`
   files that need `PaymentService` all reference the `backend/` copy
   directly (e.g. `admin/manage-bookings.php` does
   `require_once(__DIR__ . '/../backend/payments/PaymentService.php')`),
   so this divergence appears harmless in practice — but it means
   `includes/payments/PaymentService.php` is genuinely stale, not just
   line-ending-different, and should not be assumed current if anyone
   ever references it. `backend/landing.php` vs `includes/landing.php`
   also differ by one real, non-cosmetic chunk (a Round 54 responsive-grid
   fix applied only to `backend/landing.php`).

**Bottom line:** treat `backend/` as the source of truth for all new
work. Don't assume `includes/` is dead weight safe to ignore, but also
don't assume it's kept perfectly current — check the specific file."""

new2 = """## The `backend/` vs `includes/` pair — RESOLVED, Round 115

`includes/` was the project's original shared-logic folder. Round 49
renamed it to `backend/`; the old folder couldn't be deleted at the time
(no shell/file-delete access in that session) so it was left in place.
For a long stretch afterward it genuinely was NOT fully dead: two live
bugs of the same shape kept `includes/` load-bearing —

1. `backend/landing.php` (the signed-out homepage) had bare
   `include('includes/header.php')`/`include('includes/footer.php')`
   calls left over from before the Round 49 rename, which — because
   `frontend/index.php` reaches it through the root shim (working
   directory = project root) — resolved to the real, on-disk root
   `includes/header.php`/`includes/footer.php`, not `backend/`'s copies.
   **This was fixed some time before Round 115** (`backend/landing.php`
   now calls `include('backend/header.php')`/`include('backend/footer.php')`
   directly — confirmed by reading the file's own current content, which
   carries an inline comment documenting the fix).
2. `backend/footer.php` — included by literally every `frontend/*.php`
   page, ~60 files — had its own bare `include('includes/chatbot.php')`,
   the same class of leftover, still live as of the Round 115 audit. Since
   `backend/footer.php` is reached the same way (root shim → project-root
   working directory), this meant **every single page on the site** was
   serving the chat widget from the stale root `includes/chatbot.php`
   rather than its own sibling `backend/chatbot.php`. The two files were
   still byte-identical at the time, so nothing was visibly broken, but a
   future edit to `backend/chatbot.php` alone would never have reached a
   real visitor. **Fixed in Round 115**: `backend/footer.php` now calls
   `include('backend/chatbot.php')`.

**With both of those fixed, Round 115 ran a comprehensive, whole-project
grep for every remaining `include`/`require` (bare, `_once`, with or
without parens, plus `__DIR__`-relative forms) referencing a path
starting with `includes/`, excluding `admin/*.php` (whose own bare
`include('includes/header.php')`/etc. calls resolve to `admin/includes/`,
a separate, legitimate, always-correct wrapper — see the next section —
since admin pages are requested directly and their working directory is
`admin/`, not the project root). The result: zero remaining live
references anywhere in the codebase.** Root `includes/` was therefore
moved (not deleted) to `_deprecated/includes/` in Round 115, exactly as
this document's own prior "don't assume includes/ is dead weight" caution
recommended checking before assuming — it no longer applies, because this
was actually re-verified this round rather than assumed.

**What's preserved in `_deprecated/includes/` for reference:** the
folder's 56 files are untouched, in case any of this reasoning missed an
edge case. Two real, pre-existing content differences from their
`backend/` counterparts are worth knowing if anyone ever does dig through
it: `includes/config.php` lacks a Round 51 `tblbooking` fallback-schema
fix that `backend/config.php` has, and `includes/payments/PaymentService.php`
(253 lines) predates the Round 56 `handleWebhook()` shared entry point
that `backend/payments/PaymentService.php` (377 lines) has — neither
matters anymore since nothing references the `includes/` copies, but
they're recorded here rather than silently dropped from the history.

**Recommendation:** safe to delete `_deprecated/includes/` outright after
one full release cycle with no issues traced back to it. Not deleted
outright in Round 115 itself, per this engagement's standing preference
for reversible steps over irreversible ones when the two cost about the
same."""
replacements.append((old2, new2))

# 3. The stale "signed-out homepage... includes/header.php/footer.php calls" note in the request-flow section
old3 = """5. The page includes shared chrome — `include('backend/header.php')` /
   `include('backend/footer.php')` (or, for the signed-out homepage,
   `backend/landing.php`'s own `includes/header.php`/`footer.php` calls —
   see the caveat above) — plus, on many pages, `write-us.php` and
   `signin.php` for the shared contact form and sign-in modal."""
new3 = """5. The page includes shared chrome — `include('backend/header.php')` /
   `include('backend/footer.php')` (the signed-out homepage,
   `backend/landing.php`, uses these same two directly as of Round 115 —
   see "The backend/ vs includes/ pair" for the fix history) — plus, on
   many pages, `write-us.php` and `signin.php` for the shared contact form
   and sign-in modal."""
replacements.append((old3, new3))

missing = []
for i, (old, new) in enumerate(replacements, 1):
    n = content.count(old)
    if n != 1:
        missing.append((i, n))
    else:
        content = content.replace(old, new)

if missing:
    print(f"[{path}] ABORTED, no write. Bad match counts: {missing}")
else:
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print(f"[{path}] OK: {len(replacements)} replacements applied")

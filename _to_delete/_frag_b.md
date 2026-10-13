

## Round 115, Phase B — Architecture cleanup: `includes/` retired (moved, not deleted)

**Trigger:** the audit's Phase 3 scoping (`AUDIT_REPORT_ROUND15.md`, Section
A.4/J-MEDIUM) — root `includes/` was believed to have exactly one remaining
live dependency (`backend/landing.php`'s bare header/footer includes) that
needed fixing before the folder could be safely retired.

**First correction this phase (Rule 4 — re-verify, don't trust a carried-
forward conclusion):** re-reading `backend/landing.php`'s actual current
content found it **already fixed** — it now calls
`include('backend/header.php')`/`include('backend/footer.php')` directly,
with its own inline comment documenting that exact fix. This must have
happened in an earlier round not fully reflected in `ARCHITECTURE.md` at
the time this session's audit was written. Re-verified rather than assumed
either way.

**A second, previously-undocumented live dependency found instead:**
`backend/footer.php` — included by essentially every `frontend/*.php` page
(~60 files, confirmed by grep) — had its own bare
`include('includes/chatbot.php')`. Since every one of those pages is
reached through its root shim (working directory = project root), this
bare include resolved to the real root-level `includes/chatbot.php` **on
every single page of the site**, not its sibling `backend/chatbot.php`.
The two files were byte-identical at the time (confirmed via `diff`), so
nothing was visibly broken — but this was the same class of latent bug as
the already-fixed landing.php one, just not yet caught.

**Fixed this round:**
- **`backend/footer.php`** — the bare include now points at
  `backend/chatbot.php` (its real, live sibling) instead of the stale root
  `includes/chatbot.php`.
- **`backend/chatbot.php`** and **`backend/explore-chatbot.php`** — each
  had a one-line doc comment demonstrating "how to include this file" that
  itself showed the wrong (`includes/...`) path; corrected to show the
  real path, so nobody copies the stale pattern into a new file later.

**Verification before moving anything:** ran a comprehensive, whole-
project grep for every `include`/`require` (bare or `_once`, with or
without parentheses, plus `__DIR__`-relative forms) referencing any path
starting with `includes/`, excluding `admin/*.php` (whose own bare
`include('includes/header.php')`-style calls resolve to `admin/includes/`
— a separate, always-correct, unrelated wrapper, since admin pages are
requested directly with a working directory of `admin/`, not the project
root — this was independently confirmed by verifying `admin/includes/`
actually contains its own distinct, much smaller `header.php` (6,377
bytes) and `footer.php` (924 bytes), nothing like the 67KB/15KB customer-
facing versions). Also checked for dynamic path construction
(`DOCUMENT_ROOT`-based, `spl_autoload_register`, `glob()`) and `.htaccess`
aliasing toward `includes/` — none found. **Result: zero remaining live
references to root `includes/` anywhere in the codebase.**

**Then, and only then:** moved the entire root `includes/` folder (56
files) to `_deprecated/includes/` — a move, not a delete, fully reversible
by moving it back. Re-ran the same comprehensive grep after the move to
confirm nothing broke, and spot-checked that the real entry points
(`index.php`, `frontend/index.php`, `backend/landing.php`,
`backend/header.php`/`footer.php`/`chatbot.php`, `admin/includes/header.php`/
`footer.php`) all still exist and are unaffected.

**`ARCHITECTURE.md` updated** (both canonical locations) to replace its
now-stale "`includes/` is not fully dead, here's the one exception"
narrative with the corrected history above, including the two real content
differences preserved for the record (`includes/config.php` lacks a Round
51 fallback-schema fix; `includes/payments/PaymentService.php` predates the
Round 56 shared webhook entry point) — neither matters going forward since
nothing references the `includes/` copies anymore, but neither is silently
dropped from the project's own history either.

**Deliberately not done this round:** `_deprecated/includes/` was not
deleted outright, even though it's now fully confirmed dead. Recommended
in `ARCHITECTURE.md` as safe to delete after one full release cycle with
no issues traced back to it — a reversible move now, an irreversible
delete later, once real usage has had a chance to surface anything this
static analysis might have missed.

**Files touched (all `php -l` clean on the one file checked this way —
`backend/footer.php` — the others were single-line comment/doc edits or a
pure directory move; delivered/verified identical on both canonical
locations):** `backend/footer.php`, `backend/chatbot.php`,
`backend/explore-chatbot.php`, `ARCHITECTURE.md`, plus the `includes/` →
`_deprecated/includes/` move — `C:\xampp\htdocs\tms` and `D:\Project\tms`.

**Implementation status: complete.**

**Testing status: `php -l` clean; verified by static analysis (comprehensive
project-wide grep, before and after the move) rather than a live
click-through** — the chatbot widget's actual on-page behavior was not
re-tested in a browser this round, since the two files it now resolves to
were already byte-identical, so no runtime behavior change was expected or
should be observable. A live spot-check of the chat widget on any page,
and of the guest homepage, is a cheap confirmation worth doing on the
user's own next visit to the site, though nothing in this round's evidence
suggests it's needed.

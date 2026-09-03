# Audit Report — Round 13: Dark-mode text contrast + a deploy-sync gap

Triggered by two screenshots of the live `page.php?type=aboutus` page showing
the About Us intro paragraph and the Our Mission / Our Vision card bodies as
near-black text on the dark-mode background — essentially unreadable.

## 1. Root cause of the contrast bug

Two separate things were wrong, and neither was caught by the dark-mode
contrast pass from an earlier round:

- **`page.php` never had a `class="about"` wrapper.** An earlier round added
  a dark-mode override rule `html[data-theme="dark"] .about p { ... }`
  assuming this page used that class. It never did (only `class="container"`
  exists there), so that rule was dead code from the moment it was written.
- **The real content uses `rgb(...)`, not hex.** The About Us copy is not
  hardcoded PHP — it's CMS content from `tblpages` (type='aboutus'), pasted
  in at some point from a rich-text editor. Its actual inline styles are
  `color: rgb(0, 0, 0)` and `color: rgb(80, 80, 80)`. Every existing dark-mode
  patch matched literal hex strings like `[style*="color:#555"]`, which can
  never match `rgb(...)` syntax — so this content was invisible to all of
  them.

## 2. The fix — read the real color, not the string

Rather than add another entry to a hex-string allowlist (the same class of
bug would just recur for the next admin who pastes rgb/hsl content from a
different editor), `includes/header.php` now does this generically:

- `tmsFixInlineColorsForDarkMode()` scans every element with an inline
  `color` in its `style=`, reads its *computed* color (so hex/rgb/rgba/hsl/
  named colors are all normalized the same way), and computes perceived
  brightness. Anything too dark for the dark background gets swapped to a
  light, readable color (`#cdd6e0`), with the original remembered so
  switching back to Light Mode restores it exactly.
- Runs once on load, again on every `tmsToggleTheme()` call, and is kept
  current for content added later (AJAX widgets, CMS sections loaded after
  page load) via a `MutationObserver`.
- Verified directly against the real seed values from `tblpages`
  (`rgb(0, 0, 0)` and `rgb(80, 80, 80)`) on the live server after deploying
  this fix (see below) — both are now correctly repainted in dark mode.

## 3. A more important finding: the live site was not running this session's code at all

While chasing why this fix didn't seem to take effect, live-testing showed
the running server's HTML didn't contain *any* of this session's changes —
not just this one. The reason: **`D:\Project\tms` (this session's connected
folder) and `C:\xampp\htdocs\tms` (what Apache actually serves) are two
different folders on disk**, and they had drifted apart in both directions:

- `D:\Project\tms` had every fix from Rounds 11 and 12, none of which had
  been copied to the live folder yet (this was actually called out as
  deploy-checklist step 1 in earlier rounds — it just hadn't been done).
- `C:\xampp\htdocs\tms` had independently gained a real feature never pulled
  back into `D:\Project\tms`: a persistent, cookie-based chat history for
  the site-wide chatbot widget (`tms_chat_uid()`, `tbl_site_chat_messages`,
  and matching changes in `api/ai.php`, `includes/ai/SiteAssistant.php`,
  `includes/chatbot.php`, `profile.php`), plus a live-tuned
  `AI_MAX_TOKENS=3000` and a `.env` file that don't exist in the working
  copy at all.

To fix this without losing either side's work, every file this session
actually touched (Rounds 11, 12, and this one) was copied from
`D:\Project\tms` into `C:\xampp\htdocs\tms`, and `includes/config.php` was
merged by hand — the new `BookingDate` backfill was inserted alongside the
existing `tms_chat_uid`/`tbl_site_chat_messages` code rather than
overwriting it. `api/ai.php`, `includes/ai/SiteAssistant.php`,
`includes/chatbot.php`, `profile.php`, and `.env` were left untouched in the
live folder, since none of this session's rounds ever touched them and they
contain real, un-backed-up live work.

**This means Rounds 11 and 12's fixes are only live as of this round** —
the CSRF/security hardening, the `BookingDate` root-cause fix, and the map/
weather/currency fixes were sitting unshipped in the working copy the whole
time.

### Recommended follow-up (not done here — needs your decision)

The chatbot-identity feature living only in `C:\xampp\htdocs\tms` is real,
undocumented, un-backed-up work. Consider pulling `api/ai.php`,
`includes/ai/SiteAssistant.php`, `includes/chatbot.php`, `profile.php`, and
the `tms_chat_uid`/`tbl_site_chat_messages` block of `includes/config.php`
back into `D:\Project\tms` (or whatever you treat as source of truth / put
in version control), so a future session working from `D:\Project\tms`
doesn't overwrite it again. Also worth deciding on one working folder going
forward — editing directly in `C:\xampp\htdocs\tms`, or keeping
`D:\Project\tms` as the source and remembering to copy changes across every
time (deploy-checklist step 1, above) — to stop the two from drifting apart
again.

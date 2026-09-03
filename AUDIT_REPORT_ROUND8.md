# TMS — Round 4: Admin analytics

Following Rounds 6–7 (UI foundation, booking system). This round is Phase 5
from the original list — but most of it turned out to already exist.

## What was already built (didn't rebuild it)

Before touching anything I checked what's actually in `admin/`, because
re-implementing something that already works would be wasted, riskier
churn. Already real and working:

- `dashboard.php` — system overview with live stats, recent bookings, a
  booking-status pie chart (Chart.js).
- `revenue-analytics.php` — total/monthly revenue, month-over-month change,
  top packages by revenue, a monthly revenue bar chart.
- `booking-calendar.php` — calendar view of bookings.
- `export-report.php` + `do-export.php` — CSV export (Excel-compatible) and
  print-to-PDF for bookings/users/enquiries/packages. Covers Phase 5's
  "Export Excel"/"Export PDF" asks.
- `ai-dashboard.php` — 573 lines: AI call volume (today/month), itinerary
  counts, copilot session counts, review-sentiment breakdown, top AI users.
  This is a real "AI business insights" dashboard, already done.

## Real bug found: a fully-built dashboard with no way to reach it

`ai-dashboard.php` had **zero links to it anywhere in the codebase** — not
in the sidebar nav, not from `dashboard.php`, nowhere. It only existed if an
admin happened to type the URL directly. 573 lines of working analytics,
completely undiscoverable. Same bug shape as `apply-coupon.php` from last
round: something real that got built and then never actually connected to
anything a user would click. Fixed by adding it to the sidebar nav, in the
"Analytics & Tools" section next to Revenue Analytics.

## New: User Analytics (`admin/user-analytics.php`)

The one clear gap in the "Phase 5" list that didn't already exist: no
signup/growth view, no way to see who's actually spending. Added:

- Total users, new-this-month, users with ≥1 booking, avg. bookings/user.
- Monthly signup chart (last 12 months) — the "user growth" ask.
- Top spenders table, by confirmed-booking total.
- Most active users table, by booking count.

Worth flagging: the top-spenders table only became meaningful *after*
Round 3's fix to `TotalCost` actually getting populated at booking creation.
Before that fix, this table would have shown close to nothing for
everyone, regardless of what they'd actually spent — it would have looked
broken even though the query itself was fine.

## New: Popular Destinations (`admin/popular-destinations.php`)

The other real gap. Rather than invent one blended "popularity score,"
this pulls three separate, honest signals from data the app already
collects, since they mean different things:

- **Most Explored** — from `tbl_explore_history`, which already logs every
  AI-Explore lookup. Pure browsing interest, no booking required.
- **Most Wishlisted** — from `tblwishlist`. Saved-but-not-booked intent.
- **Most Booked** — confirmed bookings grouped by the package's
  `PackageLocation` (destination), not by individual package. This is
  deliberately different from Revenue Analytics' existing "Top Packages by
  Revenue" table, which is per-package; this one rolls multiple packages at
  the same destination together, which is what "popular destination"
  usually means.

Both new pages are superadmin-gated and linked from the sidebar, matching
the existing `revenue-analytics.php` pattern exactly (same page structure,
same `tms_admin_require_role`, same `safe_q`/CSV-style helper functions) —
new pages that look and behave like they belong, not a bolted-on style.

## What I deliberately didn't touch this round

- **Export types**: didn't add "export popular destinations" or "export
  user analytics" as new CSV report types in `export-report.php`. It would
  be an easy addition, but the four existing report types (bookings, users,
  enquiries, packages) already cover the raw data; the two new analytics
  pages are aggregations of that same data, and exporting an aggregation
  is a smaller, separate ask than exporting the underlying records.
- **`ai-dashboard.php`'s internal structure**: it uses
  `include('../includes/config.php')` and `tms_require_admin()` instead of
  the `include('includes/config.php')` + `tms_admin_require_role($dbh,
  [...])` pattern every other admin page in this round uses. Functionally
  it still resolves to the same root config (PHP resolves `../`-prefixed
  includes against the calling script's directory, and this file lives in
  `admin/`), so it works — just written inconsistently with its neighbors.
  Left as-is since it works and touching it wasn't the fix that mattered
  here (visibility was).
- **Real-time widgets**: "real-time" would mean websockets/polling
  infrastructure this app doesn't have — out of scope for an analytics
  pass; the existing dashboard already refreshes on page load, which is
  what a small admin panel like this one realistically needs.

## Files changed this round

`admin/includes/sidebarmenu.php` (added 3 nav links), new files
`admin/user-analytics.php` and `admin/popular-destinations.php`.

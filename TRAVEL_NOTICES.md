# Travel notices (foundation for disruptions, entry rules, sustainability)

**What this is:** a small, honest place for staff to publish entry requirements, disruption advisories and
sustainability notes. **What it is not:** a live feed, a visa checker, a routing engine or a sustainability score.
Those need licensed or official data sources that this project does not have, so nothing is simulated.

| Piece | Where |
|---|---|
| Rules + storage (`tbl_travel_notices`, auto-created) | `backend/services/TravelNoticeService.php` |
| Customer page | `/travel-notices.php` (filter by country; linked in the Explore menu) |
| Package page card | `frontend/package-details.php` shows up to 3 notices when the package's Country matches |
| Admin | `admin/manage-travel-notices.php` (roles: operations, manager) |
| Tests | `php tests/notices/run.php` (27 checks) |

## Rules
* A notice cannot be saved without a source name, a valid `http(s)` source link and the date it was checked
  (not in the future). Text is plain; all output is HTML-escaped. Source links open with `rel="noopener noreferrer"`.
* **Disruptions must have an expiry date, at most 60 days after the checked date**, and disappear when it passes.
* Every notice shows its checked-on date. After 7 days (disruption), 90 days (entry rules) or 365 days (sustainability)
  it is labelled "may be out of date" instead of silently looking current. Admin sees the same stale flag.
* Sustainability entries are information only, labelled "not an independent audit or a score".
* The page states plainly that notices are not a live feed and that no listing does not mean no restrictions.
* An empty table shows an honest empty state; no placeholder or example notices are shipped.

## Verified
27 service checks; browser run (admin add/validation, public page, XSS escaping, country filter, empty state, package-page
card, anonymous admin access blocked, 390 px); axe WCAG 2.x A/AA clean on the admin and public pages in light and dark
(the shared site header has a non-WCAG "region" best-practice note on every public page).

## Not done / how to extend
* No automatic data. To connect a licensed source later, write rows into `tbl_travel_notices` (keep Source/VerifiedOn
  populated) or replace `TravelNoticeService::visible()`; the pages need no change.
* The AI assistant does not read these notices yet. Until it does, it must not claim to know current entry rules.
* English only; matching to packages is by exact country name (case-insensitive).
* Staff are responsible for accuracy: this feature only makes the source and age of each statement visible.

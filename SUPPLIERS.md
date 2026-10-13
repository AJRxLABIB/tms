# Supplier marketplace (Phase 9)

Lets outside tour operators list and manage their own packages, while TMS keeps control of
quality, bookings and payments. It extends the existing site; nothing was rebuilt.

## What exists
| Area | Where |
|---|---|
| Business rules (one class, no HTML) | `backend/services/SupplierService.php` |
| Supplier portal | `supplier/` (`register`, `login`, `dashboard`, `packages`, `blackouts`, `bookings`, `settlements`) |
| Admin: verify suppliers, review packages, set commission | `admin/manage-suppliers.php` (roles: manager, package_manager) |
| Admin: draft / issue / pay settlements | `admin/supplier-settlements.php` (roles: finance, manager) |
| Customer-side blackout rule | `frontend/package-details.php` and `frontend/checkout.php` |
| Tests | `php tests/suppliers/run.php` (72 checks, real database, cleans up after itself) |

Tables are created automatically by `SupplierService::migrate()` (`tbl_suppliers`, `tbl_supplier_blackouts`,
`tbl_supplier_booking_responses`, `tbl_supplier_settlements`, `tbl_supplier_settlement_items`, `tbl_supplier_audit`)
and three columns are added to `tbltourpackages` (`SupplierId`, `ApprovalStatus`, `ReviewNote`). Existing packages are untouched (`SupplierId` NULL).

## Rules the code enforces
* **Verification first.** Registration creates a `pending` account. Only a `verified` supplier can list packages.
* **Nothing goes live without admin approval.** New or edited supplier packages are `pending_review` and offline
  (`Status = 0`). Editing a live package takes it offline again until re-approved. Suspending a supplier takes all
  their packages offline; reinstating does not bring them back, an admin must re-approve each one.
* **Tenant isolation.** Every supplier query is scoped by `SupplierId` in SQL, and another supplier's package,
  blackout or booking looks "not found". Tested in both directions.
* **Blackout dates** are checked on the server when a customer books or adds to cart, and again at checkout for every
  cart item (a blackout added after the item was carted still blocks payment).
* **Privacy.** A supplier sees a customer's name and mobile number only once the booking is confirmed, never the email.
* **Supplier responses do not change booking status.** Accept/decline is recorded; a declined live booking appears for
  the admin to handle (refund/cancel through the normal booking and payment flow).
* **Settlements.** Eligible = confirmed, fully paid, trip finished, not in another settlement. Partially-refunded
  bookings are excluded and counted for manual review. The commission rate is frozen when the settlement is drafted.
  State machine: draft -> issued -> paid (needs a payment reference); draft/issued can be voided, which releases the bookings.
* **Login.** Generic error messages, timing equalised for unknown emails, 15-minute lock after 5 failures, suspended or
  rejected accounts cannot sign in, and the supplier row is reloaded on every request so suspension is immediate.
* All admin actions are CSRF-protected, role-checked on the server, and destructive ones ask for confirmation.

## How it was verified (sandbox, PHP 8.3 + MariaDB + headless Chromium)
* `tests/suppliers/run.php`: 72 passed, 0 failed.
* Browser run: register -> admin verifies -> supplier lists a package -> admin approves -> supplier adds a blackout ->
  a customer booking inside the blackout is refused on `package-details`, a booking on free dates succeeds, and a cart
  item that becomes blacked-out after carting is refused at checkout with no booking created.
* axe-core (WCAG 2.0/2.1/2.2 A and AA plus best-practice): 0 violations on the supplier portal pages in light and dark.
  No horizontal page scroll at 390 px on the five portal pages.
* Regression: AI eval 41/41, payments 18/18, re-ranker 11/11.

## Not verified / known limits
* **No money moves.** Settlements are bookkeeping. The admin pays by bank or bKash and records the reference.
  Automated payouts need a payment-provider payout agreement and were not built.
* Supplier registration has no email verification or CAPTCHA, and no password-reset flow yet. An admin reviews every
  application by hand, which is the real gate; add email verification and reset before opening it to the public.
* No supplier document upload (trade licence etc.). The registration number is free text an admin checks manually.
* Supplier portal is English only. The admin pages follow the existing admin style.
* Admin settlement pages were checked for syntax, behaviour and the browser run above; the admin page axe result still
  shows the existing admin layout's issues where noted in `REDESIGN_LIMITATIONS.md`.
* Not tested on your XAMPP/Windows install (done on Linux with PHP's built-in server).
* Commission, cancellation and payout terms are business/legal decisions: set the rate per supplier and have the
  supplier agreement reviewed before real suppliers sign up.

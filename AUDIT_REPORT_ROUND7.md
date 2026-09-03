# TMS — Round 3: Booking system

Continuing from Round 6 (UI/UX foundation — misnumbered relative to the
original 5 audit rounds; call this "Booking Round 1" if that's less
confusing). Same approach: real bugs found and fixed in your actual code,
not invented ones, plus the genuinely new pieces from the Phase 4 list.

## Real bug found and fixed: bookings silently lost their own price and traveler count

`tblbooking` has had `NoOfPersons`, `TotalCost`, `MobileNumber`, `CouponCode`,
and `DiscountApplied` columns for a while, and several other features already
read them — `ticket.php`'s e-ticket, `expense-tracker.php`, the loyalty
spend total in `AchievementEngine.php`, and the AI trip copilot's context in
`TripCopilot.php` all assume they're populated. But nothing that *creates* a
booking ever wrote to them:

- `package-details.php`'s direct "Book Now" form had no traveler-count or
  mobile-number field at all, and never computed or stored a total.
- `checkout.php`'s cart-checkout flow computed a total for display but never
  saved it, and dropped `NoOfPersons` even though `tblcart` tracks it
  correctly per item.
- The "Apply" button on the coupon field only called `coupon-check.php`,
  which *validates* a code and shows "10% discount applied!" — but never
  actually applies anything. `apply-coupon.php`, the endpoint that was
  clearly meant to persist the discount and mark the coupon used, has **zero
  callers anywhere in the codebase**. It was built and then never wired up,
  so no coupon has ever actually been applied to a real booking, and no
  coupon has ever been marked used through this flow.

Net effect: every booking on the system has had `NoOfPersons=1`,
`TotalCost=NULL`, and no discount, regardless of what the visitor actually
booked — silently understating everything downstream that reads those
columns. Fixed:

- Added "Number of Travelers" and "Mobile Number" fields to the booking form,
  and gave the coupon input a `name` so it's actually submitted.
- Coupon validation + application now happens **inline, in the same request
  that creates the booking** (more robust than the old two-step design,
  which needed a booking ID that didn't exist yet at the point it would have
  had to fire). The coupon is marked used atomically when applied.
- Both `package-details.php` and `checkout.php` now compute and store the
  real total, persons, mobile number, and (for direct bookings) coupon/
  discount at creation time.
- Renamed the coupon button from "Apply" to "Check" and reworded
  `coupon-check.php`'s message, since it was always just a preview —
  calling it "Apply" was itself part of why the gap wasn't obvious.
- Left `apply-coupon.php` in place with a comment explaining it's superseded,
  rather than deleting it, in case anything external depends on it.

This isn't cosmetic — it means `ticket.php`'s e-ticket, the expense tracker,
loyalty totals, and the AI copilot will now see real numbers for any booking
made from here on, instead of falling back to their "TotalCost is NULL"
estimate path.

## New: booking modification requests

Users can now request a date change on a pending or confirmed booking
(same lead-time guard as cancellation) from **My Bookings** → "Change
Dates." It goes to `pending` rather than applying immediately — a date
change can affect availability/pricing that only staff can verify, so this
mirrors how confirmation already works (nothing auto-applies without a
human looking at it). Admins approve or decline from **Manage Bookings**;
declining asks for a short reason that's shown to the customer and emailed/
notified, the same way confirm/cancel already notify.

## New: refund status tracking

There's no payment-gateway integration in this app (no Stripe/bKash/etc.
capture step — `tblbooking` has no such fields, and confirmation is a manual
staff action), so a literal "automatically refund the captured payment"
workflow doesn't exist to build. What's real and useful instead: cancelling
an *already-confirmed* booking (the point at which payment would have
happened, some real-world way) now logs `RefundStatus='requested'`, visible
to the customer in My Bookings and to staff in Manage Bookings, who can mark
it processed or declined (with a reason). Cancelling a still-pending
booking doesn't trigger this — nothing had been collected against it yet.

## New: booking timeline + QR verification code

- My Bookings now shows a small step tracker per booking (Requested →
  Confirmed → Completed, branching to Cancelled/Refund where relevant)
  instead of just a status pill.
- Every new booking gets a `VerificationCode`, rendered as a QR code (via
  `api.qrserver.com`'s free image API — an external dependency, flagged
  here explicitly, consistent with how this app already leans on
  OpenWeatherMap/Gemini/Open-Meteo for other features) on both My Bookings
  and the e-ticket (`ticket.php`). Encodes a simple `TMS-BK{id}-{code}`
  string for now — enough to prove a ticket wasn't just typed up in Word,
  not a full check-in/redemption system.
- Existing bookings made before this round won't have a code (column is
  nullable) — the QR block just doesn't render for those rather than
  showing a broken image.

## Downloadable invoice — already existed, not rebuilt

`ticket.php` already is a clean, print-optimized e-ticket with a "Save as
PDF" button that uses the browser's native print-to-PDF — a prior session
already made the same call I would have (no vendored PDF library, no
Composer, one dependency-free path). I added the QR block to it; the rest
was untouched.

## What I deliberately didn't touch this round

- **Admin action CSRF**: the new approve/reject-modification and
  refund-processed/rejected actions use the same plain-GET-link + JS
  `confirm()`/`prompt()` pattern as the existing confirm/cancel/delete
  actions in `manage-bookings.php`, rather than introducing a one-off
  CSRF-protected POST just for these two. That file's GET-based action
  pattern was already flagged in an earlier round as "left consistent
  rather than fixed piecemeal — a good candidate for a dedicated cleanup
  pass." This round doesn't do that cleanup; it just doesn't make the
  inconsistency worse.
- **Real payment gateway integration**: out of scope for the reasons above —
  would be a substantial, separate piece of work (choosing a provider,
  handling webhooks, PCI-adjacent concerns), not something to bolt on
  as part of a booking-workflow pass.
- Admin analytics dashboard and REST API — still queued from the original
  list.

## Files changed this round

`includes/config.php` (new self-healing columns), `package-details.php`
(booking form + coupon/total fix), `checkout.php` (cart-checkout total/
persons fix), `coupon-check.php` (message wording), `apply-coupon.php`
(doc comment only), `tour-history.php` (timeline, modification request UI +
modal, refund badge, QR), `ticket.php` (QR block), `admin/manage-bookings.php`
(modification/refund admin actions + UI).

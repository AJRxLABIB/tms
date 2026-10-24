# tests/unit/

**Current state: no dedicated pure-unit test files live here yet.**

`tests/run.php` (project root of `tests/`, not moved) already contains
several tests that *are* pure unit tests in nature — no DB, no I/O, just
a function and an assertion (e.g. `PaymentService::toMinorUnits` money
math, `tms_dynamic_price`'s clamp behavior, `TravelRecommender::
applyTravelModeAdjustment`) — mixed in the same file as genuine
integration tests that hit the real local database.

They haven't been physically split out into this folder yet. Doing that
means editing `tests/run.php` (touched by `.github/workflows/ci.yml`,
`ARCHITECTURE.md`, `DATABASE.md`, `SECURITY.md`, `TESTING.md`) for a
reorganization with no behavior change — real churn across a working,
CI-wired file for a cosmetic win, which is exactly what Rule 73 (don't
overengineer) warns against doing without a concrete reason. Tracked as
a real, scoped future item rather than silently dropped: see
`TESTING.md`'s "Structure" section.

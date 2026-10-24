# tests/e2e/

**No longer an empty gap.** `run_e2e.php` is a real, HTTP-driven end-to-end
test suite (Round 115 continuation, master prompt item 58 / "TMS V2"
prompt Phase 4) -- it makes actual curl requests against a running
instance of the app: registration through the real CSRF-protected
`/register.php` handler (scraping a live token out of real HTML, exactly
like a browser), login, an authenticated booking through the public REST
API, the two new V2 domain endpoints (`trip_readiness`/`budget_status`),
cancellation, logout, and confirming a revoked token is rejected
afterward. It creates one throwaway `e2e-<random>@tms-testing.local`
account and deletes it and everything it created when done, so it's safe
to run repeatedly against a real database.

**Run it:**

```
# Against CI's php -S 127.0.0.1:8000 (the default, see .github/workflows/ci.yml)
php tests/e2e/run_e2e.php

# Against a local XAMPP install
E2E_BASE_URL=http://localhost/tourbuddy php tests/e2e/run_e2e.php
```

**Honest scope, not everything (Rule 73):** this is a first real slice --
the core account/booking lifecycle plus this session's two newest API
endpoints. It does not cover admin workflows, payments, or anything that
needs actual JavaScript execution (this is a pure-PHP/curl HTTP client,
not a browser -- see the "WHY THIS WASN'T BUILT AS BROWSER AUTOMATION"
note in `run_e2e.php`'s own header for the dependency-cost reasoning).
Those remain open, tracked gaps, same as before this file existed --
just smaller ones.

**Verification note:** this script was verified by running its exact
logic twice via a temporary CLI-guard-removed browser copy (there is no
`php` binary in the environment that built this, so it could not be run
directly as `php tests/e2e/run_e2e.php`) -- both runs passed 14/14 and
cleaned up correctly. It is wired into `.github/workflows/ci.yml` to run
for real on every push/PR, but that workflow's own run history is not
observable from this environment (same honest limitation already noted
in `MASTER_PROMPT_STATUS.md` item 59) -- so "runs in CI" is the intended
and locally-verified behavior, not something claimed as CI-confirmed.

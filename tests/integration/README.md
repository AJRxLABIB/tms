# tests/integration/

**This project's integration suite lives at `tests/run.php`** (one level
up, not physically moved into this folder — see `tests/unit/README.md`
for why). It boots the real `backend/config.php` and runs against your
actual local database: money math, pricing/availability bounds, login
lockout, refund-ledger safety, migration file sanity, NL-search
date-overlap regression, privacy-table schema consistency, marketplace
sample-data flagging, RBAC fail-open behavior, and the new
`BookingStateMachine` transition coverage (Round 115 continuation).

Run it: `php tests/run.php`. Full detail in `TESTING.md`.

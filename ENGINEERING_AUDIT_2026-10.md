# TMS engineering audit — checkpoint 1 (2026-10-22)

## Verified baseline
- 579 PHP files (excluding vendor/uploads/phpmailer) parse cleanly under PHP 8.3 (token_get_all with TOKEN_PARSE, run in a WebAssembly PHP in the sandbox).
- **Not run:** every DB-backed test (`tests/run.php`, `tests/payments`, `tests/integrity`, `tests/security`, …). They need MySQL, which the sandbox does not have. No pass is claimed. Run them on XAMPP before and after any change: `php tests/run.php` etc.
- Not measured: latency, query counts, real gateways, real AI runtime, Apache/.htaccess behaviour.
- Repo state: git history present, `.env` is untracked and git-ignored; no API keys or private keys found in tracked files (regex scan for Google/OpenAI-style keys and PEM headers). Repo history for `.env` is clean.
- Already present (do not rebuild): local Ollama provider + fallback chain (`AI_PROVIDER`), tool registry/router, injection guard, Bangla normaliser, booking state machine, payment service with idempotency keys and a webhook event log, supplier service, trip autopilot, travel notices, SW with private-path exclusion, many security tests.

## Fixed in this checkpoint
| Item | Change | Evidence |
|---|---|---|
| TOTP secret sent to api.qrserver.com (customer + admin 2FA setup) | QR now drawn in the browser by the bundled `js/qrcode.js`; `tms_totp_qr_url()` returns `''` | JS lib encodes a real otpauth URI (version-auto, M level) in node; changed PHP files parse. **Browser check on XAMPP still needed.** |

| Default AI provider was always Gemini (external) | `backend/config.php`: with no `AI_PROVIDER`, defaults to `ollama` unless a Gemini key is configured (existing installs unchanged) | Parses; logic is one expression using `tms_env` (blank → default). Not run against a live Ollama. |
| `AIClient::isRateLimited` failed open on any DB error | Fails closed, except when the usage table is missing (SQLSTATE 42S02) | Parses; **no DB test run** |
| Abandoned pending bookings held seats forever | New `scripts/expire_pending_bookings.php`: off unless `TMS_BOOKING_HOLD_MINUTES` set, dry run by default, skips paid/in-flight payments, re-checks under row lock, reverses loyalty points | Parses; **SQL not run — try the dry run on XAMPP first** |

| Schema DDL ran inside web requests | `TMS_AUTO_SCHEMA` switch in `backend/config.php` (default on in dev, off when `APP_ENV=production`); `database/migrate.php` (apply mode) runs the same setup explicitly via `TMS_RUN_SCHEMA` | Gate logic tested for 5 env combinations in PHP 8.3; files parse. **Needs a XAMPP run (below).** |
| Security test hard-coded cookie host 127.0.0.1 (false FAIL on `http://localhost/tms`) | `tests/security/run.php` uses the host of the URL under test | Re-run needed |

## Upgrade batches 2 and 3 (built, NOT yet run against MySQL/Apache)
New migrations 016–023 and 030 (all additive; apply with `php database/migrate.php` after a backup). New services: EventBus + `scripts/run_jobs.php`, RulesEngine (+`admin/workflow-rules.php`), BusinessMetrics (`admin/bi-copilot.php`), SupplierQuality/InventorySource (`admin/supplier-quality.php`, `supplier/quality.php`), ItineraryVersions + RouteOptimizer (my-trip), FraudCases (`admin/fraud-cases.php`, `scripts/scan_fraud_signals.php`), InventoryService (slots/holds, `scripts/expire_inventory_holds.php`), ApiSupport (request ids, error envelope, pagination, Idempotency-Key), EnvCheck (`scripts/check_env.php`), 2FA recovery codes, DEPLOYMENT.md, CI quality gates, a11y CSS/JS, offline notice, SEO JSON-LD, robots.php, index migration. Security: `health.php` no longer discloses detail publicly; deny-all `.htaccess` in tests/, scripts/, tools/ (about 190 diagnostic scripts had no CLI guard); XSS escapes in add/edit-package and ai-packages.
Pure-logic tests passing in PHP 8.3 (sandbox): AI eval 99/99, banglish 46, query parser 77, budget 27, metrics matcher 39, supplier quality 23, API contract 42, itinerary/route 39, fraud scoring 29, inventory 30, envcheck/recovery 40, SW 17/17.
**Batch 4 (also unrun):** hotel/activity booking and cancel now reserve/release inventory slots (slot-managed entities only; migration 023 first); admin 2FA recovery codes (migration 031); customer `appeal.php` linked from the profile page.
**Still open:** marketplace bookings have no expiry job; account deletion does not release slots; jQuery 1.12 remains; payment reconciliation/ledger deferred by request.
**Apply order after backup:** `php database/migrate.php` (applies 001–031), `php scripts/check_env.php`, then run the test suites listed in TESTING.md plus `tests/integration/*` and `tests/security/recovery_appeal_test.php`.

## AI batch (migrations 032–035; pure tests pass in PHP 8.3, nothing run on MySQL/Ollama)
Gateway: ModelRegistry (installed Ollama models + digest), ModelRouter (`AI_MODEL_FAST/CHAT/PLAN`, fallback chain, `no_model` → deterministic fallbacks, context trimming), StructuredOutput (schema + one repair), per-call model/version log without prompts. Retrieval/tools: hotels, activities, destination guides, cancellation info indexed (demo labelled, never priced); live price re-check (audit item 19 fixed); owner-only `get_my_booking`; compare/search tools; consequential actions only as code-made confirmation cards (hand-off to existing pages). Planning: PlanValidator (costs, overlaps, mode limits, infeasible detection, deterministic fallback itinerary), TripState, draft-only PackageDraft, output checks. Evaluation: eval cases 182 (leakage check: 0 of 28 leaked), PromptRedactor, AIMetrics, `scripts/ai_benchmark.php`, `admin/ai-evaluation.php`. Results (sandbox): eval 182/182, retrieval 43, tools 54, structured 31, router 44, banglish 46, parser 77, planner 47.
**Unverified:** real model answer quality, Ollama runtime, MySQL paths, browser UI of the confirmation cards.

## Fixes from the user's screen recording (2026-10-22; unrun in a browser)
Maps/places (blank map, endless "Finding hotels nearby"): Overpass mirrors raced with short timeouts, cache → live → stale → local fallback, bundled Leaflet + tile fallback chain, browser timeouts with retry. AI "temporarily unavailable": `AI_PROVIDER=auto` (running Ollama, else Gemini only with a well-formed key, else catalogue-only fallback), `admin/ai-setup.php`, `scripts/ai_setup_check.php`, AI_SETUP.md. Slowness: session lock held during slow AI calls, blocking unpkg Leaflet, per-request `CREATE TABLE`, Windows `localhost` DB host delay, `SELECT *` on cards, uncached site stats; `TMS_PROFILE=1` + `scripts/profile_pages.php` to measure. UI/content: invisible chips/buttons (white-on-white), blog title contrast, nav overflow, stale Shreya/Delhi About + invented contact details (migration 036), mismatched stock images and filler text on sample packages. Migrations 036 and 040.

## Measured baseline on XAMPP (user-run, 2026-10-22)
`tests/run.php` 20/20 · `tests/payments/run.php` 25/25 · `tests/integrity/stage16_test.php` 23/23 · `tests/security/run.php http://localhost/tms` 25/26 (the 1 failure was the test's cookie-host bug above).

## Risk register (confirmed from code; still open)
**P0**
1. `PaymentService::handleWebhook` verifies then only marks the event "processed". It never updates `tblpayments`/`tblbooking`, never checks amount/currency/order reference. All three real providers throw or return `false` (`initiate()` unimplemented, `verifyWebhook()` returns false), so no live gateway can work. Sandbox is the only path to "paid".
2. Idempotency key includes `date('Y-m-d')`: same booking and amount on a different day creates a second payment row.
3. Refund processing not atomic; supplier settlement treats `PaymentStatus='paid'` as proof of payment; no ledger.
4. Money columns are `INT`; booking dates are `varchar` (needs a tested migration).
5. Loyalty points credited at unpaid booking creation; review/referral farming (PROJECT_AUDIT items 4, 5).
6. 2FA secret encryption is opt-in (`TMS_2FA_KEY`); plaintext otherwise.
7. Schema changes in ordinary requests: `CREATE/ALTER TABLE` found in `config.php`, AI classes, `CacheService`, `SupplierService`, `TripAutopilot`, `TravelNoticeService`, `GoogleAuth`, `frontend/bd-guide.php`. Needs a versioned-migration move.
8. Default AI provider is Gemini (external); Ollama is optional. Requirement is a local-first core.

**P1:** `AIClient::isRateLimited` fails open; cart repricing/expiry; pending bookings hold seats forever (no expiry job); no outbox/queue; jQuery 1.12.0; stale `backend/tmp-round115-*.php` stub (inert, delete).

## Next implementation units (in order)
1. Payments: provider `verify()` via the gateway's server-side validation API, then one transactional `reconcile()` (amount/currency/booking match, lock, idempotent, state-machine transition, event + audit row); drop date from idempotency key; unresolved-payment list for admins. Tests with a fake provider.
2. Booking hold expiry job + atomic refund processing.
3. Move runtime DDL to `database/migrations`.
4. Default `AI_PROVIDER` to ollama with deterministic fallback.

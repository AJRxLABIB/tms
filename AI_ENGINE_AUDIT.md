# AI Engine: audit, baseline and Stage 1 status

Scope: the built-in TMS AI layer (`backend/ai/`, `api/ai.php`). This is an honest status
document, not a "production-ready" claim. Real model answer quality and real
provider calls (Gemini / OpenAI / Ollama) have NOT been tested; the sandbox had no keys.

## 1. Component audit (what exists)

| Component | Responsibility | Called from | Known limits |
|---|---|---|---|
| `AIClient` | Provider abstraction, rate limit (`tbl_ai_usage_log`), cache, telemetry (`tbl_ai_telemetry`) | everything | Before Stage 1: single provider, no failover, no breaker |
| `providers/*` | Gemini, OpenAI, Ollama HTTP adapters | `AIClient` | Errors now carry a `kind` |
| `ToolRegistry` | Read-only, schema-validated tools, audited (`tbl_ai_tool_audit`) | `ToolRouter` | No write tools by design |
| `ToolRouter` | Rule-based routing, produces wrapped FACTS and citations | `SiteAssistant` | Rules, not an LLM planner |
| `KnowledgeIndex` | BM25 over published packages | router tools | Index is rebuilt per request; vectors are persisted separately (`EmbeddingStore`) |
| `EmbeddingReranker` | Optional rerank | `KnowledgeIndex` | Only when an embedding provider exists |
| `InjectionGuard`, `SafetyEval` | Input screening, eval cases (`tests/ai/eval_cases.json`) | assistant, CI | Pattern based |
| `SiteAssistant` | Chatbot | `api/ai.php handleChatbot` | Single-turn tool use, no multi-step planning |
| `TripAutopilot` | Disruption proposals with customer approval | `my-trip.php`, cron | See `TRIP_AUTOPILOT.md` |

## 2. Baseline (before Stage 1) and now

| Measure | Before | Now |
|---|---|---|
| Routing/safety eval cases | 47 | 63 (all pass) |
| Providers per call | 1, no failover | configurable chain with circuit breaker |
| Bengali budget ("৫০ হাজার টাকার মধ্যে") | parsed as 0, price filter silently dropped (bug) | 50000 ceiling |
| Persistent user memory | none | explicit-save, user-controlled |
| Cost calculation | model free-text | deterministic integer-taka tool |

## 3. Stage 1 delivered (sandbox-verified)

- Provider failover with failure classification (unavailable / config / quota / content), circuit breaker in `tbl_app_meta`, jittered backoff, one overall deadline. Content refusals never fail over. Cloud fallback needs `AI_FALLBACK_ALLOW_EXTERNAL=1`; local Ollama is always allowed.
- `BanglaNormalizer`: Bengali digits, amounts (হাজার, লাখ, কোটি, ৳, Tk, k), budget ceilings. Tokenizer handles Bangla digits and stopwords.
- `TravelMemory` + `ai-memory.php`: saved only when the user says so (UI or "remember ..." / "মনে রাখো ..."), no silent extraction, no LLM writes, secrets rejected, 40 item cap, 90 day constraint expiry, switch-off, real deletion, included in privacy export/erasure. Avoid-list is enforced in code on search results.
- `TripBudget` tool (`calculate_trip_budget`): lines labelled catalogue / user_estimate / assumption, uncounted costs listed, verdict limited to "within_budget_for_known_costs" / "over_budget".
- Admin AI dashboard: provider health panel (breaker state, calls, success %, avg/p95).

## 3b. Stage 2 delivered: bounded multi-step tool use

`ToolRouter::run` now executes dependent steps: after a catalogue search that returns real packages, code (not the model) may add a budget step for the top result (cost intent) or a travel-notice step for its country (visa/safety intent). Hard bounds: 4 steps and 8 s per turn, identical calls never repeated, read-only tools only, early stops are disclosed to the model, and the avoid-list is applied before any follow-up. A per-step `trace` (tool, ok, ms, follow_up) is returned for audit/analytics. Still rule-based: there is no LLM planner, by design (small local models are unreliable at it).

## 3c. Stage 3 delivered: answer verification

`CitationVerifier` checks each assistant answer in code against the tool results: money figures (next to ৳/Tk/BDT/taka/টাকা, Bangla digits understood) must appear in the retrieved facts or in what the user wrote (a whole multiple x2..x20 of a verified price is accepted as group arithmetic). An unverifiable figure gets a plain caution line (English or Bangla); the answer is not rewritten or regenerated. Invented `[pkg:N]` markers are stripped. The chat widget shows "Sources" links, taken only from retrieved citations whose title the answer names. Limits: it checks numbers and source existence, not whether prose claims are true, and a model that mentions a price without a currency marker is not checked. Unverified/removed counts go to the PHP error log only; there is no analytics table yet.

## 3d. Stage 4 delivered: analytics and an evaluation regression gate

- `AITurnLog` (`tbl_ai_turn_log`): one anonymous row per assistant turn: outcome, language, step and follow-up counts, tool names, stop reason, unverified-price and removed-marker counts, sources shown, memory items used, provider. It stores no message text, email or user id, is best-effort (a storage failure never affects a reply) and is pruned after 90 days. The admin AI dashboard has an "Assistant quality signals (last 7 days)" panel built on it.
- `tests/ai/baseline.json` + `run_eval.php`: CI now fails if the eval suite has fewer cases or fewer passes than the recorded baseline, so a failing case cannot be hidden by deleting it. `php tests/ai/run_eval.php --update-baseline` raises it, and only when everything passes. The gate was proven to fail by removing a case.
- Limits: these figures describe engine behaviour (how often tools, verification and limits fire), not answer quality. Quality needs a labelled set run against a real model, which does not exist yet.

## 3e. Stage 5 delivered: live quality harness and guard gaps closed

- `tests/ai/quality_cases.json`: 26 labelled questions (English, Bengali, mixed Bengali/English, adversarial). `backend/ai/QualityEval.php` scores each real answer on objective checks only: reply language, tools that actually ran, no unverified price, forbidden / required phrases (e.g. honest "cannot confirm" hedging for visas and safety), and guard refusals. It does not judge helpfulness or tone.
- `php tests/ai/live_quality.php` runs those questions through the real assistant and the provider in `.env`, saves results under `tests/ai/results/` and reports regressions against the previous run of the same provider+model. With no provider configured it prints SKIPPED and measures nothing. `--stub` is a plumbing self-test only. The synthetic user's turns are kept out of the dashboard metrics and cleaned afterwards. **It has not been run against a real model.**
- Guard gaps found by probing and closed: "what is your system prompt?", "reveal the API key" (including combined with role hijack), "admin / database password" requests and the Bengali equivalents. Matching false-positive cases (forgot-my-password, payment security) were added so ordinary questions are not blocked. Eval suite 63 -> 75 cases.
- Known weak spot, not fixed: mixed "Banglish" (Bengali in Latin letters) is only checked for price grounding; retrieval does not transliterate it.

## 3f. Stage 6 delivered: admin engine controls

`admin/ai-dashboard.php` has an "Engine controls" panel (roles: superadmin, manager, ai_manager; CSRF-protected; post/redirect/get) backed by `AIConfig`:
chat assistant on/off, saved memory on/off, failover on/off, max tool steps (1-6) and tool time limit (2-15 s). Every value is range-checked and every change is written to `tbl_ai_config_log` (who, when, old, new) and shown in the panel. Switching the assistant off makes the endpoint return 503, so the widget shows its standard non-AI answers; switching memory off stops saving, changing and using saved items (they are kept, not deleted).
Deliberately NOT controllable from the browser: provider API keys, the cloud-fallback opt-in (`AI_FALLBACK_ALLOW_EXTERNAL`, it sends conversation text to a vendor), the injection guard and price/source verification. Admins can only switch things off or tighten/loosen bounded limits.
The dashboard also lists the 20 most recent turns (time, outcome, language, tools run, flags): counts only, HTML-escaped, no message text.
Limits: settings apply per request from the database (no cache across requests); a storage failure falls back to the defaults. Failover can only be disabled here; whether a fallback exists at all is still set in `.env`.

## 3g. Stage 7 delivered: itinerary plan checks

`ItineraryChecker` runs deterministic self-consistency checks on a generated plan (no AI, no network): times going backwards, overlaps (10 min tolerance), days over 12 h of activities or a 16 h span, starts before 05:00 or past midnight, entry costs far above the day's own budget, day count / numbering / date sequence, cost total vs its parts, estimated total vs the stated budget, and a distance check. The distance check only fires when two consecutive activities at different places with KNOWN coordinates are farther apart in a straight line than the gap between them could cover at 120 km/h (a straight line is a lower bound, so this can only flag the impossible; flights are not modelled). Coordinates come from the Explore destination cache already in the database; places without them are skipped and counted, never guessed. **No travel time is ever estimated or invented.**
The result is attached to new plans (`plan_check`, never to the AI-unavailable fallback template), shown on the planner page, on My Trip (computed fresh from the stored plan) and on the Autopilot suggestion card. It never edits the plan.
Limits: a clean result means "no contradictions found", not "the plan works" (opening hours, queues, real travel times, weather suitability are not checked, and AI-written durations like "half day" are skipped). The distance check will usually cover few pairs until more destinations are cached; the check reports what it skipped.

## 3h. Stage 8 delivered: persistent embeddings and hybrid retrieval

- `tbl_ai_embeddings` stores one L2-normalised float32 vector per package with model key and a sha1 of the indexed text, so changed packages are detected as stale and re-embedded only when needed (`EmbeddingStore`, `scripts/sync_embeddings.php`, bounded "Index now" button on the AI dashboard).
- `HybridSearch` fuses BM25 top-20 with dense cosine using Reciprocal Rank Fusion (k=60). Dense-only hits must reach `AI_EMBED_MIN_SIM` (default 0.5).
- Safety rules: hybrid is used only when at least 80% of the current catalogue is indexed for the current model; any embedding failure at query time falls back to the previous rerank/BM25 path; the tool schema seen by the model is unchanged; filters and the user's avoid-list still apply.
- Verified: `tests/ai/hybrid_test.php` (33 checks) with a deterministic stub embedder, plus unchanged results for all earlier suites.
- NOT verified: any real embedding model. Whether hybrid improves answers is unmeasured; `AI_EMBED_MIN_SIM=0.5` is an untuned guess; `nomic-embed-text` is English-centric, so Bangla queries need a multilingual model (for example bge-m3, not tested here).

## 3i. Stage 9 delivered: booking-readiness check (read-only)

- New tool `check_booking_readiness(package_id, travelers, start_date?, end_date?)` (`backend/ai/BookingReadiness.php`). It applies the booking form's own rules with the same functions: published and priced package, at most 20 travellers, departure in the future, return after departure, supplier blackout dates (`SupplierService::blackoutConflict`), remaining seats (`tms_remaining_seats`, cancelled bookings ignored) and the date-specific price (`tms_dynamic_price`).
- It creates, holds and changes nothing: no booking, seat, coupon or payment. A test compares booking rows and seat counts before and after, and a static test checks the source has no write SQL. The assistant is told never to claim a booking was made.
- Routed by code: on a package page, or as a follow-up on the top search result, when the message asks about booking or availability. Travellers and only unambiguous numeric dates (YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY, Bangla digits accepted) are parsed; "next Friday" or "15 December" are never guessed.
- The `x1_no_write_tools` guard now allowlists exactly this one name.
- Verified: `tests/ai/booking_readiness_test.php` (31 checks) against the real sandbox database with temporary packages, bookings and blackouts; all earlier suites re-run green. Two live-quality cases (en, bn) were added to `quality_cases.json`; they only run with a real provider.
- Limits: seats are a live read, not a reservation; the price excludes coupons and add-ons; without an end date the blackout check is reported as "not_checked". Whether a real model words the result well is NOT measured.

## 3j. Stage 11 delivered: query understanding, filters and injection hardening

`QueryParser` (code, not the model) reads budget ceilings/floors/ranges, trip length and traveller count from English, Bangla and common Banglish phrasing (k, lakh, hajar, Bangla digits, number words such as "ek lakh"). A stated TOTAL for N travellers becomes a per-person ceiling because package prices are per person. `ToolRouter` passes `max_price`, `min_price`, `days_min/days_max` to `search_packages`; `KnowledgeIndex::passes()` filters on them ("3 Days/2 Nights" counts as a 3-day trip). Budget and country are hard limits and are never relaxed; trip length is relaxed only when nothing matches exactly, and that is reported in the facts block. A request that is only constraints falls back to a filter-only listing (best rated, then cheapest). `ToolRouter::searchNotes` tells the model what was applied/relaxed and to say "none found" instead of inventing packages. `NLSearchParser` heuristics and `BanglaNormalizer::budgetCeiling` use the same parser ("under 1.5 lakh" was read as 1). `InjectionGuard` now matches on a normalised copy (NFKC, zero-width/bidi characters, spaced letters, leetspeak digits; e-mails/URLs untouched) and has rules for "disregard the rules", "print the text above", "repeat your initial instructions", fake chat-template markers. `AIClient::sanitizePromptInput` strips real tags only (`strip_tags` dropped text after a bare "<").
Tests: `query_parser_test.php` 77, `search_filters_test.php` 32 (real DB), eval 85/85 (baseline raised to 85).
Limits: season is parsed but nothing is filtered by it; nights are read as days; Banglish is understood only through the number words/cues listed in the tests; pattern screening is defence in depth, not a guarantee; no real model was involved.

## 3k. Stage 12 delivered: follow-up resolution and package comparison

`FollowUp` resolves references to the previous answer ("the second one", "3rd", "the last one", Bangla "দ্বিতীয়", "the cheaper one", "compare them", "compare the first and third") to real package ids in code. Ids come only from `package-details.php?pkgid=` links in the assistant turn the server itself saved (user turns can never plant ids). Anything ambiguous (ordinal beyond the list, a price tie for "cheaper", nothing to refer to, bare "that one") is NOT guessed: `SiteAssistant` tells the model to ask which package is meant. An explicit ordinal overrides the page the visitor is on. New read-only tool `compare_packages` (2-4 published packages; unpublished ones are listed as not_published and never shown; cheapest and price gap computed by code; equal prices give no winner; missing cancellation policy reported as "none recorded"). `ToolRouter::plan` takes the resolved ids; `SafetyEval` x1 allowlists exactly this tool.
Test: `tests/ai/followup_test.php` (29, real DB).
Limits: Bangla ordinals match whole words only (suffixed forms such as "দ্বিতীয়টির" are deliberately not guessed); only the latest list the assistant linked is remembered; comparison uses catalogue fields only (no hotels/activities data yet); no real model involved.

## 3l. Stage 14 delivered: suggestion chips, answer cache, database rate limits

`Suggestions` builds quick-reply chips in code from what the turn really produced (several packages -> "Compare them", "Which is the cheaper one?", ...; one package -> policy/seats/cost; nothing found -> a way forward). Every English and Bangla chip is tested to route to the tool its label promises (this caught a Bangla chip whose suffixed form the resolver rightly refuses to guess). The model's free-text chips only fill remaining slots (max 4, no URLs/markup, de-duplicated). Answer cache: guest replies are cached for `AI_CHAT_CACHE_TTL` seconds (default 600, 0 disables, max 3600) under a key that hashes the COMPLETE model input (system prompt with live facts, page context, history, messages), so a changed price, package or conversation can never serve a stale answer; never used for signed-in visitors, when saved travel memory is in the prompt, or during quality runs. `AIRateLimit`: atomic per-hour counters in `tbl_ai_rate` keyed by SHA-1 of the client address (guests, default 60/h, `AI_GUEST_IP_HOURLY`) or account (default 300/h, `AI_USER_HOURLY`); the old session limit stays. Address = `REMOTE_ADDR` only (forwarding headers are client-controlled). Fails open if the database errors (logged).
Tests: `tests/ai/conversation_test.php` (41), including 8 parallel PHP processes against one limit (exactly 30 allowed; a first version that read the count with a separate SELECT let only 29 through, now fixed with LAST_INSERT_ID).
Limits: behind a reverse proxy every visitor shares the proxy's address until a trusted-proxy setting is added (the guest limit is then effectively global); the cache depends on identical input, so hit rates on free-text questions will be modest; the 300/h account limit is a new default behaviour; real model replies were not involved.

## 4. Verification actually run (sandbox: PHP 8.3, MariaDB, stubbed HTTP transport)

engine 122/122, query parser 77/77, follow-up 29/29, conversation 41/41, search filters 32/32, itinerary checker 30/30, autopilot HTTP 23/23, quality scorer 16/16, admin controls over HTTP 14/14, resilience 29/29, memory HTTP 18/18, eval 85/85, rerank 11/11, Ollama unit, autopilot 40/40, payments 18/18, suppliers 72/72, notices 32/32, security 26/26, `tests/run.php` 20/20, e2e 14/14. axe WCAG A/AA clean on `ai-memory.php` (desktop/mobile, light/dark) and the dashboard panel (light/dark).

## 5. NOT verified

Real provider calls and answer quality (the harness exists but has never run against a real model); failover against a real outage; Apache/XAMPP behaviour; `.htaccess` guards; Bengali quality of model replies (only parsing is tested); load/latency under concurrency.

## 6. Not implemented yet (do not assume these exist)

LLM-driven planning and multi-step tasks beyond the two rule-based follow-ups above; weather-aware replanning beyond Autopilot; real routing and travel-time data (only straight-line impossibility checks exist); multimodal/OCR; per-task model routing; cache isolation measurement; trend charts over time; conversation-summary memory (deliberately omitted).

## 7. Rollback

Failover is off unless `AI_FALLBACK_PROVIDERS` is set. Memory can be switched off per user (`tblusers.PersonalizationEnabled` and the page toggle). The budget tool only triggers with a package context. Migrations are additive (`tbl_ai_user_memory`).

## Unit 17: evaluation, measurement and guardrails (built; DB/Ollama paths unrun)

- `backend/ai/PromptRedactor.php`: masks emails, phones (Latin and Bengali digits), cards, NID/passport-like numbers and key-like strings before text is logged or sent to a **non-local** provider. `PromptRedactor::forProvider($provider, $text, $baseUrl)` is the hook for the AI gateway: unchanged for a local Ollama endpoint, redacted otherwise. `AITurnLog::safeText()` and `EvalRunLog` already use it. It is pattern masking: names and free-text addresses are not detected.
- `tests/ai/eval_cases.json`: 161 cases (was 99) including leakage attempts (another user's booking, another supplier's data, secrets, system prompt, English/Bengali/Banglish), retrieved-text injection, hallucination traps and redaction. `run_eval.php` prints **unauthorised information leaked: N** and fails if N > 0. Baseline raised to 161. Closed guard gaps found by the new cases: supplier-data requests, `.env`, Banglish prompt/secret/override requests, "you are now DAN", blanket-discount requests.
- `tests/ai/quality_cases.json`: 12 more live-model cases (hallucination, refund promise, phone-number leak, supplier leak, Banglish, planning realism, booking context). Live results need a real model.
- `backend/ai/AIMetrics.php`: latency percentiles, failure rate per provider+model, retrieval hit rate, price groundedness, feedback ratio, regression status; `EvalRunLog` stores run summaries in `tbl_ai_eval_runs` (migration 035).
- `scripts/ai_benchmark.php`: fixed 8-case set over every installed Ollama model (`--dry-run` needs no runtime): latency, JSON validity, price grounding, Bengali reply rate, tokens/s, disk and loaded memory; prints a routing hint from measurements only.
- `admin/ai-evaluation.php`: read-only view of all of the above. Not verified: pages in a browser, SQL, a real Ollama run.

## Stage 7: retrieval beyond packages, owner-only booking tool, confirmable actions (sandbox-verified, DB paths unrun on MySQL)

- `rag/EntityIndex.php`: BM25 over published hotels, activities and the curated BD destination guides (allow-listed columns only; Bengali, Banglish and English via the shared tokenizer). Demo rows (`IsSampleData=1`) are searchable but flagged `demo`, their price is withheld, and availability is never stated for any row.
- Package index (audit item 19): the cache key now carries a fingerprint of the live table, `ApprovalStatus` is honoured, and every package a tool quotes is re-checked against the live table (unpublished = not returned, re-priced = current price).
- `rag/RetrievedText.php`: supplier/staff free text is stripped of control characters, capped, and withheld when it reads like instructions.
- New read-only tools: `search_hotels`, `search_activities`, `get_destination_guide`, `get_cancellation_info`, `get_my_booking` (owner-only, session identity, someone else's booking looks like a missing one).
- `ActionProposals` + migration 033 + `api/ai.php?action=action_confirm|action_dismiss`: "cancel booking 12" / "change dates of booking 7" / "use coupon X" become a confirmation card created by code after an ownership check. Confirming hands off to the existing page (`tour-history.php`, `checkout.php`); nothing is executed because no reusable business function exists. The model cannot create, confirm or execute proposals (not in `ToolRegistry`).
- Tests (PHP 8.3 + in-memory SQLite, no MySQL): `tests/ai/retrieval_test.php` 43/43, `tests/ai/tool_validation_test.php` 54/54, `run_eval.php` 182/182 (baseline raised to 182).
- Not done: dense embeddings for hotels/activities (packages keep the existing hybrid), live slot availability in answers, a real executor for proposals.

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
| `KnowledgeIndex` | BM25 over published packages | router tools | Index is rebuilt per request, no persistent embeddings |
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

## 4. Verification actually run (sandbox: PHP 8.3, MariaDB, stubbed HTTP transport)

engine 84/84, resilience 27/27, memory HTTP 18/18, eval 63/63, rerank 11/11, Ollama unit, autopilot 40/40 + 19/19, payments 18/18, suppliers 72/72, notices 32/32, security 26/26, `tests/run.php` 20/20, e2e 14/14. axe WCAG A/AA clean on `ai-memory.php` (desktop/mobile, light/dark) and the dashboard panel (light/dark).

## 5. NOT verified

Real provider calls and answer quality; failover against a real outage; Apache/XAMPP behaviour; `.htaccess` guards; Bengali quality of model replies (only parsing is tested); load/latency under concurrency.

## 6. Not implemented yet (do not assume these exist)

Unified orchestrator and bounded multi-step tasks; persistent embeddings with hybrid fusion and citation verification; weather-aware replanning beyond Autopilot; routing/geographic intelligence; booking-preparation tools; multimodal/OCR; per-task model routing; cache isolation measurement; richer admin control centre; evaluation trend tracking with regression gating; analytics; conversation-summary memory (deliberately omitted).

## 7. Rollback

Failover is off unless `AI_FALLBACK_PROVIDERS` is set. Memory can be switched off per user (`tblusers.PersonalizationEnabled` and the page toggle). The budget tool only triggers with a package context. Migrations are additive (`tbl_ai_user_memory`).

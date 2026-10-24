# AI setup (local-first)

TourBuddy uses a language model for the chat assistant, the Explore overview, the trip planner and the recommenders. **Everything
still works without one**: when no model is available the site answers from its own catalogue (packages, hotels, activities,
curated guides, emergency contacts) and says so. No customer text is sent to an outside service unless you configure one.

## How the provider is chosen

`AI_PROVIDER` in `.env` (unset = `auto`):

| Value | Behaviour |
|---|---|
| `auto` (default) | Local **Ollama** if it is running and has a chat model installed. Otherwise **Gemini**, but only if `GEMINI_API_KEY` looks like a real Google AI Studio key (`AIza` + 35 characters). Otherwise **no AI** (catalogue answers). OpenAI is never chosen automatically. |
| `ollama` | Always local Ollama. |
| `gemini` / `openai` | Always that vendor. Customer chat text goes to them; say so in your privacy policy. |
| `none` | AI off. |

If you see "AI assistant temporarily unavailable" in an old log, it came from a provider that was selected but could not answer
(wrong key, Ollama not running). The `auto` mode avoids selecting such a provider, and customers now get catalogue answers
instead of an error.

## Install the local AI on Windows with XAMPP (about 10 minutes)

1. Install Ollama: <https://ollama.com/download/windows>. It starts by itself and lives in the system tray.
   Open <http://127.0.0.1:11434> in a browser: it should say "Ollama is running".
2. Open PowerShell and pull a chat model (about 2 GB):
   `ollama pull qwen2.5:3b-instruct`
   Slower computer? `ollama pull qwen2.5:1.5b-instruct` is faster and a little less accurate.
3. Optional, for better search and Bengali: `ollama pull bge-m3`, then in `.env` set `AI_EMBED_MODEL=bge-m3` and run
   `php scripts/sync_embeddings.php`.
4. In `.env` leave `AI_PROVIDER` unset (or `auto`). **Delete a wrong `GEMINI_API_KEY` line** (a Gemini key starts with `AIza`, or `AQ.` for the newer authorization keys AI Studio issues since 2026).
5. Check it: `php scripts/ai_setup_check.php --test` or open **Admin > AI Setup & Test** and press *Send one test request*.

## Speed

* The **first** answer after Ollama starts is slow because the model loads into memory. `OLLAMA_KEEP_ALIVE` (default `30m`)
  keeps it loaded afterwards.
* A 3B model on a CPU produces a few words per second. Answers are capped by `OLLAMA_MAX_TOKENS` (default 1536).
* A graphics card makes it many times faster; Ollama uses one automatically.
* Pick models by measurement, not size: `php scripts/ai_benchmark.php`, then set `AI_MODEL_FAST`, `AI_MODEL_CHAT`,
  `AI_MODEL_PLAN` in `.env`.

## When the AI is off

| Feature | What visitors get |
|---|---|
| Chat widget | Catalogue answers: package / hotel / activity search with prices, comparisons, cancellation text, destination guides, the signed-in visitor's own bookings. A one-time note says conversational AI is offline. |
| Explore overview | A "verified catalogue information" overview built from curated guides and what the site sells there. |
| Planners / recommenders | Their existing template or rule-based results. |

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Selected provider: none` and Ollama "not reachable" | Start Ollama (tray icon), reload. Check `OLLAMA_BASE_URL`. |
| Ollama reachable, "no chat model installed" | `ollama pull qwen2.5:3b-instruct` |
| `GEMINI_API_KEY ... not a valid Google AI Studio key` | Fix or delete the line. The key is never printed by the tools. |
| Test times out on the first call | Model is still loading; run the test again. |
| HTTP 429 / quota | Gemini free-tier limit reached; use Ollama or wait. |

## Built-in AI: the TourBuddy Brain

TourBuddy ships its own travel engine in `backend/brain/` with a curated knowledge base in `data/brain/` (23 Bangladesh destinations: routes, fares, seasons, food, stays, rules). It needs **no model, no API key, no download** and works offline.

What it does: understands a question (intent classifier + typo-tolerant place matching, English/Banglish), answers routes, costs, best time, food, stays, safety, packing, compare and suggestions, and builds full day-by-day trip plans with timings, costs and a budget fit. Plans pass through the same validator and cost pipeline as AI plans.

What it is not: a generative language model. It only knows what is in `data/brain`. Prices are approximate ranges; edit the JSON files to improve them. Bangla input is recognised, replies are English.

Settings (`.env`):

- `AI_PROVIDER=builtin` turns outside AI off completely; the Brain does everything.
- `AI_PLANNER_ENGINE=auto` (default) uses the AI model and falls back to the Brain when it fails; `brain` always uses the Brain; `llm` never does.
- The planner page also has a per-trip "Planner engine" choice.

Add a destination by copying a file in `data/brain/destinations/` and filling the same fields.

### Brain: 80 places, memory and admin tools

- The knowledge base now covers 80 destinations (Bangladesh and popular international trips). International files carry `country`, `emergency` and `entry_note` (visa line). **These were written from general knowledge, not live sources: review visas, fares and emergency numbers before relying on them.**
- Chat remembers the conversation: "plan 3 days in Sylhet", then "add a day", "for 4 people", "make it cheaper", "how to reach", "and Cox's Bazar?". Two questions in one message are both answered.
- A place that is not in the knowledge base is never guessed: the Brain says so and logs it to `data/brain/unanswered.log`.
- Admin, **AI > TourBuddy Brain** (`admin/brain.php`): try questions, see what people asked that it could not answer, edit or add destinations (validated, previous version kept as `.bak`).
- Tests: `php tests/brain/brain_test.php`, `php tests/brain/validate_kb.php`, `php tests/brain/sweep_test.php`.

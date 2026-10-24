# AI setup (local-first)

TMS uses a language model for the chat assistant, the Explore overview, the trip planner and the recommenders. **Everything
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
4. In `.env` leave `AI_PROVIDER` unset (or `auto`). **Delete a wrong `GEMINI_API_KEY` line** (a Gemini key starts with `AIza`).
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

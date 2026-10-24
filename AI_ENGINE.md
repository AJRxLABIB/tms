# TMS AI engine (self-hosted)

Runtime: Ollama (`AI_PROVIDER=ollama`, `OLLAMA_MODEL`, default `qwen2.5:3b-instruct`). Gemini/OpenAI remain optional fallbacks. No commercial API is required.

Request path for the site assistant (`api/ai.php?action=chatbot`):

1. CSRF + length checks, existing per-user rate limit and telemetry (`AIClient`).
2. `InjectionGuard::assess` – blocked messages get a fixed refusal, no model call.
3. `ToolRouter::plan` picks READ-ONLY tools by rule (small models are unreliable at free-form tool calling).
4. `ToolRegistry` validates args against a schema, runs the tool with identity from the **server session**, writes `tbl_ai_tool_audit` (actor hashed).
5. `KnowledgeIndex` (BM25, English + Bengali tokenizer) retrieves from **published packages only**, each hit has a citation URL.
6. Retrieved text is wrapped as untrusted data in the prompt; the model only writes the answer.
7. `InjectionGuard::sanitizeReply` reduces output to `<a href="*.php">`, `<b>`, `<br>`, `<ul>/<li>`.

There is no tool that books, pays, cancels, edits or deletes.

Tests: `php tests/ai/run_eval.php` (deterministic, no DB/model; 41 cases). `php tests/ai/live_ollama.php` for a real-model smoke run.

Known limits: lexical retrieval only (no embeddings yet); answer quality depends on the model and on the catalogue text; the 404 auto-seeded sample packages are generic text and will be retrieved like real ones.

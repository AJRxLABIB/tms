<?php
// DISABLED after use -- one-time cleanup for Task #35 (AI Evaluation
// Suite). Removed the spurious first eval run (0/8, caused by self-inflicted
// Gemini free-tier rate limiting before the inter-case delay fix). The
// second 0/8 run (caused by genuine daily quota exhaustion, now understood
// and fixed) was deliberately left in tbl_ai_eval_runs as an honest
// historical record -- see SESSION_SUMMARY_INDEX.md #35. Kept only as a
// 404 stub per this session's cleanup pattern.
http_response_code(404);
die('Not found.');

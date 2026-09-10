<?php
// DISABLED after use -- one-time diagnostic for Task #35 (AI Evaluation
// Suite). A raw, single curl call bypassing AIClient entirely, used to see
// Gemini's actual raw error body for a 429 response. This is what revealed
// the real free-tier daily quota (20 requests/day/model for this project's
// key, quotaId "GenerateRequestsPerDayPerProjectPerModel-FreeTier") and the
// exact quotaId string shape that led to the fix in GeminiProvider.php.
// Testing is complete; kept only as a 404 stub per this session's cleanup
// pattern.
http_response_code(404);
die('Not found.');

<?php
// DISABLED after use -- one-time browser-runnable copy of tests/run.php
// used to verify the new BudgetAutopilot test integrates correctly.
// 18/19 passed; the one FAIL is the same known path-rewrite artifact
// documented in the other browsercheck stubs (extra directory depth
// breaks the database/migrations/ glob path only in this throwaway
// copy). Kept only as a 404 stub for audit-trail purposes (never delete
// diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

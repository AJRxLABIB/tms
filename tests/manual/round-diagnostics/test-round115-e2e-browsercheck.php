<?php
// ONE-TIME browser-run diagnostic copy of tests/e2e/run_e2e.php, to verify
// the real script's logic end-to-end via the live browser (no php CLI
// available in this environment). CLI guard removed and relative paths
// adjusted for this file's extra directory depth ONLY -- the real
// tests/e2e/run_e2e.php is untouched and still CLI-only.
// DISABLE (404 stub) IMMEDIATELY AFTER USE.
http_response_code(404); die('Not found.');

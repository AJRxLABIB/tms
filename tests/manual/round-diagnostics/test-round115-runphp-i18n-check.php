<?php
// ONE-TIME browser-run diagnostic copy of tests/run.php (no php CLI
// available in this environment) -- CLI guard removed and relative paths
// adjusted for this file's extra depth ONLY. Verifying no regression after
// the multilingual homepage i18n wiring (16+1 new keys, 7 tms_t() call
// sites in frontend/index.php). DISABLE (404 stub) IMMEDIATELY AFTER USE.
http_response_code(404); die('Not found.');

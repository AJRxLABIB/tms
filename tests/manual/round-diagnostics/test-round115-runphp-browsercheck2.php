<?php
// DISABLED after use -- one-time browser-runnable copy of tests/run.php
// used to verify the new TripReadinessScore test integrates correctly.
// 17/18 passed; the one FAIL is the same known path-rewrite artifact
// documented in test-round115-runphp-browsercheck.php's own stub (this
// copy's extra directory depth breaks the database/migrations/ glob
// path, not a real issue -- the actual tests/run.php is unaffected).
// Kept only as a 404 stub for audit-trail purposes (never delete
// diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

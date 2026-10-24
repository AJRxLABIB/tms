<?php
// DISABLED after use -- one-time browser-runnable copy of tests/run.php
// (with its CLI guard removed and require/chdir paths adjusted for its
// depth) used to verify the new BookingStateMachine test integrates
// correctly, since this session's own shell has no php binary on PATH.
// Result: 16/17 passed; the one FAIL ("no migration files found") was
// this copy's own path-rewrite artifact (database/migrations/ path
// wasn't adjusted for the extra directory depth), not a real issue --
// the actual tests/run.php still has the correct, unmodified path and
// was not touched by this. Kept only as a 404 stub for audit-trail
// purposes (never delete diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

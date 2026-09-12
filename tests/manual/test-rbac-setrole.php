<?php
// DISABLED — one-time diagnostic already run and verified (Task #31, Round
// 20 granular RBAC end-to-end HTTP test: confirmed real 200-vs-403
// behavior and sidebar link filtering for a support-role admin against
// real admin/*.php pages). qa_test_admin was restored to superadmin and
// the simulated session was cleared before this was disabled. Left in
// place only so the commit history shows what ran; not executable.
http_response_code(404);
die('Not found.');

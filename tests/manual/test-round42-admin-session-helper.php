<?php
// Round 42 QA session-simulation helper -- used once to run
// database/migrate.php in its browser-admin mode (simulating the
// disposable qa_test_admin superadmin account via direct $_SESSION
// assignment, never a typed password) since that round's CLI shell to the
// deployed environment wasn't working. Testing is done.
//
// SECURITY FIX (Round 52 audit-log-coverage pass): this file was the only
// one of ~14 similar one-time QA session-simulation helpers in this folder
// that was NEVER actually disabled after use -- every sibling helper
// (test-admin-login-helper.php, test-round41-session-helper.php,
// seed-qa-admin.php, etc.) follows the documented "404 stub immediately
// after use" convention; this one's docblock *claimed* that had happened
// but its full working body was still live. Concretely: this was a public,
// unauthenticated endpoint that granted a full superadmin session
// (qa_test_admin, restored to superadmin per Round 39's RBAC testing) to
// anyone who requested it -- no password, no CSRF token, nothing. Found
// while instrumenting audit-log coverage across admin/*.php, not by a
// dedicated security sweep, which is itself worth noting: this had been
// live and exploitable since Round 42, through every round after it,
// including Round 50's project restructure (which moved this file's
// contents unchanged into tests/manual/ -- it preserved the file's public
// URL and behavior exactly as instructed, including this bug, since that
// round's job was the move, not a content audit of every file it moved).
// Disabled per the same convention every other helper in this folder uses.
http_response_code(404);
die('Not found.');

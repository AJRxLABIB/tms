<?php
// DISABLED after use -- one-time QA session-simulation helper for Round 62
// (master prompt item 62, error/empty/loading states). Used to authenticate
// as the pre-existing disposable qa_test_admin / qa-alert-test-a112ff@
// tms-testing.local accounts (via direct $_SESSION assignment, checked
// against the DB first, never a typed password) to live-verify the fixes to
// admin/dashboard.php (Chart.js CDN-blocked + no-data-yet states),
// admin/manage-users.php and admin/manage-packages.php (DB-error vs
// genuinely-empty distinction), frontend/package-list.php (map-view
// loading/blocked-CDN states), and frontend/ai-copilot.php (silent
// loadHistory() failure states). All verified live -- see
// SESSION_SUMMARY_INDEX-1.md, Round 62 entry. Disabled per this project's
// standard throwaway-script convention.
http_response_code(404);
die('Not found.');

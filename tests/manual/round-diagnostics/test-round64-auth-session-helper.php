<?php
// DISABLED after use -- one-time QA session-simulation helper for Round 64
// (master prompt item 56, monitoring). Used to authenticate as the
// pre-existing disposable qa_test_admin account (direct $_SESSION
// assignment, checked against the DB first, never a typed password) to
// live-verify the new admin/system-health.php page. Testing complete --
// see SESSION_SUMMARY_INDEX-1.md, Round 64 entry. Disabled per this
// project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

<?php
// DISABLED after use -- one-time QA session-simulation helper for Round 67
// (master prompt item 32, accessibility). Used to authenticate as the
// pre-existing disposable qa-alert-test-a112ff@tms-testing.local customer
// account (direct $_SESSION assignment, checked against the DB first,
// never a typed password) to live-verify the notification dropdown's new
// aria-expanded/Escape-close behavior. Testing complete -- see
// SESSION_SUMMARY_INDEX-1.md, Round 67 entry. Disabled per this project's
// standard throwaway-script convention.
http_response_code(404);
die('Not found.');

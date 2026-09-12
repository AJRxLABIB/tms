<?php
// DISABLED after use -- one-time QA session-simulation helper for Round 65
// (master prompt item 68, pre/during/post-trip organization). Used to
// authenticate as the pre-existing disposable qa-alert-test-a112ff@
// tms-testing.local customer account (direct $_SESSION assignment, checked
// against the DB first, never a typed password) to live-verify
// my-trip.php's new phase-conditional quick-links, and to list that
// account's real bookings so the right booking id/phase could be picked.
// Testing complete -- see SESSION_SUMMARY_INDEX-1.md, Round 65 entry.
// Disabled per this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

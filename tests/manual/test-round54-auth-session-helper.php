<?php
// Round 54 QA session-simulation helper (master prompt item 61, responsive
// design audit) -- used once to authenticate as the pre-existing disposable
// qa_test_admin / qa-alert-test-a112ff@tms-testing.local accounts via direct
// $_SESSION assignment (never a typed password) so authenticated pages
// (checkout.php with an item in cart, admin dashboard, admin manage-
// bookings/manage-packages tables) could be tested for responsive overflow
// across the master prompt's breakpoints. Testing is done -- 3 real bugs
// found and fixed (topbar overflow for logged-in customers 993-1300px;
// admin dashboard/topbar/stat-grid overflow on phones). Disabled per this
// project's standard throwaway-script convention. See
// SESSION_SUMMARY_INDEX-1.md, Round 54 entry.
http_response_code(404);
die('Not found.');

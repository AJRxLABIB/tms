<?php
// Round 41 QA session-simulation helper -- used once to live-test
// admin/manage-marketplace-bookings.php (simulating the disposable
// qa_test_admin and qa-alert-test-a112ff@tms-testing.local accounts via
// direct $_SESSION assignment, never a typed password). Testing is done;
// disabled per this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

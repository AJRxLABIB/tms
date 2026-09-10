<?php
// DISABLED after use -- was a one-time test helper for Task #33 (Travel
// Alert System) that set $_SESSION['alogin'] to qa_test_admin (an account
// created and owned by this diagnostic session) to exercise the admin
// add/dismiss-alert UI end-to-end without ever typing a password. Testing
// is complete; kept only as a 404 stub per this session's cleanup pattern.
http_response_code(404);
die('Not found.');

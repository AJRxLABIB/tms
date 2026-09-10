<?php
// DISABLED after use -- was a one-time test helper for Task #33 (Travel
// Alert System) that set $_SESSION['login'] to the brand-new disposable QA
// customer account created by test-qa-customer-setup.php (never an existing
// real account), to view my-trip.php and confirm the safety-alert banner.
// Testing is complete; kept only as a 404 stub per this session's cleanup
// pattern.
http_response_code(404);
die('Not found.');

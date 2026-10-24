<?php
// DISABLED after use -- was a one-time test helper for Round 44 (Travel
// Modes live-testing) that set $_SESSION['login'] to the pre-existing
// disposable QA customer account (qa-alert-test-a112ff@tms-testing.local),
// never a typed password. Testing is complete (Travel Preferences save/
// round-trip on profile.php, and Business/Accessible mode behavior on
// ai-recommend.php all verified live). Kept only as a 404 stub per this
// project's standard cleanup pattern.
http_response_code(404);
die('Not found.');

<?php
// DISABLED after use -- one-time full live test of the AI Budget
// Autopilot path: pushed the disposable QA booking (#BK4) to 98% budget
// with a test expense, confirmed shouldNotify() correctly returned true
// and a real notification was created (count 0 -> 1), then cleaned up
// the test expense, alert row, and notification so the QA booking is
// back to baseline. Kept only as a 404 stub for audit-trail purposes
// (never delete diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

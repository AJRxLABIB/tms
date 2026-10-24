<?php
// DISABLED after use -- one-time admin-session-simulation helper that
// verified admin/manage-bookings.php still renders correctly after the
// BookingStateMachine guards were added, and (via javascript_tool fetch()
// calls from the browser session it created, not through this file
// itself) that confirming an already-cancelled booking (#BK3) is now
// correctly rejected with "Booking #BK3 is cancelled and can no longer
// be confirmed." while the booking's status stayed Cancelled -- live,
// server-side, verified. Kept only as a 404 stub for audit-trail purposes
// (never delete diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

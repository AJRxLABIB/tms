<?php
// DISABLED after use -- one-time syntax/behavior check for
// BookingStateMachine (backend/services/BookingStateMachine.php). All 19
// assertions passed live (status/PaymentStatus/RefundStatus/
// ModificationStatus legal and illegal transitions). Permanent coverage
// now lives in tests/run.php. Kept only as a 404 stub for audit-trail
// purposes (never delete diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

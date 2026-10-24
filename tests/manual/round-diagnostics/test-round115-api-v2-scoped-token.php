<?php
// ONE-TIME QA helper, same convention/rationale as
// test-round115-api-v2-token-issue.php -- issues a SCOPED token (profile:read
// only, deliberately excluding bookings:read) to verify the new
// trip_readiness/budget_status endpoints correctly reject it with 403.
// Never touches the account's password. DISABLE (404 stub) IMMEDIATELY AFTER USE.
http_response_code(404); die('Not found.');

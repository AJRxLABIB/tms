<?php
// DISABLED after use -- Round 115 continuation QA session-simulation
// helper (never a typed password -- direct $_SESSION assignment only, per
// this project's standing security rule). Used once to live-verify the
// customer 2FA enable flow, the login-time 2FA challenge (wrong code
// rejected, correct code accepted), and the booking-currency-snapshot
// feature, all against the qa-round115-2fa-fx@tms-testing.local disposable
// account. Testing is complete (see CHANGELOG.md). Kept only as a 404
// stub per this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

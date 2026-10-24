<?php
// DISABLED after use -- one-time QA session-simulation helper (no
// password) that live-verified frontend/two-factor-setup.php's badge
// background tokens (--success-bg/--warning-bg/--danger-bg) actually
// resolve correctly there (unlike the admin copy, see the sibling
// diagnostic's stub comment). Confirmed via getComputedStyle: exact
// matches for warning-bg/danger-bg, the same barely-perceptible
// pastel-green shift already accepted for tour-history.php's refund
// badges for success-bg. Kept only as a 404 stub per this project's
// standard throwaway-script convention.
http_response_code(404); die('Not found.');

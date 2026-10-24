<?php
// DISABLED after use -- one-time admin session-simulation helper (no
// password) that caught a real mistake before it shipped: admin/two-
// factor-setup.php was initially given the same var(--warning-bg)/etc.
// token swap as the frontend copy of this page, but admin pages load
// css/admin-v6.css, not css/modern-theme.css -- the tokens don't exist
// there, so the badge backgrounds silently went transparent. Reverted
// admin/two-factor-setup.php to its original hardcoded hex values; only
// the frontend copy (which does load modern-theme.css) kept the fix.
// Kept only as a 404 stub per this project's standard throwaway-script
// convention.
http_response_code(404); die('Not found.');

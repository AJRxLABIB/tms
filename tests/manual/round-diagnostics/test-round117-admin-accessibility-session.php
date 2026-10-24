<?php
// DISABLED after use -- ONE-TIME QA diagnostic (session-simulation via direct
// $_SESSION write, no password entry, per this project's standing security
// constraint -- same convention as this project's other round diagnostics).
// Verified live in a real browser: (1) all 6 admin CRUD accessibility fixes
// (task #53) -- add-blog.php, edit-blog.php, add-package.php, edit-package.php,
// admin/index.php (login + 2FA forms), manage-package-addons.php -- every
// <label> now has a matching for=/id= pair (checked via input.labels.length in
// the actual DOM, not just markup inspection), zero duplicate ids, and the
// Map Coordinates multi-input group correctly uses the role="group" +
// aria-labelledby pattern already established for radio groups elsewhere
// (frontend/reviews.php Round 58). (2) All 6 pages still render and function
// correctly after the fix -- no visual or functional regression. Kept only as
// a 404 stub per this project's standard throwaway-script convention.
http_response_code(404); die('Not found.');

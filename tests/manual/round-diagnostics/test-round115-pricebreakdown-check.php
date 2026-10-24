<?php
// DISABLED after use -- one-time check confirming price-preview.php's new
// breakdown-building logic (item 8, pricing transparency) correctly
// filters out zero-value components and preserves order for non-zero
// ones. All 3 synthetic cases passed. Kept only as a 404 stub for
// audit-trail purposes (never delete diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

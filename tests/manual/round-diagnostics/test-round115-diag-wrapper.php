<?php
// DISABLED after use -- one-time diagnostic wrapper used to surface the
// real cause of a 500 error while first testing two-factor-setup.php
// (which turned out to be a missing root-level shim, since fixed -- see
// CHANGELOG.md). Kept only as a 404 stub per this project's standard
// throwaway-script convention.
http_response_code(404);
die('Not found.');

<?php
// DISABLED after use -- one-time read-only diagnostic that scanned every
// text value in the latin1-declared columns for genuine non-ASCII bytes,
// to verify migration 015 (charset conversion) was safe before writing
// it. Findings written up in CHANGELOG.md. Kept only as a 404 stub per
// this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

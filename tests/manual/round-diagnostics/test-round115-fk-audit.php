<?php
// DISABLED after use -- one-time read-only diagnostic that identified the
// exact foreign-key constraints blocking migration 015's first attempt
// (all 9 pointing at tblusers.EmailId), used to write the corrected
// migration. Findings written up in CHANGELOG.md. Kept only as a 404 stub
// per this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

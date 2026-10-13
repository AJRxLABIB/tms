<?php
// DISABLED after use -- one-time read-only diagnostic that confirmed
// migration 015 (utf8mb4 charset conversion, FK drop/recreate corrected
// version) applied successfully: all 17 tables converted to
// utf8mb4_general_ci, all 9 foreign keys to tblusers.EmailId recreated
// correctly. Findings written up in CHANGELOG.md. Kept only as a 404 stub
// for audit-trail purposes (never delete diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');

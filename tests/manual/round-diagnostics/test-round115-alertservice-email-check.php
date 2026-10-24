<?php
// DISABLED after use -- ONE-TIME QA diagnostic (no password entry -- direct
// DB use only, same convention as this project's other round115
// diagnostics). Verified the new email-on-alert-fire code path in
// backend/AlertService.php actually fires (not just that it compiles):
// created one synthetic, disposable price alert on a real package for the
// existing QA account, deliberately set to fire immediately, ran
// tms_check_and_fire_alerts(), and confirmed both tbl_notifications
// (in-app, unconditional -- 1 new row) and tbl_system_events
// (category='email' -- 1 new row, correctly logging SMTP_HOST not
// configured in this environment's .env, the same safe no-op behavior
// documented in mailer.php's own docblock) received new rows. Alert
// correctly flipped IsActive=0 after firing. Cleaned up its own synthetic
// alert row and notification afterward. Kept only as a 404 stub per this
// project's standard throwaway-script convention.
http_response_code(404); die('Not found.');

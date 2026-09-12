<?php
// Round 52 QA session-simulation helper -- used once to live-verify this
// round's new tms_log_activity() call sites (audit log coverage across
// admin/*.php: manage-coupons.php add/delete, do-export.php, logout.php)
// actually write rows to tblactivity_log and don't break the underlying
// admin actions (simulating the disposable qa_test_admin superadmin
// account via direct $_SESSION assignment, never a typed password).
// Verified live: coupon add/delete, a data export, and an admin logout all
// produced correct, correctly-detailed activity_log rows. Testing is done;
// disabled per this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

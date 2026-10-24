<?php
// DISABLED after use -- one-time verification script for Round 64 (master
// prompt item 56, monitoring). Exercised tms_log_system_event() directly
// (no real email sent, no real booking touched) and confirmed the rows
// appear correctly on admin/system-health.php. Testing complete -- see
// SESSION_SUMMARY_INDEX-1.md, Round 64 entry. The 3 test rows it inserted
// into tbl_system_events were left in place as genuine (QA) records of a
// genuine test, same convention as Round 52's test coupon/export rows.
// Disabled per this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

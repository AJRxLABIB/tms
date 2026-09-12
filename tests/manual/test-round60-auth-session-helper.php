<?php
// Round 60 QA session-simulation helper (master prompt item 61, responsive
// design audit continuation) -- used once to authenticate as the pre-existing
// disposable qa_test_admin / qa-alert-test-a112ff@tms-testing.local accounts
// via direct $_SESSION assignment (never a typed password) so the pages
// Round 54 left untested (admin/add-package.php, admin/edit-package.php, the
// site-wide AI chat widget, blog-post.php) could be checked for responsive
// overflow. Testing is done -- 2 real bugs found and fixed (the .profile-wrap
// sidebar/main flex layout overflowing on phones, and the AI chat panel's
// mobile media query being shadowed by a later unconditional rule in a
// second <style> block). Disabled per this project's standard throwaway-
// script convention. See SESSION_SUMMARY_INDEX-1.md, Round 60 entry.
http_response_code(404);
die('Not found.');

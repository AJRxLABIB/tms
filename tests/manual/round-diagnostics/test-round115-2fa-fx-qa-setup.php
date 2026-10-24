<?php
// DISABLED after use -- was a one-time setup script for Round 115
// continuation live verification (customer 2FA + booking FX snapshot,
// post-migration 013/014). Created a single disposable customer account
// (qa-round115-2fa-fx@tms-testing.local, never a real user) with a
// server-generated password hash never typed into any UI. Testing is
// complete (see CHANGELOG.md). Kept only as a 404 stub per this project's
// standard throwaway-script convention. The disposable account and its
// one confirmed test booking (#BK4) are left in place, matching this
// project's precedent of reusing disposable QA fixtures across rounds
// (see e.g. qa_test_admin, qa-alert-test-a112ff@tms-testing.local).
http_response_code(404);
die('Not found.');

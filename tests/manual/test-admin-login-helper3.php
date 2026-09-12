<?php
// DISABLED after use -- one-time diagnostic for Task #35 (AI Evaluation
// Suite). Used to simulate a disposable admin session (qa_test_admin, an
// account created by this diagnostic itself -- never a real/pre-existing
// account) and to force-run the AI table migrations. Testing is complete;
// kept only as a 404 stub per this session's cleanup pattern.
http_response_code(404);
die('Not found.');

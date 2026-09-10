<?php
// Disabled after use. This was a throwaway diagnostic (Round 39) to
// verify 4 new tests/run.php tests against the live DB via the web,
// since tests/run.php itself is CLI-only and device_bash was
// non-functional this session. No longer needed now that verification
// is complete and the real tests live in tests/run.php.
http_response_code(404);
die('Not found.');

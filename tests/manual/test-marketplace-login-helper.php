<?php
// Disabled after use. This was a throwaway QA helper for Round 37's
// live end-to-end test of the hotel/activity marketplace booking flow
// (session-simulated a pre-existing disposable QA account, no password
// typed anywhere). No longer needed now that testing is complete.
http_response_code(404);
die('Not found.');

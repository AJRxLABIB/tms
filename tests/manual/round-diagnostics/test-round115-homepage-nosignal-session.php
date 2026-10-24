<?php
// ONE-TIME QA helper -- verifies the "Recommended for You" section is
// correctly ABSENT for a session with zero real personalization signal.
// Session simulation only, no password. DISABLE (404 stub) after use.
http_response_code(404); die('Not found.');

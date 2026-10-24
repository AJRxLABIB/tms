<?php
// ONE-TIME QA session-simulation helper (standing rule: never type a
// password into any form -- session simulation via directly setting
// $_SESSION is the only permitted technique). Logs in as the existing
// disposable QA account to verify the new "Recommended for You" homepage
// section (PersonalizedHomepage.php) against real, known data.
// DISABLE THIS FILE (404 stub) IMMEDIATELY AFTER USE.
http_response_code(404); die('Not found.');

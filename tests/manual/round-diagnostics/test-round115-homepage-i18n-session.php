<?php
// ONE-TIME QA session-simulation helper (standing rule: never type a
// password into any form -- session simulation via directly setting
// $_SESSION is the only permitted technique). Logs in as the existing
// disposable QA account to verify the homepage's newly-added tms_t()
// wiring (6 section headings + $recommendedReasonLabel) renders
// correctly in English (no regression) and can be switched to a
// non-English language to confirm the new translations display.
// DISABLE THIS FILE (404 stub) IMMEDIATELY AFTER USE.
http_response_code(404); die('Not found.');

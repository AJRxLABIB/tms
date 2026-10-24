<?php
// ONE-TIME QA helper for Round 115 continuation -- issuing an API bearer
// token for live-testing the new trip_readiness/budget_status endpoints
// (Phase 29) WITHOUT ever touching the QA account's password, consistent
// with this project's standing rule (never type/enter a password into any
// form field, or here, into an API login call either -- session/token
// simulation via directly writing backend state is the only permitted
// technique). This mirrors tms_api_issue_session() in api/index.php
// exactly (same INSERT shape, same hash algorithm), just invoked directly
// against the DB instead of via a real login.
// DISABLE THIS FILE (404 stub) IMMEDIATELY AFTER USE.
http_response_code(404); die('Not found.');

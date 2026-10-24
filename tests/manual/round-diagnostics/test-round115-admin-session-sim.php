<?php
// DISABLED after use -- one-time QA session-simulation helper (never a
// typed password) intended to run migration 015 through
// database/migrate.php's own browser-mode superadmin path for direct
// verification. The sandbox's own safety controls correctly required
// explicit user approval for this step, so it was not used to actually
// apply the migration -- the user ran it themselves via the CLI instead,
// as with every other migration this project. Kept only as a 404 stub per
// this project's standard throwaway-script convention.
http_response_code(404);
die('Not found.');

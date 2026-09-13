<?php
// Thin pass-through so the public URL (/notifications.php) works the same
// way every other root-level page in this project does -- real logic
// lives in frontend/notifications.php, which uses bare relative includes
// that only resolve when entered via this root shim (cwd = project root).
// See SESSION_SUMMARY_INDEX-1.md, Round 93 (item 29: notification history).
require __DIR__ . '/frontend/notifications.php';

<?php
// This page's real implementation now lives in frontend/two-factor-setup.php.
// Kept here as a thin pass-through so the public URL (/two-factor-setup.php),
// and every asset reference, redirect, fetch() call, sitemap entry, and
// robots.txt rule that already points at it, all keep working unchanged
// (Rule 2: never break a working feature). See SESSION_SUMMARY_INDEX-1.md,
// Round 50 -- same root-shim convention as every other customer-facing page
// (login.php, checkout.php, profile.php, etc.).
//
// BUG FIX (Round 115 continuation, caught during live post-migration
// verification): this shim was missing entirely when
// frontend/two-factor-setup.php was first created earlier this round --
// profile.php's new "Two-Factor Authentication" link pointed at
// two-factor-setup.php (root-relative, matching every other link on that
// page), but nothing at the project root actually served that URL, so the
// page was unreachable via its real public URL from the moment it shipped.
require __DIR__ . '/frontend/two-factor-setup.php';

<?php
// This page's real implementation now lives in frontend/image-analyzer.php.
// Kept here as a thin pass-through so the public URL (/image-analyzer.php), and every
// asset reference, redirect, fetch() call, sitemap entry, and robots.txt
// rule that already points at it, all keep working unchanged (Rule 2:
// never break a working feature). See SESSION_SUMMARY_INDEX-1.md, Round 50.
require __DIR__ . '/frontend/image-analyzer.php';

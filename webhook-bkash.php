<?php
// This page's real implementation now lives in backend/webhooks/bkash.php.
// Kept here as a thin pass-through so this is the stable URL to give
// bkash's merchant dashboard as its webhook/IPN callback URL (Rule 43:
// WEBHOOKS) -- https://<your-domain>/webhook-bkash.php -- without every
// asset reference, redirect, or webhook config elsewhere ever needing to
// know the real file lives under backend/. See SESSION_SUMMARY_INDEX-1.md,
// Round 56.
require __DIR__ . '/backend/webhooks/bkash.php';

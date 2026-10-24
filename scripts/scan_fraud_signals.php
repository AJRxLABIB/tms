<?php
/**
 * scripts/scan_fraud_signals.php — finds transparent anomaly signals and opens review cases.
 *
 * Signals (see backend/services/FraudCases.php): repeated payment attempts, duplicate bookings, coupon/referral
 * clusters, unusual loyalty credits, abnormal cancellation rates. A case is a prompt for a HUMAN to look; this script
 * never blocks, bans, refunds or edits any booking, payment or account.
 *
 * Usage:  php scripts/scan_fraud_signals.php            (dry run: prints what would be opened)
 *         php scripts/scan_fraud_signals.php --apply    (writes cases; idempotent per signal + subject + day)
 * Needs migration 022 (php database/migrate.php) for --apply. Schedule: daily (see SCHEDULED_JOBS.md).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/services/FraudCases.php';

$apply = in_array('--apply', $argv ?? [], true);
$findings = FraudCases::scan($dbh);
echo ($apply ? 'APPLY' : 'DRY RUN') . ': ' . count($findings) . " signal(s) found.\n";
foreach ($findings as $f) {
    printf("  [%-6s %3d] %-26s %s — %s\n", $f['severity'], $f['score'], $f['signal'], $f['subject_key'], $f['reason']);
}
if (!$apply) { echo "Nothing written. Re-run with --apply to open cases.\n"; exit(0); }

if (!FraudCases::installed($dbh)) { echo "Case tables are missing: run `php database/migrate.php` (migration 022) first.\n"; exit(1); }
$r = FraudCases::record($dbh, $findings);
echo "Cases created: {$r['created']}, already recorded today: {$r['existing']}, suppressed (open/recently cleared): {$r['suppressed']}.\n";

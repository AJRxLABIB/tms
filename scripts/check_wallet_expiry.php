<?php
/**
 * scripts/check_wallet_expiry.php — Travel Wallet document expiry reminders
 * (Rule 28). CLI/cron only, same reasoning as scripts/check_alerts.php: a
 * plain periodic script is the right amount of infrastructure for this
 * project's size (Rule 73), no queue broker needed.
 *
 * Notifies once per document at each of two windows (30 days out, 7 days
 * out) using ExpiryNotifiedAt to avoid re-notifying every run -- it stores
 * the LAST window notified so it can still notify again at the closer
 * window even after already notifying at the further one.
 *
 * Schedule: daily, e.g.
 *   Windows Task Scheduler: php.exe "C:\xampp\htdocs\tms\scripts\check_wallet_expiry.php"
 *   Linux cron: 0 8 * * * php /path/to/tms/scripts/check_wallet_expiry.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is CLI-only.');
}

require __DIR__ . '/../includes/config.php';

const WINDOWS = [30, 7]; // days-before-expiry checkpoints, furthest first

$docs = $dbh->query(
    "SELECT DocId, UserEmail, DocType, ExpiryDate, ExpiryNotifiedAt
     FROM tbltraveldocuments
     WHERE ExpiryDate IS NOT NULL AND ExpiryDate >= CURDATE()"
)->fetchAll(PDO::FETCH_OBJ);

$docTypeLabels = ['passport' => 'Passport', 'visa' => 'Visa', 'insurance' => 'Travel Insurance', 'id' => 'National ID', 'vaccination' => 'Vaccination Record', 'other' => 'Document'];

$notified = 0;
foreach ($docs as $d) {
    $daysLeft = (int) floor((strtotime($d->ExpiryDate) - strtotime(date('Y-m-d'))) / 86400);
    $lastNotifiedDaysAgo = $d->ExpiryNotifiedAt ? (int) floor((time() - strtotime($d->ExpiryNotifiedAt)) / 86400) : null;

    foreach (WINDOWS as $window) {
        if ($daysLeft > $window) continue; // not there yet
        // Already notified within the last day for this same window pass —
        // avoid a duplicate if the cron runs more than once a day.
        if ($lastNotifiedDaysAgo !== null && $lastNotifiedDaysAgo < 1) continue 2;

        $label = $docTypeLabels[$d->DocType] ?? 'Document';
        $message = $daysLeft < 0
            ? "Your {$label} in your Travel Wallet has expired."
            : "Your {$label} in your Travel Wallet expires in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ".";
        tms_notify($dbh, $d->UserEmail, $message, 'wallet.php');
        $dbh->prepare("UPDATE tbltraveldocuments SET ExpiryNotifiedAt = NOW() WHERE DocId = :id")
            ->execute([':id' => $d->DocId]);
        $notified++;
        break; // one notification per document per run is enough
    }
}

echo date('c') . " check_wallet_expiry: notified {$notified} document(s).\n";

<?php
/**
 * wallet-document.php — Secure document retrieval gateway
 *
 * The uploads/wallet/ directory itself denies all direct web access (see
 * its .htaccess). This is the ONLY path through which an uploaded document
 * can ever be retrieved, and it re-verifies session ownership on every
 * single request — a document ID alone (even if guessed/enumerated) is
 * never sufficient to retrieve someone else's file.
 *
 * Round 86 (master prompt item 28): a document with Encrypted=1 is an
 * AES-256-GCM blob on disk (see backend/wallet-crypto.php) and is decrypted
 * here before being streamed; a legacy Encrypted=0 row (uploaded before this
 * round) is still served exactly as before, byte-for-byte (Rule 2). Every
 * access attempt -- successful, denied, or failed -- is logged to the
 * project's existing tblactivity_log audit trail via tms_log_activity()
 * (Rule 47), viewable at admin/activity-log.php.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once('backend/wallet-crypto.php');
tms_require_user();

$email = $_SESSION['login'];
$docId = tms_int($_GET['id'] ?? 0, 1);

$stmt = $dbh->prepare("SELECT * FROM tbltraveldocuments WHERE DocId = ? AND UserEmail = ? LIMIT 1");
$stmt->execute([$docId, $email]);
$doc = $stmt->fetch(PDO::FETCH_OBJ);

if (!$doc) {
    // Logged even though we don't know whether this DocId belongs to
    // someone else or simply doesn't exist -- either way it's a denied
    // access attempt worth a real audit trail entry (Rule 47), not just an
    // error_log line nobody reviews.
    tms_log_activity($dbh, $email, 'Wallet Document Access Denied', "DocId={$docId} (not found or not owned)");
    http_response_code(404);
    exit('Document not found.');
}

$path = dirname(__DIR__) . '/uploads/wallet/' . $doc->StoredFilename;

// Defense in depth: StoredFilename is always a randomly-generated name this
// app created itself (never derived from user input), but resolve and
// re-confirm it's actually inside uploads/wallet/ before streaming anything,
// so a corrupted/tampered DB row can never be used to read an arbitrary path.
$realBase = realpath(dirname(__DIR__) . '/uploads/wallet/');
$realPath = realpath($path);
if (!$realPath || !$realBase || strpos($realPath, $realBase) !== 0 || !is_file($realPath)) {
    tms_log_activity($dbh, $email, 'Wallet Document Access Failed', "DocId={$docId}, file missing on disk");
    http_response_code(404);
    exit('Document file is missing.');
}

if ((int)$doc->Encrypted === 1) {
    $encKey = tms_wallet_encryption_key();
    if ($encKey === null) {
        tms_log_activity($dbh, $email, 'Wallet Document Access Failed', "DocId={$docId}, server encryption key not configured");
        http_response_code(500);
        exit('This document could not be decrypted — server misconfiguration. Contact the site operator.');
    }
    $raw = file_get_contents($realPath);
    $content = $raw === false ? null : tms_wallet_decrypt_bytes($raw, $encKey);
    if ($content === null) {
        tms_log_activity($dbh, $email, 'Wallet Document Access Failed', "DocId={$docId}, decryption failed (corrupted file or wrong key)");
        http_response_code(500);
        exit('This document could not be decrypted.');
    }
} else {
    // Legacy, pre-Round-86 document: still plaintext on disk (Rule 2).
    $content = file_get_contents($realPath);
    if ($content === false) {
        tms_log_activity($dbh, $email, 'Wallet Document Access Failed', "DocId={$docId}, could not read file");
        http_response_code(500);
        exit('This document could not be read.');
    }
}

tms_log_activity($dbh, $email, 'Wallet Document Downloaded', "DocId={$docId}, DocType={$doc->DocType}");

// Always served as an attachment (forced download), never inline — even
// though uploads are validated to be JPG/PNG/PDF only, forcing a download
// rather than inline rendering is a cheap extra layer against any
// content-sniffing-based rendering quirk in a browser.
header('Content-Type: ' . ($doc->MimeType ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc->OriginalFilename) . '"');
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $content;
exit;

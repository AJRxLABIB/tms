<?php
/**
 * wallet-document.php — Secure document retrieval gateway
 *
 * The uploads/wallet/ directory itself denies all direct web access (see
 * its .htaccess). This is the ONLY path through which an uploaded document
 * can ever be retrieved, and it re-verifies session ownership on every
 * single request — a document ID alone (even if guessed/enumerated) is
 * never sufficient to retrieve someone else's file.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];
$docId = tms_int($_GET['id'] ?? 0, 1);

$stmt = $dbh->prepare("SELECT * FROM tbltraveldocuments WHERE DocId = ? AND UserEmail = ? LIMIT 1");
$stmt->execute([$docId, $email]);
$doc = $stmt->fetch(PDO::FETCH_OBJ);

if (!$doc) {
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
    http_response_code(404);
    exit('Document file is missing.');
}

// Always served as an attachment (forced download), never inline — even
// though uploads are validated to be JPG/PNG/PDF only, forcing a download
// rather than inline rendering is a cheap extra layer against any
// content-sniffing-based rendering quirk in a browser.
header('Content-Type: ' . ($doc->MimeType ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc->OriginalFilename) . '"');
header('Content-Length: ' . filesize($realPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($realPath);
exit;

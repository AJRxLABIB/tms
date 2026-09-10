<?php
/**
 * AJAX email availability check.
 * Returns an HTML snippet shown inline in the signup form.
 * Now validates CSRF and uses tms_validate_email().
 */
session_start();
require_once 'includes/config.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Validate CSRF (uses the token from the session, same token that was embedded in the form)
tms_csrf_validate();

// FIX (Round 14): this returned an HTML snippet, and the caller decided whether
// to disable the "Create My Account" button by searching that snippet for the
// substring "red". The CSRF failure message — "Invalid or expired form
// submission..." — contains "red" inside "expired", so ANY failure here
// permanently disabled the signup button. Return structured JSON instead, so the
// caller never has to guess from prose.
header('Content-Type: application/json; charset=utf-8');

$email = tms_validate_email($_POST['emailid'] ?? '');

if (empty($email)) {
    echo json_encode(['ok' => true, 'available' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$q = $dbh->prepare("SELECT id FROM tblusers WHERE EmailId=:email LIMIT 1");
$q->bindParam(':email', $email, PDO::PARAM_STR);
$q->execute();

if ($q->rowCount() > 0) {
    echo json_encode(['ok' => true, 'available' => false, 'message' => 'This email is already registered. Try signing in instead.']);
} else {
    echo json_encode(['ok' => true, 'available' => true, 'message' => 'Email is available.']);
}

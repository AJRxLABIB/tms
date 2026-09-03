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

$email = tms_validate_email($_POST['emailid'] ?? '');

if (empty($email)) {
    echo "<span style='color:#e74c3c;font-size:12px;'><i class='fa fa-times-circle'></i> Please enter a valid email address.</span>";
    exit;
}

$q = $dbh->prepare("SELECT id FROM tblusers WHERE EmailId=:email LIMIT 1");
$q->bindParam(':email', $email, PDO::PARAM_STR);
$q->execute();

if ($q->rowCount() > 0) {
    echo "<span style='color:#e74c3c;font-size:12px;'><i class='fa fa-times-circle'></i> This email is already registered.</span>";
} else {
    echo "<span style='color:#27ae60;font-size:12px;'><i class='fa fa-check-circle'></i> Email is available.</span>";
}

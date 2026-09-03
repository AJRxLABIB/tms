<?php
/**
 * user-register-ajax.php
 * Handles AJAX registration from the signup modal (signup.php)
 * Returns JSON response
 */
require_once 'includes/config.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────────
if (
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
) {
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.']);
    exit;
}

// ── Collect & sanitize ────────────────────────────────────────────────────────
$fullName = tms_clean($_POST['FullName']     ?? '');
$mobile   = tms_clean($_POST['MobileNumber'] ?? '');
$email    = tms_validate_email($_POST['EmailId'] ?? '');
$password = $_POST['Password']  ?? '';
$confirm  = $_POST['CPassword'] ?? '';

// ── Validate ──────────────────────────────────────────────────────────────────
if (empty($fullName) || empty($mobile) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}
if (strlen($fullName) < 3) {
    echo json_encode(['success' => false, 'message' => 'Full name must be at least 3 characters.']);
    exit;
}
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

// ── Check duplicate email ─────────────────────────────────────────────────────
$stmt = $dbh->prepare("SELECT id FROM tblusers WHERE EmailId = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
    exit;
}

// ── Insert user ───────────────────────────────────────────────────────────────
try {
    $hash = tms_hash_password($password);

    // Referral: prefer an explicit ref field the signup form submitted,
    // falling back to the 30-day cookie set when they first clicked a
    // referral link (see includes/config.php).
    $refCode = tms_clean($_POST['ref'] ?? ($_COOKIE['tms_ref'] ?? ''));
    $referrerEmail = null;
    if ($refCode !== '') {
        $refStmt = $dbh->prepare("SELECT EmailId FROM tblusers WHERE ReferralCode = :c LIMIT 1");
        $refStmt->execute([':c' => $refCode]);
        $referrerEmail = $refStmt->fetchColumn() ?: null;
    }

    $myReferralCode = tms_generate_referral_code($dbh);

    $ins  = $dbh->prepare(
        "INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password, RegDate, ReferralCode, ReferredBy)
         VALUES (?, ?, ?, ?, NOW(), ?, ?)"
    );
    $ins->execute([$fullName, $mobile, $email, $hash, $myReferralCode, $referrerEmail]);

    // Seed loyalty
    try {
        $dbh->prepare(
            "INSERT IGNORE INTO tblloyalty (UserEmail, Points, TotalEarned) VALUES (?,0,0)"
        )->execute([$email]);
    } catch (Exception $e) {}

    // Referral bonus: 50 points each for the new user and whoever referred them
    if ($referrerEmail && $referrerEmail !== $email) {
        foreach ([$email, $referrerEmail] as $rewardEmail) {
            $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,50,50)
                ON DUPLICATE KEY UPDATE Points=Points+50, TotalEarned=TotalEarned+50")->execute([':e'=>$rewardEmail]);
        }
        try {
            $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,50,:d)")
                ->execute([':e'=>$email, ':d'=>'Signed up via a referral link']);
            $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,50,:d)")
                ->execute([':e'=>$referrerEmail, ':d'=>'Referred a new traveler ('.$email.')']);
        } catch (Exception $e) {
            error_log('[TMS Loyalty] Log insert failed (registration still succeeded): ' . $e->getMessage());
        }
        try { tms_notify($dbh, $referrerEmail, "🎉 Someone joined TMS using your referral link — you both earned 50 loyalty points!", 'referral.php'); } catch (Exception $e) {}
        setcookie('tms_ref', '', time() - 3600, '/'); // consumed — clear it
    }

    // Refresh CSRF token after use
    unset($_SESSION['csrf_token']);

    tms_send_email($email, $fullName, 'Welcome to TMS Travel!', tms_email_template(
        'Welcome aboard, ' . $fullName . '!',
        '<p>Your TMS account is ready. Start exploring tour packages, save your favorites to your wishlist, and earn loyalty points with every booking and review.</p>
         <p>Your personal referral code is <strong>' . htmlspecialchars($myReferralCode, ENT_QUOTES, 'UTF-8') . '</strong> — share it with friends and you\'ll both earn 50 points when they sign up. Find your referral link anytime on the <strong>Refer Friends</strong> page.</p>'
    ));

    echo json_encode(['success' => true, 'message' => 'Account created successfully!']);

} catch (Exception $e) {
    error_log('Registration error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
}
<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
tms_require_user();

/**
 * privacy.php — customer-facing privacy controls (master prompt spec item
 * 48). Covers, honestly:
 *   - data export (tms_export_user_data(), a real read of every table this
 *     project keeps a customer's data in -- see tms_privacy_tables())
 *   - account deletion (tms_anonymize_user() -- anonymize, not hard-delete;
 *     see that function's docblock in includes/config.php for why)
 *   - notification preferences (gates only non-critical booking-status
 *     emails; password reset/security mail is never gated by this)
 *   - personalization controls (gates whether TravelRecommender reads past
 *     booking history into its prompts -- see includes/ai/TravelRecommender.php)
 *   - AI history controls (delete rows from every AI-feature table for this
 *     user: copilot chats, itineraries, travel memories, cached AI packages,
 *     the learned preference profile)
 *   - document management: NOT rebuilt here -- wallet.php already has real
 *     upload/view/delete for Travel Wallet documents, so this page links to
 *     it rather than duplicating it (Rule 73: don't build a second copy of
 *     something that already works).
 *   - marketing preferences: deliberately NOT built. This project sends no
 *     marketing/promotional email at all (grep confirms only transactional
 *     mail exists: booking confirmations, password resets, status updates)
 *     -- a marketing opt-out toggle with nothing behind it would be exactly
 *     the fake placeholder control Rule 7 forbids. If marketing email is
 *     ever added, this is the file to add a real toggle to.
 */

$email = $_SESSION['login'];
$error = '';
$msg   = '';

$userStmt = $dbh->prepare("SELECT id, FullName, EmailId, NotificationsEnabled, PersonalizationEnabled FROM tblusers WHERE EmailId = :e LIMIT 1");
$userStmt->execute([':e' => $email]);
$user = $userStmt->fetch(PDO::FETCH_OBJ);

if (!$user) {
    // Session says logged in but the row is gone (or already anonymized in
    // a race) -- don't let this page pretend to work against nothing.
    session_destroy();
    header('Location: login.php');
    exit;
}

if (isset($_POST['save_preferences'])) {
    tms_csrf_validate();
    $notif = isset($_POST['notifications_enabled']) ? 1 : 0;
    $personalize = isset($_POST['personalization_enabled']) ? 1 : 0;
    $dbh->prepare("UPDATE tblusers SET NotificationsEnabled = :n, PersonalizationEnabled = :p WHERE id = :id")
        ->execute([':n' => $notif, ':p' => $personalize, ':id' => $user->id]);
    $user->NotificationsEnabled = $notif;
    $user->PersonalizationEnabled = $personalize;
    $msg = 'Preferences saved.';
}

if (isset($_POST['clear_ai_history'])) {
    tms_csrf_validate();
    // All eight of these use the lowercase `user_email` column (verified
    // against AI_MIGRATION.sql / explore_migration.sql -- see
    // tms_privacy_tables()'s docblock for how that list was built).
    // tbl_ai_telemetry (Round 34) added alongside tbl_ai_usage_log -- same
    // kind of operational per-call log, not user-facing "history" content,
    // but keyed by user_email so it belongs in the same clear-on-request set.
    $aiTables = ['tbl_copilot_sessions', 'tbl_ai_itineraries', 'tbl_travel_memories', 'tbl_ai_packages', 'tbl_ai_usage_log', 'tbl_ai_telemetry', 'tbl_explore_history', 'tbl_explore_chat'];
    $cleared = 0;
    foreach ($aiTables as $t) {
        try {
            $n = $dbh->prepare("DELETE FROM `$t` WHERE `user_email` = ?");
            $n->execute([$email]);
            $cleared += $n->rowCount();
        } catch (Exception $e) {
            error_log("[TMS Privacy] Could not clear AI history from `$t`: " . $e->getMessage());
        }
    }
    // The learned preference profile is UPDATE-or-nothing, not append-only
    // history -- clear it too so "clear my AI history" actually means it.
    try {
        $dbh->prepare("DELETE FROM tbl_user_profile WHERE user_email = ?")->execute([$email]);
    } catch (Exception $e) {}
    $msg = "AI history cleared ({$cleared} record(s) removed across chat sessions, itineraries, travel memories, and cached recommendations).";
}

if (isset($_POST['confirm_delete_account'])) {
    tms_csrf_validate();
    $pwd = $_POST['delete_password'] ?? '';
    if (empty($pwd)) {
        $error = 'Enter your password to confirm account deletion.';
    } else {
        $checkStmt = $dbh->prepare("SELECT Password FROM tblusers WHERE id = :id");
        $checkStmt->execute([':id' => $user->id]);
        $storedHash = $checkStmt->fetchColumn();
        if (!tms_verify_password($pwd, (string)$storedHash, (int)$user->id, $dbh)) {
            $error = 'Incorrect password. Account not deleted.';
        } else {
            if (tms_anonymize_user($dbh, $email, (int)$user->id)) {
                tms_log_activity($dbh, $email, 'Account Deleted', 'Self-service account deletion via privacy.php (anonymized, not hard-deleted -- see tms_anonymize_user()).');
                session_destroy();
                header('Location: index.php?account_deleted=1');
                exit;
            } else {
                $error = 'Something went wrong deleting your account. Nothing was changed. Please try again or contact support.';
            }
        }
    }
}

$exportUrl = 'export-my-data.php';
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | Privacy &amp; My Data</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.errorWrap{padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.succWrap{padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.tms-page-hero{position:relative;min-height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;
  background:radial-gradient(ellipse 80% 60% at 30% 0%, rgba(15,163,163,0.35), transparent 60%),
  linear-gradient(160deg, var(--primary-dark) 0%, var(--primary) 55%, #0C3A4A 100%);}
.tms-page-hero h1{position:relative;z-index:2;color:#fff;font-family:var(--font-display);font-size:clamp(26px,4vw,38px);font-weight:600;margin:0;}
.priv-card{background:var(--white);border-radius:var(--radius-md);box-shadow:var(--shadow-sm);border:1px solid #eef2f4;padding:26px;max-width:680px;margin:0 auto 24px;}
.priv-card h3{margin:0 0 6px;font-size:18px;font-weight:700;color:var(--text);}
.priv-card p.desc{color:var(--text-muted);font-size:14px;margin:0 0 16px;}
.priv-card.danger{border:1px solid var(--danger-bg);}
.priv-card.danger h3{color:var(--danger);}
.priv-toggle{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f0f0;}
.priv-toggle:last-child{border-bottom:none;}
.priv-toggle b{font-size:14px;font-weight:600;color:var(--text);}
.priv-toggle small{display:block;color:var(--text-muted);font-size:12.5px;margin-top:2px;}
</style>
</head>
<body>
<?php include('includes/header.php'); ?>
<div class="tms-page-hero">
    <h1>Privacy &amp; My Data</h1>
</div>

<div class="container" style="padding:40px 15px 60px;">

    <?php if ($error): ?>
        <div class="errorWrap" style="max-width:680px;margin:0 auto 20px;"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif ($msg): ?>
        <div class="succWrap" style="max-width:680px;margin:0 auto 20px;"><strong>DONE:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="priv-card">
        <h3><i class="fa fa-download"></i> Export My Data</h3>
        <p class="desc">Download everything this account has stored about you -- profile, bookings, reviews, wishlist, loyalty history, AI chat/itinerary history, and more -- as a single JSON file.</p>
        <a href="<?php echo htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary"><i class="fa fa-download"></i> Download My Data (.json)</a>
    </div>

    <div class="priv-card">
        <h3><i class="fa fa-sliders"></i> Preferences</h3>
        <p class="desc">Control how your account is used.</p>
        <form method="post">
            <?php tms_csrf_field(); ?>
            <div class="priv-toggle">
                <div>
                    <b>Booking status email notifications</b>
                    <small>When off, we'll still show in-app notifications and never skip password-reset or security emails -- this only turns off status-update emails.</small>
                </div>
                <input type="checkbox" name="notifications_enabled" <?php echo $user->NotificationsEnabled ? 'checked' : ''; ?> style="width:20px;height:20px;">
            </div>
            <div class="priv-toggle">
                <div>
                    <b>Personalized recommendations</b>
                    <small>When off, the AI Recommender won't factor your past bookings into future suggestions.</small>
                </div>
                <input type="checkbox" name="personalization_enabled" <?php echo $user->PersonalizationEnabled ? 'checked' : ''; ?> style="width:20px;height:20px;">
            </div>
            <button type="submit" name="save_preferences" class="btn btn-primary" style="margin-top:16px;">Save Preferences</button>
        </form>
    </div>

    <div class="priv-card">
        <h3><i class="fa fa-robot"></i> AI History</h3>
        <p class="desc">Permanently delete your Trip Copilot conversations, AI-generated itineraries, travel memories, cached AI recommendations, and learned preference profile. This cannot be undone.</p>
        <form method="post" onsubmit="return confirm('Delete all your AI history? This cannot be undone.');">
            <?php tms_csrf_field(); ?>
            <button type="submit" name="clear_ai_history" class="btn btn-default"><i class="fa fa-trash"></i> Clear My AI History</button>
        </form>
    </div>

    <div class="priv-card">
        <h3><i class="fa fa-file-text"></i> Travel Documents</h3>
        <p class="desc">Upload, view, and delete your travel wallet documents (passports, visas, tickets) from the Travel Wallet page.</p>
        <a href="wallet.php" class="btn btn-default"><i class="fa fa-folder-open"></i> Manage My Documents</a>
    </div>

    <div class="priv-card danger">
        <h3><i class="fa fa-exclamation-triangle"></i> Delete My Account</h3>
        <p class="desc">
            This removes your name, email, and phone number from our systems and permanently locks this login.
            Your booking/payment history is kept (anonymized) for our financial and legal records, as most bookings
            already were even before you asked -- it will just no longer be linked to your real name or email.
            <strong>This cannot be undone.</strong>
        </p>
        <form method="post" onsubmit="return confirm('This will permanently delete your account. Are you absolutely sure?');">
            <?php tms_csrf_field(); ?>
            <div class="form-group" style="max-width:320px;">
                <input type="password" name="delete_password" class="form-control" placeholder="Enter your password to confirm" required autocomplete="current-password">
            </div>
            <button type="submit" name="confirm_delete_account" class="btn btn-danger" style="margin-top:10px;background:var(--danger);border-color:var(--danger);"><i class="fa fa-trash"></i> Permanently Delete My Account</button>
        </form>
    </div>

</div>

<?php include('includes/footer.php'); ?>
</body>
</html>

<?php
/**
 * appeal.php — a signed-in customer can appeal a review decision about THEIR OWN account.
 *
 * - Lists only the customer's own reviews that ended in a decision (or already have an appeal): see FraudCases::customerView().
 * - Shows a plain-language status, never risk scores, signals or evidence.
 * - Filing goes through FraudCases::fileAppeal(), which re-checks server-side that the case belongs to the signed-in email.
 * - CSRF-protected, length-limited (10-2000 characters) and rate-limited per account and per address.
 * - A human answers every appeal; nothing here changes the account.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once('backend/services/FraudCases.php');
tms_require_user();

$email = (string)$_SESSION['login'];
$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_appeal'])) {
    tms_csrf_validate();
    $caseId  = tms_int($_POST['case_id'] ?? 0, 0, PHP_INT_MAX);
    $message = (string)($_POST['message'] ?? '');
    if (!tms_rate_limit($dbh, 'fraud-appeal-user', strtolower($email), 5) || !tms_rate_limit($dbh, 'fraud-appeal-ip', tms_client_ip(), 20)) {
        $error = 'Too many requests. Please try again later.';
    } elseif ($caseId <= 0) {
        $error = 'Please choose a review to appeal.';
    } else {
        $err = FraudCases::fileAppeal($dbh, $caseId, $email, $message);   // ownership is verified inside
        if ($err === null) {
            $msg = 'Your appeal was submitted. A member of our team will review it.';
            tms_log_activity($dbh, $email, 'Fraud Appeal Filed', 'Customer filed an appeal');
        } else {
            $error = $err;
        }
    }
}

$cases = FraudCases::customerView($dbh, $email);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<title>TourBuddy | Appeal a Review</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;font-family:var(--font-display);}
.appeal-card{background:#fff;border-radius:var(--radius-md);box-shadow:var(--shadow-sm);padding:20px;margin-bottom:20px;border-top:3px solid var(--secondary);}
.appeal-card h4{font-size:15px;font-weight:700;margin:0 0 8px;color:var(--primary);}
.appeal-note{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:13px;padding:10px 14px;border-radius:8px;margin-bottom:18px;}
</style>
<link rel="stylesheet" href="css/tms-account.css?v=1">
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title"><div class="container"><h2 style="margin:0;"><i class="fa fa-balance-scale"></i> Appeal a Review</h2>
<p style="margin:6px 0 0;opacity:.9;font-size:13.5px;">If you think a decision about your account is wrong, tell us why. A person reads every appeal.</p></div></div>
<div class="container" style="padding-bottom:50px;" id="main-content">
<?php if ($msg): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="appeal-note"><i class="fa fa-info-circle"></i> Automated checks only flag activity for a person to look at; they never prove anything. Filing an appeal does not change your account by itself.</div>

<?php if (!$cases): ?>
  <div class="appeal-card"><p style="margin:0;">There are no reviews of your account that can be appealed.</p></div>
<?php else: foreach ($cases as $c): ?>
  <div class="appeal-card">
    <h4>Review #<?= (int)$c['id'] ?> <small style="color:#64748b;font-weight:400;">&middot; <?= htmlspecialchars($c['date'], ENT_QUOTES, 'UTF-8') ?></small></h4>
    <p style="margin:0 0 10px;"><?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($c['response'] !== null && $c['response'] !== ''): ?>
      <p style="margin:0 0 10px;font-size:13px;color:#475569;"><strong>Reviewer's response:</strong> <?= htmlspecialchars($c['response'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($c['can_appeal']): ?>
      <form method="post">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>">
        <div class="form-group">
          <label for="appealMsg<?= (int)$c['id'] ?>">Why do you think this is wrong? (10&ndash;2000 characters)</label>
          <textarea id="appealMsg<?= (int)$c['id'] ?>" name="message" class="form-control" rows="4" minlength="10" maxlength="2000" required></textarea>
        </div>
        <button type="submit" name="file_appeal" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Submit appeal</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

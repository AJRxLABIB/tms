<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];

// Fetch points
$q = $dbh->prepare("SELECT * FROM tblloyalty WHERE UserEmail=:e LIMIT 1");
$q->execute([':e'=>$email]);
$loyalty = $q->fetch(PDO::FETCH_OBJ);
if (!$loyalty) {
    $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,0,0)")->execute([':e'=>$email]);
    $q->execute([':e'=>$email]);
    $loyalty = $q->fetch(PDO::FETCH_OBJ);
}

// Redeem coupon
$msg = ''; $error = '';
if (isset($_POST['redeem'])) {
    tms_csrf_validate(); // before anything is read or changed
    // FIX: the balance was read first and deducted later without a lock, so two parallel requests could both pass the check
    // (two coupons, balance negative), and the deduction ignored the 40% cap (5,000 points paid for what 2,000 buy).
    // Now: lock the row, compute the discount from the CURRENT balance, deduct only the points that discount needs, and do the
    // deduction, the coupon and the history entry in one transaction.
    try {
        $dbh->beginTransaction();
        $bal = $dbh->prepare("SELECT Points FROM tblloyalty WHERE UserEmail=:e FOR UPDATE");
        $bal->execute([':e' => $email]);
        $points = (int)$bal->fetchColumn();
        if ($points < 500) {
            $dbh->rollBack();
            $error = 'You need at least 500 points to redeem a coupon.';
        } else {
            $discount = (int)min(TMS_MAX_LOYALTY_DISCOUNT, intdiv($points, 500) * 10);
            $used     = (int)(ceil($discount / 10) * 500);          // only what this discount actually costs
            $upd = $dbh->prepare("UPDATE tblloyalty SET Points=Points-:used WHERE UserEmail=:e AND Points>=:used2");
            $upd->execute([':used' => $used, ':used2' => $used, ':e' => $email]);
            if ($upd->rowCount() !== 1) { $dbh->rollBack(); $error = 'Could not redeem right now. Please try again.'; }
            else {
                $coupon = 'TMS-' . strtoupper(bin2hex(random_bytes(4)));
                $dbh->prepare("INSERT INTO tblcoupons(Code,DiscountPercent,UserEmail,ExpiryDate,IsUsed) VALUES(:c,:d,:e,DATE_ADD(NOW(),INTERVAL 30 DAY),0)")
                    ->execute([':c' => $coupon, ':d' => $discount, ':e' => $email]);
                $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,:p,:d)")
                    ->execute([':e' => $email, ':p' => -$used, ':d' => "Redeemed {$used} points for coupon {$coupon} ({$discount}% off)"]);
                $dbh->commit();
                // The message used to say "use it at checkout" — but checkout.php has no
                // coupon field at all; the only place a code can be entered is a package's
                // own booking form. Point people where the feature actually is.
                $msg = "Coupon <strong>$coupon</strong> generated! {$discount}% off — valid for 30 days ({$used} points used). "
                     . "Enter it in the <em>Coupon code</em> box on any package's booking form.";
            }
        }
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log('[TMS Loyalty] redeem failed: ' . $e->getMessage());
        $error = 'Could not redeem right now. Please try again.';
    }
    $q->execute([':e' => $email]);
    $loyalty = $q->fetch(PDO::FETCH_OBJ);
}

// Fetch history
$hist = $dbh->prepare("SELECT * FROM tblloyalty_log WHERE UserEmail=:e ORDER BY id DESC LIMIT 20");
$hist->execute([':e'=>$email]);
$history = $hist->fetchAll(PDO::FETCH_OBJ);

// Fetch coupons
$coupons = $dbh->prepare("SELECT * FROM tblcoupons WHERE UserEmail=:e ORDER BY id DESC");
$coupons->execute([':e'=>$email]);
$myCoupons = $coupons->fetchAll(PDO::FETCH_OBJ);

$tier = $loyalty->TotalEarned >= 2000 ? 'Gold' : ($loyalty->TotalEarned >= 500 ? 'Silver' : 'Bronze');
$tierColors = ['Bronze'=>'#cd7f32','Silver'=>'#aaa','Gold'=>'#f5c518'];

// Gamification (Phase 2h): checked lazily on this page view rather than
// hooked into every action that could contribute to one (booking, review,
// referral) — simpler and safer than touching those transactional flows,
// and achievements still unlock within one page view of the qualifying
// action next time the user checks their rewards.
require_once dirname(__DIR__) . '/backend/AchievementEngine.php';
$achievementEngine = new AchievementEngine($dbh);
$justUnlocked = $achievementEngine->evaluateAndUnlock($email);
$myAchievements = $achievementEngine->getUserAchievements($email);
$leaderboard = $achievementEngine->getLeaderboard(10);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Loyalty Points</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,#104B3A,#176B50);color:#fff;padding:40px 0;margin-bottom:30px;}
.points-card{background:linear-gradient(135deg,<?= $tierColors[$tier] ?>,<?= $tierColors[$tier] ?>aa);color:#fff;border-radius:16px;padding:30px;text-align:center;margin-bottom:24px;}
.points-card h1{font-size:64px;font-weight:900;margin:10px 0;}
.tier-badge{display:inline-block;background:rgba(255,255,255,0.3);padding:6px 18px;border-radius:20px;font-size:16px;font-weight:700;margin-bottom:10px;}
.info-card{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:20px;}
.progress-bar-loyalty{height:14px;border-radius:8px;background:var(--secondary);}
.coupon-badge{background:#e8f5e9;border:2px dashed #4caf50;border-radius:8px;padding:12px 18px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;}
.coupon-code{font-family:monospace;font-size:18px;font-weight:700;color:#2e7d32;letter-spacing:2px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-star"></i> My Loyalty Points</h2></div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!empty($justUnlocked)): ?>
<div class="alert alert-success" style="border:2px solid #f5c518;background:#fffbeb;color:#92400e;">
  <i class="fa fa-trophy" style="color:#f5c518;"></i> <strong>Achievement unlocked!</strong>
  <?php foreach ($justUnlocked as $code): $d = $myAchievements[$code]; ?>
    <span style="margin-left:6px;"><i class="fa fa-<?= $d['icon'] ?>" style="color:<?= $d['color'] ?>;"></i> <?= htmlspecialchars($d['name']) ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row">
  <div class="col-md-4">
    <div class="points-card">
      <div class="tier-badge"><i class="fa fa-trophy"></i> <?= $tier ?> Member</div>
      <h1><?= number_format($loyalty->Points) ?></h1>
      <p style="font-size:18px;margin:0;">Available Points</p>
      <p style="font-size:13px;opacity:0.8;margin-top:6px;">Total earned: <?= number_format($loyalty->TotalEarned) ?> pts</p>
    </div>
    <div class="info-card">
      <h5><i class="fa fa-info-circle"></i> How to Earn</h5>
      <ul style="color:#555;font-size:14px;padding-left:18px;line-height:2;">
        <li>Each confirmed booking = <strong>100 pts</strong></li>
        <li>Write a review = <strong>20 pts</strong></li>
        <li>Custom trip request = <strong>30 pts</strong></li>
      </ul>
      <hr>
      <h5><i class="fa fa-gift"></i> Redeem</h5>
      <p style="font-size:13px;color:#666;">500 pts = 10% discount coupon</p>
      <?php if ($loyalty->Points >= 500): ?>
        <form method="post">
          <?php tms_csrf_field(); ?>
          <button name="redeem" class="btn btn-warning btn-block" style="font-weight:700;">
            <i class="fa fa-ticket"></i> Redeem <?= floor($loyalty->Points/500)*10 ?>% Coupon
          </button>
        </form>
      <?php else: ?>
        <div class="progress" style="margin-top:8px;">
          <div class="progress-bar-loyalty" style="width:<?= min(100, $loyalty->Points/5) ?>%"></div>
        </div>
        <small style="color:var(--text-muted);">Need <?= 500 - $loyalty->Points ?> more points to redeem</small>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-8">
    <?php if (count($myCoupons) > 0): ?>
    <div class="info-card" style="margin-bottom:20px;">
      <h5><i class="fa fa-ticket"></i> My Coupons</h5>
      <?php foreach ($myCoupons as $c): ?>
      <div class="coupon-badge" style="<?= $c->IsUsed ? 'opacity:0.5;' : '' ?>">
        <div>
          <div class="coupon-code"><?= htmlspecialchars($c->Code) ?></div>
          <small style="color:#666;"><?= $c->DiscountPercent ?>% off · Expires: <?= date('d M Y', strtotime($c->ExpiryDate)) ?></small>
        </div>
        <span class="label label-<?= $c->IsUsed ? 'default' : 'success' ?>"><?= $c->IsUsed ? 'Used' : 'Active' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="info-card">
      <h5 style="display:flex;justify-content:space-between;align-items:center;">
        <span><i class="fa fa-history"></i> Points History</span>
        <a href="loyalty-history.php" style="font-size:12.5px;font-weight:600;color:var(--secondary);">View Full History →</a>
      </h5>
      <?php if (count($history) === 0): ?>
        <p style="color:var(--text-muted);text-align:center;padding:20px;">No points activity yet. Book a tour to start earning!</p>
      <?php else: ?>
      <div class="table-responsive" tabindex="0" role="region" aria-label="Table, scrollable"><table class="table table-striped" style="margin-top:10px;">
        <thead><tr><th>Date</th><th>Description</th><th>Points</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td><?= date('d M Y', strtotime($h->CreatedAt)) ?></td>
          <td><?= htmlspecialchars($h->Description) ?></td>
          <td><span style="color:<?= $h->Points > 0 ? '#0B6B2E' : '#B3302B' ?>;font-weight:700;"><?= ($h->Points > 0 ? '+' : '') . $h->Points ?> pts</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Achievements (Phase 2h) -->
<div class="info-card" style="margin-top:24px;">
  <h5><i class="fa fa-trophy"></i> Achievements</h5>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin-top:14px;">
    <?php foreach ($myAchievements as $a): ?>
    <div style="text-align:center;padding:16px 10px;border-radius:10px;background:var(--white);border:1px solid <?= $a['unlocked'] ? 'var(--primary)' : 'var(--border,#d7e0db)' ?>;">
      <div style="width:44px;height:44px;border-radius:50%;background:<?= $a['unlocked'] ? $a['color'] : '#94a3b8' ?>;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:18px;">
        <i class="fa fa-<?= $a['icon'] ?>"></i>
      </div>
      <div style="font-size:12.5px;font-weight:700;color:var(--text);"><?= htmlspecialchars($a['name']) ?></div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:3px;"><?= htmlspecialchars($a['description']) ?></div>
      <?php if ($a['unlocked']): ?>
        <div style="font-size:10px;color:var(--text);margin-top:4px;"><i class="fa fa-check"></i> <?= date('d M Y', strtotime($a['unlocked_at'])) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Leaderboard (Phase 2h) -->
<?php if (!empty($leaderboard)): ?>
<div class="info-card" style="margin-top:20px;">
  <h5><i class="fa fa-list-ol"></i> Top Achievers</h5>
  <div class="table-responsive" tabindex="0" role="region" aria-label="Table, scrollable"><table class="table table-striped" style="margin-top:10px;">
    <thead><tr><th>#</th><th>Traveler</th><th>Achievements</th></tr></thead>
    <tbody>
    <?php foreach ($leaderboard as $i => $l): ?>
    <tr style="<?= $l->UserEmail === $email ? 'font-weight:700;background:#eff6ff;' : '' ?>">
      <td><?= $i + 1 ?></td>
      <td><?= htmlspecialchars($l->FullName ?: 'Traveler #' . substr(md5($l->UserEmail), 0, 5)) ?><?= $l->UserEmail === $email ? ' (you)' : '' ?></td>
      <td><i class="fa fa-trophy" style="color:#f5c518;"></i> <?= (int)$l->achievement_count ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

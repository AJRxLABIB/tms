<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];

$uq = $dbh->prepare("SELECT ReferralCode FROM tblusers WHERE EmailId=:e LIMIT 1");
$uq->execute([':e' => $email]);
$myCode = $uq->fetchColumn();

// Referral codes are backfilled lazily — older accounts created before this
// feature existed won't have one yet.
if (!$myCode) {
    $myCode = tms_generate_referral_code($dbh);
    $dbh->prepare("UPDATE tblusers SET ReferralCode=:c WHERE EmailId=:e")->execute([':c' => $myCode, ':e' => $email]);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$referralLink = "$base/index.php?ref=" . urlencode($myCode);

$rq = $dbh->prepare("SELECT FullName, EmailId, RegDate FROM tblusers WHERE ReferredBy=:e ORDER BY RegDate DESC");
$rq->execute([':e' => $email]);
$referred = $rq->fetchAll(PDO::FETCH_OBJ);

$pointsFromReferrals = count($referred) * 50;
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Refer Friends</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:40px 0;margin-bottom:30px;}
.info-card{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:20px;}
.ref-link-box{display:flex;gap:8px;background:#f8fafc;border:2px dashed #cbd5e1;border-radius:10px;padding:14px 16px;align-items:center;}
.ref-link-box input{flex:1;border:none;background:transparent;font-size:13.5px;color:#334155;outline:none;font-family:monospace;}
.btn-copy{background:#667eea;color:#fff;border:none;border-radius:7px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;}
.btn-copy:hover{background:#5568d3;}
.stat-row{display:flex;gap:16px;margin-bottom:20px;}
.stat-box{flex:1;background:var(--white);border-radius:12px;padding:20px;text-align:center;box-shadow:0 3px 15px rgba(0,0,0,0.08);}
.stat-box .num{font-size:32px;font-weight:900;color:#667eea;}
.stat-box .lbl{font-size:12.5px;color:#94a3b8;margin-top:4px;}
.share-btns{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;}
.share-btns a{padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:#fff;display:inline-flex;align-items:center;gap:6px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-users"></i> Refer Friends, Earn Together</h2>
  <p style="margin:8px 0 0;opacity:.9;">You and your friend both get <strong>50 loyalty points</strong> when they join using your link.</p></div>
</div>
<div class="container" style="padding-bottom:50px;">

  <div class="stat-row">
    <div class="stat-box"><div class="num"><?= count($referred) ?></div><div class="lbl">Friends Referred</div></div>
    <div class="stat-box"><div class="num"><?= number_format($pointsFromReferrals) ?></div><div class="lbl">Points Earned from Referrals</div></div>
  </div>

  <div class="info-card">
    <h5><i class="fa fa-link"></i> Your Referral Link</h5>
    <p style="color:#888;font-size:13px;">Share this link — anyone who signs up through it gives you both 50 points.</p>
    <div class="ref-link-box">
      <input type="text" id="refLinkInput" readonly value="<?= htmlspecialchars($referralLink, ENT_QUOTES, 'UTF-8') ?>">
      <button class="btn-copy" onclick="copyRefLink()"><i class="fa fa-copy"></i> Copy</button>
    </div>
    <p style="margin-top:10px;font-size:12.5px;color:#94a3b8;">Your referral code: <strong><?= htmlspecialchars($myCode, ENT_QUOTES, 'UTF-8') ?></strong></p>
    <div class="share-btns">
      <a href="https://wa.me/?text=<?= urlencode("Plan your next trip with TMS Travel! Sign up with my link and we both get bonus points: $referralLink") ?>" target="_blank" style="background:#25D366;"><i class="fa fa-whatsapp"></i> WhatsApp</a>
      <a href="mailto:?subject=<?= urlencode('Join me on TMS Travel') ?>&body=<?= urlencode("Hey! I've been using TMS Travel to plan trips. Sign up with my referral link and we both get 50 bonus points: $referralLink") ?>" style="background:#64748b;"><i class="fa fa-envelope"></i> Email</a>
    </div>
  </div>

  <div class="info-card">
    <h5><i class="fa fa-user-plus"></i> Friends You've Referred</h5>
    <?php if (empty($referred)): ?>
      <p style="color:#999;text-align:center;padding:24px;">No referrals yet — share your link above to start earning!</p>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-striped" style="margin-top:10px;">
      <thead><tr><th>Name</th><th>Joined</th></tr></thead>
      <tbody>
      <?php foreach ($referred as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r->FullName) ?></td>
        <td><?= date('d M Y', strtotime($r->RegDate)) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

</div>
<script>
function copyRefLink() {
    var input = document.getElementById('refLinkInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard ? navigator.clipboard.writeText(input.value) : document.execCommand('copy');
    var btn = event.target.closest('button');
    var original = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
    setTimeout(function(){ btn.innerHTML = original; }, 1800);
}
</script>
<?php include('backend/footer.php'); ?>
</body></html>

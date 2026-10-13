<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$msg = '';
$error = '';

// Add/Remove wishlist
if (isset($_POST['toggle_wish'])) {
    tms_csrf_validate();
    $pkgId = tms_int($_POST['pkgid'] ?? 0, 1);
    $email = $_SESSION['login'];
    $chk = $dbh->prepare("SELECT id FROM tblwishlist WHERE UserEmail=:e AND PackageId=:p LIMIT 1");
    $chk->execute([':e'=>$email,':p'=>$pkgId]);
    if ($chk->fetch()) {
        $dbh->prepare("DELETE FROM tblwishlist WHERE UserEmail=:e AND PackageId=:p")->execute([':e'=>$email,':p'=>$pkgId]);
        $msg = 'Package removed from wishlist.';
    } else {
        $priceQ = $dbh->prepare("SELECT PackagePrice FROM tbltourpackages WHERE PackageId=:p LIMIT 1");
        $priceQ->execute([':p'=>$pkgId]);
        $priceAtAdd = $priceQ->fetchColumn();
        $dbh->prepare("INSERT INTO tblwishlist(UserEmail,PackageId,PriceAtAdd) VALUES(:e,:p,:price)")
            ->execute([':e'=>$email,':p'=>$pkgId,':price'=>$priceAtAdd !== false ? $priceAtAdd : null]);
        $msg = 'Package added to wishlist!';
    }
}

// Remove single
if (isset($_POST['remove_wish'])) {
    tms_csrf_validate();
    $pkgId = tms_int($_POST['pkgid'] ?? 0, 1);
    $email = $_SESSION['login'];
    $dbh->prepare("DELETE FROM tblwishlist WHERE UserEmail=:e AND PackageId=:p")->execute([':e'=>$email,':p'=>$pkgId]);
    $msg = 'Removed from wishlist.';
}

$email = $_SESSION['login'];
$sql = "SELECT p.*, w.id as wid, w.PriceAtAdd FROM tblwishlist w JOIN tbltourpackages p ON p.PackageId=w.PackageId WHERE w.UserEmail=:e ORDER BY w.id DESC";
$q = $dbh->prepare($sql);
$q->execute([':e'=>$email]);
$wishes = $q->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | My Wishlist</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.wish-card{background:var(--white);border-radius:12px;box-shadow:0 3px 20px rgba(0,0,0,0.09);overflow:hidden;margin-bottom:24px;transition:transform 0.2s;}
.wish-card:hover{transform:translateY(-4px);}
.wish-card img{width:100%;height:180px;object-fit:cover;}
.wish-card-body{padding:16px;}
.wish-card-body h4{margin:0 0 6px;font-size:16px;font-weight:700;color:#333;}
.wish-card-body .loc{color:var(--text-muted);font-size:13px;margin-bottom:8px;}
html[data-theme="dark"] .wish-card-body h4 { color: var(--text) !important; }
html[data-theme="dark"] .wish-card-body .loc { color: var(--text-muted) !important; }
.wish-card-body .price{color:var(--secondary);font-weight:700;font-size:18px;}
.price-drop-badge{margin-top:6px;display:inline-flex;align-items:center;gap:5px;background:#d1fae5;color:#065f46;font-size:11.5px;font-weight:700;padding:4px 9px;border-radius:20px;}
.wish-card-footer{padding:12px 16px;background:#f8f9fa;display:flex;gap:8px;justify-content:space-between;align-items:center;}
.empty-state{text-align:center;padding:80px 20px;}
.empty-state i{font-size:64px;color:#ddd;margin-bottom:16px;}
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-heart"></i> My Wishlist</h2></div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (count($wishes) === 0): ?>
<div class="empty-state">
  <i class="fa fa-heart-o"></i>
  <h3>Your wishlist is empty</h3>
  <p style="color:var(--text-muted);">Browse tour packages and click the heart icon to save them here.</p>
  <a href="package-list.php" class="btn btn-primary btn-lg" style="margin-top:10px;">Browse Packages</a>
</div>
<?php else: ?>
<div class="row">
<?php foreach ($wishes as $p): ?>
<div class="col-md-4 col-sm-6">
  <div class="wish-card">
    <a href="package-details.php?pkgid=<?= (int)$p->PackageId ?>">
      <img loading="lazy" src="<?= htmlspecialchars(tms_package_image_src($p->PackageImage),ENT_QUOTES,'UTF-8') ?>"
           onerror="this.src='images/no-image-placeholder.jpg'" alt="<?= htmlspecialchars($p->PackageName,ENT_QUOTES,'UTF-8') ?>">
    </a>
    <div class="wish-card-body">
      <h4><?= htmlspecialchars($p->PackageName,ENT_QUOTES,'UTF-8') ?></h4>
      <div class="loc"><i class="fa fa-map-marker"></i> <?= htmlspecialchars($p->PackageLocation,ENT_QUOTES,'UTF-8') ?></div>
      <div class="price"><?= tms_currency((float)$p->PackagePrice) ?> <small style="color:var(--text-muted);font-weight:400;font-size:13px;">/ person</small></div>
      <?php if (!empty($p->PriceAtAdd) && (float)$p->PackagePrice < (float)$p->PriceAtAdd): ?>
        <?php $dropPct = round((($p->PriceAtAdd - $p->PackagePrice) / $p->PriceAtAdd) * 100); ?>
        <div class="price-drop-badge">
          <i class="fa fa-arrow-down"></i> Price dropped <?= $dropPct ?>% — was <?= tms_currency((float)$p->PriceAtAdd) ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="wish-card-footer">
      <a href="package-details.php?pkgid=<?= (int)$p->PackageId ?>" class="btn btn-primary btn-sm"><?php echo tms_t('btn_book_now','Book Now'); ?></a>
      <form method="post" style="display:inline;">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="pkgid" value="<?= (int)$p->PackageId ?>">
        <button name="remove_wish" class="btn btn-danger btn-sm" onclick="return confirm('Remove from wishlist?')">
          <i class="fa fa-trash"></i> <?php echo tms_t('btn_delete','Remove'); ?>
        </button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

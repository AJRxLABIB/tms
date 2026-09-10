<?php
/**
 * alerts.php — "notify me" price/availability alerts (Rule 26/27).
 * Deliberately its own small page rather than patching them into the large
 * existing package-details.php template (safer -- Rule 2: don't risk a
 * well-tuned, heavily-featured page for a net-new feature). Reachable from
 * any package via the "Set an alert" link this page itself explains, and
 * linked from wishlist.php.
 */
require('includes/config.php');
require_once('includes/AlertService.php');
tms_require_user();

$email = $_SESSION['login'];
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $action = $_POST['action'] ?? '';
    $packageId = (int)($_POST['package_id'] ?? 0);

    if ($action === 'create_price' && $packageId > 0) {
        $target = (float)($_POST['target_price'] ?? 0);
        if ($target > 0 && tms_create_price_alert($dbh, $email, $packageId, $target)) {
            $msg = 'Price alert set — we\'ll notify you if it drops to that price or lower.';
        } else {
            $msg = 'Please enter a valid target price.'; $msgType = 'danger';
        }
    } elseif ($action === 'create_availability' && $packageId > 0) {
        if (tms_create_availability_alert($dbh, $email, $packageId)) {
            $msg = 'We\'ll let you know as soon as this package has seats again.';
        }
    } elseif ($action === 'cancel_price') {
        $dbh->prepare("DELETE FROM tbl_price_alerts WHERE id=:id AND UserEmail=:e")
            ->execute([':id' => (int)($_POST['alert_id'] ?? 0), ':e' => $email]);
        $msg = 'Alert cancelled.';
    } elseif ($action === 'cancel_availability') {
        $dbh->prepare("DELETE FROM tbl_availability_alerts WHERE id=:id AND UserEmail=:e")
            ->execute([':id' => (int)($_POST['alert_id'] ?? 0), ':e' => $email]);
        $msg = 'Alert cancelled.';
    }
}

// Preselect a package if arrived via ?pkgid=X (e.g. a "Set price alert" link from package-details.php)
$preselectId = (int)($_GET['pkgid'] ?? 0);
$packages = $dbh->query("SELECT PackageId, PackageName, PackagePrice FROM tbltourpackages WHERE Status=1 ORDER BY PackageName")->fetchAll(PDO::FETCH_OBJ);

$priceAlerts = $dbh->prepare(
    "SELECT a.*, p.PackageName FROM tbl_price_alerts a JOIN tbltourpackages p ON p.PackageId=a.PackageId
     WHERE a.UserEmail=:e ORDER BY a.IsActive DESC, a.CreatedAt DESC"
);
$priceAlerts->execute([':e' => $email]);
$priceAlerts = $priceAlerts->fetchAll(PDO::FETCH_OBJ);

$availAlerts = $dbh->prepare(
    "SELECT a.*, p.PackageName FROM tbl_availability_alerts a JOIN tbltourpackages p ON p.PackageId=a.PackageId
     WHERE a.UserEmail=:e ORDER BY a.IsActive DESC, a.CreatedAt DESC"
);
$availAlerts->execute([':e' => $email]);
$availAlerts = $availAlerts->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<title>TMS | Price &amp; Availability Alerts</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.alert-box{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:24px;}
.alert-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #eef1f6;font-size:14px;}
.alert-row:last-child{border-bottom:none;}
.form-inline-alert select,.form-inline-alert input{margin-bottom:10px;}
</style>
</head><body>
<?php include('includes/header.php'); ?>
<div class="page-title"><div class="container"><h2 style="margin:0;"><i class="fa fa-bell"></i> Price &amp; Availability Alerts</h2></div></div>
<div class="container" style="margin-bottom:60px;">
  <?php if ($msg): ?><div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <div class="alert-box">
    <h4>Set a price alert</h4>
    <form method="POST" class="form-inline-alert">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="create_price">
      <label for="price_alert_package" class="sr-only">Package</label>
      <select id="price_alert_package" name="package_id" class="form-control" required>
        <option value="">Choose a package...</option>
        <?php foreach ($packages as $p): ?>
          <option value="<?php echo (int)$p->PackageId; ?>" <?php echo $p->PackageId==$preselectId?'selected':''; ?>>
            <?php echo htmlspecialchars($p->PackageName, ENT_QUOTES, 'UTF-8'); ?><?php echo $p->PackagePrice !== null ? ' (current: '.tms_currency((float)$p->PackagePrice).')' : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label for="price_alert_target" class="sr-only">Target price</label>
      <input type="number" step="0.01" min="1" id="price_alert_target" name="target_price" class="form-control" placeholder="Notify me at or below this price" required>
      <button type="submit" class="btn btn-primary"><i class="fa fa-bell"></i> Set Price Alert</button>
    </form>
  </div>

  <div class="alert-box">
    <h4>Set an availability alert</h4>
    <form method="POST" class="form-inline-alert">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="create_availability">
      <label for="avail_alert_package" class="sr-only">Package</label>
      <select id="avail_alert_package" name="package_id" class="form-control" required>
        <option value="">Choose a package...</option>
        <?php foreach ($packages as $p): ?>
          <option value="<?php echo (int)$p->PackageId; ?>" <?php echo $p->PackageId==$preselectId?'selected':''; ?>><?php echo htmlspecialchars($p->PackageName, ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary"><i class="fa fa-bell"></i> Notify Me When Available</button>
    </form>
  </div>

  <div class="alert-box">
    <h4>Your price alerts</h4>
    <?php if (empty($priceAlerts)): ?><p style="color:#888;">No price alerts yet.</p><?php endif; ?>
    <?php foreach ($priceAlerts as $a): ?>
      <div class="alert-row">
        <span><?php echo htmlspecialchars($a->PackageName, ENT_QUOTES, 'UTF-8'); ?> — at or below <?php echo tms_currency((float)$a->TargetPrice); ?>
          <?php echo $a->IsActive ? '<span class="label label-info">Active</span>' : '<span class="label label-default">Fired '.htmlspecialchars((string)$a->NotifiedAt, ENT_QUOTES, 'UTF-8').'</span>'; ?>
        </span>
        <?php if ($a->IsActive): ?>
        <form method="POST" style="display:inline;margin:0;">
          <?php tms_csrf_field(); ?>
          <input type="hidden" name="action" value="cancel_price">
          <input type="hidden" name="alert_id" value="<?php echo (int)$a->id; ?>">
          <button type="submit" class="btn btn-xs btn-danger">Cancel</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="alert-box">
    <h4>Your availability alerts</h4>
    <?php if (empty($availAlerts)): ?><p style="color:#888;">No availability alerts yet.</p><?php endif; ?>
    <?php foreach ($availAlerts as $a): ?>
      <div class="alert-row">
        <span><?php echo htmlspecialchars($a->PackageName, ENT_QUOTES, 'UTF-8'); ?>
          <?php echo $a->IsActive ? '<span class="label label-info">Active</span>' : '<span class="label label-default">Fired '.htmlspecialchars((string)$a->NotifiedAt, ENT_QUOTES, 'UTF-8').'</span>'; ?>
        </span>
        <?php if ($a->IsActive): ?>
        <form method="POST" style="display:inline;margin:0;">
          <?php tms_csrf_field(); ?>
          <input type="hidden" name="action" value="cancel_availability">
          <input type="hidden" name="alert_id" value="<?php echo (int)$a->id; ?>">
          <button type="submit" class="btn btn-xs btn-danger">Cancel</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include('includes/footer.php'); ?>
</body></html>

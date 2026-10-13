<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['package_manager', 'manager']); // Round 20 RBAC (item 46)

// Round 87 (item 7): per-package add-on catalog (tblpackageaddons). Scoped to
// one PackageId at a time, linked to from edit-package.php's "Manage Add-ons"
// button — matches manage-coupons.php's own admin CRUD conventions.

$pkgid = intval($_GET['pkgid'] ?? 0);

$pkgQuery = $dbh->prepare("SELECT PackageId, PackageName FROM tbltourpackages WHERE PackageId=:pid");
$pkgQuery->bindParam(':pid', $pkgid, PDO::PARAM_INT);
$pkgQuery->execute();
$pkg = $pkgQuery->fetch(PDO::FETCH_OBJ);
if (!$pkg) { echo "<script>alert('Package not found.'); document.location='manage-packages.php';</script>"; exit; }

$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_addon'])) {
    tms_csrf_validate();
    $name  = tms_clean($_POST['name'] ?? '');
    $desc  = tms_clean($_POST['description'] ?? '');
    $price = tms_int($_POST['price'] ?? 0, 0, 9999999);
    if ($name !== '' && $price >= 0) {
        $dbh->prepare("INSERT INTO tblpackageaddons (PackageId, Name, Description, Price, IsActive) VALUES (:pid,:n,:d,:p,1)")
            ->execute([':pid'=>$pkgid, ':n'=>$name, ':d'=>$desc, ':p'=>$price]);
        tms_log_activity($dbh, $_SESSION['alogin'], 'Package Add-on Created', "PackageId=$pkgid, Name: \"$name\", Price: $price", 'package_addon', $dbh->lastInsertId(), null, (string)$price);
        $msg = 'Add-on added.'; $msgType = 'success';
    } else {
        $msg = 'Name and a valid price are required.'; $msgType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete', 'toggle'], true) && isset($_POST['id'])) {
    tms_csrf_validate();
    $aid = (int)$_POST['id'];
    // Confirm the add-on actually belongs to this package before mutating it.
    $own = $dbh->prepare("SELECT AddOnId FROM tblpackageaddons WHERE AddOnId=:id AND PackageId=:pid");
    $own->execute([':id'=>$aid, ':pid'=>$pkgid]);
    if ($own->fetch()) {
        if ($_POST['action'] === 'delete') {
            // tblbookingaddons snapshots Name/Price at booking time (nullable
            // AddOnId), so deleting a catalog entry never rewrites past
            // bookings — it only removes it from future selection.
            $dbh->prepare("DELETE FROM tblpackageaddons WHERE AddOnId=:id")->execute([':id'=>$aid]);
            tms_log_activity($dbh, $_SESSION['alogin'], 'Package Add-on Deleted', "PackageId=$pkgid, AddOnId=$aid", 'package_addon', $aid);
            $msg = 'Add-on deleted.'; $msgType = 'success';
        } elseif ($_POST['action'] === 'toggle') {
            // Round 96 (item 47): read the pre-toggle value first, same
            // reasoning as the coupon toggle above.
            $prevStmt = $dbh->prepare("SELECT IsActive FROM tblpackageaddons WHERE AddOnId=:id");
            $prevStmt->execute([':id'=>$aid]);
            $prevActive = (int)$prevStmt->fetchColumn();
            $dbh->prepare("UPDATE tblpackageaddons SET IsActive=IF(IsActive=1,0,1) WHERE AddOnId=:id")->execute([':id'=>$aid]);
            tms_log_activity($dbh, $_SESSION['alogin'], 'Package Add-on Status Toggled', "PackageId=$pkgid, AddOnId=$aid", 'package_addon', $aid, (string)$prevActive, (string)(1 - $prevActive));
            $msg = 'Add-on status updated.'; $msgType = 'success';
        }
    } else {
        $msg = 'Add-on not found for this package.'; $msgType = 'error';
    }
}

try {
    $addonsQuery = $dbh->prepare("SELECT * FROM tblpackageaddons WHERE PackageId=:pid ORDER BY AddOnId DESC");
    $addonsQuery->execute([':pid'=>$pkgid]);
    $addons = $addonsQuery->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) { $addons = []; }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Package Add-ons</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
.page-title-row h2 small{display:block;font-size:13px;font-weight:500;color:#64748b;margin-top:4px;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-edit{background:#dbeafe;color:#1e40af;}.btn-edit:hover{background:#bfdbfe;}
.btn-delete{background:#fee2e2;color:#991b1b;}.btn-delete:hover{background:#fecaca;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-plus-square" style="color:#1d4ed8;margin-right:10px;"></i>Manage Add-ons
    <small>Package: <?php echo htmlspecialchars($pkg->PackageName, ENT_QUOTES, 'UTF-8'); ?> (#<?php echo (int)$pkg->PackageId; ?>)</small>
  </h2>
  <a href="edit-package.php?pid=<?php echo (int)$pkgid; ?>" class="btn-v6 ghost"><i class="fa fa-arrow-left"></i> Back to Package</a>
</div>

<?php if ($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>

<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">Add New Add-on</h3>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
  <?php tms_csrf_field(); ?>
    <div><label for="addonNameField" style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;">Name</label>
    <input type="text" id="addonNameField" name="name" placeholder="e.g. Airport Pickup" maxlength="150" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;" required></div>
    <div><label for="addonDescriptionField" style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;">Description</label>
    <input type="text" id="addonDescriptionField" name="description" placeholder="Optional details" maxlength="500" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:260px;"></div>
    <div><label for="addonPriceField" style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;">Price (৳)</label>
    <input type="number" id="addonPriceField" name="price" placeholder="500" min="0" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:110px;" required></div>
    <button type="submit" name="add_addon" class="btn-v6 primary"><i class="fa fa-plus"></i> Add Add-on</button>
  </form>
</div>

<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Name</th><th>Description</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (empty($addons)): ?>
  <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No add-ons yet for this package.</td></tr>
  <?php else: $i=1; foreach ($addons as $a): $active = !empty($a->IsActive); ?>
  <tr>
    <td><?=$i++?></td>
    <td><strong><?=htmlspecialchars($a->Name ?? '—', ENT_QUOTES, 'UTF-8')?></strong></td>
    <td><?=htmlspecialchars($a->Description ?? '', ENT_QUOTES, 'UTF-8')?></td>
    <td><?php echo tms_currency((int)($a->Price ?? 0)); ?></td>
    <td><span style="padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:<?=$active?'#d1fae5':'#fee2e2'?>;color:<?=$active?'#065f46':'#991b1b'?>"><?=$active?'Active':'Inactive'?></span></td>
    <td style="display:flex;gap:5px;">
      <form method="post" style="display:contents"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$a->AddOnId?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-edit"><i class="fa fa-toggle-<?=$active?'on':'off'?>"></i> Toggle</button></form>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$a->AddOnId?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete this add-on? Past bookings that included it keep their own record.')"><i class="fa fa-trash"></i></button></form>
    </td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

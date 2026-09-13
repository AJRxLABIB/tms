<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['finance', 'package_manager', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Coupons</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-edit{background:#dbeafe;color:#1e40af;}.btn-edit:hover{background:#bfdbfe;}
.btn-delete{background:#fee2e2;color:#991b1b;}.btn-delete:hover{background:#fecaca;}
.btn-approve{background:#d1fae5;color:#065f46;}.btn-approve:hover{background:#a7f3d0;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-tag" style="color:#1d4ed8;margin-right:10px;"></i>Manage Coupons</h2>
</div>


<?php
$msg=''; $msgType='';
if(isset($_POST['add_coupon'])){
    tms_csrf_validate();
    $code=tms_clean($_POST['code']??'');
    $disc=(float)($_POST['discount']??0);
    $exp=tms_clean($_POST['expiry']??'');
    if($code && $disc>0){
        try{
            $dbh->prepare("INSERT INTO tblcoupons (Code,DiscountPercent,ExpiryDate,IsUsed) VALUES(:c,:d,:e,0)")
                ->execute([':c'=>$code,':d'=>$disc,':e'=>$exp ?: null]);
            // Round 96 bugfix (item 47): capture the real insert id immediately,
            // before tms_notify_all() runs -- that call performs its own INSERT(s)
            // internally, so a lastInsertId() read after it silently returns the
            // WRONG id (confirmed live: a test coupon logged ObjectId=19 while its
            // real id was 5). Capturing it right here is the only correct order.
            $newCouponId = $dbh->lastInsertId();
            tms_notify_all($dbh, "🎁 New offer: use code \"" . $code . "\" for " . $disc . "% off — " . ($exp ? "valid until " . $exp : "limited time"), 'package-list.php');
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Coupon Created', "Code: $code, Discount: {$disc}%" . ($exp ? ", Expires: $exp" : ''), 'coupon', $newCouponId, null, $code);
            $msg="Coupon added."; $msgType='success';
        }catch(Exception $e){ $msg="Error: ".$e->getMessage(); $msgType='error'; }
    } else { $msg="Please fill all fields."; $msgType='error'; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete','toggle'], true) && isset($_POST['id'])) {
    tms_csrf_validate();
    $cid=(int)$_POST['id'];
    if($_POST['action']==='delete'){
        $dbh->prepare("DELETE FROM tblcoupons WHERE id=:id")->execute([':id'=>$cid]);
        // Audit log coverage (Round 52).
        tms_log_activity($dbh, $_SESSION['alogin'], 'Coupon Deleted', "id=$cid", 'coupon', $cid);
        $msg="Coupon deleted."; $msgType='success';
    } elseif($_POST['action']==='toggle'){
        // Round 96 (item 47): read the pre-toggle value first -- the UPDATE
        // itself flips it in SQL (IF(IsUsed=1,0,1)) without ever surfacing
        // the old/new state to PHP, so there was nothing to log before.
        $prevStmt = $dbh->prepare("SELECT IsUsed FROM tblcoupons WHERE id=:id");
        $prevStmt->execute([':id'=>$cid]);
        $prevUsed = (int)$prevStmt->fetchColumn();
        $dbh->prepare("UPDATE tblcoupons SET IsUsed=IF(IsUsed=1,0,1) WHERE id=:id")->execute([':id'=>$cid]);
        tms_log_activity($dbh, $_SESSION['alogin'], 'Coupon Status Toggled', "id=$cid", 'coupon', $cid, (string)$prevUsed, (string)(1 - $prevUsed));
        $msg="Coupon status updated."; $msgType='success';
    }
}
try{ $coupons=$dbh->query("SELECT * FROM tblcoupons ORDER BY id DESC")->fetchAll(PDO::FETCH_OBJ); }
catch(Exception $e){ $coupons=[]; }
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">Add New Coupon</h3>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
  <?php tms_csrf_field(); ?>
    <div><label style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;" for="cpn_code">Coupon Code</label>
    <input type="text" id="cpn_code" name="code" placeholder="e.g. SAVE20" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;" required></div>
    <div><label style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;" for="cpn_discount">Discount (%)</label>
    <input type="number" id="cpn_discount" name="discount" placeholder="20" min="1" max="100" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:100px;" required></div>
    <div><label style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;" for="cpn_expiry">Expiry Date</label>
    <input type="date" id="cpn_expiry" name="expiry" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></div>
    <button type="submit" name="add_coupon" class="btn-v6 primary"><i class="fa fa-plus"></i> Add Coupon</button>
  </form>
</div>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Coupon Code</th><th>Discount</th><th>Expiry</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($coupons)): ?>
  <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No coupons yet.</td></tr>
  <?php else: $i=1; foreach($coupons as $c): $active=empty($c->IsUsed); ?>
  <tr>
    <td><?=$i++?></td>
    <td><code style="font-size:13px;font-weight:700;"><?=htmlspecialchars($c->Code??'—')?></code></td>
    <td><?=$c->DiscountPercent??0?>%</td>
    <td><?=$c->ExpiryDate??'No expiry'?></td>
    <td><span style="padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:<?=$active?'#d1fae5':'#fee2e2'?>;color:<?=$active?'#065f46':'#991b1b'?>"><?=$active?'Active':'Inactive'?></span></td>
    <td style="display:flex;gap:5px;">
      <form method="post" style="display:contents"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$c->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-edit"><i class="fa fa-toggle-<?=$active?'on':'off'?>"></i> Toggle</button></form>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$c->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete coupon?')"><i class="fa fa-trash"></i></button></form>
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

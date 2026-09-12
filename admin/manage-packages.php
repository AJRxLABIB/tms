<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['package_manager', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Packages</title>
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
  <h2><i class="fa fa-briefcase" style="color:#1d4ed8;margin-right:10px;"></i>Manage Packages</h2>
</div>


<?php
$msg=''; $msgType='';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $pid=(int)$_POST['id'];
    $delName = $dbh->prepare("SELECT PackageName FROM tbltourpackages WHERE PackageId=:id");
    $delName->execute([':id'=>$pid]);
    $delName = $delName->fetchColumn();
    $dbh->prepare("DELETE FROM tbltourpackages WHERE PackageId=:id")->execute([':id'=>$pid]);
    // Audit log coverage (Round 52).
    tms_log_activity($dbh, $_SESSION['alogin'], 'Package Deleted', "PackageId=$pid" . ($delName ? ", Name: \"$delName\"" : ''));
    $msg="Package deleted."; $msgType='success';
}
try{
    $searchQ = trim($_GET['q'] ?? '');
    $perPage = 25;
    $page    = max(1, (int)($_GET['p'] ?? 1));
    $where   = ''; $params = [];
    if ($searchQ !== '') {
        $where = "WHERE PackageName LIKE :q OR Country LIKE :q2";
        $params = [':q' => "%$searchQ%", ':q2' => "%$searchQ%"];
    }
    $total = (int)(function() use ($dbh, $where, $params) {
        $c = $dbh->prepare("SELECT COUNT(*) FROM tbltourpackages $where");
        $c->execute($params);
        return $c->fetchColumn();
    })();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $pq = $dbh->prepare("SELECT * FROM tbltourpackages $where ORDER BY PackageId DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k=>$v) { $pq->bindValue($k, $v, PDO::PARAM_STR); }
    $pq->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $pq->bindValue(':off', $offset, PDO::PARAM_INT);
    $pq->execute();
    $packages = $pq->fetchAll(PDO::FETCH_OBJ);
}catch(Exception $e){
    // Master prompt item 62 (error/empty/loading states): see the identical
    // fix/comment in manage-users.php -- a real DB failure was previously
    // indistinguishable from a genuinely-empty package table.
    $packages=[]; $total=0; $totalPages=1; $page=1;
    $msg = "Error loading packages: " . $e->getMessage();
    $msgType = 'error';
}
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-<?=$msgType==='success'?'check-circle':'exclamation-circle'?>"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div style="margin-bottom:16px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <a href="add-package.php" class="btn-v6 primary"><i class="fa fa-plus"></i> Add New Package</a>
    <form method="get" style="display:flex;gap:6px;">
        <input type="text" name="q" value="<?=htmlspecialchars($searchQ)?>" placeholder="Search by name or country…"
               style="border:1px solid #e2e8f0;border-radius:8px;padding:6px 12px;font-size:13px;min-width:220px;">
        <button type="submit" class="btn-v6" style="background:#f1f5f9;color:#334155;"><i class="fa fa-search"></i></button>
    </form>
</div>
<div style="font-size:12.5px;color:#94a3b8;margin-bottom:10px;"><?=$total?> package<?=$total!==1?'s':''?> total &nbsp;·&nbsp; page <?=$page?> of <?=$totalPages?></div>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Package Name</th><th>Price</th><th>Description</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($packages)): ?>
  <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">No packages found. <a href="add-package.php">Add one</a>.</td></tr>
  <?php else: $i=1; foreach($packages as $p): ?>
  <tr>
    <td><?=$i++?></td>
    <td><strong><?=htmlspecialchars($p->PackageName??'—')?></strong></td>
    <td><?=$p->PackagePrice?tms_currency($p->PackagePrice):'—'?></td>
    <td style="max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars(substr($p->PackageDescription??'',0,80))?></td>
    <td>
      <a href="edit-package.php?id=<?=$p->PackageId?>" class="action-btn btn-edit"><i class="fa fa-edit"></i> Edit</a>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$p->PackageId?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete this package?')"><i class="fa fa-trash"></i> Delete</button></form>
    </td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php if($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
    <?php for($pn=1; $pn<=$totalPages; $pn++):
        $qs = http_build_query(array_merge($_GET, ['p'=>$pn])); ?>
    <a href="?<?=$qs?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;
        <?=$pn===$page ? 'background:#1d4ed8;color:#fff;' : 'background:#f1f5f9;color:#334155;'?>"><?=$pn?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>


<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

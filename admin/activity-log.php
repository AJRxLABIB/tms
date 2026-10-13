<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['operations', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | TMS Admin | Activity Log</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<?php

$filter = tms_clean($_GET['filter'] ?? '');
$where = $filter ? "WHERE Action LIKE :f" : "";
$params = $filter ? [':f'=>"%$filter%"] : [];

$q = $dbh->prepare("SELECT * FROM tblactivity_log $where ORDER BY id DESC LIMIT 200");
$q->execute($params);
$logs = $q->fetchAll(PDO::FETCH_OBJ);
?>





  <div style="margin-bottom:16px;display:flex;gap:10px;">
    <form method="get" style="display:flex;gap:8px;">
      <input type="text" name="filter" value="<?= htmlspecialchars($filter) ?>" class="form-control" placeholder="Filter by action..." style="width:220px;">
      <button class="btn-v6 primary"><i class="fa fa-search"></i></button>
      <?php if ($filter): ?><a href="activity-log.php" class="btn-v6 ghost">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="table-responsive">
  <table class="tms-table" style="font-size:13px;">
    <thead><tr><th>#</th><th>Actor</th><th>Action</th><th>Object</th><th>Details</th><th>Change</th><th>IP</th><th>Time</th></tr></thead>
    <tbody>
    <?php $i=1; foreach ($logs as $l): ?>
    <?php
        // Round 96 (item 47): ObjectType/ObjectId/OldValue/NewValue/
        // RequestMethod/RequestUri/UserAgent are new, optional columns --
        // older rows (and call sites this round didn't retrofit) simply
        // have them NULL, so every cell here falls back to an em dash
        // rather than assuming they're always populated.
        $objectLabel = ($l->ObjectType ?? null)
            ? htmlspecialchars($l->ObjectType) . ($l->ObjectId !== null ? ' #' . htmlspecialchars($l->ObjectId) : '')
            : '—';
        $hasChange = ($l->OldValue ?? null) !== null || ($l->NewValue ?? null) !== null;
        $requestTitle = trim(($l->RequestMethod ?? '') . ' ' . ($l->RequestUri ?? ''));
    ?>
    <tr<?= $requestTitle !== '' ? ' title="' . htmlspecialchars($requestTitle) . '"' : '' ?>>
      <td><?= $i++ ?></td>
      <td><?= htmlspecialchars($l->Actor ?? '—') ?></td>
      <td><span class="label label-info"><?= htmlspecialchars($l->Action) ?></span></td>
      <td><?= $objectLabel ?></td>
      <td><?= htmlspecialchars($l->Details ?? '') ?></td>
      <td>
        <?php if ($hasChange): ?>
          <code style="font-size:11px;"><?= htmlspecialchars((string)($l->OldValue ?? '')) ?> → <?= htmlspecialchars((string)($l->NewValue ?? '')) ?></code>
        <?php else: ?>
          —
        <?php endif; ?>
      </td>
      <td><code><?= htmlspecialchars($l->IPAddress ?? '') ?></code></td>
      <td><?= date('d M Y H:i', strtotime($l->CreatedAt)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (count($logs)===0): ?><tr><td colspan="8" class="text-center text-muted">No log entries yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div></div>

</div>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

<?php
/** group-trips.php — list the signed-in user's group trips + create a new one. */
require('includes/config.php');
require_once('includes/GroupTripService.php');
tms_require_user();
$email = $_SESSION['login'];
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    tms_csrf_validate();
    $name = tms_clean($_POST['name'] ?? '');
    if ($name !== '') {
        $groupId = tms_create_group_trip($dbh, $name, $email);
        if ($groupId > 0) {
            header('Location: group-trip.php?id=' . $groupId);
            exit;
        }
        $msg = 'Could not create the group trip. Please try again.'; $msgType = 'danger';
    } else {
        $msg = 'Please enter a trip name.'; $msgType = 'danger';
    }
}

$groups = $dbh->prepare(
    "SELECT g.GroupId, g.Name, g.CreatedAt, g.CreatedBy,
            (SELECT COUNT(*) FROM tblgroup_members m WHERE m.GroupId=g.GroupId) AS MemberCount
     FROM tblgroup_trips g JOIN tblgroup_members gm ON gm.GroupId=g.GroupId
     WHERE gm.UserEmail=:e ORDER BY g.CreatedAt DESC"
);
$groups->execute([':e' => $email]);
$groups = $groups->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<title>TMS | Group Trips</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.gt-box{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:24px;}
.gt-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #eef1f6;}
.gt-row:last-child{border-bottom:none;}
</style>
</head><body>
<?php include('includes/header.php'); ?>
<div class="page-title"><div class="container"><h2 style="margin:0;"><i class="fa fa-users"></i> Group Trips</h2></div></div>
<div class="container" style="margin-bottom:60px;">
  <?php if ($msg): ?><div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <div class="gt-box">
    <h4>Start a new group trip</h4>
    <form method="POST" class="form-inline">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="create">
      <label for="gt_new_name" class="sr-only">Group trip name</label>
      <input type="text" id="gt_new_name" name="name" class="form-control" placeholder="e.g. Cox's Bazar with friends" required style="width:280px;">
      <button type="submit" class="btn btn-primary"><i class="fa fa-plus"></i> Create</button>
    </form>
  </div>

  <div class="gt-box">
    <h4>Your group trips</h4>
    <?php if (empty($groups)): ?><p style="color:#888;">You're not part of any group trip yet.</p><?php endif; ?>
    <?php foreach ($groups as $g): ?>
      <div class="gt-row">
        <span><strong><?php echo htmlspecialchars($g->Name, ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo (int)$g->MemberCount; ?> member<?php echo $g->MemberCount==1?'':'s'; ?></span>
        <a href="group-trip.php?id=<?php echo (int)$g->GroupId; ?>" class="btn btn-sm btn-primary">Open</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include('includes/footer.php'); ?>
</body></html>

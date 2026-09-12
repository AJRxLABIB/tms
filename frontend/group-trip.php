<?php
/**
 * group-trip.php — one group trip: members, shared expenses, settlement.
 * Every action here re-checks tms_group_is_member() before touching
 * anything (Rule 44 -- no IDOR via a guessed ?id=).
 */
require('backend/config.php');
require_once('backend/GroupTripService.php');
tms_require_user();

$email = $_SESSION['login'];
$groupId = (int)($_GET['id'] ?? $_POST['group_id'] ?? 0);
$msg = ''; $msgType = 'success';

$group = $dbh->prepare("SELECT * FROM tblgroup_trips WHERE GroupId = :id");
$group->execute([':id' => $groupId]);
$group = $group->fetch(PDO::FETCH_OBJ);

if (!$group || !tms_group_is_member($dbh, $groupId, $email)) {
    header('Location: group-trips.php');
    exit;
}

$myRole = $dbh->prepare("SELECT Role FROM tblgroup_members WHERE GroupId=:g AND UserEmail=:e");
$myRole->execute([':g' => $groupId, ':e' => $email]);
$myRole = $myRole->fetchColumn() ?: 'member';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_member' && $myRole === 'organizer') {
        $newEmail = trim(strtolower($_POST['member_email'] ?? ''));
        if ($newEmail !== '') {
            $result = tms_add_group_member($dbh, $groupId, $newEmail);
            $msg = $result['message']; $msgType = $result['ok'] ? 'success' : 'danger';
        }
    } elseif ($action === 'add_expense') {
        $description = tms_clean($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $amountMinor = (int) round($amount * 100);
        if ($description !== '' && $amountMinor > 0) {
            tms_add_group_expense($dbh, $groupId, $email, $description, $amountMinor);
            $msg = 'Expense added.';
        } else {
            $msg = 'Please enter a description and a positive amount.'; $msgType = 'danger';
        }
    }
}

$members = $dbh->prepare("SELECT UserEmail, Role FROM tblgroup_members WHERE GroupId=:g ORDER BY Role, UserEmail");
$members->execute([':g' => $groupId]);
$members = $members->fetchAll(PDO::FETCH_OBJ);

$expenses = $dbh->prepare("SELECT * FROM tblgroup_expenses WHERE GroupId=:g ORDER BY CreatedAt DESC");
$expenses->execute([':g' => $groupId]);
$expenses = $expenses->fetchAll(PDO::FETCH_OBJ);

$settlement = tms_compute_group_settlement($dbh, $groupId);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<title>TMS | <?php echo htmlspecialchars($group->Name, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.gt-box{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:24px;}
.gt-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #eef1f6;font-size:14px;}
.gt-row:last-child{border-bottom:none;}
.balance-pos{color:#27ae60;font-weight:700;}
.balance-neg{color:#e74c3c;font-weight:700;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title"><div class="container"><h2 style="margin:0;"><i class="fa fa-users"></i> <?php echo htmlspecialchars($group->Name, ENT_QUOTES, 'UTF-8'); ?></h2></div></div>
<div class="container" style="margin-bottom:60px;">
  <p><a href="group-trips.php">&larr; All group trips</a></p>
  <?php if ($msg): ?><div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <div class="gt-box">
    <h4>Members</h4>
    <?php foreach ($members as $m): ?>
      <div class="gt-row"><span><?php echo htmlspecialchars($m->UserEmail, ENT_QUOTES, 'UTF-8'); ?></span><span class="label label-<?php echo $m->Role==='organizer'?'primary':'default'; ?>"><?php echo ucfirst($m->Role); ?></span></div>
    <?php endforeach; ?>
    <?php if ($myRole === 'organizer'): ?>
    <form method="POST" class="form-inline" style="margin-top:14px;">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="add_member">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <label for="member_email" class="sr-only">Member email</label>
      <input type="email" id="member_email" name="member_email" class="form-control" placeholder="friend@email.com" required style="width:240px;">
      <button type="submit" class="btn btn-primary"><i class="fa fa-user-plus"></i> Add Member</button>
    </form>
    <p style="font-size:12px;color:#888;">They must already have a TMS account.</p>
    <?php endif; ?>
  </div>

  <div class="gt-box">
    <h4>Shared expenses</h4>
    <form method="POST" class="form-inline" style="margin-bottom:16px;">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="add_expense">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <label for="expense_description" class="sr-only">Description</label>
      <input type="text" id="expense_description" name="description" class="form-control" placeholder="What was it for?" required style="width:220px;">
      <label for="expense_amount" class="sr-only">Amount</label>
      <input type="number" step="0.01" min="0.01" id="expense_amount" name="amount" class="form-control" placeholder="Amount" required style="width:120px;">
      <button type="submit" class="btn btn-primary"><i class="fa fa-plus"></i> Add Expense</button>
    </form>
    <?php if (empty($expenses)): ?><p style="color:#888;">No shared expenses logged yet.</p><?php endif; ?>
    <?php foreach ($expenses as $e): ?>
      <div class="gt-row"><span><?php echo htmlspecialchars($e->Description, ENT_QUOTES, 'UTF-8'); ?> <small style="color:#aaa;">paid by <?php echo htmlspecialchars($e->PaidByEmail, ENT_QUOTES, 'UTF-8'); ?></small></span><span><?php echo tms_currency($e->AmountMinor / 100); ?></span></div>
    <?php endforeach; ?>
    <div class="gt-row" style="font-weight:800;"><span>Total spent</span><span><?php echo tms_currency($settlement['total_minor'] / 100); ?></span></div>
  </div>

  <div class="gt-box">
    <h4>Who owes whom</h4>
    <p style="color:#888;font-size:13px;">Split evenly across <?php echo count($settlement['members']); ?> member(s). Every amount below is exact — no rounding drift, any odd cent is assigned to specific members rather than lost.</p>
    <?php foreach ($settlement['balance'] as $memberEmail => $bal): ?>
      <div class="gt-row">
        <span><?php echo htmlspecialchars($memberEmail, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="<?php echo $bal >= 0 ? 'balance-pos' : 'balance-neg'; ?>">
          <?php echo $bal >= 0 ? 'is owed ' . tms_currency($bal/100) : 'owes ' . tms_currency(abs($bal)/100); ?>
        </span>
      </div>
    <?php endforeach; ?>
    <?php if (!empty($settlement['settlements'])): ?>
    <h5 style="margin-top:16px;">Suggested settlement</h5>
    <?php foreach ($settlement['settlements'] as $s): ?>
      <div class="gt-row"><span><?php echo htmlspecialchars($s['from'], ENT_QUOTES, 'UTF-8'); ?> &rarr; <?php echo htmlspecialchars($s['to'], ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo tms_currency($s['amount_minor']/100); ?></span></div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

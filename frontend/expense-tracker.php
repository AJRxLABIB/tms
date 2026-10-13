<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once dirname(__DIR__) . '/backend/ai/ExpenseAnalyst.php';
tms_require_user();

$email   = $_SESSION['login'];
$analyst = new ExpenseAnalyst($dbh);
$msg = ''; $error = '';

// ─── Add expense ────────────────────────────────────────────────────────────
if (isset($_POST['add_expense'])) {
    tms_csrf_validate();
    $amount = tms_int($_POST['amount'] ?? 0, 1);
    $category = tms_clean($_POST['category'] ?? 'other');
    $description = tms_clean($_POST['description'] ?? '');
    $date = tms_clean($_POST['expense_date'] ?? date('Y-m-d'));
    $bookingId = !empty($_POST['booking_id']) ? tms_int($_POST['booking_id'], 1) : null;

    if ($amount < 1) {
        $error = 'Enter a valid amount.';
    } else {
        $analyst->addExpense($email, $bookingId, $category, $amount, $description, $date);
        $msg = 'Expense logged.';
    }
}

// ─── Delete expense ─────────────────────────────────────────────────────────
if (isset($_POST['delete_expense'])) {
    tms_csrf_validate();
    $expenseId = tms_int($_POST['expense_id'] ?? 0, 1);
    if ($analyst->deleteExpense($expenseId, $email)) {
        $msg = 'Expense removed.';
    }
}

// ─── Filter by booking (optional) ──────────────────────────────────────────
$filterBookingId = !empty($_GET['booking']) ? tms_int($_GET['booking'], 1) : null;

// User's bookings, for the "attach to a trip" dropdown and the filter tabs
$bookingsStmt = $dbh->prepare(
    "SELECT b.BookingId, b.BookingDate, b.TotalCost, p.PackageName, p.PackageLocation
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.UserEmail = ? AND b.status != 2 ORDER BY b.RegDate DESC"
);
$bookingsStmt->execute([$email]);
$bookings = $bookingsStmt->fetchAll(PDO::FETCH_OBJ);

$expenses  = $analyst->getExpenses($email, $filterBookingId);
$breakdown = $filterBookingId ? $analyst->getBudgetComparison($email, $filterBookingId) : $analyst->getCategoryBreakdown($email);
$insight   = $analyst->generateInsight($email, $filterBookingId);

$categoryIcons = ['accommodation'=>'bed','food'=>'cutlery','transport'=>'car','activities'=>'star','shopping'=>'shopping-bag','other'=>'ellipsis-h'];
$categoryColors = ['accommodation'=>'#3b82f6','food'=>'#f59e0b','transport'=>'#10b981','activities'=>'#1F8A66','shopping'=>'#ec4899','other'=>'#6b7280'];
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Expense Tracker</title>
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
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.exp-card{background:var(--white);border-radius:12px;box-shadow:0 3px 20px rgba(0,0,0,0.07);padding:20px;margin-bottom:24px;}
.exp-card h4{font-size:15px;font-weight:700;margin:0 0 16px;color:var(--text);}
.insight-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;font-size:13.5px;color:#1e40af;margin-bottom:24px;display:flex;gap:10px;align-items:flex-start;}
.budget-bar{height:10px;border-radius:6px;background:var(--border);overflow:hidden;margin:10px 0;}
.budget-bar-fill{height:100%;border-radius:6px;transition:width .3s;}
.cat-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);}
.cat-row:last-child{border-bottom:none;}
.cat-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;flex-shrink:0;}
.exp-list-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.filter-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.filter-tabs a{padding:6px 14px;border-radius:20px;font-size:12.5px;text-decoration:none;background:var(--bg);color:var(--text);border:1px solid var(--border);}
.filter-tabs a.active{background:var(--secondary);color:#fff;}
html[data-theme="dark"] .filter-tabs a.active{color:#0E3B2D;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-money"></i> <?php echo tms_t('heading_expense_tracker','Expense Tracker'); ?></h2>
  <p style="margin:6px 0 0;opacity:.9;font-size:13.5px;"><?php echo tms_t('dest_expense_tracker_subtitle', 'Log real spending, see where your money goes, get AI insights grounded in your actual numbers.'); ?></p></div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($bookings): ?>
<div class="filter-tabs">
  <a href="expense-tracker.php" class="<?= !$filterBookingId ? 'active' : '' ?>"><?php echo tms_t('link_all_trips','All Trips'); ?></a>
  <?php foreach ($bookings as $b): ?>
  <a href="expense-tracker.php?booking=<?= (int)$b->BookingId ?>" class="<?= $filterBookingId===(int)$b->BookingId ? 'active' : '' ?>">
    <?= htmlspecialchars($b->PackageName) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="insight-box"><i class="fa fa-lightbulb-o" style="margin-top:2px;"></i> <span><?= htmlspecialchars($insight) ?></span></div>

<div class="row">
  <div class="col-md-4">
    <div class="exp-card">
      <h4><i class="fa fa-plus-circle"></i> <?php echo tms_t('heading_log_expense','Log an Expense'); ?></h4>
      <form method="post">
        <?php tms_csrf_field(); ?>
        <?php if ($bookings): ?>
        <div class="form-group">
          <label for="expTripSelect"><?php echo tms_t('label_trip_optional','Trip (optional)'); ?></label>
          <select name="booking_id" id="expTripSelect" class="form-control">
            <option value=""><?php echo tms_t('dest_not_linked_to_trip','— Not linked to a trip —'); ?></option>
            <?php foreach ($bookings as $b): ?>
            <option value="<?= (int)$b->BookingId ?>" <?= $filterBookingId===(int)$b->BookingId ? 'selected' : '' ?>><?= htmlspecialchars($b->PackageName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label for="expCategorySelect"><?php echo tms_t('label_category','Category'); ?></label>
          <select name="category" id="expCategorySelect" class="form-control">
            <?php foreach (ExpenseAnalyst::categories() as $cat): ?>
            <option value="<?= $cat ?>"><?= tms_t('cat_' . $cat, ucfirst($cat)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="expAmountField"><?php echo tms_t('label_amount_currency','Amount (৳)'); ?></label>
          <input type="number" name="amount" id="expAmountField" class="form-control" min="1" required>
        </div>
        <div class="form-group">
          <label for="expDescField"><?php echo tms_t('label_description','Description'); ?></label>
          <input type="text" name="description" id="expDescField" class="form-control" maxlength="255" placeholder="<?php echo htmlspecialchars(tms_t('dest_expense_desc_placeholder','e.g. Dinner at Sea Pearl'), ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="form-group">
          <label for="expDateField"><?php echo tms_t('label_date_word','Date'); ?></label>
          <input type="date" name="expense_date" id="expDateField" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <button type="submit" name="add_expense" class="btn btn-primary btn-block"><i class="fa fa-plus"></i> <?php echo tms_t('btn_add_expense','Add Expense'); ?></button>
      </form>
    </div>
  </div>

  <div class="col-md-4">
    <div class="exp-card">
      <h4><i class="fa fa-pie-chart"></i> <?= $filterBookingId ? tms_t('label_budget_vs_actual','Budget vs Actual') : tms_t('heading_spending_by_category','Spending by Category') ?></h4>
      <?php if ($filterBookingId && $breakdown['budget'] > 0): ?>
        <div style="font-size:13px;color:var(--text-muted);"><?php echo tms_t('label_spent_prefix','Spent'); ?> <strong><?= tms_currency($breakdown['spent']) ?></strong> <?php echo tms_t('label_spent_of_budget_suffix','of'); ?> <strong><?= tms_currency($breakdown['budget']) ?></strong> <?php echo tms_t('label_budget_word','budget'); ?></div>
        <div class="budget-bar"><div class="budget-bar-fill" style="width:<?= min(100,$breakdown['percent_used']) ?>%;background:<?= $breakdown['over_budget'] ? '#dc2626' : '#10b981' ?>;"></div></div>
        <div style="font-size:12px;color:<?= $breakdown['over_budget'] ? '#dc2626' : '#6b7280' ?>;">
          <?= $breakdown['percent_used'] ?>% <?php echo tms_t('label_used_suffix','used'); ?><?= $breakdown['over_budget'] ? ' ' . tms_t('dest_over_budget_by_prefix','— over budget by') . ' ' . tms_currency(abs($breakdown['remaining'])) : ', ' . tms_currency($breakdown['remaining']) . ' ' . tms_t('label_remaining_word','remaining') ?>
        </div>
      <?php endif; ?>
      <div style="margin-top:14px;">
      <?php if (empty($breakdown['by_category'])): ?>
        <div style="color:var(--text-muted);font-size:13px;"><?php echo tms_t('msg_no_expenses_logged','No expenses logged yet.'); ?></div>
      <?php else: foreach ($breakdown['by_category'] as $cat => $data): ?>
        <div class="cat-row">
          <div class="cat-icon" style="background:<?= $categoryColors[$cat] ?? '#6b7280' ?>;"><i class="fa fa-<?= $categoryIcons[$cat] ?? 'circle' ?>"></i></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;text-transform:capitalize;"><?= htmlspecialchars(tms_t('cat_' . $cat, ucfirst($cat))) ?></div>
            <div style="font-size:11.5px;color:var(--text-muted);"><?= $data['count'] ?> <?php echo tms_t('label_expense_count_suffix','expense(s)'); ?></div>
          </div>
          <div style="font-weight:700;font-size:13.5px;"><?= tms_currency($data['total']) ?></div>
        </div>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="exp-card">
      <h4><i class="fa fa-list"></i> <?php echo tms_t('heading_recent_expenses','Recent Expenses'); ?></h4>
      <?php if (empty($expenses)): ?>
        <div style="color:var(--text-muted);font-size:13px;"><?php echo tms_t('msg_nothing_logged_yet', 'Nothing logged yet — add your first expense.'); ?></div>
      <?php else: foreach (array_slice($expenses, 0, 10) as $e): ?>
        <div class="exp-list-row">
          <div class="cat-icon" style="background:<?= $categoryColors[$e->Category] ?? '#6b7280' ?>;"><i class="fa fa-<?= $categoryIcons[$e->Category] ?? 'circle' ?>"></i></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($e->Description ?: tms_t('cat_' . $e->Category, ucfirst($e->Category))) ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($e->ExpenseDate) ?></div>
          </div>
          <div style="font-weight:700;font-size:13px;"><?= tms_currency($e->Amount) ?></div>
          <form method="post" style="display:inline;margin:0;">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="expense_id" value="<?= (int)$e->ExpenseId ?>">
            <button type="submit" name="delete_expense" class="btn btn-link btn-xs" style="color:#dc2626;padding:0;" onclick="return confirm('<?php echo addslashes(tms_t('msg_confirm_remove_expense','Remove this expense?')); ?>')"><i class="fa fa-trash"></i></button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

</div>
<?php include('backend/footer.php'); ?>
</body></html>

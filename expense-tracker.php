<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
require_once __DIR__ . '/includes/ai/ExpenseAnalyst.php';
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
$categoryColors = ['accommodation'=>'#3b82f6','food'=>'#f59e0b','transport'=>'#10b981','activities'=>'#8b5cf6','shopping'=>'#ec4899','other'=>'#6b7280'];
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | Expense Tracker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.exp-card{background:#fff;border-radius:12px;box-shadow:0 3px 20px rgba(0,0,0,0.07);padding:20px;margin-bottom:24px;}
.exp-card h4{font-size:15px;font-weight:700;margin:0 0 16px;color:#1f2937;}
.insight-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;font-size:13.5px;color:#1e40af;margin-bottom:24px;display:flex;gap:10px;align-items:flex-start;}
.budget-bar{height:10px;border-radius:6px;background:#e5e7eb;overflow:hidden;margin:10px 0;}
.budget-bar-fill{height:100%;border-radius:6px;transition:width .3s;}
.cat-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f3f4f6;}
.cat-row:last-child{border-bottom:none;}
.cat-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;flex-shrink:0;}
.exp-list-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;}
.filter-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.filter-tabs a{padding:6px 14px;border-radius:20px;font-size:12.5px;text-decoration:none;background:#f3f4f6;color:#4b5563;}
.filter-tabs a.active{background:var(--secondary);color:#fff;}
</style>
</head><body>
<?php include('includes/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-money"></i> Expense Tracker</h2>
  <p style="margin:6px 0 0;opacity:.9;font-size:13.5px;">Log real spending, see where your money goes, get AI insights grounded in your actual numbers.</p></div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($bookings): ?>
<div class="filter-tabs">
  <a href="expense-tracker.php" class="<?= !$filterBookingId ? 'active' : '' ?>">All Trips</a>
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
      <h4><i class="fa fa-plus-circle"></i> Log an Expense</h4>
      <form method="post">
        <?php tms_csrf_field(); ?>
        <?php if ($bookings): ?>
        <div class="form-group">
          <label>Trip (optional)</label>
          <select name="booking_id" class="form-control">
            <option value="">— Not linked to a trip —</option>
            <?php foreach ($bookings as $b): ?>
            <option value="<?= (int)$b->BookingId ?>" <?= $filterBookingId===(int)$b->BookingId ? 'selected' : '' ?>><?= htmlspecialchars($b->PackageName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label>Category</label>
          <select name="category" class="form-control">
            <?php foreach (ExpenseAnalyst::categories() as $cat): ?>
            <option value="<?= $cat ?>"><?= ucfirst($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Amount (৳)</label>
          <input type="number" name="amount" class="form-control" min="1" required>
        </div>
        <div class="form-group">
          <label>Description</label>
          <input type="text" name="description" class="form-control" maxlength="255" placeholder="e.g. Dinner at Sea Pearl">
        </div>
        <div class="form-group">
          <label>Date</label>
          <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <button type="submit" name="add_expense" class="btn btn-primary btn-block"><i class="fa fa-plus"></i> Add Expense</button>
      </form>
    </div>
  </div>

  <div class="col-md-4">
    <div class="exp-card">
      <h4><i class="fa fa-pie-chart"></i> <?= $filterBookingId ? 'Budget vs Actual' : 'Spending by Category' ?></h4>
      <?php if ($filterBookingId && $breakdown['budget'] > 0): ?>
        <div style="font-size:13px;color:#6b7280;">Spent <strong><?= tms_currency($breakdown['spent']) ?></strong> of <strong><?= tms_currency($breakdown['budget']) ?></strong> budget</div>
        <div class="budget-bar"><div class="budget-bar-fill" style="width:<?= min(100,$breakdown['percent_used']) ?>%;background:<?= $breakdown['over_budget'] ? '#dc2626' : '#10b981' ?>;"></div></div>
        <div style="font-size:12px;color:<?= $breakdown['over_budget'] ? '#dc2626' : '#6b7280' ?>;">
          <?= $breakdown['percent_used'] ?>% used<?= $breakdown['over_budget'] ? ' — over budget by ' . tms_currency(abs($breakdown['remaining'])) : ', ' . tms_currency($breakdown['remaining']) . ' remaining' ?>
        </div>
      <?php endif; ?>
      <div style="margin-top:14px;">
      <?php if (empty($breakdown['by_category'])): ?>
        <div style="color:#9ca3af;font-size:13px;">No expenses logged yet.</div>
      <?php else: foreach ($breakdown['by_category'] as $cat => $data): ?>
        <div class="cat-row">
          <div class="cat-icon" style="background:<?= $categoryColors[$cat] ?? '#6b7280' ?>;"><i class="fa fa-<?= $categoryIcons[$cat] ?? 'circle' ?>"></i></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;text-transform:capitalize;"><?= htmlspecialchars($cat) ?></div>
            <div style="font-size:11.5px;color:#9ca3af;"><?= $data['count'] ?> expense(s)</div>
          </div>
          <div style="font-weight:700;font-size:13.5px;"><?= tms_currency($data['total']) ?></div>
        </div>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="exp-card">
      <h4><i class="fa fa-list"></i> Recent Expenses</h4>
      <?php if (empty($expenses)): ?>
        <div style="color:#9ca3af;font-size:13px;">Nothing logged yet — add your first expense.</div>
      <?php else: foreach (array_slice($expenses, 0, 10) as $e): ?>
        <div class="exp-list-row">
          <div class="cat-icon" style="background:<?= $categoryColors[$e->Category] ?? '#6b7280' ?>;"><i class="fa fa-<?= $categoryIcons[$e->Category] ?? 'circle' ?>"></i></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($e->Description ?: ucfirst($e->Category)) ?></div>
            <div style="font-size:11px;color:#9ca3af;"><?= htmlspecialchars($e->ExpenseDate) ?></div>
          </div>
          <div style="font-weight:700;font-size:13px;"><?= tms_currency($e->Amount) ?></div>
          <form method="post" style="display:inline;margin:0;">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="expense_id" value="<?= (int)$e->ExpenseId ?>">
            <button type="submit" name="delete_expense" class="btn btn-link btn-xs" style="color:#dc2626;padding:0;" onclick="return confirm('Remove this expense?')"><i class="fa fa-trash"></i></button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

</div>
<?php include('includes/footer.php'); ?>
</body></html>

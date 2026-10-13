<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', 0); ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['booking_manager', 'operations', 'manager']); // Round 20 RBAC (item 46)

// Which month to display (defaults to current month)
$monthParam = $_GET['month'] ?? date('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m-d', $monthParam . '-01') ?: new DateTime('first day of this month');
$monthStart = (clone $monthDate)->modify('first day of this month')->setTime(0,0);
$monthEnd   = (clone $monthStart)->modify('last day of this month')->setTime(23,59,59);
$prevMonth  = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $monthStart)->modify('+1 month')->format('Y-m');

$q = $dbh->prepare(
    "SELECT b.BookingId, b.FromDate, b.ToDate, b.status, b.UserEmail, p.PackageName
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.FromDate BETWEEN :start AND :end
     ORDER BY b.FromDate ASC"
);
$q->execute([':start' => $monthStart->format('Y-m-d'), ':end' => $monthEnd->format('Y-m-d')]);
$bookings = $q->fetchAll(PDO::FETCH_OBJ);

// Group by day-of-month for quick lookup while rendering the grid
$byDay = [];
foreach ($bookings as $b) {
    $day = (int)date('j', strtotime($b->FromDate));
    $byDay[$day][] = $b;
}

$statusMeta = [
    0 => ['label'=>'Pending',   'bg'=>'#fef3c7', 'fg'=>'#92400e'],
    1 => ['label'=>'Confirmed', 'bg'=>'#d1fae5', 'fg'=>'#065f46'],
    2 => ['label'=>'Cancelled', 'bg'=>'#fee2e2', 'fg'=>'#991b1b'],
];

$daysInMonth  = (int)$monthStart->format('t');
$firstWeekday = (int)$monthStart->format('w'); // 0 = Sunday
$today        = (int)(new DateTime())->format('Ymd');
$isCurrentViewMonth = $monthStart->format('Y-m') === (new DateTime())->format('Y-m');
$todayDay = $isCurrentViewMonth ? (int)(new DateTime())->format('j') : 0;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Booking Calendar</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; }
.page-title-row h2 { font-family:'Inter',sans-serif; font-size:22px; font-weight:800; color:var(--text-primary); margin:0; }
.month-nav { display:flex; align-items:center; gap:14px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px 14px; }
.month-nav a { color:#176B50; text-decoration:none; font-size:14px; }
.month-nav .cur { font-weight:700; font-size:14.5px; color:var(--text-primary); min-width:150px; text-align:center; }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; }
.cal-dow { text-align:center; font-size:11.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; padding-bottom:6px; }
.cal-cell { background:#fff; border:1px solid #e2e8f0; border-radius:10px; min-height:110px; padding:8px; }
.cal-cell.empty { background:transparent; border:none; }
.cal-cell.today { border-color:#176B50; border-width:2px; }
.cal-daynum { font-size:12.5px; font-weight:700; color:#334155; margin-bottom:6px; }
.cal-booking { display:block; font-size:10.5px; padding:3px 6px; border-radius:5px; margin-bottom:3px; text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cal-more { font-size:10px; color:var(--text-muted); padding-left:4px; }
.legend { display:flex; gap:16px; margin-top:18px; font-size:12.5px; color:var(--text-muted); }
.legend span { display:inline-flex; align-items:center; gap:6px; }
.legend i { width:10px; height:10px; border-radius:3px; display:inline-block; }
html[data-theme="dark"] .month-nav,
html[data-theme="dark"] .cal-cell { background:#1b222c !important; border-color:#2b3542 !important; }
html[data-theme="dark"] .page-title-row h2,
html[data-theme="dark"] .month-nav .cur,
html[data-theme="dark"] .cal-daynum { color:#eef2f7 !important; }
html[data-theme="dark"] .cal-dow,
html[data-theme="dark"] .legend,
html[data-theme="dark"] .cal-more { color:var(--text-muted) !important; }
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<div class="page-title-row">
  <h2><i class="fa fa-calendar-check-o" style="color:#176B50;margin-right:8px;"></i>Booking Calendar</h2>
  <div class="month-nav">
    <a href="?month=<?php echo $prevMonth; ?>"><i class="fa fa-chevron-left"></i> Prev</a>
    <span class="cur"><?php echo $monthStart->format('F Y'); ?></span>
    <a href="?month=<?php echo $nextMonth; ?>">Next <i class="fa fa-chevron-right"></i></a>
  </div>
</div>

<div class="cal-grid">
  <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
  <div class="cal-dow"><?php echo $dow; ?></div>
  <?php endforeach; ?>

  <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
  <div class="cal-cell empty"></div>
  <?php endfor; ?>

  <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
  <div class="cal-cell<?php echo ($day === $todayDay) ? ' today' : ''; ?>">
    <div class="cal-daynum"><?php echo $day; ?></div>
    <?php
      $dayBookings = $byDay[$day] ?? [];
      $shown = array_slice($dayBookings, 0, 3);
      foreach ($shown as $b):
        $meta = $statusMeta[(int)$b->status] ?? $statusMeta[0];
    ?>
    <a href="manage-bookings.php?search=<?php echo urlencode($b->UserEmail); ?>" class="cal-booking" style="background:<?php echo $meta['bg']; ?>;color:<?php echo $meta['fg']; ?>;" title="<?php echo htmlspecialchars($b->PackageName . ' — ' . $b->UserEmail, ENT_QUOTES, 'UTF-8'); ?>">
      #<?php echo (int)$b->BookingId; ?> <?php echo htmlspecialchars($b->PackageName, ENT_QUOTES, 'UTF-8'); ?>
    </a>
    <?php endforeach; ?>
    <?php if (count($dayBookings) > 3): ?>
    <div class="cal-more">+<?php echo count($dayBookings) - 3; ?> more</div>
    <?php endif; ?>
  </div>
  <?php endfor; ?>
</div>

<div class="legend">
  <span><i style="background:#fef3c7;"></i> Pending</span>
  <span><i style="background:#d1fae5;"></i> Confirmed</span>
  <span><i style="background:#fee2e2;"></i> Cancelled</span>
</div>

</div>
</main>
</div>
<?php include('includes/footer.php'); ?>
</body>
</html>

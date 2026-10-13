<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['booking_manager', 'operations', 'manager']); // same gate as manage-bookings.php (Round 20 RBAC, item 46)

/**
 * admin/manage-marketplace-bookings.php — Round 41.
 *
 * Admin-side view/confirm/cancel for the two Round 37 marketplace booking
 * tables (tblhotel_bookings / tblactivity_bookings), deliberately deferred
 * out of Round 37 itself to keep that round bounded (Rule 73) and flagged
 * in DATABASE.md / SESSION_SUMMARY_INDEX.md as real follow-up work -- this
 * is that follow-up.
 *
 * Deliberately simpler than manage-bookings.php: these sample-data bookings
 * have no real payment gateway behind them (my-marketplace-bookings.php's
 * own docblock says the same), so there is no refund workflow and no
 * date-change-request workflow here -- just confirm / cancel / delete,
 * mirroring manage-bookings.php's simplest three actions only.
 *
 * Both booking types are shown in one unified, paginated list (a SQL
 * UNION ALL of the two tables, normalized into a common shape, wrapped in
 * a derived table so search/status/type filters and LIMIT/OFFSET apply
 * once instead of being duplicated across both halves) -- the same
 * "one view for both product types" choice my-marketplace-bookings.php
 * already made on the customer-facing side.
 */

$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'], $_POST['type'])) {
    tms_csrf_validate();
    $bid  = (int)$_POST['id'];
    // Whitelist, not user-controlled interpolation: exactly two literal
    // values are possible, so building the table name off this ternary
    // never allows an arbitrary string into the query.
    $type = ($_POST['type'] === 'activity') ? 'activity' : 'hotel';
    $table = $type === 'activity' ? 'tblactivity_bookings' : 'tblhotel_bookings';
    $label = $type === 'activity' ? 'Activity' : 'Hotel';
    $code  = ($type === 'activity' ? 'AB' : 'HB') . str_pad((string)$bid, 3, '0', STR_PAD_LEFT);

    switch ($_POST['action']) {
        case 'confirm':
            $dbh->prepare("UPDATE $table SET status=1 WHERE BookingId=:id")->execute([':id' => $bid]);
            tms_notify_marketplace_booking_owner(
                $dbh, $type, $bid,
                "Great news! Your $label booking #$code has been confirmed.",
                "Your $label booking #$code is confirmed!",
                "<p>Great news — your $label booking <strong>#$code</strong> has been confirmed by our team.</p>
                 <p>You can view it anytime from <strong>My Marketplace Bookings</strong>.</p>"
            );
            $msg = "$label booking #$code confirmed."; $msgType = 'success';
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], "Marketplace $label Booking Confirmed", "BookingId=$bid");
            break;
        case 'cancel':
            $dbh->prepare("UPDATE $table SET status=2 WHERE BookingId=:id")->execute([':id' => $bid]);
            tms_notify_marketplace_booking_owner(
                $dbh, $type, $bid,
                "Your $label booking #$code has been cancelled by our team. Please contact support if you have questions.",
                "Your $label booking #$code has been cancelled",
                "<p>Your $label booking <strong>#$code</strong> has been cancelled by our team.</p>
                 <p>If you weren't expecting this or have questions, please reach out to TMS support.</p>"
            );
            $msg = "$label booking #$code cancelled."; $msgType = 'success';
            tms_log_activity($dbh, $_SESSION['alogin'], "Marketplace $label Booking Cancelled", "BookingId=$bid");
            break;
        case 'delete':
            $dbh->prepare("DELETE FROM $table WHERE BookingId=:id")->execute([':id' => $bid]);
            $msg = "$label booking #$code deleted."; $msgType = 'success';
            tms_log_activity($dbh, $_SESSION['alogin'], "Marketplace $label Booking Deleted", "BookingId=$bid");
            break;
    }
}

/** Same pattern as manage-bookings.php's tms_notify_booking_owner(), parametrized by product type. */
function tms_notify_marketplace_booking_owner(PDO $dbh, string $type, int $bookingId, string $message, string $emailSubject = '', string $emailHtml = ''): void {
    try {
        $table = $type === 'activity' ? 'tblactivity_bookings' : 'tblhotel_bookings';
        $alias = $type === 'activity' ? 'ab' : 'hb';
        $stmt = $dbh->prepare(
            "SELECT $alias.UserEmail, u.FullName, u.NotificationsEnabled FROM $table $alias
             LEFT JOIN tblusers u ON u.EmailId = $alias.UserEmail
             WHERE $alias.BookingId = :id"
        );
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row && $row->UserEmail) {
            // Same reasoning as manage-bookings.php: the in-app notification
            // always goes out, only the email half is gated by the user's
            // NotificationsEnabled preference (default-send on NULL).
            tms_notify($dbh, $row->UserEmail, $message, 'my-marketplace-bookings.php');
            $wantsEmail = $row->NotificationsEnabled === null || (int)$row->NotificationsEnabled === 1;
            if ($emailSubject && $emailHtml && $wantsEmail) {
                tms_send_email($row->UserEmail, $row->FullName ?: $row->UserEmail, $emailSubject, tms_email_template($emailSubject, $emailHtml));
            }
        }
    } catch (Exception $e) {
        error_log('[TMS] Could not notify marketplace booking owner: ' . $e->getMessage());
    }
}

// --- Stats (each wrapped so a not-yet-migrated table shows 0, not a fatal) ---
function safe_val(PDO $db, string $sql): int {
    try { return (int)$db->query($sql)->fetchColumn(); } catch (Exception $e) { return 0; }
}
$totalHotel   = safe_val($dbh, "SELECT COUNT(*) FROM tblhotel_bookings");
$totalActivity= safe_val($dbh, "SELECT COUNT(*) FROM tblactivity_bookings");
$total        = $totalHotel + $totalActivity;
$pending      = safe_val($dbh, "SELECT COUNT(*) FROM tblhotel_bookings WHERE status=0") + safe_val($dbh, "SELECT COUNT(*) FROM tblactivity_bookings WHERE status=0");
$confirmed    = safe_val($dbh, "SELECT COUNT(*) FROM tblhotel_bookings WHERE status=1") + safe_val($dbh, "SELECT COUNT(*) FROM tblactivity_bookings WHERE status=1");
$cancelled    = safe_val($dbh, "SELECT COUNT(*) FROM tblhotel_bookings WHERE status=2") + safe_val($dbh, "SELECT COUNT(*) FROM tblactivity_bookings WHERE status=2");

// --- Filters ---
$search        = tms_clean($_GET['search'] ?? '');
$filter_status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : null;
$filter_type   = in_array($_GET['type'] ?? '', ['hotel', 'activity'], true) ? $_GET['type'] : null;

$outerWhere = "WHERE 1=1";
$params = [];
if ($search) {
    $outerWhere .= " AND (u.UserEmail LIKE :s OR u.ItemName LIKE :s2 OR u.BookingId LIKE :s3)";
    $params[':s'] = "%$search%"; $params[':s2'] = "%$search%"; $params[':s3'] = "%$search%";
}
if ($filter_status !== null) { $outerWhere .= " AND u.status = :st"; $params[':st'] = $filter_status; }
if ($filter_type !== null)   { $outerWhere .= " AND u.BookingType = :bt"; $params[':bt'] = $filter_type; }

$perPage = 25;
$page    = max(1, (int)($_GET['p'] ?? 1));

$unionSql = "
    SELECT 'hotel' AS BookingType, hb.BookingId, hb.UserEmail, hb.RegDate, hb.status, hb.TotalCost,
           hb.VerificationCode, h.HotelName AS ItemName, hb.CheckInDate AS PrimaryDate, hb.CheckOutDate AS SecondaryDate
    FROM tblhotel_bookings hb JOIN tblhotels h ON h.HotelId = hb.HotelId
    UNION ALL
    SELECT 'activity' AS BookingType, ab.BookingId, ab.UserEmail, ab.RegDate, ab.status, ab.TotalCost,
           ab.VerificationCode, a.ActivityName AS ItemName, ab.ActivityDate AS PrimaryDate, NULL AS SecondaryDate
    FROM tblactivity_bookings ab JOIN tblactivities a ON a.ActivityId = ab.ActivityId
";

try {
    $countQ = $dbh->prepare("SELECT COUNT(*) FROM ($unionSql) u $outerWhere");
    $countQ->execute($params);
    $bookingTotal = (int)$countQ->fetchColumn();
    $totalPages = max(1, (int)ceil($bookingTotal / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $q = $dbh->prepare("SELECT * FROM ($unionSql) u $outerWhere ORDER BY u.RegDate DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) { $q->bindValue($k, $v, PDO::PARAM_STR); }
    $q->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $q->bindValue(':off', $offset, PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {
    // Most likely cause: neither marketplace table has migrated in this DB
    // yet (a brand-new install that hasn't visited hotels.php/activities.php
    // to trigger the session-gated migration) -- show an empty, honest list
    // rather than a fatal error.
    $rows = [];
    $bookingTotal = 0; $totalPages = 1; $page = 1;
}

$statusLabel = [0 => 'Pending', 1 => 'Confirmed', 2 => 'Cancelled'];
$statusClass = [0 => 'status-pending', 1 => 'status-confirmed', 2 => 'status-cancelled'];
$statusIcon  = [0 => 'fa-clock-o', 1 => 'fa-check-circle', 2 => 'fa-times-circle'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Marketplace Bookings</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.booking-status { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; }
.status-pending  { background:#fef3c7; color:#92400e; }
.status-confirmed{ background:#d1fae5; color:#065f46; }
.status-cancelled{ background:#fee2e2; color:#991b1b; }
.type-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.type-hotel    { background:#e0f2fe; color:#075985; }
.type-activity { background:#ede9fe; color:#5b21b6; }
.filter-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:20px; }
.filter-bar select, .filter-bar input { padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; font-family:inherit; color:var(--text-primary); outline:none; background:#f8fafc; }
.filter-bar select:focus, .filter-bar input:focus { border-color:#176B50; }
.stat-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:24px; }
.mini-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px; }
.mini-stat .val { font-size:24px; font-weight:700; color:var(--text-primary); }
.mini-stat .lbl { font-size:12px; color:var(--text-muted); margin-top:2px; }
.action-btn { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:6px; font-size:12px; font-weight:500; border:none; cursor:pointer; text-decoration:none; transition:all .15s; }
.btn-confirm  { background:#d1fae5; color:#065f46; }
.btn-confirm:hover  { background:#a7f3d0; }
.btn-cancel   { background:#fee2e2; color:#991b1b; }
.btn-cancel:hover   { background:#fecaca; }
.btn-delete   { background:#f1f5f9; color:var(--text-muted); }
.btn-delete:hover   { background:#e2e8f0; }
.page-title-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.page-title-row h2 { font-family:'Inter',sans-serif; font-size:22px; font-weight:800; color:var(--text-primary); margin:0; }
.alert-msg { padding:12px 16px; border-radius:10px; font-size:13.5px; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.alert-success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
.alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.sample-note { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:10px; padding:10px 14px; font-size:12.5px; margin-bottom:18px; }
html[data-theme="dark"] .mini-stat { background:#1b222c !important; border-color:#2b3542 !important; }
html[data-theme="dark"] .mini-stat .val,
html[data-theme="dark"] .page-title-row h2 { color:#eef2f7 !important; }
html[data-theme="dark"] .mini-stat .lbl { color:var(--text-muted) !important; }
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<div class="page-title-row">
  <h2><i class="fa fa-suitcase" style="color:#176B50;margin-right:10px;"></i>Manage Marketplace Bookings</h2>
  <span style="font-size:13px;color:var(--text-muted);"><?= count($rows) ?> booking(s) on this page</span>
</div>

<div class="sample-note"><i class="fa fa-flask"></i> These are Round 37's hotel/activity marketplace bookings — sample/demo catalog, real customer bookings against it. No payment gateway or refund workflow exists for this feature (see DATABASE.md), so only Confirm / Cancel / Delete are available here.</div>

<?php if ($msg): ?>
<div class="alert-msg alert-<?= $msgType ?>">
  <i class="fa fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="stat-strip">
  <div class="mini-stat"><div class="val"><?= $total ?></div><div class="lbl">Total Bookings</div></div>
  <div class="mini-stat"><div class="val" style="color:#92400e;"><?= $pending ?></div><div class="lbl">Pending</div></div>
  <div class="mini-stat"><div class="val" style="color:#065f46;"><?= $confirmed ?></div><div class="lbl">Confirmed</div></div>
  <div class="mini-stat"><div class="val" style="color:#991b1b;"><?= $cancelled ?></div><div class="lbl">Cancelled</div></div>
</div>

<form method="get" class="filter-bar">
  <input type="text" name="search" placeholder="Search by email, hotel/activity, ID..." value="<?= htmlspecialchars($search) ?>" style="min-width:240px;">
  <select name="type">
    <option value="">All Types</option>
    <option value="hotel" <?= $filter_type === 'hotel' ? 'selected' : '' ?>>Hotel</option>
    <option value="activity" <?= $filter_type === 'activity' ? 'selected' : '' ?>>Activity</option>
  </select>
  <select name="status">
    <option value="">All Statuses</option>
    <option value="0" <?= $filter_status === 0 ? 'selected' : '' ?>>Pending</option>
    <option value="1" <?= $filter_status === 1 ? 'selected' : '' ?>>Confirmed</option>
    <option value="2" <?= $filter_status === 2 ? 'selected' : '' ?>>Cancelled</option>
  </select>
  <button type="submit" class="btn-v6 primary"><i class="fa fa-search"></i> Filter</button>
  <?php if ($search || $filter_status !== null || $filter_type !== null): ?>
    <a href="manage-marketplace-bookings.php" class="btn-v6 ghost">Clear</a>
  <?php endif; ?>
</form>

<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead>
    <tr>
      <th>#</th><th>Type</th><th>Booking</th><th>User Email</th><th>Item</th><th>Total</th><th>Date</th><th>Status</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">
      <i class="fa fa-suitcase" style="font-size:32px;display:block;margin-bottom:10px;"></i>
      No marketplace bookings found.
    </td></tr>
  <?php else: ?>
    <?php $i = 1; foreach ($rows as $r): ?>
    <?php
      $st = (int)($r->status ?? 0);
      $bid = (int)$r->BookingId;
      $isActivity = $r->BookingType === 'activity';
      $code = ($isActivity ? 'AB' : 'HB') . str_pad((string)$bid, 3, '0', STR_PAD_LEFT);
      $dateDisplay = $r->PrimaryDate ? date('d M Y', strtotime($r->PrimaryDate)) : '—';
      if (!$isActivity && $r->SecondaryDate) { $dateDisplay .= ' → ' . date('d M Y', strtotime($r->SecondaryDate)); }
    ?>
    <tr>
      <td><?= $i++ ?></td>
      <td><span class="type-badge <?= $isActivity ? 'type-activity' : 'type-hotel' ?>"><i class="fa <?= $isActivity ? 'fa-map-signs' : 'fa-hotel' ?>"></i> <?= $isActivity ? 'Activity' : 'Hotel' ?></span></td>
      <td><strong style="color:#176B50;">#<?= $code ?></strong></td>
      <td><?= htmlspecialchars($r->UserEmail ?? '—') ?></td>
      <td><?= htmlspecialchars($r->ItemName ?? '—') ?></td>
      <td><?= $r->TotalCost !== null ? tms_currency((float)$r->TotalCost) : '—' ?></td>
      <td><?= $dateDisplay ?></td>
      <td>
        <span class="booking-status <?= $statusClass[$st] ?? 'status-pending' ?>">
          <i class="fa <?= $statusIcon[$st] ?? 'fa-clock-o' ?>"></i> <?= $statusLabel[$st] ?? 'Unknown' ?>
        </span>
      </td>
      <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap;">
          <?php if ($st !== 1): ?>
          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="id" value="<?= $bid ?>">
            <input type="hidden" name="type" value="<?= $r->BookingType ?>">
            <?php tms_csrf_field(); ?>
            <button type="submit" class="action-btn btn-confirm" onclick="return confirm('Confirm this booking?')"><i class="fa fa-check"></i> Confirm</button>
          </form>
          <?php endif; ?>
          <?php if ($st !== 2): ?>
          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" value="<?= $bid ?>">
            <input type="hidden" name="type" value="<?= $r->BookingType ?>">
            <?php tms_csrf_field(); ?>
            <button type="submit" class="action-btn btn-cancel" onclick="return confirm('Cancel this booking?')"><i class="fa fa-times"></i> Cancel</button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $bid ?>">
            <input type="hidden" name="type" value="<?= $r->BookingType ?>">
            <?php tms_csrf_field(); ?>
            <button type="submit" class="action-btn btn-delete" onclick="return confirm('Permanently delete this booking?')"><i class="fa fa-trash"></i></button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<div style="font-size:12.5px;color:var(--text-muted);margin:10px 0;"><?php echo $bookingTotal; ?> booking<?php echo $bookingTotal !== 1 ? 's' : ''; ?> total &nbsp;·&nbsp; page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
    <?php for ($pn = 1; $pn <= $totalPages; $pn++):
        $qs = http_build_query(array_merge($_GET, ['p' => $pn])); ?>
    <a href="?<?php echo $qs; ?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;
        <?php echo $pn === $page ? 'background:#176B50;color:#fff;' : 'background:#f1f5f9;color:#334155;'; ?>"><?php echo $pn; ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</div>
</main>
</div>
</body>
</html>

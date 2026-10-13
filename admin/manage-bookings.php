<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require_once(__DIR__ . '/../backend/payments/PaymentProviderInterface.php');
require_once(__DIR__ . '/../backend/payments/SandboxProvider.php');
require_once(__DIR__ . '/../backend/payments/PaymentService.php');
require_once(__DIR__ . '/../backend/services/BookingStateMachine.php');
tms_require_admin();
// SECURITY FIX (Round 75, master prompt item 46 -- RBAC): this page performs
// real state-changing actions (confirm/cancel/delete a booking, approve/
// reject a date-change request, process/reject a refund) but only ever
// checked tms_require_admin() -- ANY admin role, not just the roles this
// feature is meant for. admin/includes/sidebarmenu.php already hides the
// "Manage Bookings" nav link from every role except booking_manager/
// operations/manager, and the sibling page admin/manage-marketplace-
// bookings.php already gates itself with this exact role list (its own
// comment even claims "same gate as manage-bookings.php" -- it wasn't).
// Any other admin (Content Manager, AI Manager, Finance, Support, Package
// Manager) could reach this page directly by URL and confirm/cancel/delete
// bookings or process refunds with zero server-side check. Closing that
// gap here, matching the existing pattern exactly (Rule 1: no new pattern
// invented).
tms_admin_require_role($dbh, ['booking_manager', 'operations', 'manager']);
$paymentService = new PaymentService($dbh);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Bookings</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.booking-status { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; }
.status-pending  { background:#fef3c7; color:#92400e; }
.status-confirmed{ background:#d1fae5; color:#065f46; }
.status-cancelled{ background:#fee2e2; color:#991b1b; }
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

<?php

// --- Handle actions ---
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    tms_csrf_validate();
    $bid = (int)$_POST['id'];
    switch ($_POST['action']) {
        case 'confirm':
            // GUARD (new "TMS V2" prompt -- booking state machine): nothing
            // previously stopped this action from "confirming" an
            // already-cancelled booking, silently resurrecting it (status
            // 2 -> 1) with no trace -- a real bug class, not a hypothetical
            // one, since this switch has never checked a booking's current
            // status before acting. BookingStateMachine::canTransitionStatus
            // encodes exactly the same 0->1 / 1->2 lifecycle every other
            // real write site in this codebase already follows (see
            // backend/services/BookingStateMachine.php), so this only ever
            // rejects a transition nothing here has ever legitimately made.
            $curStatusStmt = $dbh->prepare("SELECT status FROM tblbooking WHERE BookingId=:id");
            $curStatusStmt->execute([':id'=>$bid]);
            $curStatus = $curStatusStmt->fetchColumn();
            if ($curStatus === false) {
                $msg = "Booking #BK$bid not found.";
                $msgType = 'error';
            } elseif (!BookingStateMachine::canTransitionStatus((int)$curStatus, 1)) {
                $msg = "Booking #BK$bid is cancelled and can no longer be confirmed.";
                $msgType = 'error';
            } else {
                $dbh->prepare("UPDATE tblbooking SET status=1 WHERE BookingId=:id")->execute([':id'=>$bid]);
                $ref = 'BK'.str_pad($bid,3,'0',STR_PAD_LEFT);
                tms_notify_booking_owner(
                    $dbh, $bid, "Great news! Your booking #$ref has been confirmed.",
                    "Your booking #$ref is confirmed! ✈",
                    "<p>Great news — your booking <strong>#$ref</strong> has been confirmed by our team.</p>
                     <p>You can view your itinerary and download your e-ticket anytime from <strong>My Bookings</strong>.</p>"
                );
                $msg = "Booking #BK".str_pad($bid,3,'0',STR_PAD_LEFT)." confirmed.";
                $msgType = 'success';
                // Audit log coverage (Round 52).
                tms_log_activity($dbh, $_SESSION['alogin'], 'Booking Confirmed', "BookingId=$bid");
            }
            break;
        case 'cancel':
            $dbh->prepare("UPDATE tblbooking SET status=2 WHERE BookingId=:id")->execute([':id'=>$bid]);
            $ref = 'BK'.str_pad($bid,3,'0',STR_PAD_LEFT);
            tms_notify_booking_owner(
                $dbh, $bid, "Your booking #$ref has been cancelled by our team. Please contact support if you have questions.",
                "Your booking #$ref has been cancelled",
                "<p>Your booking <strong>#$ref</strong> has been cancelled by our team.</p>
                 <p>If you weren't expecting this or have questions, please reach out to TMS support and we'll help sort it out.</p>"
            );
            $msg = "Booking #BK".str_pad($bid,3,'0',STR_PAD_LEFT)." cancelled.";
            $msgType = 'success';
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Booking Cancelled', "BookingId=$bid");
            break;
        case 'delete':
            $dbh->prepare("DELETE FROM tblbooking WHERE BookingId=:id")->execute([':id'=>$bid]);
            $msg = "Booking deleted.";
            $msgType = 'success';
            // Audit log coverage (Round 52). Deleting a booking record is a
            // permanent, financially-relevant action.
            tms_log_activity($dbh, $_SESSION['alogin'], 'Booking Deleted', "BookingId=$bid");
            break;
        case 'approve_mod':
            // GUARD (Round 20, caught in live testing): a customer cancelling
            // a booking that still had a pending date-change request used to
            // leave the request pending, so approving it here would silently
            // rewrite FromDate/ToDate on an already-cancelled booking. That
            // stale-data path is now closed at the source (tour-history.php's
            // cancel_booking clears the request), but this check stays as a
            // second line of defense against acting on a cancelled booking.
            // FromDate/ToDate added (Round 96, item 47) so the pre-change
            // dates are available to the audit log below without a second
            // query -- RequestedFromDate/RequestedToDate/status were already
            // being selected for the guard logic above.
            $bq = $dbh->prepare("SELECT FromDate, ToDate, RequestedFromDate, RequestedToDate, status FROM tblbooking WHERE BookingId=:id");
            $bq->execute([':id'=>$bid]);
            $row = $bq->fetch(PDO::FETCH_OBJ);
            if ($row && (int)$row->status === 2) {
                $msg = "Booking #BK$bid is cancelled — its date-change request can no longer be applied.";
                $msgType = 'error';
            } elseif ($row && $row->RequestedFromDate && $row->RequestedToDate) {
                $dbh->prepare("UPDATE tblbooking SET FromDate=:f, ToDate=:t, RequestedFromDate=NULL, RequestedToDate=NULL, ModificationStatus='approved', ModificationNote=NULL WHERE BookingId=:id")
                    ->execute([':f'=>$row->RequestedFromDate, ':t'=>$row->RequestedToDate, ':id'=>$bid]);
                $ref = 'BK'.str_pad($bid,3,'0',STR_PAD_LEFT);
                tms_notify_booking_owner($dbh, $bid, "Your date-change request for booking #$ref was approved.",
                    "Booking #$ref dates updated",
                    "<p>Your requested date change for booking <strong>#$ref</strong> has been approved and applied.</p>");
                $msg = "Date change approved for #BK$bid.";
                $msgType = 'success';
                // Audit log coverage (Round 52). Round 96 (item 47): $row was
                // already fetched above (before the UPDATE), so the prior
                // dates were sitting right there and just never logged.
                tms_log_activity($dbh, $_SESSION['alogin'], 'Booking Date-Change Approved', "BookingId=$bid, New dates: {$row->RequestedFromDate} to {$row->RequestedToDate}", 'booking', $bid, "{$row->FromDate} to {$row->ToDate}", "{$row->RequestedFromDate} to {$row->RequestedToDate}");
            } else {
                $msg = "No pending date-change request found for that booking.";
                $msgType = 'error';
            }
            break;
        case 'reject_mod':
            $note = tms_clean($_POST['note'] ?? '') ?: 'Requested dates were not available.';
            $dbh->prepare("UPDATE tblbooking SET RequestedFromDate=NULL, RequestedToDate=NULL, ModificationStatus='rejected', ModificationNote=:n WHERE BookingId=:id")
                ->execute([':n'=>$note, ':id'=>$bid]);
            $ref = 'BK'.str_pad($bid,3,'0',STR_PAD_LEFT);
            tms_notify_booking_owner($dbh, $bid, "Your date-change request for booking #$ref was declined: $note",
                "Booking #$ref date-change request declined",
                "<p>We were unable to approve your requested date change for booking <strong>#$ref</strong>.</p><p>$note</p>");
            $msg = "Date-change request declined for #BK$bid.";
            $msgType = 'success';
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Booking Date-Change Rejected', "BookingId=$bid, Note: $note");
            break;
        case 'refund_processed':
            // Round 96 (item 47): read the pre-change RefundStatus first --
            // never captured before, even though this is exactly the kind of
            // financial-status change an audit trail must cover.
            $prevRefundStmt = $dbh->prepare("SELECT RefundStatus FROM tblbooking WHERE BookingId=:id");
            $prevRefundStmt->execute([':id'=>$bid]);
            $prevRefundStatus = (string)$prevRefundStmt->fetchColumn();
            // GUARD (new "TMS V2" prompt -- booking state machine): this
            // action had no check that a refund was ever actually
            // requested, so a stray/duplicate click could mark
            // RefundStatus='processed' on a booking with no refund
            // request on file at all, corrupting the same audit trail the
            // Round 96 fix just above this was written to protect.
            if (!BookingStateMachine::canTransitionRefundStatus($prevRefundStatus, 'processed')) {
                $msg = "Booking #BK$bid has no pending refund request to process.";
                $msgType = 'error';
                break;
            }
            $dbh->prepare("UPDATE tblbooking SET RefundStatus='processed' WHERE BookingId=:id")->execute([':id'=>$bid]);
            // Keeps tblpayments/tblpayment_refunds truthful when this booking
            // was actually paid through payment.php's sandbox flow; a no-op
            // otherwise (see PaymentService::markRefundProcessed()).
            $paymentService->markRefundProcessed($bid);
            $ref = 'BK'.str_pad($bid,3,'0',STR_PAD_LEFT);
            tms_notify_booking_owner($dbh, $bid, "Your refund for booking #$ref has been processed.",
                "Refund processed for #$ref",
                "<p>Your refund for the cancelled booking <strong>#$ref</strong> has been processed.</p>");
            $msg = "Refund marked as processed for #BK$bid.";
            $msgType = 'success';
            // Audit log coverage (Round 52). A financial-status change is
            // exactly the category of action an audit trail must cover.
            tms_log_activity($dbh, $_SESSION['alogin'], 'Booking Refund Processed', "BookingId=$bid", 'booking', $bid, $prevRefundStatus, 'processed');
            break;
        case 'refund_rejected':
            $note = tms_clean($_POST['note'] ?? '') ?: 'Refund could not be verified.';
            // Round 96 (item 47): same reasoning as refund_processed above.
            $prevRefundStmt = $dbh->prepare("SELECT RefundStatus FROM tblbooking WHERE BookingId=:id");
            $prevRefundStmt->execute([':id'=>$bid]);
            $prevRefundStatus = (string)$prevRefundStmt->fetchColumn();
            // GUARD (new "TMS V2" prompt -- booking state machine): same
            // reasoning as refund_processed above -- don't let this mark a
            // refund 'rejected' when none was ever requested.
            if (!BookingStateMachine::canTransitionRefundStatus($prevRefundStatus, 'rejected')) {
                $msg = "Booking #BK$bid has no pending refund request to decline.";
                $msgType = 'error';
                break;
            }
            $dbh->prepare("UPDATE tblbooking SET RefundStatus='rejected', RefundNote=:n WHERE BookingId=:id")->execute([':n'=>$note, ':id'=>$bid]);
            $paymentService->markRefundRejected($bid, $note);
            $ref = 'BK'.str_pad($bid,3,'0',STR_PAD_LEFT);
            tms_notify_booking_owner($dbh, $bid, "Your refund request for booking #$ref was declined: $note",
                "Refund request declined for #$ref",
                "<p>We were unable to process a refund for booking <strong>#$ref</strong>.</p><p>$note</p>");
            $msg = "Refund declined for #BK$bid.";
            $msgType = 'success';
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Booking Refund Rejected', "BookingId=$bid, Note: $note", 'booking', $bid, $prevRefundStatus, 'rejected');
            break;
    }
}

/** Looks up which user owns a booking and sends them an in-app notification + email. */
function tms_notify_booking_owner(PDO $dbh, int $bookingId, string $message, string $emailSubject = '', string $emailHtml = ''): void {
    try {
        $stmt = $dbh->prepare(
            "SELECT b.UserEmail, u.FullName, u.NotificationsEnabled FROM tblbooking b
             LEFT JOIN tblusers u ON u.EmailId = b.UserEmail
             WHERE b.BookingId=:id"
        );
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row && $row->UserEmail) {
            // Always leave the in-app notification (bell icon) -- it's not
            // an interruption the way email is, and losing your only record
            // that a booking status changed would be a worse outcome than
            // this preference intends. Only the email half is gated by
            // NotificationsEnabled (Round 20, spec item 48 -- Privacy),
            // and only for this non-critical status-update category: a
            // NULL NotificationsEnabled (no tblusers row, e.g. a booking
            // made without an account) defaults to sending, matching the
            // column's own DEFAULT 1 rather than silently going quiet.
            tms_notify($dbh, $row->UserEmail, $message, 'tour-history.php');
            $wantsEmail = $row->NotificationsEnabled === null || (int)$row->NotificationsEnabled === 1;
            if ($emailSubject && $emailHtml && $wantsEmail) {
                tms_send_email($row->UserEmail, $row->FullName ?: $row->UserEmail, $emailSubject, tms_email_template($emailSubject, $emailHtml));
            }
        }
    } catch (Exception $e) {
        error_log('[TMS] Could not notify booking owner: ' . $e->getMessage());
    }
}

// --- Stats ---
function safe_val(PDO $db, string $sql): int {
    try { return (int)$db->query($sql)->fetchColumn(); } catch(Exception $e) { return 0; }
}
$total     = safe_val($dbh, "SELECT COUNT(*) FROM tblbooking");
$pending   = safe_val($dbh, "SELECT COUNT(*) FROM tblbooking WHERE status=0");
$confirmed = safe_val($dbh, "SELECT COUNT(*) FROM tblbooking WHERE status=1");
$cancelled = safe_val($dbh, "SELECT COUNT(*) FROM tblbooking WHERE status=2");

// --- Filters ---
$search     = tms_clean($_GET['search'] ?? '');
$filter_status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : null;

$where = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (b.UserEmail LIKE :s OR p.PackageName LIKE :s2 OR b.BookingId LIKE :s3)";
    $params[':s'] = "%$search%";
    $params[':s2'] = "%$search%";
    $params[':s3'] = "%$search%";
}
if ($filter_status !== null) {
    $where .= " AND b.status=:st";
    $params[':st'] = $filter_status;
}

// PERFORMANCE FIX (Section 9 audit): this table has real transactional
// data that only ever grows — unlike manage-packages.php (which already
// paginates a fixed-ish 400-package catalog), an unbounded booking list
// gets slower every single day the site is live. Same LIMIT/OFFSET +
// numbered-pager pattern already established in manage-packages.php.
$bookingPerPage = 25;
$bookingPage    = max(1, (int)($_GET['p'] ?? 1));

try {
    $countQ = $dbh->prepare("
        SELECT COUNT(*) FROM tblbooking b
        LEFT JOIN tbltourpackages p ON p.PackageId = b.PackageId
        $where
    ");
    $countQ->execute($params);
    $bookingTotal = (int)$countQ->fetchColumn();
    $bookingTotalPages = max(1, (int)ceil($bookingTotal / $bookingPerPage));
    $bookingPage = min($bookingPage, $bookingTotalPages);
    $bookingOffset = ($bookingPage - 1) * $bookingPerPage;

    $q = $dbh->prepare("
        SELECT b.BookingId, b.UserEmail, b.RegDate, b.status,
               b.ModificationStatus, b.RequestedFromDate, b.RequestedToDate, b.RefundStatus,
               p.PackageName, p.PackagePrice
        FROM tblbooking b
        LEFT JOIN tbltourpackages p ON p.PackageId = b.PackageId
        $where
        ORDER BY b.BookingId DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) { $q->bindValue($k, $v, PDO::PARAM_STR); }
    $q->bindValue(':lim', $bookingPerPage, PDO::PARAM_INT);
    $q->bindValue(':off', $bookingOffset, PDO::PARAM_INT);
    $q->execute();
    $bookings = $q->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {
    $bookings = [];
    $bookingTotal = 0; $bookingTotalPages = 1; $bookingPage = 1;
    $msg = "Error loading bookings: " . $e->getMessage();
    $msgType = 'error';
}

$statusLabel = [0=>'Pending', 1=>'Confirmed', 2=>'Cancelled'];
$statusClass = [0=>'status-pending', 1=>'status-confirmed', 2=>'status-cancelled'];
$statusIcon  = [0=>'fa-clock-o', 1=>'fa-check-circle', 2=>'fa-times-circle'];
?>

<div class="page-title-row">
  <h2><i class="fa fa-calendar-check-o" style="color:#176B50;margin-right:10px;"></i>Manage Bookings</h2>
  <span style="font-size:13px;color:var(--text-muted);"><?= count($bookings) ?> booking(s) found</span>
</div>

<?php if ($msg): ?>
<div class="alert-msg alert-<?= $msgType ?>">
  <i class="fa fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Stats Strip -->
<div class="stat-strip">
  <div class="mini-stat">
    <div class="val"><?= $total ?></div>
    <div class="lbl">Total Bookings</div>
  </div>
  <div class="mini-stat">
    <div class="val" style="color:#92400e;"><?= $pending ?></div>
    <div class="lbl">Pending</div>
  </div>
  <div class="mini-stat">
    <div class="val" style="color:#065f46;"><?= $confirmed ?></div>
    <div class="lbl">Confirmed</div>
  </div>
  <div class="mini-stat">
    <div class="val" style="color:#991b1b;"><?= $cancelled ?></div>
    <div class="lbl">Cancelled</div>
  </div>
</div>

<!-- Filters -->
<form method="get" class="filter-bar">
  <input type="text" name="search" placeholder="Search by email, package, ID..." value="<?= htmlspecialchars($search) ?>" style="min-width:240px;">
  <select name="status">
    <option value="">All Statuses</option>
    <option value="0" <?= $filter_status===0?'selected':'' ?>>Pending</option>
    <option value="1" <?= $filter_status===1?'selected':'' ?>>Confirmed</option>
    <option value="2" <?= $filter_status===2?'selected':'' ?>>Cancelled</option>
  </select>
  <button type="submit" class="btn-v6 primary"><i class="fa fa-search"></i> Filter</button>
  <?php if ($search || $filter_status !== null): ?>
    <a href="manage-bookings.php" class="btn-v6 ghost">Clear</a>
  <?php endif; ?>
</form>

<!-- Table -->
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead>
    <tr>
      <th>#</th>
      <th>Booking ID</th>
      <th>User Email</th>
      <th>Package</th>
      <th>Price</th>
      <th>Date</th>
      <th>Status</th>
      <th>Requests</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($bookings)): ?>
    <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">
      <i class="fa fa-calendar-o" style="font-size:32px;display:block;margin-bottom:10px;"></i>
      No bookings found.
    </td></tr>
  <?php else: ?>
    <?php $i=1; foreach ($bookings as $b): ?>
    <?php
      $st = (int)($b->status ?? 0);
      $bid = (int)$b->BookingId;
      $bcode = '#BK'.str_pad($bid,3,'0',STR_PAD_LEFT);
    ?>
    <tr>
      <td><?= $i++ ?></td>
      <td><strong style="color:#176B50;"><?= $bcode ?></strong></td>
      <td><?= htmlspecialchars($b->UserEmail ?? '—') ?></td>
      <td><?= htmlspecialchars($b->PackageName ?? '—') ?></td>
      <td><?= $b->PackagePrice ? tms_currency($b->PackagePrice) : '—' ?></td>
      <td><?= $b->RegDate ? date('d M Y', strtotime($b->RegDate)) : '—' ?></td>
      <td>
        <span class="booking-status <?= $statusClass[$st] ?? 'status-pending' ?>">
          <i class="fa <?= $statusIcon[$st] ?? 'fa-clock-o' ?>"></i>
          <?= $statusLabel[$st] ?? 'Unknown' ?>
        </span>
      </td>
      <td>
        <?php if ($b->ModificationStatus === 'pending'): ?>
          <div style="font-size:11.5px;color:#104B3A;margin-bottom:4px;">
            <i class="fa fa-exchange"></i> &rarr; <?= htmlspecialchars($b->RequestedFromDate) ?> / <?= htmlspecialchars($b->RequestedToDate) ?>
          </div>
          <div style="display:flex;gap:4px;">
            <form method="post" style="display:contents"><input type="hidden" name="action" value="approve_mod"><input type="hidden" name="id" value="<?= $bid ?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-confirm" onclick="return confirm('Approve this date change?')">Approve</button></form>
            <form method="post" style="display:contents" onsubmit="return rejectMod(this)"><input type="hidden" name="action" value="reject_mod"><input type="hidden" name="id" value="<?= $bid ?>"><input type="hidden" name="note"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-cancel">Reject</button></form>
          </div>
        <?php elseif ($b->RefundStatus === 'requested'): ?>
          <div style="font-size:11.5px;color:#92400e;margin-bottom:4px;"><i class="fa fa-undo"></i> Refund requested</div>
          <div style="display:flex;gap:4px;">
            <form method="post" style="display:contents"><input type="hidden" name="action" value="refund_processed"><input type="hidden" name="id" value="<?= $bid ?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-confirm" onclick="return confirm('Mark refund as processed?')">Processed</button></form>
            <form method="post" style="display:contents" onsubmit="return rejectRefund(this)"><input type="hidden" name="action" value="refund_rejected"><input type="hidden" name="id" value="<?= $bid ?>"><input type="hidden" name="note"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-cancel">Reject</button></form>
          </div>
        <?php elseif ($b->RefundStatus): ?>
          <span style="font-size:11.5px;color:var(--text-muted);"><?= ucfirst($b->RefundStatus) ?></span>
        <?php else: ?>
          <span style="font-size:11.5px;color:#cbd5e1;">—</span>
        <?php endif; ?>
      </td>
      <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap;">
          <?php if ($st !== 1): ?>
          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="id" value="<?= $bid ?>">
            <?php tms_csrf_field(); ?>
            <button type="submit" class="action-btn btn-confirm" onclick="return confirm('Confirm this booking?')">
              <i class="fa fa-check"></i> Confirm
            </button>
          </form>
          <?php endif; ?>
          <?php if ($st !== 2): ?>
          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" value="<?= $bid ?>">
            <?php tms_csrf_field(); ?>
            <button type="submit" class="action-btn btn-cancel" onclick="return confirm('Cancel this booking?')">
              <i class="fa fa-times"></i> Cancel
            </button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $bid ?>">
            <?php tms_csrf_field(); ?>
            <button type="submit" class="action-btn btn-delete" onclick="return confirm('Permanently delete this booking?')">
              <i class="fa fa-trash"></i>
            </button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<div style="font-size:12.5px;color:var(--text-muted);margin:10px 0;"><?php echo $bookingTotal; ?> booking<?php echo $bookingTotal!==1?'s':''; ?> total &nbsp;·&nbsp; page <?php echo $bookingPage; ?> of <?php echo $bookingTotalPages; ?></div>
<?php if ($bookingTotalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
    <?php for ($pn = 1; $pn <= $bookingTotalPages; $pn++):
        $qs = http_build_query(array_merge($_GET, ['p' => $pn])); ?>
    <a href="?<?php echo $qs; ?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;
        <?php echo $pn === $bookingPage ? 'background:#176B50;color:#fff;' : 'background:#f1f5f9;color:#334155;'; ?>"><?php echo $pn; ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
// These mirror the confirm/cancel links above (plain GET + confirm()) rather
// than introducing a new CSRF-protected POST pattern just for these two
// actions — consistent with how the rest of this file already works. A
// dedicated pass to move all of manage-bookings.php's actions to CSRF-
// protected POST requests is still a good idea (noted in earlier audits);
// this doesn't attempt that here.
function rejectMod(form) {
    var note = prompt('Reason for declining this date-change request (shown to the customer):', 'Requested dates are not available.');
    if (note === null) return false;
    form.note.value = note;
    return true;
}
function rejectRefund(form) {
    var note = prompt('Reason for declining this refund (shown to the customer):', 'Refund could not be verified.');
    if (note === null) return false;
    form.note.value = note;
    return true;
}
</script>
</body>
</html>

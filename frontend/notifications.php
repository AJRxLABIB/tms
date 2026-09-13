<?php
/**
 * frontend/notifications.php — full notification history (item 29: the
 * topbar bell in backend/header.php only ever showed the latest 8 rows in
 * a dropdown, with nothing linking to the rest). Reuses the exact same
 * `tbl_notifications` table and read/unread mechanism the dropdown already
 * uses -- no new schema, no new notification-creation path -- just a
 * paginated view of everything tms_notify()/tms_notify_all() have ever
 * written for this user.
 *
 * Deliberately does NOT add per-event-type filtering or channel
 * preferences (also asked for by item 29's "support user preferences for
 * channels") -- tbl_notifications has no category/event-type column to
 * filter by (every notification is a free-text Message + Link), and
 * channel preferences (email vs in-app, per event type) is a separate,
 * larger feature this project has no groundwork for yet (no preference
 * storage, no per-channel delivery code). Both are left as an honest,
 * disclosed gap rather than bolted on here.
 */
require('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];

// Mirror the topbar dropdown's own behavior (see tmsToggleNotif() in
// backend/header.php / frontend/mark-notifications-read.php): opening the
// notification view marks everything read, whether that's the small
// dropdown or this full history page.
try {
    $dbh->prepare("UPDATE tbl_notifications SET IsRead=1 WHERE UserEmail=:e AND IsRead=0")
        ->execute([':e' => $email]);
} catch (Exception $e) {
    error_log('[TMS Notify] notifications.php mark-read failed: ' . $e->getMessage());
}

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$total = 0;
try {
    $totalQ = $dbh->prepare("SELECT COUNT(*) FROM tbl_notifications WHERE UserEmail=:e");
    $totalQ->execute([':e' => $email]);
    $total = (int)$totalQ->fetchColumn();
} catch (Exception $e) {
    $total = 0;
}
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$items = [];
try {
    $listQ = $dbh->prepare(
        "SELECT Message, Link, IsRead, CreatedAt FROM tbl_notifications
         WHERE UserEmail=:e ORDER BY id DESC LIMIT :lim OFFSET :off"
    );
    $listQ->bindValue(':e', $email);
    $listQ->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $listQ->bindValue(':off', $offset, PDO::PARAM_INT);
    $listQ->execute();
    $items = $listQ->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {
    $items = [];
}
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<title>TMS | Notifications</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.notif-box{background:var(--white);border-radius:12px;padding:8px 24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:24px;}
.notif-row{display:block;padding:14px 0;border-bottom:1px solid #eef1f6;font-size:14px;text-decoration:none;color:inherit;}
.notif-row:last-child{border-bottom:none;}
.notif-row:hover{text-decoration:none;color:inherit;background:#fafbfd;}
.notif-row .notif-msg{font-weight:500;}
.notif-row.was-unread .notif-msg{font-weight:700;}
.notif-row .notif-time{font-size:12px;color:#98a2b3;margin-top:3px;}
.notif-pager{display:flex;justify-content:center;gap:8px;margin:20px 0 40px;}
.notif-pager a, .notif-pager span{padding:6px 14px;border-radius:8px;font-size:13px;text-decoration:none;}
.notif-pager a{background:var(--white);border:1px solid #e2e8f0;color:var(--secondary);}
.notif-pager span.current{background:var(--secondary);color:#fff;}
.notif-pager a.disabled{pointer-events:none;opacity:0.4;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title"><div class="container"><h2 style="margin:0;"><i class="fa fa-bell"></i> Notifications</h2></div></div>
<div class="container" style="margin-bottom:20px;">
  <div class="notif-box">
    <?php if (empty($items)): ?>
      <p style="color:#888;padding:20px 0;">You're all caught up — no notifications yet.</p>
    <?php else: foreach ($items as $n): ?>
      <a class="notif-row<?php echo $n->IsRead ? '' : ' was-unread'; ?>" href="<?php echo htmlspecialchars($n->Link ?: '#', ENT_QUOTES, 'UTF-8'); ?>">
        <div class="notif-msg"><?php echo htmlspecialchars($n->Message, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="notif-time"><?php echo htmlspecialchars((string)$n->CreatedAt, ENT_QUOTES, 'UTF-8'); ?></div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="notif-pager">
    <a class="<?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo $page - 1; ?>"><i class="fa fa-chevron-left"></i> Newer</a>
    <span class="current">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    <a class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?page=<?php echo $page + 1; ?>">Older <i class="fa fa-chevron-right"></i></a>
  </div>
  <?php endif; ?>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

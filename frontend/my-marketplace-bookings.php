<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

/**
 * my-marketplace-bookings.php — Round 37 (items 12/13).
 *
 * A single "my bookings" view for the two new standalone product types
 * (tblhotel_bookings/tblactivity_bookings), the same way tour-history.php
 * covers tblbooking. Deliberately simpler than tour-history.php: these
 * sample-data bookings have no real payment gateway integration behind
 * them (nothing is actually charged), so there's no refund/payment-status
 * logic here -- just create, view, and cancel (>24h-before rule, same
 * convention as package bookings).
 */

$email = $_SESSION['login'];
$error = '';
$msg   = '';

if (isset($_POST['cancel_hotel'])) {
    tms_csrf_validate();
    $bid = tms_int($_POST['bkid'] ?? 0, 1);
    $chk = $dbh->prepare("SELECT BookingId, CheckInDate FROM tblhotel_bookings WHERE BookingId=:b AND UserEmail=:e AND status != 2 LIMIT 1");
    $chk->execute([':b' => $bid, ':e' => $email]);
    $b = $chk->fetch(PDO::FETCH_OBJ);
    if ($b) {
        $hoursUntil = (new DateTime($b->CheckInDate))->getTimestamp() - (new DateTime())->getTimestamp();
        if ($hoursUntil / 3600 > 24) {
            $dbh->prepare("UPDATE tblhotel_bookings SET status=2 WHERE BookingId=:b")->execute([':b' => $bid]);
            $msg = 'Hotel booking cancelled.';
        } else {
            $error = 'Hotel bookings can only be cancelled more than 24 hours before check-in.';
        }
    } else {
        $error = 'Booking not found.';
    }
}

if (isset($_POST['cancel_activity'])) {
    tms_csrf_validate();
    $bid = tms_int($_POST['bkid'] ?? 0, 1);
    $chk = $dbh->prepare("SELECT BookingId, ActivityDate FROM tblactivity_bookings WHERE BookingId=:b AND UserEmail=:e AND status != 2 LIMIT 1");
    $chk->execute([':b' => $bid, ':e' => $email]);
    $b = $chk->fetch(PDO::FETCH_OBJ);
    if ($b) {
        $hoursUntil = (new DateTime($b->ActivityDate))->getTimestamp() - (new DateTime())->getTimestamp();
        if ($hoursUntil / 3600 > 24) {
            $dbh->prepare("UPDATE tblactivity_bookings SET status=2 WHERE BookingId=:b")->execute([':b' => $bid]);
            $msg = 'Activity booking cancelled.';
        } else {
            $error = 'Activity bookings can only be cancelled more than 24 hours before the activity date.';
        }
    } else {
        $error = 'Booking not found.';
    }
}

$hotelBookings = $dbh->prepare(
    "SELECT hb.*, h.HotelName, h.City, h.Country, h.HotelImage
     FROM tblhotel_bookings hb JOIN tblhotels h ON h.HotelId = hb.HotelId
     WHERE hb.UserEmail=:e ORDER BY hb.RegDate DESC"
);
$hotelBookings->execute([':e' => $email]);
$hotelBookings = $hotelBookings->fetchAll(PDO::FETCH_OBJ);

$activityBookings = $dbh->prepare(
    "SELECT ab.*, a.ActivityName, a.City, a.Country, a.ActivityImage
     FROM tblactivity_bookings ab JOIN tblactivities a ON a.ActivityId = ab.ActivityId
     WHERE ab.UserEmail=:e ORDER BY ab.RegDate DESC"
);
$activityBookings->execute([':e' => $email]);
$activityBookings = $activityBookings->fetchAll(PDO::FETCH_OBJ);

$statusMeta = [
    0 => ['label' => 'Pending',   'color' => '#d97706', 'bg' => '#fef3c7'],
    1 => ['label' => 'Confirmed', 'color' => '#059669', 'bg' => '#d1fae5'],
    2 => ['label' => 'Cancelled', 'color' => '#dc2626', 'bg' => '#fee2e2'],
];
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<title>TMS | My Marketplace Bookings</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<style>
.mb-card{background:var(--white);border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.07);padding:18px 22px;margin-bottom:14px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;}
.mb-card img{width:80px;height:80px;object-fit:cover;border-radius:8px;}
.mb-status{padding:4px 12px;border-radius:14px;font-size:12px;font-weight:700;}
.mb-cancel{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;border-radius:6px;padding:6px 14px;font-size:12.5px;cursor:pointer;}
.alert-error{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:16px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px;margin-bottom:16px;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="banner-3"><div class="container"><h1 class="wow zoomIn animated" data-wow-delay=".1s">My Marketplace Bookings</h1></div></div>
<div class="container" style="padding:30px 15px;">
    <?php if ($error): ?><div class="alert-error"><i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert-success"><i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <h3 style="color:var(--primary);margin-bottom:14px;"><i class="fa fa-hotel"></i> Hotel Bookings</h3>
    <?php if (empty($hotelBookings)): ?>
    <p style="color:var(--text-muted);">No hotel bookings yet. <a href="hotels.php">Browse Hotels →</a></p>
    <?php else: foreach ($hotelBookings as $hb): $sm = $statusMeta[(int)$hb->status] ?? $statusMeta[0]; ?>
    <div class="mb-card">
        <img src="<?php echo htmlspecialchars($hb->HotelImage ?: 'images/no-image-placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='images/no-image-placeholder.jpg'" alt="">
        <div style="flex:1;min-width:200px;">
            <div style="font-weight:700;color:var(--text);"><?php echo htmlspecialchars($hb->HotelName, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);"><?php echo htmlspecialchars($hb->City.', '.$hb->Country, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($hb->CheckInDate, ENT_QUOTES, 'UTF-8'); ?> → <?php echo htmlspecialchars($hb->CheckOutDate, ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int)$hb->NumRooms; ?> room(s), <?php echo (int)$hb->NumGuests; ?> guest(s)</div>
            <div style="font-size:12.5px;color:var(--text-muted);">Code: <strong><?php echo htmlspecialchars($hb->VerificationCode, ENT_QUOTES, 'UTF-8'); ?></strong> · <?php echo tms_currency((float)$hb->TotalCost); ?></div>
        </div>
        <span class="mb-status" style="background:<?php echo $sm['bg']; ?>;color:<?php echo $sm['color']; ?>;"><?php echo $sm['label']; ?></span>
        <?php if ((int)$hb->status !== 2): ?>
        <form method="post" onsubmit="return confirm('Cancel this hotel booking?');">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="bkid" value="<?php echo (int)$hb->BookingId; ?>">
            <button type="submit" name="cancel_hotel" class="mb-cancel">Cancel</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>

    <h3 style="color:var(--primary);margin:28px 0 14px;"><i class="fa fa-map-signs"></i> Activity Bookings</h3>
    <?php if (empty($activityBookings)): ?>
    <p style="color:var(--text-muted);">No activity bookings yet. <a href="activities.php">Browse Activities →</a></p>
    <?php else: foreach ($activityBookings as $ab): $sm = $statusMeta[(int)$ab->status] ?? $statusMeta[0]; ?>
    <div class="mb-card">
        <img src="<?php echo htmlspecialchars($ab->ActivityImage ?: 'images/no-image-placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='images/no-image-placeholder.jpg'" alt="">
        <div style="flex:1;min-width:200px;">
            <div style="font-weight:700;color:var(--text);"><?php echo htmlspecialchars($ab->ActivityName, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);"><?php echo htmlspecialchars($ab->City.', '.$ab->Country, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($ab->ActivityDate, ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int)$ab->NumParticipants; ?> participant(s)</div>
            <div style="font-size:12.5px;color:var(--text-muted);">Code: <strong><?php echo htmlspecialchars($ab->VerificationCode, ENT_QUOTES, 'UTF-8'); ?></strong> · <?php echo tms_currency((float)$ab->TotalCost); ?></div>
        </div>
        <span class="mb-status" style="background:<?php echo $sm['bg']; ?>;color:<?php echo $sm['color']; ?>;"><?php echo $sm['label']; ?></span>
        <?php if ((int)$ab->status !== 2): ?>
        <form method="post" onsubmit="return confirm('Cancel this activity booking?');">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="bkid" value="<?php echo (int)$ab->BookingId; ?>">
            <button type="submit" name="cancel_activity" class="mb-cancel">Cancel</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>
<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
<?php include('backend/signin.php'); ?>
</body>
</html>

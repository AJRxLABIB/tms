<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
// Public browsing, same pattern as package-details.php -- only the booking
// POST below requires sign-in.

$error = '';
$msg   = '';
$hotelId = tms_int($_GET['id'] ?? 0, 1);

$hq = $dbh->prepare("SELECT * FROM tblhotels WHERE HotelId=:id AND Status=1 LIMIT 1");
$hq->execute([':id' => $hotelId]);
$hotel = $hq->fetch(PDO::FETCH_OBJ);

if (!$hotel) {
    http_response_code(404);
    include('includes/header.php');
    echo '<div class="container" style="padding:60px 20px;text-align:center;color:#aaa;"><i class="fa fa-hotel" style="font-size:50px;display:block;margin-bottom:16px;"></i><h3>Hotel not found</h3><p><a href="hotels.php">Back to Hotels</a></p></div>';
    include('includes/footer.php');
    exit;
}

if (isset($_POST['book_hotel'])) {
    tms_csrf_validate();
    if (empty($_SESSION['login'])) {
        $error = 'You must be signed in to book a hotel.';
    } else {
        $email    = $_SESSION['login'];
        $checkin  = tms_clean($_POST['checkin'] ?? '');
        $checkout = tms_clean($_POST['checkout'] ?? '');
        $rooms    = tms_int($_POST['rooms'] ?? 1, 1, 10);
        $guests   = tms_int($_POST['guests'] ?? 1, 1, 20);
        $mobile   = tms_clean($_POST['mobile'] ?? '');
        $comment  = tms_clean($_POST['comment'] ?? '');

        $in  = DateTime::createFromFormat('Y-m-d', $checkin);
        $out = DateTime::createFromFormat('Y-m-d', $checkout);
        $now = new DateTime('today');

        if (!$in || !$out) {
            $error = 'Please enter valid check-in and check-out dates.';
        } elseif ($in < $now) {
            $error = 'Check-in date must be today or later.';
        } elseif ($out <= $in) {
            $error = 'Check-out date must be after check-in.';
        } elseif ((int)$hotel->PricePerNight <= 0) {
            $error = "This hotel doesn't have a price set yet -- please contact support.";
        } else {
            $nights = (int)$out->diff($in)->days;
            $total  = (int)$hotel->PricePerNight * $nights * $rooms;
            $verificationCode = strtoupper(bin2hex(random_bytes(5)));

            $ins = $dbh->prepare(
                "INSERT INTO tblhotel_bookings(HotelId,UserEmail,CheckInDate,CheckOutDate,NumRooms,NumGuests,MobileNumber,Comment,TotalCost,VerificationCode,status)
                 VALUES(:hid,:email,:in,:out,:rooms,:guests,:mobile,:comment,:total,:vcode,0)"
            );
            $ins->execute([
                ':hid' => $hotelId, ':email' => $email, ':in' => $in->format('Y-m-d'), ':out' => $out->format('Y-m-d'),
                ':rooms' => $rooms, ':guests' => $guests, ':mobile' => $mobile ?: null, ':comment' => $comment,
                ':total' => $total, ':vcode' => $verificationCode,
            ]);
            $newId = $dbh->lastInsertId();
            if ($newId) {
                // Same loyalty mechanic as package bookings (checkout.php /
                // package-details.php), at half the points -- a hotel-only
                // booking is a smaller-value transaction than a full package.
                $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,50,50)
                    ON DUPLICATE KEY UPDATE Points=Points+50, TotalEarned=TotalEarned+50")->execute([':e'=>$email]);
                try {
                    $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,50,:d)")
                        ->execute([':e'=>$email, ':d'=>'Hotel booking #HB'.$newId.' confirmed']);
                } catch (Exception $e) { error_log('[TMS Loyalty] Hotel booking log insert failed: '.$e->getMessage()); }

                $msg = 'Hotel booked successfully! Verification code: <strong>'.htmlspecialchars($verificationCode, ENT_QUOTES, 'UTF-8').'</strong>. '
                     . 'View it in <a href="my-marketplace-bookings.php">My Marketplace Bookings</a>. You earned <strong>50 loyalty points!</strong>';
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<title>TMS | <?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.hd-wrap{background:var(--white);border-radius:10px;box-shadow:0 2px 14px rgba(0,0,0,.07);overflow:hidden;margin-bottom:24px;}
.hd-img{width:100%;height:340px;object-fit:cover;}
.hd-body{padding:26px;}
.hd-body h1{font-size:26px;color:var(--primary);margin-bottom:6px;}
.form-group label{font-weight:600;font-size:13px;display:block;margin-bottom:4px;}
.form-control{border:1.5px solid #e0e0e0;border-radius:7px;height:42px;font-size:14px;width:100%;padding:0 12px;}
.btn-book{background:var(--secondary);color:#fff;border:none;border-radius:7px;height:44px;padding:0 26px;font-weight:700;width:100%;margin-top:6px;}
.alert-error{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:16px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px;margin-bottom:16px;}
</style>
</head>
<body>
<?php include('includes/header.php'); ?>

<div class="banner-3"><div class="container"><h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?></h1></div></div>

<div class="container" style="padding:30px 15px;">
    <div class="tms-sample-banner">
        <i class="fa fa-info-circle"></i> This is a <strong>sample/demo hotel listing</strong> used to showcase the TMS hotel marketplace feature -- not a real, live-supplier property. A booking here creates a real record in your account for demonstration purposes.
    </div>

    <?php if ($error): ?><div class="alert-error"><i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert-success"><i class="fa fa-check-circle"></i> <?php echo $msg; /* contains safe, hardcoded HTML + one escaped var */ ?></div><?php endif; ?>

    <div class="row">
        <div class="col-md-7">
            <div class="hd-wrap">
                <img src="<?php echo htmlspecialchars($hotel->HotelImage ?: 'images/no-image-placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='images/no-image-placeholder.jpg'" class="hd-img" alt="<?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="hd-body">
                    <span class="tms-sample-badge"><i class="fa fa-flask"></i> Sample Listing</span>
                    <h1 style="margin-top:10px;"><?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlspecialchars($hotel->City.', '.$hotel->Country, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><?php for ($s=1;$s<=5;$s++): ?><i class="fa fa-star<?php echo $s<=(int)$hotel->StarRating?'':'-o'; ?>" style="color:#f0ad4e;"></i><?php endfor; ?>
                    &nbsp; <?php if ((float)$hotel->AvgRating>0): ?><span style="color:#888;font-size:13px;"><?php echo number_format((float)$hotel->AvgRating,1); ?>/5 guest rating</span><?php endif; ?></p>
                    <p style="color:var(--text-muted);"><?php echo nl2br(htmlspecialchars($hotel->Description ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                    <p><i class="fa fa-list" style="color:#27ae60"></i> <strong>Amenities:</strong> <?php echo htmlspecialchars($hotel->Amenities ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <h3 style="color:var(--primary);margin-top:16px;"><?php echo tms_currency((float)$hotel->PricePerNight); ?> <span style="font-size:13px;color:#999;font-weight:400;">/night</span></h3>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="hd-wrap">
                <div class="hd-body">
                    <h4 style="margin-bottom:14px;"><i class="fa fa-calendar-check-o"></i> Book This Hotel</h4>
                    <?php if (empty($_SESSION['login'])): ?>
                    <p style="color:var(--text-muted);font-size:14px;">Please <a href="javascript:void(0)" onclick="openModal('signinModal')">sign in</a> to book this hotel.</p>
                    <?php else: ?>
                    <form method="post" action="hotel-details.php?id=<?php echo $hotelId; ?>">
                        <?php tms_csrf_field(); ?>
                        <div class="form-group">
                            <label>Check-in Date</label>
                            <input type="date" name="checkin" class="form-control" id="hdCheckin" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Check-out Date</label>
                            <input type="date" name="checkout" class="form-control" id="hdCheckout" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Rooms</label>
                            <input type="number" name="rooms" class="form-control" value="1" min="1" max="10" required>
                        </div>
                        <div class="form-group">
                            <label>Guests</label>
                            <input type="number" name="guests" class="form-control" value="1" min="1" max="20" required>
                        </div>
                        <div class="form-group">
                            <label>Mobile Number <small style="color:#aaa;">(optional)</small></label>
                            <input type="tel" name="mobile" class="form-control" placeholder="e.g. 017XXXXXXXX" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label>Special Requests <small style="color:#aaa;">(optional)</small></label>
                            <textarea name="comment" class="form-control" style="height:70px;padding:10px 12px;"></textarea>
                        </div>
                        <button type="submit" name="book_hotel" class="btn-book"><i class="fa fa-check-circle"></i> Book Now</button>
                    </form>
                    <script>
                    (function(){
                        var ci = document.getElementById('hdCheckin'), co = document.getElementById('hdCheckout');
                        ci.addEventListener('change', function(){
                            if (ci.value) {
                                var next = new Date(ci.value); next.setDate(next.getDate()+1);
                                co.min = next.toISOString().slice(0,10);
                                if (co.value && co.value <= ci.value) co.value = co.min;
                            }
                        });
                    })();
                    </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <p style="margin-top:10px;"><a href="hotels.php"><i class="fa fa-arrow-left"></i> Back to Hotels</a></p>
</div>

<?php include('includes/footer.php'); ?>
<?php include('includes/write-us.php'); ?>
<?php include('includes/signin.php'); ?>
</body>
</html>

<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once __DIR__ . '/../backend/Seo.php';
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
    include('backend/header.php');
    echo '<div class="container" style="padding:60px 20px;text-align:center;color:var(--text-muted);"><i class="fa fa-hotel" style="font-size:50px;display:block;margin-bottom:16px;"></i><h3>Hotel not found</h3><p><a href="hotels.php">Back to Hotels</a></p></div>';
    include('backend/footer.php');
    exit;
}

// NEW (Round 80, master prompt item 12 -- Hotel System "reviews"
// requirement): real reviews for this hotel, same pattern as
// package-details.php's "Recent Reviews" section. See frontend/reviews.php
// for the write flow (reviews.php?hotel=ID pre-selects this hotel).
$hRevQ = $dbh->prepare("SELECT r.*, u.FullName as UserFullName
    FROM tblreviews r LEFT JOIN tblusers u ON r.UserEmail=u.EmailId
    WHERE r.HotelId=:hid AND r.Status=1 ORDER BY r.id DESC LIMIT 5");
$hRevQ->execute([':hid' => $hotelId]);
$hotelReviews = $hRevQ->fetchAll(PDO::FETCH_OBJ);
$hrcQ = $dbh->prepare("SELECT COUNT(*) FROM tblreviews WHERE HotelId=:hid AND Status=1");
$hrcQ->execute([':hid' => $hotelId]);
$hotelReviewCount = (int)$hrcQ->fetchColumn();

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

            // Slot-managed hotels (tbl_inventory_slots has rows for it): reserve every night atomically in the SAME
            // transaction as the booking insert and take the price from the slots. Unmanaged hotels skip all of this
            // and behave exactly as before.
            require_once __DIR__ . '/../backend/services/InventoryService.php';
            $invManaged = InventoryService::isManaged($dbh, 'hotel', (int)$hotelId);
            $newId = 0;
            try {
                if ($invManaged) {
                    $nightDates = [];
                    for ($nd = clone $in; $nd < $out; $nd->modify('+1 day')) { $nightDates[] = $nd->format('Y-m-d'); }
                    $invHolder = substr((string)$email, 0, 100) . '#' . $verificationCode;
                    $dbh->beginTransaction();
                    $invRes = InventoryService::reserveForBooking($dbh, 'hotel', (int)$hotelId, $nightDates, $rooms, $invHolder, (int)$hotel->PricePerNight);
                    if ($invRes['status'] !== 'ok') {
                        $dbh->rollBack();
                        $error = $invRes['status'] === 'unavailable'
                            ? ($invRes['reason'] === 'too_long' ? 'Inventory-managed stays are limited to 62 nights.' : InventoryService::reasonMessage((string)($invRes['reason'] ?? ''), (string)($invRes['date'] ?? '')))
                            : 'Could not check availability right now. Please try again.';
                    } else {
                        $total = (int)$invRes['total'];   // authoritative price (slot override or listing price), not client-derived
                    }
                }
                if ($error === '') {
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
                    if ($invManaged) {
                        if ($newId && InventoryService::confirmReservation($dbh, $invRes['hold_ids'], $invHolder, InventoryService::bookingRef('hotel', (int)$newId))) {
                            $dbh->commit();
                        } else {
                            $dbh->rollBack(); $newId = 0;
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($dbh->inTransaction()) $dbh->rollBack();
                error_log('[TourBuddy Hotel booking] ' . $e->getMessage());
                $newId = 0;
                if ($invManaged) { $error = 'Something went wrong. Please try again.'; } else { throw $e; }
            }
            if ($newId) {
                // Same loyalty mechanic as package bookings (checkout.php /
                // package-details.php), at half the points -- a hotel-only
                // booking is a smaller-value transaction than a full package.
                $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,50,50)
                    ON DUPLICATE KEY UPDATE Points=Points+50, TotalEarned=TotalEarned+50")->execute([':e'=>$email]);
                try {
                    $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,50,:d)")
                        ->execute([':e'=>$email, ':d'=>'Hotel booking #HB'.$newId.' confirmed']);
                } catch (Exception $e) { error_log('[TourBuddy Loyalty] Hotel booking log insert failed: '.$e->getMessage()); }

                $msg = 'Hotel booked successfully! Verification code: <strong>'.htmlspecialchars($verificationCode, ENT_QUOTES, 'UTF-8').'</strong>. '
                     . 'View it in <a href="my-marketplace-bookings.php">My Marketplace Bookings</a>. You earned <strong>50 loyalty points!</strong>';
            } elseif ($error === '') {
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
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | <?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?></title>
<?php
// SEO meta broadening (master prompt item 53), same pattern as
// package-details.php/blog-post.php: ?id= is this page's resource
// identifier, not a filter, so it's kept in the canonical explicitly
// (tms_seo_meta()'s default strips the whole query string).
// RULE 3: structured data only for NON-sample rows (tms_seo_hotel_jsonld() returns null for IsSampleData=1).
// Original note: no JSON-LD/structured data for demo rows on purpose. tblhotels is explicitly
// sample/demo data (see this page's own "sample/demo hotel listing"
// banner above) -- emitting schema.org Hotel/Offer/AggregateRating markup
// would tell search engines this is a real, ratable, bookable-today
// property, which it is not. Plain title/description only.
$hotelSeoDesc = trim((string)($hotel->Description ?? ''));
if ($hotelSeoDesc === '') {
    $hotelSeoDesc = 'Sample/demo hotel listing in ' . $hotel->City . ', ' . $hotel->Country . ' for the TourBuddy hotel marketplace feature.';
}
$hdScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hdHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hdPath   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
tms_seo_meta([
    'title'       => $hotel->HotelName . ' — ' . $hotel->City,
    'description' => $hotelSeoDesc,
    'image'       => $hotel->HotelImage ?: '',
    'canonical'   => $hdScheme . '://' . $hdHost . $hdPath . '?id=' . (int)$hotelId,
    'jsonld'      => tms_seo_hotel_jsonld($hotel, $hdScheme . '://' . $hdHost . $hdPath . '?id=' . (int)$hotelId),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Hotels', 'url' => 'hotels.php'],
        ['label' => $hotel->HotelName],
    ],
]);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
.review-mini{background:#f8f9fa;border-radius:8px;padding:14px;margin-bottom:12px;}
.review-mini h6{font-size:13px;font-weight:700;color:#333;margin:0 0 4px;}
.stars-sm{color:#f0ad4e;font-size:13px;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<link rel="stylesheet" href="<?php echo tms_asset_ver('css/tms-pages.css'); ?>">

<div class="banner-3"><div class="container"><h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?></h1></div></div>

<div class="container" style="padding:30px 15px;">
    <?php tms_render_breadcrumb_nav([
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Hotels', 'url' => 'hotels.php'],
        ['label' => $hotel->HotelName],
    ]); ?>
    <div class="tms-sample-banner">
        <i class="fa fa-info-circle"></i> This is a <strong>sample/demo hotel listing</strong> used to showcase the TourBuddy hotel marketplace feature -- not a real, live-supplier property. A booking here creates a real record in your account for demonstration purposes.
    </div>

    <?php if ($error): ?><div class="alert-error"><i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert-success"><i class="fa fa-check-circle"></i> <?php echo $msg; /* contains safe, hardcoded HTML + one escaped var */ ?></div><?php endif; ?>

    <div class="row tms-detail-grid">
        <div class="col-md-7">
            <div class="hd-wrap">
                <img src="<?php echo htmlspecialchars($hotel->HotelImage ?: 'images/no-image-placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='images/no-image-placeholder.jpg'" class="hd-img" alt="<?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="hd-body">
                    <span class="tms-sample-badge"><i class="fa fa-flask"></i> Sample Listing</span>
                    <?php
                    // Availability badge only where inventory slots exist. InventorySource decides the label; a sample/demo
                    // listing is always "Demo data" and never shows availability numbers.
                    try {
                        require_once __DIR__ . '/../backend/services/InventoryService.php';
                        require_once __DIR__ . '/../backend/services/InventorySource.php';
                        if (InventoryService::isManaged($dbh, 'hotel', (int)$hotelId)) {
                            $invLabel = InventorySource::labelFor($dbh, 'hotel', (int)$hotelId);
                            echo InventoryService::badgeHtml($invLabel);
                            if (!in_array($invLabel['badge'], ['demo'], true)) {
                                $invSum = InventoryService::summaryForNextDays($dbh, 'hotel', (int)$hotelId, 14);
                                if ($invSum) echo ' <small style="color:var(--text-muted);">' . ($invSum['slots_open'] > 0
                                    ? (int)$invSum['slots_open'] . ' bookable night(s) in the next ' . (int)$invSum['days'] . ' days' . ($invLabel['stale'] ? ' (data may be out of date)' : '')
                                    : 'No bookable nights in the next ' . (int)$invSum['days'] . ' days') . '</small>';
                            }
                        }
                    } catch (Throwable $e) { error_log('[TourBuddy] inventory badge: ' . $e->getMessage()); }
                    ?>
                    <h1 style="margin-top:10px;"><?php echo htmlspecialchars($hotel->HotelName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlspecialchars($hotel->City.', '.$hotel->Country, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><?php for ($s=1;$s<=5;$s++): ?><i class="fa fa-star<?php echo $s<=(int)$hotel->StarRating?'':'-o'; ?>" style="color:#f0ad4e;"></i><?php endfor; ?>
                    &nbsp; <?php if ((float)$hotel->AvgRating>0): ?><span style="color:var(--text-muted);font-size:13px;"><?php echo number_format((float)$hotel->AvgRating,1); ?>/5 guest rating<?php if ($hotelReviewCount > 0): ?> (<?php echo $hotelReviewCount; ?> review<?php echo $hotelReviewCount===1?'':'s'; ?>)<?php endif; ?></span><?php endif; ?></p>
                    <p style="color:var(--text-muted);"><?php echo nl2br(htmlspecialchars($hotel->Description ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                    <p><i class="fa fa-list" style="color:#27ae60"></i> <strong>Amenities:</strong> <?php echo htmlspecialchars($hotel->Amenities ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <h3 style="color:var(--primary);margin-top:16px;"><?php echo tms_currency((float)$hotel->PricePerNight); ?> <span style="font-size:13px;color:var(--text-muted);font-weight:400;">/night</span></h3>
                </div>
            </div>

            <!-- NEW (Round 80, master prompt item 12 -- "reviews"): same
                 pattern as package-details.php's Recent Reviews card. -->
            <?php if (count($hotelReviews) > 0): ?>
            <div class="hd-wrap">
                <div class="hd-body">
                    <h4 style="font-weight:700;margin-bottom:16px;"><i class="fa fa-star" style="color:#f0ad4e"></i> Recent Reviews</h4>
                    <?php foreach ($hotelReviews as $rev): ?>
                    <div class="review-mini">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <h6><?php echo htmlspecialchars($rev->UserName, ENT_QUOTES, 'UTF-8'); ?></h6>
                            <div class="stars-sm"><?php for ($i=1;$i<=5;$i++) echo $i<=$rev->Rating?'★':'☆'; ?></div>
                        </div>
                        <p style="font-size:13px;color:#666;margin:4px 0 0;"><?php echo htmlspecialchars(substr($rev->ReviewText,0,150), ENT_QUOTES, 'UTF-8'); ?>…</p>
                    </div>
                    <?php endforeach; ?>
                    <a href="reviews.php?hotel=<?php echo $hotelId; ?>" style="font-size:13px;color:var(--secondary);">View all reviews <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-md-5 tms-book-col" id="tmsBook">
            <div class="hd-wrap">
                <div class="hd-body">
                    <h4 style="margin-bottom:14px;"><i class="fa fa-calendar-check-o"></i> Book This Hotel</h4>
                    <?php if (empty($_SESSION['login'])): ?>
                    <p style="color:var(--text-muted);font-size:14px;">Please <a href="javascript:void(0)" onclick="openModal('signinModal')">sign in</a> to book this hotel.</p>
                    <?php else: ?>
                    <form method="post" action="hotel-details.php?id=<?php echo $hotelId; ?>">
                        <?php tms_csrf_field(); ?>
                        <div class="form-group">
                            <label for="hdCheckin">Check-in Date</label>
                            <input type="date" name="checkin" class="form-control" id="hdCheckin" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="hdCheckout">Check-out Date</label>
                            <input type="date" name="checkout" class="form-control" id="hdCheckout" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="hdRooms">Rooms</label>
                            <input type="number" name="rooms" id="hdRooms" class="form-control" value="1" min="1" max="10" required>
                        </div>
                        <div class="form-group">
                            <label for="hdGuests">Guests</label>
                            <input type="number" name="guests" id="hdGuests" class="form-control" value="1" min="1" max="20" required>
                        </div>
                        <div class="form-group">
                            <label for="hdMobile">Mobile Number <small style="color:var(--text-muted);">(optional)</small></label>
                            <input type="tel" name="mobile" id="hdMobile" class="form-control" placeholder="e.g. 017XXXXXXXX" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label for="hdComment">Special Requests <small style="color:var(--text-muted);">(optional)</small></label>
                            <textarea name="comment" id="hdComment" class="form-control" style="height:70px;padding:10px 12px;"></textarea>
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
    <p style="margin-top:10px;">
        <a href="hotels.php"><i class="fa fa-arrow-left"></i> Back to Hotels</a>
        &nbsp;·&nbsp;
        <a href="reviews.php?hotel=<?php echo $hotelId; ?>"><i class="fa fa-star"></i> Write a Review</a>
    </p>
</div>

<div class="tms-actionbar" role="region" aria-label="Booking shortcut">
    <span class="tms-actionbar-price"><?php echo tms_currency((float)$hotel->PricePerNight); ?> <small>/night</small></span>
    <a class="tms-actionbar-btn" href="#tmsBook">Book now</a>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
<?php include('backend/signin.php'); ?>
</body>
</html>

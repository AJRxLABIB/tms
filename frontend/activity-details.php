<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');

$error = '';
$msg   = '';
$activityId = tms_int($_GET['id'] ?? 0, 1);

$aq = $dbh->prepare("SELECT * FROM tblactivities WHERE ActivityId=:id AND Status=1 LIMIT 1");
$aq->execute([':id' => $activityId]);
$activity = $aq->fetch(PDO::FETCH_OBJ);

if (!$activity) {
    http_response_code(404);
    include('backend/header.php');
    echo '<div class="container" style="padding:60px 20px;text-align:center;color:var(--text-muted);"><i class="fa fa-map-signs" style="font-size:50px;display:block;margin-bottom:16px;"></i><h3>Activity not found</h3><p><a href="activities.php">Back to Activities</a></p></div>';
    include('backend/footer.php');
    exit;
}

// NEW (Round 80, master prompt item 13 -- Activities Marketplace "reviews"
// requirement): real reviews for this activity, same pattern as
// package-details.php's "Recent Reviews" section. See frontend/reviews.php
// for the write flow (reviews.php?activity=ID pre-selects this activity).
$aRevQ = $dbh->prepare("SELECT r.*, u.FullName as UserFullName
    FROM tblreviews r LEFT JOIN tblusers u ON r.UserEmail=u.EmailId
    WHERE r.ActivityId=:aid AND r.Status=1 ORDER BY r.id DESC LIMIT 5");
$aRevQ->execute([':aid' => $activityId]);
$activityReviews = $aRevQ->fetchAll(PDO::FETCH_OBJ);
$arcQ = $dbh->prepare("SELECT COUNT(*) FROM tblreviews WHERE ActivityId=:aid AND Status=1");
$arcQ->execute([':aid' => $activityId]);
$activityReviewCount = (int)$arcQ->fetchColumn();

if (isset($_POST['book_activity'])) {
    tms_csrf_validate();
    if (empty($_SESSION['login'])) {
        $error = 'You must be signed in to book an activity.';
    } else {
        $email    = $_SESSION['login'];
        $date     = tms_clean($_POST['activity_date'] ?? '');
        $people   = tms_int($_POST['participants'] ?? 1, 1, 30);
        $mobile   = tms_clean($_POST['mobile'] ?? '');
        $comment  = tms_clean($_POST['comment'] ?? '');

        $when = DateTime::createFromFormat('Y-m-d', $date);
        $now  = new DateTime('today');

        if (!$when) {
            $error = 'Please enter a valid activity date.';
        } elseif ($when < $now) {
            $error = 'Activity date must be today or later.';
        } elseif ((int)$activity->PricePerPerson <= 0) {
            $error = "This activity doesn't have a price set yet -- please contact support.";
        } else {
            $total = (int)$activity->PricePerPerson * $people;
            $verificationCode = strtoupper(bin2hex(random_bytes(5)));

            $ins = $dbh->prepare(
                "INSERT INTO tblactivity_bookings(ActivityId,UserEmail,ActivityDate,NumParticipants,MobileNumber,Comment,TotalCost,VerificationCode,status)
                 VALUES(:aid,:email,:date,:people,:mobile,:comment,:total,:vcode,0)"
            );
            $ins->execute([
                ':aid' => $activityId, ':email' => $email, ':date' => $when->format('Y-m-d'),
                ':people' => $people, ':mobile' => $mobile ?: null, ':comment' => $comment,
                ':total' => $total, ':vcode' => $verificationCode,
            ]);
            $newId = $dbh->lastInsertId();
            if ($newId) {
                $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,50,50)
                    ON DUPLICATE KEY UPDATE Points=Points+50, TotalEarned=TotalEarned+50")->execute([':e'=>$email]);
                try {
                    $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,50,:d)")
                        ->execute([':e'=>$email, ':d'=>'Activity booking #AB'.$newId.' confirmed']);
                } catch (Exception $e) { error_log('[TMS Loyalty] Activity booking log insert failed: '.$e->getMessage()); }

                $msg = 'Activity booked successfully! Verification code: <strong>'.htmlspecialchars($verificationCode, ENT_QUOTES, 'UTF-8').'</strong>. '
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
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | <?php echo htmlspecialchars($activity->ActivityName, ENT_QUOTES, 'UTF-8'); ?></title>
<?php
// SEO meta broadening (master prompt item 53), same pattern as
// hotel-details.php: ?id= is this page's resource identifier, kept
// explicitly in the canonical.
// RULE 3: no JSON-LD/structured data on purpose -- tblactivities is
// explicitly sample/demo data, so no schema.org markup is emitted that
// would tell search engines this is a real, bookable-today activity.
$activitySeoDesc = trim((string)($activity->Description ?? ''));
if ($activitySeoDesc === '') {
    $activitySeoDesc = 'Sample/demo activity listing in ' . $activity->City . ', ' . $activity->Country . ' for the TMS activities marketplace feature.';
}
$adScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$adHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$adPath   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
tms_seo_meta([
    'title'       => $activity->ActivityName . ' — ' . $activity->City,
    'description' => $activitySeoDesc,
    'image'       => $activity->ActivityImage ?: '',
    'canonical'   => $adScheme . '://' . $adHost . $adPath . '?id=' . (int)$activityId,
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Activities', 'url' => 'activities.php'],
        ['label' => $activity->ActivityName],
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

<div class="banner-3"><div class="container"><h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo htmlspecialchars($activity->ActivityName, ENT_QUOTES, 'UTF-8'); ?></h1></div></div>

<div class="container" style="padding:30px 15px;">
    <?php tms_render_breadcrumb_nav([
        ['label' => 'Home', 'url' => 'index.php'],
        ['label' => 'Activities', 'url' => 'activities.php'],
        ['label' => $activity->ActivityName],
    ]); ?>
    <div class="tms-sample-banner">
        <i class="fa fa-info-circle"></i> This is a <strong>sample/demo activity listing</strong> used to showcase the TMS activities marketplace feature -- not a real, live-supplier tour. A booking here creates a real record in your account for demonstration purposes.
    </div>

    <?php if ($error): ?><div class="alert-error"><i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert-success"><i class="fa fa-check-circle"></i> <?php echo $msg; /* contains safe, hardcoded HTML + one escaped var */ ?></div><?php endif; ?>

    <div class="row">
        <div class="col-md-7">
            <div class="hd-wrap">
                <img src="<?php echo htmlspecialchars($activity->ActivityImage ?: 'images/no-image-placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='images/no-image-placeholder.jpg'" class="hd-img" alt="<?php echo htmlspecialchars($activity->ActivityName, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="hd-body">
                    <span class="tms-sample-badge"><i class="fa fa-flask"></i> Sample Listing</span>
                    <h1 style="margin-top:10px;"><?php echo htmlspecialchars($activity->ActivityName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlspecialchars($activity->City.', '.$activity->Country, ENT_QUOTES, 'UTF-8'); ?>
                    &nbsp;·&nbsp; <i class="fa fa-tags"></i> <?php echo htmlspecialchars($activity->Category ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    &nbsp;·&nbsp; <i class="fa fa-clock-o"></i> <?php echo rtrim(rtrim(number_format((float)$activity->DurationHours,1),'0'),'.'); ?> hours</p>
                    <?php if ((float)$activity->AvgRating>0): ?>
                    <p><?php for ($s=1;$s<=5;$s++): ?><i class="fa fa-star<?php echo $s<=round((float)$activity->AvgRating)?'':'-o'; ?>" style="color:#f0ad4e;"></i><?php endfor; ?>
                    <span style="color:var(--text-muted);font-size:13px;"> <?php echo number_format((float)$activity->AvgRating,1); ?>/5<?php if ($activityReviewCount > 0): ?> (<?php echo $activityReviewCount; ?> review<?php echo $activityReviewCount===1?'':'s'; ?>)<?php endif; ?></span></p>
                    <?php endif; ?>
                    <p style="color:var(--text-muted);"><?php echo nl2br(htmlspecialchars($activity->Description ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                    <h3 style="color:var(--primary);margin-top:16px;"><?php echo tms_currency((float)$activity->PricePerPerson); ?> <span style="font-size:13px;color:var(--text-muted);font-weight:400;">/person</span></h3>
                </div>
            </div>

            <!-- NEW (Round 80, master prompt item 13 -- "reviews"): same
                 pattern as package-details.php's Recent Reviews card. -->
            <?php if (count($activityReviews) > 0): ?>
            <div class="hd-wrap">
                <div class="hd-body">
                    <h4 style="font-weight:700;margin-bottom:16px;"><i class="fa fa-star" style="color:#f0ad4e"></i> Recent Reviews</h4>
                    <?php foreach ($activityReviews as $rev): ?>
                    <div class="review-mini">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <h6><?php echo htmlspecialchars($rev->UserName, ENT_QUOTES, 'UTF-8'); ?></h6>
                            <div class="stars-sm"><?php for ($i=1;$i<=5;$i++) echo $i<=$rev->Rating?'★':'☆'; ?></div>
                        </div>
                        <p style="font-size:13px;color:#666;margin:4px 0 0;"><?php echo htmlspecialchars(substr($rev->ReviewText,0,150), ENT_QUOTES, 'UTF-8'); ?>…</p>
                    </div>
                    <?php endforeach; ?>
                    <a href="reviews.php?activity=<?php echo $activityId; ?>" style="font-size:13px;color:var(--secondary);">View all reviews <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-md-5">
            <div class="hd-wrap">
                <div class="hd-body">
                    <h4 style="margin-bottom:14px;"><i class="fa fa-calendar-check-o"></i> Book This Activity</h4>
                    <?php if (empty($_SESSION['login'])): ?>
                    <p style="color:var(--text-muted);font-size:14px;">Please <a href="javascript:void(0)" onclick="openModal('signinModal')">sign in</a> to book this activity.</p>
                    <?php else: ?>
                    <form method="post" action="activity-details.php?id=<?php echo $activityId; ?>">
                        <?php tms_csrf_field(); ?>
                        <div class="form-group">
                            <label for="actDateField">Activity Date</label>
                            <input type="date" name="activity_date" id="actDateField" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="actParticipantsField">Number of Participants</label>
                            <input type="number" name="participants" id="actParticipantsField" class="form-control" value="1" min="1" max="30" required>
                        </div>
                        <div class="form-group">
                            <label for="actMobileField">Mobile Number <small style="color:var(--text-muted);">(optional)</small></label>
                            <input type="tel" name="mobile" id="actMobileField" class="form-control" placeholder="e.g. 017XXXXXXXX" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label for="actCommentField">Special Requests <small style="color:var(--text-muted);">(optional)</small></label>
                            <textarea name="comment" id="actCommentField" class="form-control" style="height:70px;padding:10px 12px;"></textarea>
                        </div>
                        <button type="submit" name="book_activity" class="btn-book"><i class="fa fa-check-circle"></i> Book Now</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <p style="margin-top:10px;">
        <a href="activities.php"><i class="fa fa-arrow-left"></i> Back to Activities</a>
        &nbsp;·&nbsp;
        <a href="reviews.php?activity=<?php echo $activityId; ?>"><i class="fa fa-star"></i> Write a Review</a>
    </p>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
<?php include('backend/signin.php'); ?>
</body>
</html>

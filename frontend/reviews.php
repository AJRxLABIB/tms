<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see backend/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.
//
// ROUND 80 (master prompt items 12/13 -- Hotel System / Activities
// Marketplace "reviews" requirement): this page used to only support
// reviewing tour packages. tblreviews now has nullable HotelId/ActivityId
// columns alongside the original PackageId, and this page lets a review
// point at exactly one of the three (never more than one, enforced below
// via $reviewType). Package reviews behave exactly as before -- nothing
// about that path changed other than sharing code with the two new paths.

$error   = '';
$success = '';

if (isset($_POST['submitreview']) && !empty($_SESSION['login'])) {
    tms_csrf_validate();

    $reviewType = in_array($_POST['reviewtype'] ?? '', ['package', 'hotel', 'activity'], true) ? $_POST['reviewtype'] : 'package';
    $pkgId      = $reviewType === 'package'  ? tms_int($_POST['pkgid']      ?? 0, 1) : 0;
    $hotelId    = $reviewType === 'hotel'    ? tms_int($_POST['hotelid']    ?? 0, 1) : 0;
    $activityId = $reviewType === 'activity' ? tms_int($_POST['activityid'] ?? 0, 1) : 0;
    $entityId   = $pkgId ?: ($hotelId ?: $activityId);
    $rating     = tms_int($_POST['rating']     ?? 0, 1, 5);
    $reviewText = tms_clean($_POST['reviewtext'] ?? '');
    $userEmail  = $_SESSION['login'];

    // Fixed, whitelisted maps -- $reviewType is validated against exactly
    // three literal values above, so these are never built from raw user
    // input and are safe to use as SQL identifiers below.
    $entityCol   = ['package' => 'PackageId',      'hotel' => 'HotelId',   'activity' => 'ActivityId'][$reviewType];
    $entityTable = ['package' => 'tbltourpackages', 'hotel' => 'tblhotels', 'activity' => 'tblactivities'][$reviewType];
    $entityUrl   = ['package' => 'package-details.php?pkgid=', 'hotel' => 'hotel-details.php?id=', 'activity' => 'activity-details.php?id='][$reviewType];
    $entityLabel = ['package' => 'package', 'hotel' => 'hotel', 'activity' => 'activity'][$reviewType];

    if (empty($reviewText)) {
        $error = 'Please write your experience before submitting.';
    } elseif ($entityId === 0) {
        $error = 'Please select what you are reviewing.';
    } else {
        // Get username
        $uq = $dbh->prepare("SELECT FullName FROM tblusers WHERE EmailId=:e LIMIT 1");
        $uq->bindParam(':e', $userEmail);
        $uq->execute();
        $uRow     = $uq->fetch(PDO::FETCH_OBJ);
        $userName = $uRow ? $uRow->FullName : $userEmail;

        // Duplicate check (one review per user per entity, same rule as before)
        $dc = $dbh->prepare("SELECT id FROM tblreviews WHERE $entityCol=:eid AND UserEmail=:ue LIMIT 1");
        $dc->bindParam(':eid', $entityId, PDO::PARAM_INT);
        $dc->bindParam(':ue',  $userEmail);
        $dc->execute();

        if ($dc->rowCount() > 0) {
            $error = "You have already reviewed this $entityLabel.";
        } else {
            $reviewImage = '';
            if (!empty($_FILES['reviewimage']['size'])) {
                $allowed = ['jpg','jpeg','png','gif','webp'];
                $ext     = strtolower(pathinfo($_FILES['reviewimage']['name'], PATHINFO_EXTENSION));
                $maxSize = 5 * 1024 * 1024; // 5 MB

                $sigCheck = ['ok' => true, 'error' => null];
                if (in_array($ext, $allowed)) {
                    // Only sniff the signature once the extension already
                    // passes -- no point spending a finfo call on a file
                    // we're rejecting anyway.
                    $sigCheck = tms_validate_upload_signature($_FILES['reviewimage']['tmp_name'], $allowed);
                }

                if (!in_array($ext, $allowed)) {
                    $error = 'Only JPG, PNG, GIF, WEBP image files are allowed.';
                } elseif ($_FILES['reviewimage']['size'] > $maxSize) {
                    $error = 'Image must be under 5 MB.';
                } elseif (!$sigCheck['ok']) {
                    $error = $sigCheck['error'];
                } else {
                    if (!is_dir('uploads/reviews/')) mkdir('uploads/reviews/', 0755, true);
                    $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    move_uploaded_file($_FILES['reviewimage']['tmp_name'], 'uploads/reviews/' . $filename);
                    tms_compress_uploaded_image('uploads/reviews/' . $filename);
                    $reviewImage = $filename;
                }
            }

            if (!$error) {
                $needsModeration = ($reviewImage !== '');
                $initialStatus = $needsModeration ? 0 : 1;

                $ins = $dbh->prepare("INSERT INTO tblreviews
                    (PackageId,HotelId,ActivityId,UserEmail,UserName,Rating,ReviewText,ReviewImage,Status)
                    VALUES(:pid,:hid,:aid,:ue,:un,:r,:rt,:ri,:st)");
                $ins->execute([
                    ':pid' => $pkgId ?: null, ':hid' => $hotelId ?: null, ':aid' => $activityId ?: null,
                    ':ue' => $userEmail, ':un' => $userName, ':r' => $rating, ':rt' => $reviewText,
                    ':ri' => $reviewImage, ':st' => $initialStatus,
                ]);

                // Recalculate the entity's avg rating (only ever counts
                // Status=1 rows, so a pending photo review correctly
                // doesn't affect it yet) -- same mechanic as before,
                // just now targeting whichever of the 3 tables applies.
                $dbh->prepare("UPDATE $entityTable
                    SET AvgRating=(SELECT AVG(Rating) FROM tblreviews WHERE $entityCol=:e AND Status=1)
                    WHERE $entityCol=:e2")
                    ->execute([':e' => $entityId, ':e2' => $entityId]);

                // Award 20 loyalty points for review
                $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,20,20)
                    ON DUPLICATE KEY UPDATE Points=Points+20, TotalEarned=TotalEarned+20")->execute([':e'=>$userEmail]);
                try {
                    $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,20,:d)")
                        ->execute([':e'=>$userEmail,':d'=>'Wrote a review']);
                } catch (Exception $e) {
                    error_log('[TMS Loyalty] Log insert failed (review still submitted): ' . $e->getMessage());
                }

                $notifyMsg = $needsModeration
                    ? "⭐ Your review was received and is awaiting approval (photo reviews are moderated) — you still earned 20 loyalty points."
                    : "⭐ Your review was posted — thanks for sharing! You earned 20 loyalty points.";
                tms_notify($dbh, $userEmail, $notifyMsg, $entityUrl . (int)$entityId);

                $success = $needsModeration
                    ? 'Thank you! Your review has been submitted. Since it includes a photo, it will appear once our team approves it. You still earned 20 loyalty points!'
                    : 'Thank you! Your review has been published. You earned 20 loyalty points!';
            }
        }
    }
}

$filterPkg      = tms_int($_GET['pkg']      ?? 0, 0);
$filterHotel    = tms_int($_GET['hotel']    ?? 0, 0);
$filterActivity = tms_int($_GET['activity'] ?? 0, 0);

$filterType = $filterPkg ? 'package' : ($filterHotel ? 'hotel' : ($filterActivity ? 'activity' : ''));
$filterId   = $filterPkg ?: ($filterHotel ?: $filterActivity);

$packages   = $dbh->query("SELECT PackageId, PackageName FROM tbltourpackages ORDER BY PackageName")->fetchAll(PDO::FETCH_OBJ);
$hotelsList = $dbh->query("SELECT HotelId, HotelName FROM tblhotels WHERE Status=1 ORDER BY HotelName")->fetchAll(PDO::FETCH_OBJ);
$activitiesList = $dbh->query("SELECT ActivityId, ActivityName FROM tblactivities WHERE Status=1 ORDER BY ActivityName")->fetchAll(PDO::FETCH_OBJ);

$filterColMap = ['package' => 'r.PackageId', 'hotel' => 'r.HotelId', 'activity' => 'r.ActivityId'];
$filterWhere  = $filterType ? "AND {$filterColMap[$filterType]} = :fid" : "";

$revQ = $dbh->prepare("SELECT r.*,
        COALESCE(p.PackageName, h.HotelName, a.ActivityName) AS EntityName,
        CASE WHEN r.PackageId IS NOT NULL THEN 'package' WHEN r.HotelId IS NOT NULL THEN 'hotel' ELSE 'activity' END AS EntityType
    FROM tblreviews r
    LEFT JOIN tbltourpackages p ON r.PackageId=p.PackageId
    LEFT JOIN tblhotels h ON r.HotelId=h.HotelId
    LEFT JOIN tblactivities a ON r.ActivityId=a.ActivityId
    WHERE r.Status=1 $filterWhere ORDER BY r.id DESC");
if ($filterType) $revQ->bindValue(':fid', $filterId, PDO::PARAM_INT);
$revQ->execute();
$reviews = $revQ->fetchAll(PDO::FETCH_OBJ);

// Stats (same column reference, no "r." alias needed against the bare table)
$statsColMap = ['package' => 'PackageId', 'hotel' => 'HotelId', 'activity' => 'ActivityId'];
$statsWhere  = $filterType ? "AND {$statsColMap[$filterType]} = " . (int)$filterId : "";
$statsQ = $dbh->query("SELECT AVG(Rating) as avg, COUNT(*) as total,
    SUM(Rating=5) as s5, SUM(Rating=4) as s4, SUM(Rating=3) as s3,
    SUM(Rating=2) as s2, SUM(Rating=1) as s1
    FROM tblreviews WHERE Status=1 $statsWhere");
$stats = $statsQ->fetch(PDO::FETCH_OBJ);

// Pre-select the write-review form to match an incoming ?hotel=/?activity=/
// ?pkg= deep link (e.g. a "Write a Review" link from hotel-details.php).
$preselectType = $filterType ?: 'package';
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Tourist Reviews</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link href="css/animate.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.review-hero{background:linear-gradient(135deg,#f093fb,#f5576c);color:#fff;padding:55px 0 30px;text-align:center;}
.review-hero h1{font-size:2.3em;}
.review-card{background:var(--white);border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:25px;margin-bottom:20px;border:1px solid #f0f0f0;transition:.3s;}
.review-card:hover{box-shadow:0 8px 30px rgba(0,0,0,.12);}
.reviewer-avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#f093fb,#f5576c);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:700;flex-shrink:0;}
.reviewer-info h5{font-size:15px;font-weight:700;color:#333;margin:0;}
.reviewer-info p{font-size:12px;color:#aaa;margin:0;}
.review-stars{color:#f0ad4e;font-size:18px;}
.review-text{color:#555;font-size:14px;line-height:1.6;margin:12px 0 0;}
.review-image{border-radius:8px;max-height:200px;object-fit:cover;margin-top:12px;cursor:pointer;}
.pkg-badge{background:#f8f0ff;color:#6f42c1;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;}
.write-card{background:var(--white);border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:28px;margin-bottom:25px;}
html[data-theme="dark"] .reviewer-info h5,
html[data-theme="dark"] .write-card h4 { color: var(--text) !important; }
html[data-theme="dark"] .reviewer-info p { color: var(--text-light) !important; }
html[data-theme="dark"] .review-text { color: var(--text-muted) !important; }
.write-card h4{color:#333;font-size:1.1em;font-weight:700;border-bottom:2px solid #f0f0f0;padding-bottom:10px;margin-bottom:20px;}
.star-rating{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:5px;}
.star-rating input{display:none;}
.star-rating label{font-size:30px;color:#ddd;cursor:pointer;transition:.2s;}
.star-rating input:checked ~ label,.star-rating label:hover,.star-rating label:hover ~ label{color:#f0ad4e;}
.form-control{border:2px solid #e0e0e0;border-radius:8px;padding:10px 14px;}
.form-control:focus{border-color:#f5576c;box-shadow:none;outline:none;}
.btn-review{background:linear-gradient(135deg,#f093fb,#f5576c);color:#fff;border:none;border-radius:8px;padding:12px 30px;font-weight:700;cursor:pointer;font-size:15px;transition:.2s;}
.btn-review:hover{opacity:.9;color:#fff;}
.stats-box{background:linear-gradient(135deg,#f093fb,#f5576c);color:#fff;border-radius:12px;padding:25px;margin-bottom:20px;}
.stats-box .big-rating{font-size:4em;font-weight:700;}
.stat-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;font-size:13px;}
.stat-bar-track{flex:1;height:8px;background:rgba(255,255,255,.3);border-radius:4px;overflow:hidden;}
.stat-bar-fill{height:100%;background:var(--white);border-radius:4px;}
.filter-row{background:var(--white);border-radius:10px;padding:15px 20px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:20px;}
.img-modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.85);z-index:9999;justify-content:center;align-items:center;}
.img-modal img{max-width:90%;max-height:90%;border-radius:8px;}
.img-modal.open{display:flex;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="review-hero">
    <div class="container">
        <h1><i class="fa fa-star"></i> Tourist Reviews</h1>
        <p>Real experiences from real travelers — share yours too!</p>
    </div>
</div>

<div class="img-modal" id="imgModal" onclick="this.classList.remove('open')">
    <img id="modalImg" src="" alt="Review Photo">
</div>

<div class="container" style="padding-top:25px;">
    <div class="row">
        <div class="col-md-4">
            <?php if ($stats->total > 0): ?>
            <div class="stats-box wow fadeInLeft">
                <div class="big-rating"><?php echo number_format($stats->avg, 1); ?></div>
                <div style="font-size:20px;margin-bottom:5px;">
                    <?php for ($i=1;$i<=5;$i++) echo $i<=round($stats->avg)?'★':'☆'; ?>
                </div>
                <div style="font-size:13px;opacity:.85;"><?php echo $stats->total; ?> reviews</div>
                <hr style="border-color:rgba(255,255,255,.3);margin:15px 0;">
                <?php foreach ([5,4,3,2,1] as $s):
                    $cnt = $stats->{'s'.$s} ?? 0;
                    $pct = $stats->total > 0 ? ($cnt / $stats->total * 100) : 0; ?>
                <div class="stat-bar-row">
                    <span><?php echo $s; ?>★</span>
                    <div class="stat-bar-track"><div class="stat-bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
                    <span><?php echo $cnt; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="write-card wow fadeInLeft">
                <h4><i class="fa fa-edit" style="color:#f5576c"></i> Write a Review</h4>

                <?php if ($error):   ?><div class="alert alert-danger"><?php  echo htmlspecialchars($error,   ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>

                <?php if (!empty($_SESSION['login'])): ?>
                <form method="post" enctype="multipart/form-data" id="reviewForm">
                    <?php tms_csrf_field(); ?>
                    <div class="form-group">
                        <label for="reviewTypeSelect">What are you reviewing?</label>
                        <select name="reviewtype" id="reviewTypeSelect" class="form-control" onchange="tmsReviewTypeChange()">
                            <option value="package"  <?php echo $preselectType==='package'  ? 'selected' : ''; ?>>Tour Package</option>
                            <option value="hotel"    <?php echo $preselectType==='hotel'    ? 'selected' : ''; ?>>Hotel</option>
                            <option value="activity" <?php echo $preselectType==='activity' ? 'selected' : ''; ?>>Activity</option>
                        </select>
                    </div>
                    <div class="form-group" id="reviewPkgWrap">
                        <label for="reviewPkgSelect">Package</label>
                        <select name="pkgid" id="reviewPkgSelect" class="form-control">
                            <option value="">-- Select Package --</option>
                            <?php foreach ($packages as $p): ?>
                            <option value="<?php echo (int)$p->PackageId; ?>"
                                <?php echo ($filterPkg==$p->PackageId)?'selected':''; ?>>
                                <?php echo htmlspecialchars($p->PackageName, ENT_QUOTES,'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="reviewHotelWrap" style="display:none;">
                        <label for="reviewHotelSelect">Hotel</label>
                        <select name="hotelid" id="reviewHotelSelect" class="form-control">
                            <option value="">-- Select Hotel --</option>
                            <?php foreach ($hotelsList as $h): ?>
                            <option value="<?php echo (int)$h->HotelId; ?>"
                                <?php echo ($filterHotel==$h->HotelId)?'selected':''; ?>>
                                <?php echo htmlspecialchars($h->HotelName, ENT_QUOTES,'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="reviewActivityWrap" style="display:none;">
                        <label for="reviewActivitySelect">Activity</label>
                        <select name="activityid" id="reviewActivitySelect" class="form-control">
                            <option value="">-- Select Activity --</option>
                            <?php foreach ($activitiesList as $a): ?>
                            <option value="<?php echo (int)$a->ActivityId; ?>"
                                <?php echo ($filterActivity==$a->ActivityId)?'selected':''; ?>>
                                <?php echo htmlspecialchars($a->ActivityName, ENT_QUOTES,'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <!-- ACCESSIBILITY FIX (Round 58, item 32): this label headed a
                             GROUP of 5 star radio buttons, not one single control, so a
                             `for` pointing at just one of them would misrepresent the
                             group. role="radiogroup" + aria-labelledby is the correct
                             ARIA pattern for a labeled group of radios (the individual
                             star <label for="starN"> associations below were already
                             correct and are unchanged). -->
                        <label id="ratingGroupLabel">Your Rating</label>
                        <div class="star-rating" role="radiogroup" aria-labelledby="ratingGroupLabel">
                            <?php for ($i=5; $i>=1; $i--): ?>
                            <input type="radio" id="star<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" <?php echo $i==5?'checked':''; ?>>
                            <label for="star<?php echo $i; ?>">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="reviewTextField">Your Experience *</label>
                        <textarea name="reviewtext" id="reviewTextField" class="form-control" rows="4" placeholder="Share your experience…" required maxlength="2000"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="reviewImageField">Upload Photo <small style="color:#aaa;">(optional, max 5 MB)</small></label>
                        <input type="file" name="reviewimage" id="reviewImageField" class="form-control" accept="image/*">
                    </div>
                    <button type="submit" name="submitreview" class="btn-review">
                        <i class="fa fa-paper-plane"></i> Submit Review
                    </button>
                </form>
                <script>
                // Round 80: show only the select matching the chosen review
                // type, so the form never submits an id for the wrong kind
                // of entity. Runs once on load (to match a ?hotel=/?activity=
                // deep link's pre-selected type) and again on every change.
                function tmsReviewTypeChange() {
                    var type = document.getElementById('reviewTypeSelect').value;
                    document.getElementById('reviewPkgWrap').style.display      = (type === 'package')  ? '' : 'none';
                    document.getElementById('reviewHotelWrap').style.display    = (type === 'hotel')    ? '' : 'none';
                    document.getElementById('reviewActivityWrap').style.display = (type === 'activity') ? '' : 'none';
                }
                tmsReviewTypeChange();
                </script>
                <?php else: ?>
                <div style="text-align:center;padding:20px;">
                    <i class="fa fa-lock" style="font-size:40px;color:#ddd;display:block;margin-bottom:15px;"></i>
                    <p style="color:#777;margin-bottom:15px;">Please sign in to write a review</p>
                    <a href="#" data-toggle="modal" data-target="#myModal4"
                       class="btn-review" style="text-decoration:none;display:inline-block;padding:10px 25px;">Sign In</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-8">
            <div class="filter-row wow fadeInRight">
                <label for="reviewFilterSelect" style="margin-right:10px;color:#555;">Filter by:</label>
                <select name="filter" id="reviewFilterSelect" class="form-control" style="display:inline-block;width:auto;min-width:240px;margin-right:10px;"
                        onchange="if(this.value) location.href=this.value;">
                    <option value="reviews.php">All Reviews</option>
                    <optgroup label="Tour Packages">
                        <?php foreach ($packages as $p): ?>
                        <option value="reviews.php?pkg=<?php echo (int)$p->PackageId; ?>" <?php echo ($filterPkg==$p->PackageId)?'selected':''; ?>>
                            <?php echo htmlspecialchars($p->PackageName, ENT_QUOTES,'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Hotels">
                        <?php foreach ($hotelsList as $h): ?>
                        <option value="reviews.php?hotel=<?php echo (int)$h->HotelId; ?>" <?php echo ($filterHotel==$h->HotelId)?'selected':''; ?>>
                            <?php echo htmlspecialchars($h->HotelName, ENT_QUOTES,'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Activities">
                        <?php foreach ($activitiesList as $a): ?>
                        <option value="reviews.php?activity=<?php echo (int)$a->ActivityId; ?>" <?php echo ($filterActivity==$a->ActivityId)?'selected':''; ?>>
                            <?php echo htmlspecialchars($a->ActivityName, ENT_QUOTES,'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <span style="color:#aaa;font-size:14px;"><?php echo count($reviews); ?> review(s)</span>
            </div>

            <?php if (count($reviews) > 0): ?>
            <?php foreach ($reviews as $rev): ?>
            <div class="review-card wow fadeInUp">
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div class="reviewer-avatar"><?php echo strtoupper(substr($rev->UserName,0,1)); ?></div>
                    <div class="reviewer-info" style="flex:1;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:5px;">
                            <div>
                                <h5><?php echo htmlspecialchars($rev->UserName, ENT_QUOTES,'UTF-8'); ?></h5>
                                <p><?php echo date('d M Y', strtotime($rev->PostingDate)); ?></p>
                            </div>
                            <span class="pkg-badge">
                                <i class="fa <?php echo ['package'=>'fa-briefcase','hotel'=>'fa-hotel','activity'=>'fa-hiking'][$rev->EntityType] ?? 'fa-briefcase'; ?>"></i>
                                <?php echo htmlspecialchars($rev->EntityName ?? '—', ENT_QUOTES,'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="review-stars">
                            <?php for ($i=1;$i<=5;$i++) echo $i<=$rev->Rating?'★':'☆'; ?>
                            <span style="color:#aaa;font-size:13px;">&nbsp;<?php echo (int)$rev->Rating; ?>.0</span>
                        </div>
                        <p class="review-text"><?php echo nl2br(htmlspecialchars($rev->ReviewText, ENT_QUOTES,'UTF-8')); ?></p>
                        <?php if ($rev->ReviewImage): ?>
                        <img loading="lazy" src="uploads/reviews/<?php echo htmlspecialchars($rev->ReviewImage, ENT_QUOTES,'UTF-8'); ?>"
                             class="review-image" alt="Review Photo"
                             onclick="document.getElementById('modalImg').src=this.src; document.getElementById('imgModal').classList.add('open')">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="text-align:center;padding:60px 20px;">
                <i class="fa fa-comment-o" style="font-size:60px;color:#ddd;display:block;margin-bottom:20px;"></i>
                <h4 style="color:#aaa;">No reviews yet</h4>
                <p style="color:#bbb;">Be the first to share your experience!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

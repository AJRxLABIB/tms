<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$error = '';
$msg   = '';

if (isset($_POST['submit6'])) {
    tms_csrf_validate();

    $name     = tms_clean($_POST['name']     ?? '');
    $mobileno = tms_clean($_POST['mobileno'] ?? '');
    $email    = $_SESSION['login'];

    if (empty($name)) {
        $error = 'Full name is required.';
    } elseif (strlen($name) > 100) {
        $error = 'Name is too long (max 100 characters).';
    } elseif (!empty($mobileno) && !preg_match('/^\+?[0-9]{7,15}$/', $mobileno)) {
        $error = 'Please enter a valid mobile number.';
    } else {
        $sql = "UPDATE tblusers SET FullName=:name, MobileNumber=:mobileno WHERE EmailId=:email";
        $query = $dbh->prepare($sql);
        $query->bindParam(':name',     $name,     PDO::PARAM_STR);
        $query->bindParam(':mobileno', $mobileno, PDO::PARAM_STR);
        $query->bindParam(':email',    $email,    PDO::PARAM_STR);
        $query->execute();
        $msg = 'Profile updated successfully.';
    }
}

// NEW (master prompt item 67 — Travel Modes / item 23 Personalization):
// tbl_user_profile (interests/budget_range/preferred_climate/travel_style/
// travel_mode) has existed since an earlier round, but nothing in the app
// ever wrote to it -- there was no settings UI at all. This is that UI.
// A separate form/submit name from the profile-details form above so the
// two save independently and one can't clobber the other's fields.
$prefError = '';
$prefMsg   = '';
if (isset($_POST['submit_travel_prefs'])) {
    tms_csrf_validate();

    $travelMode       = tms_clean($_POST['travel_mode'] ?? '');
    $budgetRange      = tms_clean($_POST['budget_range'] ?? '');
    $preferredClimate = tms_clean($_POST['preferred_climate'] ?? '');
    $interestsRaw     = trim($_POST['interests'] ?? '');
    // NEW (Round 77, master prompt item 23 -- Personalization): "food
    // preferences" is one of the spec's explicitly-named preference
    // dimensions and previously had no field anywhere in the app.
    $foodPreference   = tms_clean($_POST['food_preference'] ?? '');

    $allowedModes    = ['', 'family', 'couple', 'solo', 'business', 'accessible', 'group', 'adventure'];
    $allowedBudgets  = ['', 'budget', 'mid-range', 'luxury'];
    $allowedClimates = ['', 'any', 'hot', 'cold', 'beach', 'mountain'];
    $allowedFoodPrefs = ['', 'no_restriction', 'vegetarian', 'vegan', 'halal', 'kosher'];

    if (!in_array($travelMode, $allowedModes, true) || !in_array($budgetRange, $allowedBudgets, true) || !in_array($preferredClimate, $allowedClimates, true) || !in_array($foodPreference, $allowedFoodPrefs, true)) {
        $prefError = 'Invalid travel preference selection.';
    } else {
        // Free-text interests -> a clean JSON array, comma-separated input,
        // trimmed, empty entries dropped, capped so this can't grow unbounded.
        $interests = array_values(array_filter(array_map(fn($v) => trim(strip_tags($v)), explode(',', $interestsRaw)), fn($v) => $v !== ''));
        $interests = array_slice($interests, 0, 20);

        try {
            $up = $dbh->prepare(
                "INSERT INTO tbl_user_profile (user_email, travel_mode, budget_range, preferred_climate, interests, food_preference, updated_at)
                 VALUES (:e, :tm, :br, :pc, :i, :fp, NOW())
                 ON DUPLICATE KEY UPDATE travel_mode=:tm2, budget_range=:br2, preferred_climate=:pc2, interests=:i2, food_preference=:fp2, updated_at=NOW()"
            );
            $interestsJson = json_encode($interests, JSON_UNESCAPED_UNICODE);
            $tmOrNull = $travelMode !== '' ? $travelMode : null;
            $brOrNull = $budgetRange !== '' ? $budgetRange : null;
            $pcOrNull = $preferredClimate !== '' ? $preferredClimate : null;
            $fpOrNull = $foodPreference !== '' ? $foodPreference : null;
            $up->execute([
                ':e' => $_SESSION['login'],
                ':tm' => $tmOrNull, ':br' => $brOrNull, ':pc' => $pcOrNull, ':i' => $interestsJson, ':fp' => $fpOrNull,
                ':tm2' => $tmOrNull, ':br2' => $brOrNull, ':pc2' => $pcOrNull, ':i2' => $interestsJson, ':fp2' => $fpOrNull,
            ]);
            $prefMsg = 'Travel preferences saved.';
        } catch (Exception $e) {
            error_log('[Profile] Travel preference save failed: ' . $e->getMessage());
            $prefError = 'Could not save travel preferences right now — please try again.';
        }
    }
}

$useremail = $_SESSION['login'];
$q = $dbh->prepare("SELECT * FROM tblusers WHERE EmailId=:e LIMIT 1");
$q->bindParam(':e', $useremail, PDO::PARAM_STR);
$q->execute();
$user = $q->fetch(PDO::FETCH_OBJ);

// Load existing travel preferences (if any) to prefill the new card.
$travelPrefs = ['travel_mode' => '', 'budget_range' => '', 'preferred_climate' => '', 'interests' => [], 'food_preference' => ''];
try {
    $tpQ = $dbh->prepare("SELECT travel_mode, budget_range, preferred_climate, interests, food_preference FROM tbl_user_profile WHERE user_email=:e LIMIT 1");
    $tpQ->execute([':e' => $useremail]);
    if ($row = $tpQ->fetch(PDO::FETCH_ASSOC)) {
        $travelPrefs['travel_mode']        = $row['travel_mode'] ?? '';
        $travelPrefs['budget_range']       = $row['budget_range'] ?? '';
        $travelPrefs['preferred_climate']  = $row['preferred_climate'] ?? '';
        $decoded = json_decode($row['interests'] ?? '[]', true);
        $travelPrefs['interests'] = is_array($decoded) ? $decoded : [];
        $travelPrefs['food_preference']    = $row['food_preference'] ?? '';
    }
} catch (Exception $e) {
    // Self-healing column may not exist yet on a very old install -- the
    // card below just renders with empty defaults in that case.
}

// Booking summary counts
$bkQ = $dbh->prepare("SELECT status, COUNT(*) as cnt FROM tblbooking WHERE UserEmail=:e GROUP BY status");
$bkQ->bindParam(':e', $useremail, PDO::PARAM_STR);
$bkQ->execute();
$bkStats = ['total'=>0, 'confirmed'=>0, 'pending'=>0, 'cancelled'=>0];
foreach ($bkQ->fetchAll(PDO::FETCH_OBJ) as $bk) {
    $bkStats['total'] += $bk->cnt;
    if ($bk->status == 1) $bkStats['confirmed'] = $bk->cnt;
    if ($bk->status == 0) $bkStats['pending']   = $bk->cnt;
    if ($bk->status == 2) $bkStats['cancelled'] = $bk->cnt;
}
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
<title>TMS | My Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.errorWrap{padding:10px;margin:0 0 16px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.succWrap {padding:10px;margin:0 0 16px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.profile-wrap{display:flex;gap:24px;flex-wrap:wrap;max-width:900px;margin:0 auto;}
.profile-sidebar{flex:0 0 220px;}
.profile-avatar{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--secondary),#27ae60);
    display:flex;align-items:center;justify-content:center;color:#fff;font-size:32px;font-weight:700;
    margin:0 auto 14px;}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;}
.stat-cell{background:var(--white);border-radius:10px;padding:14px 10px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.07);}
.stat-cell .num{font-size:1.6em;font-weight:700;color:var(--secondary);}
.stat-cell .lbl{font-size:11px;color:#aaa;margin-top:2px;}
.profile-main{flex:1;min-width:0;}
.profile-card{background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);padding:28px;}
.profile-card label{font-weight:600;color:#555;font-size:13px;display:block;margin-bottom:4px;}
.profile-card .form-control{border:2px solid #e0e0e0;border-radius:7px;height:42px;font-size:14px;}
.profile-card .form-control:focus{border-color:var(--secondary);box-shadow:none;outline:none;}
.profile-card .form-control[readonly]{background:#f8f9fa;cursor:default;}
.btn-save{background:var(--secondary);color:#fff;border:none;border-radius:7px;padding:11px 32px;font-weight:700;font-size:14px;cursor:pointer;transition:.2s;}
.btn-save:hover{background:#2d6a97;}
.quick-links{background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);padding:20px;margin-top:20px;}
.quick-links a{display:flex;align-items:center;gap:10px;padding:10px 0;color:#555;font-size:14px;border-bottom:1px solid #f0f0f0;text-decoration:none;transition:.2s;}
html[data-theme="dark"] .stat-cell .lbl { color: var(--text-light) !important; }
html[data-theme="dark"] .profile-card label,
html[data-theme="dark"] .quick-links a { color: var(--text-muted) !important; }
.quick-links a:last-child{border-bottom:none;}
.quick-links a:hover{color:var(--secondary);}
.quick-links i{width:20px;color:var(--secondary);}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="banner-1">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s">My Profile</h1>
    </div>
</div>

<div class="privacy">
    <div class="container">
        <div class="profile-wrap">

            <!-- Sidebar -->
            <div class="profile-sidebar wow fadeInLeft" data-wow-delay=".1s">
                <div style="background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);padding:24px;text-align:center;">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user->FullName ?? 'U', 0, 1)); ?>
                    </div>
                    <h5 style="font-weight:700;color:#333;margin:0 0 4px;"><?php echo htmlspecialchars($user->FullName ?? '', ENT_QUOTES,'UTF-8'); ?></h5>
                    <p style="font-size:12px;color:#aaa;margin:0;"><?php echo htmlspecialchars($user->EmailId ?? '', ENT_QUOTES,'UTF-8'); ?></p>
                    <p style="font-size:11px;color:#ccc;margin-top:6px;">
                        Member since <?php echo date('M Y', strtotime($user->RegDate ?? 'now')); ?>
                    </p>
                </div>
                <div class="stat-grid">
                    <div class="stat-cell">
                        <div class="num"><?php echo $bkStats['total']; ?></div>
                        <div class="lbl">Bookings</div>
                    </div>
                    <div class="stat-cell">
                        <div class="num" style="color:#27ae60;"><?php echo $bkStats['confirmed']; ?></div>
                        <div class="lbl">Confirmed</div>
                    </div>
                    <div class="stat-cell">
                        <div class="num" style="color:#f0ad4e;"><?php echo $bkStats['pending']; ?></div>
                        <div class="lbl">Pending</div>
                    </div>
                    <div class="stat-cell">
                        <div class="num" style="color:#e74c3c;"><?php echo $bkStats['cancelled']; ?></div>
                        <div class="lbl">Cancelled</div>
                    </div>
                </div>
                <div class="quick-links" style="margin-top:16px;">
                    <a href="tour-history.php"><i class="fa fa-history"></i> My Bookings</a>
                    <a href="change-password.php"><i class="fa fa-lock"></i> Change Password</a>
                    <!-- FEATURE (Round 115 continuation, master prompt item 8 --
                         Auth & Security Center): customer-facing 2FA's natural
                         home is this existing account hub, next to Change
                         Password, rather than a separate "Security Center"
                         page duplicating what profile.php already is. -->
                    <a href="two-factor-setup.php"><i class="fa fa-shield"></i> Two-Factor Authentication</a>
                    <a href="ai-recommend.php"><i class="fa fa-magic"></i> Get Recommendations</a>
                    <a href="package-list.php"><i class="fa fa-search"></i> Browse Packages</a>
                </div>
            </div>

            <!-- Main form -->
            <div class="profile-main wow fadeInRight" data-wow-delay=".1s">
                <div class="profile-card">
                    <h4 style="font-size:1.05em;font-weight:700;color:#333;border-bottom:2px solid #f0f0f0;padding-bottom:12px;margin-bottom:22px;">
                        <i class="fa fa-user-circle" style="color:var(--secondary)"></i> Edit Profile
                    </h4>

                    <?php if ($error): ?>
                        <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php elseif ($msg): ?>
                        <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?php tms_csrf_field(); ?>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" class="form-control" required maxlength="100"
                                   value="<?php echo htmlspecialchars($user->FullName ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Mobile Number</label>
                            <input type="text" name="mobileno" class="form-control" maxlength="15"
                                   pattern="[\+0-9]{7,15}"
                                   value="<?php echo htmlspecialchars($user->MobileNumber ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email Address <small style="color:#aaa;">(cannot be changed)</small></label>
                            <input type="email" class="form-control" readonly
                                   value="<?php echo htmlspecialchars($user->EmailId ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Registration Date</label>
                            <input type="text" class="form-control" readonly
                                   value="<?php echo htmlspecialchars($user->RegDate ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <button type="submit" name="submit6" class="btn-save">
                            <i class="fa fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>

                <div class="profile-card" style="margin-top:20px;">
                    <h4 style="font-size:1.05em;font-weight:700;color:#333;border-bottom:2px solid #f0f0f0;padding-bottom:12px;margin-bottom:22px;">
                        <i class="fa fa-compass" style="color:var(--secondary)"></i> Travel Preferences
                    </h4>
                    <p style="font-size:12.5px;color:#999;margin-top:-14px;margin-bottom:18px;">
                        Used to personalize your <a href="ai-recommend.php">AI recommendations</a> — entirely optional, and only used if you have personalization enabled in
                        <a href="privacy.php">Privacy settings</a>.
                    </p>

                    <?php if ($prefError): ?>
                        <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($prefError, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php elseif ($prefMsg): ?>
                        <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($prefMsg, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?php tms_csrf_field(); ?>
                        <div class="form-group">
                            <label>Travel Mode</label>
                            <select name="travel_mode" class="form-control">
                                <?php $tm = $travelPrefs['travel_mode']; $modeOpts = [
                                    '' => 'No preference', 'family' => 'Family', 'couple' => 'Couple / Honeymoon',
                                    'solo' => 'Solo', 'business' => 'Business', 'accessible' => 'Accessible Travel',
                                    'group' => 'Group', 'adventure' => 'Adventure',
                                ];
                                foreach ($modeOpts as $val => $label): ?>
                                    <option value="<?php echo htmlspecialchars($val, ENT_QUOTES,'UTF-8'); ?>" <?php echo ($tm===$val)?'selected':''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES,'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Typical Budget</label>
                            <select name="budget_range" class="form-control">
                                <?php $br = $travelPrefs['budget_range']; $budgetOpts = [
                                    '' => 'No preference', 'budget' => 'Budget', 'mid-range' => 'Mid-range', 'luxury' => 'Luxury',
                                ];
                                foreach ($budgetOpts as $val => $label): ?>
                                    <option value="<?php echo htmlspecialchars($val, ENT_QUOTES,'UTF-8'); ?>" <?php echo ($br===$val)?'selected':''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES,'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Preferred Climate</label>
                            <select name="preferred_climate" class="form-control">
                                <?php $pc = $travelPrefs['preferred_climate']; $climateOpts = [
                                    '' => 'No preference', 'any' => 'Any Climate', 'hot' => 'Warm / Tropical',
                                    'cold' => 'Cold / Snow', 'beach' => 'Beach / Coastal', 'mountain' => 'Mountains / Hills',
                                ];
                                foreach ($climateOpts as $val => $label): ?>
                                    <option value="<?php echo htmlspecialchars($val, ENT_QUOTES,'UTF-8'); ?>" <?php echo ($pc===$val)?'selected':''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES,'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Interests <small style="color:#aaa;">(comma-separated, e.g. hiking, museums, beach)</small></label>
                            <input type="text" name="interests" class="form-control" maxlength="500"
                                   value="<?php echo htmlspecialchars(implode(', ', $travelPrefs['interests']), ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Dietary / Food Preference</label>
                            <select name="food_preference" class="form-control">
                                <?php $fp = $travelPrefs['food_preference']; $foodOpts = [
                                    '' => 'No preference', 'no_restriction' => 'No restriction', 'vegetarian' => 'Vegetarian',
                                    'vegan' => 'Vegan', 'halal' => 'Halal', 'kosher' => 'Kosher',
                                ];
                                foreach ($foodOpts as $val => $label): ?>
                                    <option value="<?php echo htmlspecialchars($val, ENT_QUOTES,'UTF-8'); ?>" <?php echo ($fp===$val)?'selected':''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES,'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="submit_travel_prefs" class="btn-save">
                            <i class="fa fa-save"></i> Save Preferences
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

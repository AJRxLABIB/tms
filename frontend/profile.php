<?php
require_once __DIR__ . '/../backend/session-boot.php';
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
        unset($_SESSION['nav_name'], $_SESSION['nav_name_for']);   // header shows the new name straight away
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
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | My Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<link rel="stylesheet" href="css/tms-account.css?v=2">
<style>
.errorWrap{padding:13px 16px;margin:0 0 16px;border-radius:14px;background:var(--tms-bad-bg);color:var(--tms-bad);font-weight:600;}
.succWrap{padding:13px 16px;margin:0 0 16px;border-radius:14px;background:var(--tms-ok-bg);color:var(--tms-ok);font-weight:600;}
.profile-wrap{display:grid;grid-template-columns:300px minmax(0,1fr);gap:24px;align-items:start}
@media(max-width:860px){.profile-wrap{grid-template-columns:1fr}}
.profile-avatar{width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,var(--tms-accent),var(--tms-primary));display:flex;align-items:center;justify-content:center;color:#fff;font-size:34px;font-weight:800;margin:0 auto 12px}
.profile-card label{font-weight:700;color:var(--tms-text);font-size:14px;display:block;margin-bottom:6px}
.profile-card .form-control{width:100%;border:2px solid var(--tms-border);border-radius:14px;height:50px;font-size:16px;padding:0 14px;background:var(--tms-surface);color:var(--tms-text)}
.profile-card .form-control:focus{border-color:var(--tms-primary);box-shadow:var(--tms-ring);outline:none}
.profile-card .form-control[readonly]{background:var(--tms-surface-2);cursor:default}
.profile-card .form-group{margin-bottom:16px}
.btn-save{display:inline-flex;gap:8px;align-items:center;min-height:50px;padding:0 28px;border:0;border-radius:14px;cursor:pointer;font-weight:800;font-size:15px;color:var(--tms-accent-ink,#1d0c05);background:var(--tms-accent);box-shadow:0 8px 20px color-mix(in srgb,var(--tms-accent) 35%,transparent)}
.btn-save:hover{transform:translateY(-1px)}
.tms-mini{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:16px}
.tms-mini div{background:var(--tms-surface-2);border-radius:12px;padding:10px 4px;text-align:center}.tms-mini b{display:block;font-size:20px}.tms-mini span{font-size:11px;color:var(--tms-muted)}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<main class="tms-acct">
<section class="tms-acct-hero reveal"><h1>My Profile</h1><p>Keep your details and travel taste up to date.</p></section>

<div>
    <div>
        <div class="profile-wrap">

            <!-- Sidebar -->
            <div class="profile-sidebar">
                <div class="tms-card" style="text-align:center">
                    <div class="profile-avatar"><?php echo strtoupper(substr($user->FullName ?? 'U', 0, 1)); ?></div>
                    <h2 style="margin:0 0 4px;font-size:19px"><?php echo htmlspecialchars($user->FullName ?? '', ENT_QUOTES,'UTF-8'); ?></h2>
                    <p style="font-size:13px;color:var(--tms-muted);margin:0;"><?php echo htmlspecialchars($user->EmailId ?? '', ENT_QUOTES,'UTF-8'); ?></p>
                    <p style="font-size:12px;color:var(--tms-muted);margin:6px 0 0;">Member since <?php echo date('M Y', strtotime($user->RegDate ?? 'now')); ?></p>
                    <div class="tms-mini">
                        <div><b><?php echo $bkStats['total']; ?></b><span>Bookings</span></div>
                        <div><b><?php echo $bkStats['confirmed']; ?></b><span>Confirmed</span></div>
                        <div><b><?php echo $bkStats['pending']; ?></b><span>Pending</span></div>
                        <div><b><?php echo $bkStats['cancelled']; ?></b><span>Cancelled</span></div>
                    </div>
                </div>
                <div class="tms-card">
                    <h2>Account &amp; privacy</h2>
                    <!-- Customer 2FA lives here next to Change Password (Auth & Security Center). -->
                    <ul class="tms-list">
                        <li><a href="tour-history.php"><i class="fa fa-history l" aria-hidden="true"></i><span>My bookings</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                        <li><a href="change-password.php"><i class="fa fa-lock l" aria-hidden="true"></i><span>Change password</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                        <li><a href="two-factor-setup.php"><i class="fa fa-shield l" aria-hidden="true"></i><span>Two-factor authentication</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                        <li><a href="privacy.php"><i class="fa fa-eye-slash l" aria-hidden="true"></i><span>Privacy settings</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                        <li><a href="wallet.php"><i class="fa fa-briefcase l" aria-hidden="true"></i><span>Travel wallet</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                        <li><a href="ai-memory.php"><i class="fa fa-magic l" aria-hidden="true"></i><span>AI memory</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                        <li><a href="referral.php"><i class="fa fa-gift l" aria-hidden="true"></i><span>Refer a friend</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                        <li><a href="appeal.php"><i class="fa fa-balance-scale l" aria-hidden="true"></i><span>Account review &amp; appeals</span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
                    </ul>
                </div>
            </div>

            <!-- Main form -->
            <div class="profile-main">
                <div class="profile-card tms-card">
                    <h2>
                        <i class="fa fa-user-circle" style="color:var(--secondary)"></i> Edit Profile
                    </h2>

                    <?php if ($error): ?>
                        <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php elseif ($msg): ?>
                        <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?php tms_csrf_field(); ?>
                        <div class="form-group">
                            <label for="fld_name">Full Name *</label>
                            <input id="fld_name" type="text" name="name" class="form-control" required maxlength="100"
                                   value="<?php echo htmlspecialchars($user->FullName ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="fld_mobileno">Mobile Number</label>
                            <input id="fld_mobileno" type="text" name="mobileno" class="form-control" maxlength="15"
                                   pattern="[\+0-9]{7,15}"
                                   value="<?php echo htmlspecialchars($user->MobileNumber ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="pf_email">Email Address <small style="color:var(--text-muted);">(cannot be changed)</small></label>
                            <input type="email" id="pf_email" class="form-control" readonly
                                   value="<?php echo htmlspecialchars($user->EmailId ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="pf_regdate">Registration Date</label>
                            <input type="text" id="pf_regdate" class="form-control" readonly
                                   value="<?php echo htmlspecialchars($user->RegDate ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <button type="submit" name="submit6" class="btn-save">
                            <i class="fa fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>

                <div class="profile-card tms-card">
                    <h2>
                        <i class="fa fa-compass" style="color:var(--secondary)"></i> Travel Preferences
                    </h2>
                    <p style="font-size:12.5px;color:var(--text-muted);margin-top:-14px;margin-bottom:18px;">
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
                            <label for="fld_travel_mode">Travel Mode</label>
                            <select name="travel_mode" class="form-control" id="fld_travel_mode">
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
                            <label for="fld_budget_range">Typical Budget</label>
                            <select name="budget_range" class="form-control" id="fld_budget_range">
                                <?php $br = $travelPrefs['budget_range']; $budgetOpts = [
                                    '' => 'No preference', 'budget' => 'Budget', 'mid-range' => 'Mid-range', 'luxury' => 'Luxury',
                                ];
                                foreach ($budgetOpts as $val => $label): ?>
                                    <option value="<?php echo htmlspecialchars($val, ENT_QUOTES,'UTF-8'); ?>" <?php echo ($br===$val)?'selected':''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES,'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fld_preferred_climate">Preferred Climate</label>
                            <select name="preferred_climate" class="form-control" id="fld_preferred_climate">
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
                            <label for="fld_interests">Interests <small style="color:var(--text-muted);">(comma-separated, e.g. hiking, museums, beach)</small></label>
                            <input id="fld_interests" type="text" name="interests" class="form-control" maxlength="500"
                                   value="<?php echo htmlspecialchars(implode(', ', $travelPrefs['interests']), ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="fld_food_preference">Dietary / Food Preference</label>
                            <select name="food_preference" class="form-control" id="fld_food_preference">
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

</main>
<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

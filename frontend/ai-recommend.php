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

$recommendations = [];
$searched = false;
$totalPackagesConsidered = 0;

// FIX (Round 20 audit, spec items 31/66): this page used to run its own
// flat point-scoring loop (budget/type/climate/duration/rating, each a
// fixed +N) that never showed its work beyond one blended "match %". A
// separate, better-built engine -- backend/ai/TravelRecommender.php --
// already existed with per-dimension scores (budget/weather/style/safety/
// experience), a "why recommended" explanation, warnings, and highlights
// (the transparency the spec explicitly asks for), reachable only via
// api/ai.php?action=recommend, which nothing on this site ever called. It
// was dead code. Wired this page to it instead of maintaining two
// recommendation engines; its own AIClient failure path already falls back
// to a deterministic rule-based scorer (same shape as the AI path) if the
// AI call fails, so this is not a new single point of failure.
if(isset($_POST['recommend'])) {
    tms_csrf_validate();
    $searched = true;
    $budget = intval($_POST['budget']);
    $traveltype = $_POST['traveltype'];
    $duration = intval($_POST['duration']);
    $climate = $_POST['climate'];
    $travelers = intval($_POST['travelers']);
    $activities = trim($_POST['activities'] ?? '');
    $accommodation = $_POST['accommodation'] ?? 'any';
    $foodPreference = $_POST['food_preference'] ?? '';

    try {
        $totalPackagesConsidered = (int)$dbh->query("SELECT COUNT(*) FROM tbltourpackages WHERE Status = 1")->fetchColumn();
    } catch (Exception $e) {
        $totalPackagesConsidered = 0;
    }

    require_once dirname(__DIR__) . '/backend/ai/TravelRecommender.php';
    $recommender = new TravelRecommender($dbh);
    $prefs = [
        'budget'        => $budget,
        'travelers'     => $travelers,
        // No specific dates are collected on this quick-recommender form
        // (that happens later, at booking) -- say so honestly rather than
        // inventing a date range the user never chose.
        'start_date'    => 'Flexible / not yet chosen',
        'end_date'      => 'Flexible / not yet chosen',
        'duration'      => $duration,
        'travel_type'   => $traveltype,
        'climate'       => $climate,
        'activities'    => $activities !== '' ? $activities : 'No specific preference',
        'accommodation' => $accommodation,
        'food_preference' => $foodPreference !== '' ? $foodPreference : 'No specific preference',
    ];
    $emailForRec = !empty($_SESSION['login']) ? $_SESSION['login'] : 'guest';
    $recommendations = $recommender->recommend($prefs, $emailForRec);

    // Save preference if logged in
    if(!empty($_SESSION['login'])) {
        $useremail = $_SESSION['login'];
        try {
            $sql2 = "INSERT INTO tblrecommendations (UserEmail,Budget,TravelType,Duration,Climate) VALUES(:ue,:b,:tt,:d,:c)";
            $q2 = $dbh->prepare($sql2);
            $q2->bindParam(':ue',$useremail); $q2->bindParam(':b',$budget);
            $q2->bindParam(':tt',$traveltype); $q2->bindParam(':d',$duration);
            $q2->bindParam(':c',$climate); $q2->execute();
        } catch (Exception $e) {
            error_log('[TMS Recommend] Preference save failed (recommendations still shown): ' . $e->getMessage());
        }
    }
}

// NEW (master prompt item 67 — Travel Modes): if the visitor is logged in
// and hasn't submitted this form yet this request, pre-select their saved
// travel_mode/preferred_climate from tbl_user_profile (profile.php's new
// "Travel Preferences" card) as the form defaults. Purely a convenience --
// the fields stay fully editable, and a guest or a user with no saved
// preference just sees the existing defaults (nothing invented).
$savedProfile = null;
if (!isset($_POST['recommend']) && !empty($_SESSION['login'])) {
    try {
        // food_preference added Round 77 (master prompt item 23 --
        // Personalization) -- same prefill-only convenience as
        // travel_mode/preferred_climate, still fully editable per request.
        $pq = $dbh->prepare("SELECT travel_mode, preferred_climate, food_preference FROM tbl_user_profile WHERE user_email = :e LIMIT 1");
        $pq->execute([':e' => $_SESSION['login']]);
        $savedProfile = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $savedProfile = null; // self-healing column may not exist yet on a very old install -- fine, just no prefill
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
<title>TMS | AI Smart Tour Recommendation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="css/animate.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<script src="<?php echo tms_asset_ver('js/ai-feedback.js'); ?>" defer></script>
<style>
.ai-hero { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); padding: 60px 0; text-align:center; color:#fff; }
.ai-hero h1 { font-size: 2.5em; margin-bottom:10px; }
.ai-hero p { font-size: 1.1em; opacity:0.85; }
.ai-badge { background: linear-gradient(135deg,#176B50,#104B3A); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; display:inline-block; margin-bottom:20px; }
.filter-card { background:var(--white); border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.1); padding:35px; margin: -30px auto 40px; max-width:900px; position:relative; z-index:10; }
.filter-card h3 { color:#333; margin-bottom:25px; font-size:1.4em; }
.form-group label { font-weight:600; color:#555; margin-bottom:6px; display:block; }
.form-control { border:2px solid #e8ecf0; border-radius:8px; padding:10px 14px; font-size:14px; transition:0.3s; }
.form-control:focus { border-color:#176B50; box-shadow:0 0 0 3px rgba(23,107,80,0.1); outline:none; }
.btn-ai { background: linear-gradient(135deg,#176B50,#104B3A); color:#fff; border:none; border-radius:8px; padding:12px 35px; font-size:15px; font-weight:700; cursor:pointer; width:100%; margin-top:10px; transition:0.3s; }
.btn-ai:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(23,107,80,0.4); color:#fff; }
.result-section { padding:20px 0 50px; }
.pkg-card { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow:hidden; margin-bottom:25px; transition:0.3s; border:1px solid #f0f0f0; }
.pkg-card:hover { transform:translateY(-4px); box-shadow:0 12px 35px rgba(0,0,0,0.15); }
.pkg-card img { width:100%; height:180px; object-fit:cover; }
.pkg-card-body { padding:18px; }
.match-badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700; margin-bottom:8px; }
.match-high { background:#d4edda; color:#155724; }
.match-med { background:#fff3cd; color:#856404; }
.match-low { background:#f8d7da; color:#721c24; }
.pkg-card-body h4 { font-size:1em; font-weight:700; color:#333; margin-bottom:5px; }
.pkg-card-body p { font-size:13px; color:var(--text-muted); margin-bottom:4px; }
.price-tag { font-size:1.3em; font-weight:700; color:#176B50; }
.stars { color:#f0ad4e; font-size:13px; }
.match-bar-wrap { background:#eee; border-radius:10px; height:6px; margin:10px 0; }
.match-bar { height:6px; border-radius:10px; background: linear-gradient(90deg,#176B50,#104B3A); transition:width 1s ease; }
.btn-view { background:#176B50; color:#fff; border-radius:6px; padding:8px 20px; font-size:13px; font-weight:600; display:inline-block; margin-top:10px; }
.btn-view:hover { background:#5a6fd6; color:#fff; }
.ai-tip { background: linear-gradient(135deg,#e8f4fd,#f0e8fd); border-radius:10px; padding:18px; margin-bottom:25px; border-left:4px solid #176B50; }
.ai-tip i { color:#176B50; margin-right:8px; }
.no-results { text-align:center; padding:60px 20px; }
.no-results i { font-size:60px; color:#ddd; display:block; margin-bottom:20px; }
.input-group-icon { position:relative; }
.input-group-icon .fa { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); z-index:5; }
html[data-theme="dark"] .filter-card h3,
html[data-theme="dark"] .pkg-card-body h4 { color: var(--text) !important; }
html[data-theme="dark"] .form-group label,
html[data-theme="dark"] .pkg-card-body p,
html[data-theme="dark"] .input-group-icon .fa { color: var(--text-muted) !important; }
.input-group-icon .form-control { padding-left:35px; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="ai-hero">
    <div class="container">
        <span class="ai-badge"><i class="fa fa-magic"></i> AI-POWERED</span>
        <h1><i class="fa fa-compass"></i> Smart Tour Recommender</h1>
        <p>Tell us your preferences — our AI engine finds the perfect package for you</p>
    </div>
</div>

<div class="container">
    <div class="filter-card wow fadeInUp">
        <h3><i class="fa fa-sliders" style="color:#176B50"></i> Your Travel Preferences</h3>
        <form method="post">
        <?php tms_csrf_field(); ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="fld_budget"><i class="fa fa-money"></i> Total Budget (৳)</label>
                        <div class="input-group-icon">
                            <span class="fa" style="font-weight:700;font-style:normal;font-family:Arial,sans-serif;">৳</span>
                            <input type="number" id="fld_budget" name="budget" class="form-control" placeholder="e.g. 5000" min="100" value="<?php echo isset($_POST['budget'])?htmlentities($_POST['budget']):''; ?>" required>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="fld_travelers"><i class="fa fa-users"></i> Number of Travelers</label>
                        <select name="travelers" class="form-control" id="fld_travelers">
                            <?php for($i=1;$i<=10;$i++) echo "<option value='$i' ".((isset($_POST['travelers'])&&$_POST['travelers']==$i)?'selected':'').">$i Person".($i>1?'s':'')."</option>"; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="fld_duration"><i class="fa fa-calendar"></i> Trip Duration (days)</label>
                        <select name="duration" class="form-control" id="fld_duration">
                            <option value="3" <?php echo (isset($_POST['duration'])&&$_POST['duration']==3)?'selected':''; ?>>3–5 days</option>
                            <option value="7" <?php echo (isset($_POST['duration'])&&$_POST['duration']==7)?'selected':''; ?>>5–7 days</option>
                            <option value="10" <?php echo (isset($_POST['duration'])&&$_POST['duration']==10)?'selected':''; ?>>7–10 days</option>
                            <option value="14" <?php echo (isset($_POST['duration'])&&$_POST['duration']==14)?'selected':''; ?>>10–14 days</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="fld_traveltype"><i class="fa fa-heart"></i> Travel Style</label>
                        <?php
                        // Pre-select from: this submission > the user's saved
                        // profile preference > the original 'family' default.
                        $selectedMode = $_POST['traveltype'] ?? ($savedProfile['travel_mode'] ?? '') ?: 'family';
                        ?>
                        <select name="traveltype" class="form-control" id="fld_traveltype">
                            <option value="family" <?php echo ($selectedMode=='family')?'selected':''; ?>>Family Vacation</option>
                            <option value="couple" <?php echo ($selectedMode=='couple')?'selected':''; ?>>Couple / Honeymoon</option>
                            <option value="group" <?php echo ($selectedMode=='group')?'selected':''; ?>>Group Tour</option>
                            <option value="solo" <?php echo ($selectedMode=='solo')?'selected':''; ?>>Solo Travel</option>
                            <option value="business" <?php echo ($selectedMode=='business')?'selected':''; ?>>Business Trip</option>
                            <option value="accessible" <?php echo ($selectedMode=='accessible')?'selected':''; ?>>Accessible Travel</option>
                            <option value="adventure" <?php echo ($selectedMode=='adventure')?'selected':''; ?>>Adventure</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="fld_climate"><i class="fa fa-sun-o"></i> Preferred Climate</label>
                        <?php $selectedClimate = $_POST['climate'] ?? ($savedProfile['preferred_climate'] ?? 'any'); ?>
                        <select name="climate" class="form-control" id="fld_climate">
                            <option value="any" <?php echo ($selectedClimate=='any')?'selected':''; ?>>Any Climate</option>
                            <option value="hot" <?php echo ($selectedClimate=='hot')?'selected':''; ?>>Warm / Tropical</option>
                            <option value="cold" <?php echo ($selectedClimate=='cold')?'selected':''; ?>>Cold / Snow</option>
                            <option value="beach" <?php echo ($selectedClimate=='beach')?'selected':''; ?>>Beach / Coastal</option>
                            <option value="mountain" <?php echo ($selectedClimate=='mountain')?'selected':''; ?>>Mountains / Hills</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="fld_accommodation"><i class="fa fa-bed"></i> Accommodation Preference</label>
                        <select name="accommodation" class="form-control" id="fld_accommodation">
                            <option value="any">No preference</option>
                            <option value="budget" <?php echo (isset($_POST['accommodation'])&&$_POST['accommodation']=='budget')?'selected':''; ?>>Budget</option>
                            <option value="mid-range" <?php echo (isset($_POST['accommodation'])&&$_POST['accommodation']=='mid-range')?'selected':''; ?>>Mid-range</option>
                            <option value="luxury" <?php echo (isset($_POST['accommodation'])&&$_POST['accommodation']=='luxury')?'selected':''; ?>>Luxury</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="fld_activities"><i class="fa fa-star-o"></i> Activities You Enjoy (optional)</label>
                        <input id="fld_activities" type="text" name="activities" class="form-control" placeholder="e.g. hiking, museums, beach relaxation" value="<?php echo isset($_POST['activities'])?htmlentities($_POST['activities']):''; ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="fld_food"><i class="fa fa-cutlery"></i> Dietary / Food Preference (optional)</label>
                        <?php
                        // NEW (Round 77, master prompt item 23 -- Personalization): prefills
                        // from the saved profile like Travel Type/Climate above, still fully
                        // editable per request -- same pattern, not a new one.
                        $selectedFood = $_POST['food_preference'] ?? ($savedProfile['food_preference'] ?? '') ?: '';
                        ?>
                        <select name="food_preference" class="form-control" id="fld_food">
                            <option value="">No preference</option>
                            <option value="no_restriction" <?php echo ($selectedFood=='no_restriction')?'selected':''; ?>>No restriction</option>
                            <option value="vegetarian" <?php echo ($selectedFood=='vegetarian')?'selected':''; ?>>Vegetarian</option>
                            <option value="vegan" <?php echo ($selectedFood=='vegan')?'selected':''; ?>>Vegan</option>
                            <option value="halal" <?php echo ($selectedFood=='halal')?'selected':''; ?>>Halal</option>
                            <option value="kosher" <?php echo ($selectedFood=='kosher')?'selected':''; ?>>Kosher</option>
                        </select>
                    </div>
                </div>
            </div>
            <button type="submit" name="recommend" class="btn-ai">
                <i class="fa fa-magic"></i> &nbsp; Find My Perfect Tour
            </button>
        </form>
    </div>

    <?php if($searched): ?>
    <div class="result-section">
        <?php if(count($recommendations) > 0): ?>
        <div class="ai-tip">
            <i class="fa fa-lightbulb-o"></i>
            <strong>AI Tip:</strong> We considered <?php echo (int)$totalPackagesConsidered; ?> active packages and found
            <strong><?php echo count($recommendations); ?></strong> matches based on your budget of
            <strong><?php echo tms_currency($budget); ?></strong> for
            <strong><?php echo (int)$travelers; ?></strong> traveler(s).
            Each match below shows exactly why it was recommended.
        </div>
        <h3 style="margin-bottom:25px;"><i class="fa fa-star" style="color:#f0ad4e"></i> Recommended Packages</h3>
        <div class="row">
        <?php foreach($recommendations as $pkg):
            $overall = (int)($pkg['overall_match'] ?? 0);
            $matchClass = $overall >= 70 ? 'match-high' : ($overall >= 40 ? 'match-med' : 'match-low');
            $matchLabel = $overall >= 70 ? 'Great Match' : ($overall >= 40 ? 'Good Match' : 'Partial Match');
            $scores = $pkg['scores'] ?? [];
            // FIX (spec items 31/66, "recommendation transparency"): show the
            // actual per-dimension scores as a checklist, not just one blended
            // percentage -- this is real data from the engine's own $scores
            // array, not invented for display.
            $scoreLabels = [
                'budget_match'         => 'Budget compatible',
                'weather_suitability'  => 'Good season/weather fit',
                'style_match'          => 'Matches your travel style',
                'safety'               => 'Good safety profile',
                'experience'           => 'Strong experience rating',
            ];
        ?>
        <div class="col-md-4 wow fadeInUp">
            <div class="pkg-card">
                <img loading="lazy" src="<?php echo htmlentities(tms_package_image_src($pkg['image'] ?? '')); ?>" onerror="this.src='images/no-image-placeholder.jpg'" alt="<?php echo htmlentities($pkg['package_name'] ?? ''); ?>">
                <div class="pkg-card-body">
                    <span class="match-badge <?php echo $matchClass; ?>"><i class="fa fa-check-circle"></i> <?php echo $matchLabel; ?> <?php echo $overall; ?>%</span>
                    <div class="match-bar-wrap"><div class="match-bar" style="width:<?php echo $overall; ?>%"></div></div>
                    <h4><?php echo htmlentities($pkg['package_name'] ?? 'Package'); ?></h4>
                    <p><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlentities($pkg['destination'] ?? ''); ?></p>
                    <?php if (!empty($pkg['why_recommended'])): ?>
                    <p style="font-size:13px;color:#555;font-style:italic;">"<?php echo htmlentities($pkg['why_recommended']); ?>"</p>
                    <?php
                    // Round 115, master prompt item 14 ("AI Feedback Loop"): a
                    // thumbs up/down on the recommendation itself, right next to
                    // the "why" text it's rating. See js/ai-feedback.js.
                    $pkgIdForFeedback = (int)($pkg['package_id'] ?? 0);
                    ?>
                    <div class="tms-ai-feedback" style="margin:2px 0 8px;font-size:12px;color:var(--text-muted);">
                        Helpful?
                        <button type="button" onclick="tmsSendAiFeedback('recommend', <?php echo $pkgIdForFeedback; ?>, 1, this)" style="border:none;background:none;color:#27ae60;cursor:pointer;padding:2px 6px;" title="Yes, helpful"><i class="fa fa-thumbs-up"></i></button>
                        <button type="button" onclick="tmsSendAiFeedback('recommend', <?php echo $pkgIdForFeedback; ?>, -1, this)" style="border:none;background:none;color:#c0392b;cursor:pointer;padding:2px 6px;" title="No, not helpful"><i class="fa fa-thumbs-down"></i></button>
                    </div>
                    <?php endif; ?>
                    <?php if ($scores): ?>
                    <ul style="list-style:none;padding:0;margin:8px 0;font-size:12px;">
                        <?php foreach ($scoreLabels as $key => $label): if (!isset($scores[$key])) continue;
                            $good = (int)$scores[$key] >= 60; ?>
                        <li style="color:<?php echo $good ? '#27ae60' : '#aaa'; ?>;margin-bottom:2px;">
                            <i class="fa <?php echo $good ? 'fa-check-circle' : 'fa-minus-circle'; ?>"></i>
                            <?php echo htmlentities($label); ?> (<?php echo (int)$scores[$key]; ?>%)
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if (!empty($pkg['warnings']) && is_array($pkg['warnings'])): foreach ($pkg['warnings'] as $w): ?>
                    <p style="font-size:12px;color:#c0392b;"><i class="fa fa-exclamation-triangle"></i> <?php echo htmlentities($w); ?></p>
                    <?php endforeach; endif; ?>
                    <p class="price-tag"><?php echo tms_currency($pkg['price'] ?? 0); ?> <small style="font-size:12px;color:var(--text-muted)">/ person</small></p>
                    <p style="font-size:12px;color:#27ae60;font-weight:600">Total for <?php echo (int)$travelers; ?> traveler(s): <?php echo tms_currency($pkg['estimated_total_cost'] ?? (($pkg['price'] ?? 0) * $travelers)); ?></p>
                    <?php if (!empty($pkg['cost_savings_tip'])): ?>
                    <p style="font-size:12px;color:var(--text-muted);"><i class="fa fa-piggy-bank"></i> <?php echo htmlentities($pkg['cost_savings_tip']); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo htmlentities($pkg['package_url'] ?? ('package-details.php?pkgid=' . (int)($pkg['package_id'] ?? 0))); ?>" class="btn-view">View & Book <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-results">
            <i class="fa fa-search"></i>
            <h3>No Packages Found</h3>
            <p>Try increasing your budget or changing your preferences.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>
<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see includes/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.

$recommendations = [];
$searched = false;

if(isset($_POST['recommend'])) {
    tms_csrf_validate();
    $searched = true;
    $budget = intval($_POST['budget']);
    $traveltype = $_POST['traveltype'];
    $duration = intval($_POST['duration']);
    $climate = $_POST['climate'];
    $travelers = intval($_POST['travelers']);

    // Smart scoring algorithm - only fetch active packages
    $sql = "SELECT * FROM tbltourpackages WHERE Status = 1 ORDER BY PackageId DESC LIMIT 200";
    $query = $dbh->prepare($sql);
    $query->execute();
    $allPackages = $query->fetchAll(PDO::FETCH_OBJ);

    foreach($allPackages as $pkg) {
        $score = 0;

        // Budget scoring (within 50% above budget = valid)
        $pricePerPerson = $pkg->PackagePrice;
        $totalCost = $pricePerPerson * $travelers;
        if($totalCost <= $budget) $score += 40;
        elseif($totalCost <= $budget * 1.2) $score += 20;
        elseif($totalCost <= $budget * 1.5) $score += 5;

        // Travel type scoring
        $typeMap = [
            'family' => ['Family Package'],
            'couple' => ['Couple Package'],
            'group' => ['Group Package', 'Group'],
            'solo' => ['Domestic Packages'],
            'adventure' => ['Group Package', 'Domestic Packages']
        ];
        if(isset($typeMap[$traveltype])) {
            foreach($typeMap[$traveltype] as $t) {
                if(stripos($pkg->PackageType, $t) !== false) { $score += 30; break; }
            }
        }

        // Climate scoring based on location
        $hotDestinations = ['Dubai','Thailand','Bali','Kerala','Indonesia'];
        $coldDestinations = ['Switzerland','Bhutan','Sikkim','Darjeeling','Lachung'];
        $beachDestinations = ['Bali','Kerala','Dubai','Goa'];
        $mountainDestinations = ['Bhutan','Sikkim','Darjeeling','Lachung','Switzerland'];

        $locationMatch = false;
        if($climate === 'hot') {
            foreach($hotDestinations as $d) if(stripos($pkg->PackageLocation, $d)!==false) { $score+=20; $locationMatch=true; break; }
        } elseif($climate === 'cold') {
            foreach($coldDestinations as $d) if(stripos($pkg->PackageLocation, $d)!==false) { $score+=20; $locationMatch=true; break; }
        } elseif($climate === 'beach') {
            foreach($beachDestinations as $d) if(stripos($pkg->PackageLocation, $d)!==false) { $score+=20; $locationMatch=true; break; }
        } elseif($climate === 'mountain') {
            foreach($mountainDestinations as $d) if(stripos($pkg->PackageLocation, $d)!==false) { $score+=20; $locationMatch=true; break; }
        } else {
            $score += 10; // 'any' preference
        }

        // Duration scoring - loose match
        if($duration >= 5 && $duration <= 10) $score += 10;
        elseif($duration > 10) $score += 5;

        // Rating boost
        if(isset($pkg->AvgRating) && $pkg->AvgRating >= 4) $score += 10;

        $pkg->score = $score;
        $pkg->matchPercent = min(100, round(($score / 100) * 100));
        $pkg->estimatedTotal = $pricePerPerson * $travelers;
        $recommendations[] = $pkg;
    }

    // Sort by score descending
    usort($recommendations, function($a, $b) { return $b->score - $a->score; });
    $recommendations = array_slice($recommendations, 0, 6);

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
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | AI Smart Tour Recommendation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link href="css/animate.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<style>
.ai-hero { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); padding: 60px 0; text-align:center; color:#fff; }
.ai-hero h1 { font-size: 2.5em; margin-bottom:10px; }
.ai-hero p { font-size: 1.1em; opacity:0.85; }
.ai-badge { background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; display:inline-block; margin-bottom:20px; }
.filter-card { background:var(--white); border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.1); padding:35px; margin: -30px auto 40px; max-width:900px; position:relative; z-index:10; }
.filter-card h3 { color:#333; margin-bottom:25px; font-size:1.4em; }
.form-group label { font-weight:600; color:#555; margin-bottom:6px; display:block; }
.form-control { border:2px solid #e8ecf0; border-radius:8px; padding:10px 14px; font-size:14px; transition:0.3s; }
.form-control:focus { border-color:#667eea; box-shadow:0 0 0 3px rgba(102,126,234,0.1); outline:none; }
.btn-ai { background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; border:none; border-radius:8px; padding:12px 35px; font-size:15px; font-weight:700; cursor:pointer; width:100%; margin-top:10px; transition:0.3s; }
.btn-ai:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(102,126,234,0.4); color:#fff; }
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
.pkg-card-body p { font-size:13px; color:#777; margin-bottom:4px; }
.price-tag { font-size:1.3em; font-weight:700; color:#667eea; }
.stars { color:#f0ad4e; font-size:13px; }
.match-bar-wrap { background:#eee; border-radius:10px; height:6px; margin:10px 0; }
.match-bar { height:6px; border-radius:10px; background: linear-gradient(90deg,#667eea,#764ba2); transition:width 1s ease; }
.btn-view { background:#667eea; color:#fff; border-radius:6px; padding:8px 20px; font-size:13px; font-weight:600; display:inline-block; margin-top:10px; }
.btn-view:hover { background:#5a6fd6; color:#fff; }
.ai-tip { background: linear-gradient(135deg,#e8f4fd,#f0e8fd); border-radius:10px; padding:18px; margin-bottom:25px; border-left:4px solid #667eea; }
.ai-tip i { color:#667eea; margin-right:8px; }
.no-results { text-align:center; padding:60px 20px; }
.no-results i { font-size:60px; color:#ddd; display:block; margin-bottom:20px; }
.input-group-icon { position:relative; }
.input-group-icon .fa { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#aaa; z-index:5; }
html[data-theme="dark"] .filter-card h3,
html[data-theme="dark"] .pkg-card-body h4 { color: var(--text) !important; }
html[data-theme="dark"] .form-group label,
html[data-theme="dark"] .pkg-card-body p,
html[data-theme="dark"] .input-group-icon .fa { color: var(--text-muted) !important; }
.input-group-icon .form-control { padding-left:35px; }
</style>
</head>
<body>
<?php include('includes/header.php'); ?>

<div class="ai-hero">
    <div class="container">
        <span class="ai-badge"><i class="fa fa-magic"></i> AI-POWERED</span>
        <h1><i class="fa fa-compass"></i> Smart Tour Recommender</h1>
        <p>Tell us your preferences — our AI engine finds the perfect package for you</p>
    </div>
</div>

<div class="container">
    <div class="filter-card wow fadeInUp">
        <h3><i class="fa fa-sliders" style="color:#667eea"></i> Your Travel Preferences</h3>
        <form method="post">
        <?php tms_csrf_field(); ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label><i class="fa fa-money"></i> Total Budget (৳)</label>
                        <div class="input-group-icon">
                            <span class="fa" style="font-weight:700;font-style:normal;font-family:Arial,sans-serif;">৳</span>
                            <input type="number" name="budget" class="form-control" placeholder="e.g. 5000" min="100" value="<?php echo isset($_POST['budget'])?htmlentities($_POST['budget']):''; ?>" required>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label><i class="fa fa-users"></i> Number of Travelers</label>
                        <select name="travelers" class="form-control">
                            <?php for($i=1;$i<=10;$i++) echo "<option value='$i' ".((isset($_POST['travelers'])&&$_POST['travelers']==$i)?'selected':'').">$i Person".($i>1?'s':'')."</option>"; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label><i class="fa fa-calendar"></i> Trip Duration (days)</label>
                        <select name="duration" class="form-control">
                            <option value="3" <?php echo (isset($_POST['duration'])&&$_POST['duration']==3)?'selected':''; ?>>3–5 days</option>
                            <option value="7" <?php echo (isset($_POST['duration'])&&$_POST['duration']==7)?'selected':''; ?>>5–7 days</option>
                            <option value="10" <?php echo (isset($_POST['duration'])&&$_POST['duration']==10)?'selected':''; ?>>7–10 days</option>
                            <option value="14" <?php echo (isset($_POST['duration'])&&$_POST['duration']==14)?'selected':''; ?>>10–14 days</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fa fa-heart"></i> Travel Style</label>
                        <select name="traveltype" class="form-control">
                            <option value="family" <?php echo (isset($_POST['traveltype'])&&$_POST['traveltype']=='family')?'selected':''; ?>>Family Vacation</option>
                            <option value="couple" <?php echo (isset($_POST['traveltype'])&&$_POST['traveltype']=='couple')?'selected':''; ?>>Couple / Honeymoon</option>
                            <option value="group" <?php echo (isset($_POST['traveltype'])&&$_POST['traveltype']=='group')?'selected':''; ?>>Group Tour</option>
                            <option value="solo" <?php echo (isset($_POST['traveltype'])&&$_POST['traveltype']=='solo')?'selected':''; ?>>Solo Travel</option>
                            <option value="adventure" <?php echo (isset($_POST['traveltype'])&&$_POST['traveltype']=='adventure')?'selected':''; ?>>Adventure</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fa fa-sun-o"></i> Preferred Climate</label>
                        <select name="climate" class="form-control">
                            <option value="any">Any Climate</option>
                            <option value="hot" <?php echo (isset($_POST['climate'])&&$_POST['climate']=='hot')?'selected':''; ?>>Warm / Tropical</option>
                            <option value="cold" <?php echo (isset($_POST['climate'])&&$_POST['climate']=='cold')?'selected':''; ?>>Cold / Snow</option>
                            <option value="beach" <?php echo (isset($_POST['climate'])&&$_POST['climate']=='beach')?'selected':''; ?>>Beach / Coastal</option>
                            <option value="mountain" <?php echo (isset($_POST['climate'])&&$_POST['climate']=='mountain')?'selected':''; ?>>Mountains / Hills</option>
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
            <strong>AI Tip:</strong> We analyzed <?php echo count($allPackages); ?> packages and found
            <strong><?php echo count($recommendations); ?></strong> matches based on your budget of
            <strong><?php echo tms_currency($budget); ?></strong> for
            <strong><?php echo (int)$travelers; ?></strong> traveler(s).
            Top matches are highlighted in green.
        </div>
        <h3 style="margin-bottom:25px;"><i class="fa fa-star" style="color:#f0ad4e"></i> Recommended Packages</h3>
        <div class="row">
        <?php foreach($recommendations as $pkg):
            $matchClass = $pkg->matchPercent >= 70 ? 'match-high' : ($pkg->matchPercent >= 40 ? 'match-med' : 'match-low');
            $matchLabel = $pkg->matchPercent >= 70 ? 'Great Match' : ($pkg->matchPercent >= 40 ? 'Good Match' : 'Partial Match');
        ?>
        <div class="col-md-4 wow fadeInUp">
            <div class="pkg-card">
                <img loading="lazy" src="<?php echo htmlentities(tms_package_image_src($pkg->PackageImage)); ?>" onerror="this.src='images/no-image-placeholder.jpg'" alt="<?php echo htmlentities($pkg->PackageName); ?>">
                <div class="pkg-card-body">
                    <span class="match-badge <?php echo $matchClass; ?>"><i class="fa fa-check-circle"></i> <?php echo $matchLabel; ?> <?php echo $pkg->matchPercent; ?>%</span>
                    <div class="match-bar-wrap"><div class="match-bar" style="width:<?php echo $pkg->matchPercent; ?>%"></div></div>
                    <h4><?php echo htmlentities($pkg->PackageName); ?></h4>
                    <p><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlentities($pkg->PackageLocation); ?></p>
                    <p><i class="fa fa-tag" style="color:#3498db"></i> <?php echo htmlentities($pkg->PackageType); ?></p>
                    <p class="price-tag"><?php echo tms_currency($pkg->PackagePrice); ?> <small style="font-size:12px;color:#999">/ person</small></p>
                    <p style="font-size:12px;color:#27ae60;font-weight:600">Total for <?php echo (int)$travelers; ?> traveler(s): <?php echo tms_currency($pkg->estimatedTotal); ?></p>
                    <a href="package-details.php?pkgid=<?php echo $pkg->PackageId; ?>" class="btn-view">View & Book <i class="fa fa-arrow-right"></i></a>
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

<?php include('includes/footer.php'); ?>
<?php include('includes/signin.php'); ?>
<?php include('includes/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>
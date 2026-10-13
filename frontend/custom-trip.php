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

$error = ''; $success = '';

if(isset($_POST['submittrip'])) {
    tms_csrf_validate();
    if(empty($_SESSION['login'])) {
        $error = 'Please sign in to submit a custom trip request.';
    } else {
        $userEmail = $_SESSION['login'];
        $sql = "INSERT INTO tblcustomtrips (UserEmail,TripName,Destination,StartDate,EndDate,Budget,TravelersCount,Accommodation,Transport,Activities,MealPlan,SpecialRequests,AiItinerary)
                VALUES(:ue,:tn,:dest,:sd,:ed,:b,:tc,:acc,:trans,:act,:mp,:sr,:ai)";
        $q = $dbh->prepare($sql);
        $q->bindParam(':ue',$userEmail);
        $q->bindParam(':tn',$_POST['tripname']);
        $q->bindParam(':dest',$_POST['destination']);
        $q->bindParam(':sd',$_POST['startdate']);
        $q->bindParam(':ed',$_POST['enddate']);
        $q->bindParam(':b',$_POST['budget']);
        $q->bindParam(':tc',$_POST['travelers']);
        $q->bindParam(':acc',$_POST['accommodation']);
        $q->bindParam(':trans',$_POST['transport']);
        $q->bindParam(':act',$_POST['activities']);
        $q->bindParam(':mp',$_POST['mealplan']);
        $q->bindParam(':sr',$_POST['specialrequests']);
        $aiItinerary = $_POST['ai_itinerary_json'] ?? '';
        $q->bindParam(':ai',$aiItinerary);
        $q->execute();

        // FIX (Round 14): loyalty.php advertises "Custom trip request = 30 pts"
        // and nothing here ever awarded them, so users earned nothing and saw no
        // entry in Points History. Awarded now — but rate-limited to once every
        // 24 hours, because this form has no submit limit and would otherwise be
        // an unlimited points farm (points can be redeemed for discounts).
        try {
            $recent = $dbh->prepare(
                "SELECT COUNT(*) FROM tblloyalty_log
                 WHERE UserEmail = :e AND Description = 'Custom trip request'
                   AND CreatedAt > DATE_SUB(NOW(), INTERVAL 1 DAY)"
            );
            $recent->execute([':e' => $userEmail]);
            if ((int)$recent->fetchColumn() === 0) {
                $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,30,30)
                    ON DUPLICATE KEY UPDATE Points=Points+30, TotalEarned=TotalEarned+30")
                    ->execute([':e' => $userEmail]);
                $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,30,:d)")
                    ->execute([':e' => $userEmail, ':d' => 'Custom trip request']);
            }
        } catch (Exception $e) {
            error_log('[TMS Loyalty] custom-trip points failed (request still saved): ' . $e->getMessage());
        }

        $success = 'Your custom trip request has been submitted! Our team will contact you within 24 hours.';
    }
}

// Load user's past custom trips
$myTrips = [];
if(!empty($_SESSION['login'])) {
    $sqlM = "SELECT * FROM tblcustomtrips WHERE UserEmail=:ue ORDER BY id DESC";
    $qM = $dbh->prepare($sqlM);
    $qM->bindParam(':ue',$_SESSION['login']);
    $qM->execute();
    $myTrips = $qM->fetchAll(PDO::FETCH_OBJ);
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
<title>TMS | Custom Trip Planner</title>
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
<script src="js/wow.min.js" defer></script>
<style>
.custom-hero { background: linear-gradient(135deg,#11998e,#38ef7d); color:#fff; padding:55px 0 30px; text-align:center; }
.custom-hero h1 { font-size:2.3em; }
.step-card { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:28px; margin-bottom:25px; }
.step-card h4 { color:#333; font-size:1.1em; font-weight:700; border-bottom:2px solid #f0f0f0; padding-bottom:10px; margin-bottom:20px; }
.step-num { background: linear-gradient(135deg,#11998e,#38ef7d); color:#fff; width:32px; height:32px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:700; margin-right:10px; font-size:14px; }
.form-control { border:2px solid #e0e0e0; border-radius:8px; padding:10px 14px; }
.form-control:focus { border-color:#11998e; box-shadow:none; outline:none; }
.option-cards { display:flex; flex-wrap:wrap; gap:10px; }
.option-card { border:2px solid #e0e0e0; border-radius:8px; padding:12px 18px; cursor:pointer; transition:0.2s; font-size:14px; }
.option-card:hover, .option-card.selected { border-color:#11998e; background:#e8fdf5; color:#11998e; font-weight:600; }
.option-card i { margin-right:6px; }
.activity-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; }
.activity-item { border:2px solid #e0e0e0; border-radius:8px; padding:12px; text-align:center; cursor:pointer; transition:0.2s; }
.activity-item:hover, .activity-item.selected { border-color:#11998e; background:#e8fdf5; }
.activity-item i { font-size:22px; color:#11998e; display:block; margin-bottom:6px; }
.activity-item span { font-size:13px; font-weight:600; color:#555; }
.btn-custom { background: linear-gradient(135deg,#11998e,#38ef7d); color:#fff; border:none; border-radius:8px; padding:14px 40px; font-weight:700; cursor:pointer; font-size:16px; width:100%; margin-top:10px; }
.btn-custom:hover { opacity:0.9; color:#fff; }
.trip-status { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; }
.status-pending { background:#fff3cd; color:#856404; }
.status-confirmed { background:#d4edda; color:#155724; }
.status-processing { background:#cce5ff; color:#004085; }
.my-trip-card { background:#f8f9fa; border-radius:10px; padding:18px; margin-bottom:12px; border:1px solid #e0e0e0; }
.my-trip-card h5 { color:#11998e; font-weight:700; margin:0 0 8px; }
.progress-steps { display:flex; justify-content:space-between; margin:25px 0; }
.prog-step { text-align:center; flex:1; position:relative; }
.prog-step::after { content:''; position:absolute; top:16px; left:50%; width:100%; height:3px; background:#e0e0e0; z-index:0; }
.prog-step:last-child::after { display:none; }
.prog-dot { width:32px; height:32px; border-radius:50%; background:#e0e0e0; display:flex; align-items:center; justify-content:center; margin:0 auto 8px; font-size:13px; font-weight:700; position:relative; z-index:1; color:#fff; }
.prog-dot.active { background: linear-gradient(135deg,#11998e,#38ef7d); }
.prog-step span { font-size:11px; color:#555; display:block; }
/* ── AI Itinerary Preview ── */
.ai-skeleton{background:var(--white);border-radius:10px;padding:20px;margin-bottom:14px;}
.ai-skel-line{height:14px;border-radius:4px;background:linear-gradient(90deg,#eee 25%,#f5f5f5 37%,#eee 63%);
    background-size:400% 100%;animation:aiShimmer 1.4s ease infinite;margin-bottom:10px;}
@keyframes aiShimmer{0%{background-position:100% 50%;}100%{background-position:0 50%;}}
.ai-error-box{background:#fff3f3;border:1px solid #ffd0d0;border-radius:10px;padding:16px 20px;color:#c0392b;font-size:13.5px;}
.ai-itin-header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:10px;padding:20px 24px;margin-bottom:16px;}
.ai-itin-header h3{margin:0 0 6px;font-size:1.3em;}
.ai-cost-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-top:14px;}
.ai-cost-item{background:rgba(255,255,255,0.15);border-radius:8px;padding:10px;text-align:center;}
.ai-cost-item .amt{font-weight:700;font-size:1.05em;}
.ai-cost-item .lbl{font-size:11px;opacity:0.85;text-transform:uppercase;}
.ai-day-card{background:var(--white);border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.06);margin-bottom:14px;overflow:hidden;}
.ai-day-head{background:#f7f9fc;padding:14px 18px;font-weight:700;display:flex;justify-content:space-between;align-items:center;cursor:pointer;}
.ai-day-body{padding:16px 18px;display:none;}
.ai-day-body.open{display:block;}
.ai-sched-row{display:flex;gap:12px;padding:8px 0;border-bottom:1px dashed #eee;font-size:13.5px;}
.ai-sched-row:last-child{border-bottom:none;}
.ai-sched-time{flex:0 0 60px;color:#667eea;font-weight:700;}
.ai-rec-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:10px;}
.ai-rec-card{background:#f8f9fc;border-radius:8px;padding:12px 14px;font-size:13px;}
.ai-rec-card strong{display:block;color:#333;margin-bottom:3px;}
.ai-tip-list{font-size:13.5px;color:#555;padding-left:18px;}
html[data-theme="dark"] .step-card h4 { color: var(--text) !important; }
html[data-theme="dark"] .activity-item span,
html[data-theme="dark"] .prog-step span,
html[data-theme="dark"] .ai-tip-list { color: var(--text-muted) !important; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="custom-hero">
    <div class="container">
        <h1><i class="fa fa-paint-brush"></i> Build Your Custom Trip</h1>
        <p>Design your dream trip exactly the way you want it</p>
    </div>
</div>

<div class="container" style="padding-top:25px;">
    <?php if($error): ?><div class="alert alert-danger"><i class="fa fa-times"></i> <?php echo $error; ?></div><?php endif; ?>
    <?php if($success): ?><div class="alert alert-success"><i class="fa fa-check"></i> <?php echo $success; ?></div><?php endif; ?>

    <!-- Progress Tracker -->
    <div class="progress-steps wow fadeInUp">
        <div class="prog-step"><div class="prog-dot active">1</div><span>Trip Basics</span></div>
        <div class="prog-step"><div class="prog-dot active">2</div><span>Travel Style</span></div>
        <div class="prog-step"><div class="prog-dot active">3</div><span>Activities</span></div>
        <div class="prog-step"><div class="prog-dot active">4</div><span>Submit</span></div>
    </div>

    <form method="post" id="customForm">
    <?php tms_csrf_field(); ?>
        <!-- Step 1: Basics -->
        <div class="step-card wow fadeInUp">
            <h4><span class="step-num">1</span> Trip Basics</h4>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="ctTripName">Trip Name</label>
                        <input type="text" name="tripname" id="ctTripName" class="form-control" placeholder="My Dream Vacation" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="ctDestination">Destination(s)</label>
                        <input type="text" name="destination" id="ctDestination" class="form-control" placeholder="e.g. Bali, Kerala, Paris" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="ctStartDate">Start Date</label>
                        <input type="date" name="startdate" id="ctStartDate" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="ctEndDate">End Date</label>
                        <input type="date" name="enddate" id="ctEndDate" class="form-control" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="ctTravelers">Number of Travelers</label>
                        <select name="travelers" id="ctTravelers" class="form-control">
                            <?php for($i=1;$i<=15;$i++) echo "<option value='$i'>$i</option>"; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="ctBudget">Total Budget (৳)</label>
                        <input type="number" name="budget" id="ctBudget" class="form-control" placeholder="e.g. 5000" min="100">
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Travel Style -->
        <div class="step-card wow fadeInUp">
            <h4><span class="step-num">2</span> Accommodation Preference</h4>
            <div class="option-cards" id="accCards">
                <?php
                $accOptions = [['fa-home','Budget/Hostel'],['fa-hotel','3-Star Hotel'],['fa-star','4-Star Hotel'],['fa-trophy','5-Star Luxury'],['fa-leaf','Eco Resort'],['fa-ship','Houseboat']];
                foreach($accOptions as $opt): ?>
                <div class="option-card" onclick="selectOption(this,'acc')">
                    <i class="fa <?php echo $opt[0]; ?>"></i> <?php echo $opt[1]; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="accommodation" id="accommodation" value="3-Star Hotel">

            <h4 style="margin-top:25px;"><span class="step-num" style="background:linear-gradient(135deg,#11998e,#38ef7d)">2b</span> Transport Preference</h4>
            <div class="option-cards" id="transCards">
                <?php
                $transOptions = [['fa-plane','Flight'],['fa-train','Train'],['fa-bus','Bus/Coach'],['fa-car','Private Car'],['fa-bicycle','Multi-mode']];
                foreach($transOptions as $opt): ?>
                <div class="option-card" onclick="selectOption(this,'trans')">
                    <i class="fa <?php echo $opt[0]; ?>"></i> <?php echo $opt[1]; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="transport" id="transport" value="Flight">

            <h4 style="margin-top:25px;"><span class="step-num" style="background:linear-gradient(135deg,#11998e,#38ef7d)">2c</span> Meal Plan</h4>
            <div class="option-cards">
                <?php
                $mealOptions = [['fa-coffee','No meals'],['fa-sun-o','Breakfast only'],['fa-cutlery','Half Board'],['fa-star','Full Board'],['fa-leaf','All Inclusive']];
                foreach($mealOptions as $opt): ?>
                <div class="option-card" onclick="selectOption(this,'meal')">
                    <i class="fa <?php echo $opt[0]; ?>"></i> <?php echo $opt[1]; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="mealplan" id="mealplan" value="Breakfast only">
        </div>

        <!-- Step 3: Activities -->
        <div class="step-card wow fadeInUp">
            <h4><span class="step-num">3</span> Preferred Activities</h4>
            <p style="color:#777;font-size:13px;margin-bottom:15px;">Select all that interest you:</p>
            <div class="activity-grid">
                <?php
                $activities = [
                    ['fa-camera','Sightseeing'],['fa-tree','Nature Walks'],['fa-tint','Water Sports'],
                    ['fa-bicycle','Cycling'],['fa-ship','Boat Tours'],['fa-cutlery','Food Tours'],
                    ['fa-shopping-bag','Shopping'],['fa-music','Cultural Shows'],['fa-medkit','Spa/Wellness'],
                    ['fa-paw','Wildlife Safari'],['fa-star','Adventure Sports'],['fa-book','History Tours'],
                ];
                foreach($activities as $act): ?>
                <div class="activity-item" onclick="toggleActivity(this)">
                    <i class="fa <?php echo $act[0]; ?>"></i>
                    <span><?php echo $act[1]; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="activities" id="activitiesInput" value="">
        </div>

        <!-- Step 3.5: AI Itinerary Preview -->
        <div class="step-card wow fadeInUp">
            <h4><span class="step-num" style="background:linear-gradient(135deg,#667eea,#764ba2)"><i class="fa fa-magic" style="font-size:13px;"></i></span> AI Itinerary Preview</h4>
            <p style="color:#777;font-size:13px;margin-bottom:15px;">
                See a complete AI-generated day-by-day plan — itinerary, costs, hotel &amp; restaurant picks, and safety tips —
                before you submit your request. This is optional and doesn't affect your booking.
            </p>
            <button type="button" class="btn-custom" id="genAiBtn" onclick="generateAiItinerary()" style="background:linear-gradient(135deg,#667eea,#764ba2);">
                <i class="fa fa-magic"></i> Generate AI Itinerary Preview
            </button>
            <!-- NEW (AI-trip-planning round): this button used to relabel
                 itself "Regenerate Itinerary" but call the exact same
                 function with identical inputs -- the backend caches by trip
                 details, so a click could silently return the same plan.
                 Revealed after the first successful generation; wired to
                 ItineraryPlanner::regenerate() via action=itinerary_regenerate. -->
            <div id="aiRegenBox" style="display:none;margin-top:12px;">
                <textarea id="aiRegenFeedback" class="form-control" placeholder="What should change? (optional) e.g. cheaper hotels, less walking, add free time…" style="width:100%;min-height:60px;border-radius:8px;border:1px solid #ddd;padding:10px;font-size:13px;"></textarea>
            </div>
            <div id="aiItineraryResult" style="margin-top:20px;"></div>
            <input type="hidden" name="ai_itinerary_json" id="aiItineraryJson" value="">
        </div>

        <!-- Step 4: Special Requests -->
        <div class="step-card wow fadeInUp">
            <h4><span class="step-num">4</span> Special Requests & Submit</h4>
            <div class="form-group">
                <label for="ctSpecialRequests">Any special requirements or notes?</label>
                <textarea name="specialrequests" id="ctSpecialRequests" class="form-control" rows="4" placeholder="e.g. wheelchair access, vegetarian meals, honeymoon setup, early check-in..."></textarea>
            </div>
            <?php if(!empty($_SESSION['login'])): ?>
            <button type="submit" name="submittrip" class="btn-custom" onclick="collectActivities()">
                <i class="fa fa-paper-plane"></i> Submit Custom Trip Request
            </button>
            <?php else: ?>
            <a href="#" data-toggle="modal" data-target="#myModal4" class="btn-custom" style="display:block;text-align:center;text-decoration:none;padding:14px;">
                <i class="fa fa-sign-in"></i> Sign In to Submit Request
            </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- My Trips -->
    <?php if(count($myTrips) > 0): ?>
    <div class="step-card wow fadeInUp">
        <h4><i class="fa fa-history" style="color:#11998e"></i> My Trip Requests</h4>
        <?php foreach($myTrips as $trip):
            $statusClass = ['Pending'=>'status-pending','Confirmed'=>'status-confirmed','Processing'=>'status-processing'];
            $sc = $statusClass[$trip->Status] ?? 'status-pending';
        ?>
        <div class="my-trip-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:5px;">
                <h5><?php echo htmlentities($trip->TripName); ?></h5>
                <span class="trip-status <?php echo $sc; ?>"><?php echo $trip->Status; ?></span>
            </div>
            <div class="row" style="font-size:13px;color:#555;">
                <div class="col-sm-4"><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlentities($trip->Destination); ?></div>
                <div class="col-sm-4"><i class="fa fa-calendar" style="color:#3498db"></i> <?php echo $trip->StartDate; ?> → <?php echo $trip->EndDate; ?></div>
                <div class="col-sm-4"><i class="fa fa-users" style="color:#27ae60"></i> <?php echo $trip->TravelersCount; ?> traveler(s) | <?php echo tms_currency($trip->Budget); ?></div>
            </div>
            <?php if($trip->AdminNote): ?>
            <div style="margin-top:8px;padding:8px 12px;background:#e8fdf5;border-radius:6px;font-size:13px;color:#11998e;">
                <strong>Admin Note:</strong> <?php echo htmlentities($trip->AdminNote); ?>
            </div>
            <?php endif; ?>
            <?php if(!empty($trip->AiItinerary)):
                $savedItin = json_decode($trip->AiItinerary, true);
            ?>
            <div style="margin-top:8px;padding:8px 12px;background:#f0edff;border-radius:6px;font-size:13px;color:#5a4fc0;">
                <i class="fa fa-magic"></i> <strong>AI Itinerary saved:</strong>
                <?php echo htmlentities($savedItin['trip_title'] ?? 'Preview generated'); ?>
                <?php if(!empty($savedItin['estimated_cost']['total'])): ?>
                    · Est. total: ৳<?php echo number_format($savedItin['estimated_cost']['total']); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function selectOption(el, type) {
    var parent = el.parentElement;
    parent.querySelectorAll('.option-card').forEach(function(c){ c.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById(type === 'acc' ? 'accommodation' : type === 'trans' ? 'transport' : 'mealplan').value = el.textContent.trim();
}

var selectedActivities = [];
function toggleActivity(el) {
    var txt = el.querySelector('span').textContent;
    el.classList.toggle('selected');
    if(el.classList.contains('selected')) { selectedActivities.push(txt); }
    else { selectedActivities = selectedActivities.filter(function(a){ return a !== txt; }); }
}

function collectActivities() {
    document.getElementById('activitiesInput').value = selectedActivities.join(', ');
}

// Set first option as default selected
document.querySelectorAll('#accCards .option-card')[2].classList.add('selected');

// ── AI Itinerary Preview ──────────────────────────────────────────────────
function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function toggleAiDay(el) {
    el.nextElementSibling.classList.toggle('open');
}

// NEW (AI-trip-planning round): tracks whether a plan already exists, so a
// second click switches to action=itinerary_regenerate (real feedback +
// forced cache-bust, see ItineraryPlanner::regenerate()) instead of repeating
// the exact same action=itinerary call the backend could cache-hit unchanged.
var aiHasGenerated = false;

function generateAiItinerary() {
    var resultBox = document.getElementById('aiItineraryResult');
    var btn = document.getElementById('genAiBtn');
    var destination = document.querySelector('[name="destination"]').value.trim();
    var isRegenerate = aiHasGenerated;

    if (!destination) {
        resultBox.innerHTML = '<div class="ai-error-box"><i class="fa fa-exclamation-triangle"></i> Please enter a destination in Step 1 first.</div>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (isRegenerate ? 'Regenerating…' : 'Generating…');
    resultBox.innerHTML =
        '<div class="ai-skeleton"><div class="ai-skel-line" style="width:60%"></div><div class="ai-skel-line" style="width:90%"></div><div class="ai-skel-line" style="width:40%"></div></div>' +
        '<div class="ai-skeleton"><div class="ai-skel-line" style="width:50%"></div><div class="ai-skel-line" style="width:80%"></div></div>';

    var startDate = document.querySelector('[name="startdate"]').value;
    var endDate   = document.querySelector('[name="enddate"]').value;
    var duration  = 3;
    if (startDate && endDate) {
        var d1 = new Date(startDate), d2 = new Date(endDate);
        var diff = Math.round((d2 - d1) / 86400000);
        if (diff > 0) duration = Math.min(30, diff);
    }
    collectActivities();

    var body = new URLSearchParams({
        destination: destination,
        duration: duration,
        travelers: document.querySelector('[name="travelers"]').value || 2,
        budget: document.querySelector('[name="budget"]').value || 0,
        start_date: startDate || '',
        accommodation: document.getElementById('accommodation').value,
        transport: document.getElementById('transport').value,
        activities: document.getElementById('activitiesInput').value || 'sightseeing',
        special_requests: document.querySelector('[name="specialrequests"]').value || '',
        // SECURITY (round 20): api/ai.php's itinerary action now requires a CSRF
        // token; this page already renders one (tms_csrf_field()) for its own
        // form further down -- reuse that same value here.
        csrf_token: (document.querySelector('[name="csrf_token"]') || {}).value || ''
    });

    var action = 'itinerary';
    if (isRegenerate) {
        action = 'itinerary_regenerate';
        var fb = (document.getElementById('aiRegenFeedback') || {}).value || '';
        body.set('feedback', fb.trim());
    }

    fetch('api/ai.php?action=' + action, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-refresh"></i> Regenerate Itinerary';
            if (!res.success) {
                resultBox.innerHTML = '<div class="ai-error-box"><i class="fa fa-exclamation-triangle"></i> ' + esc(res.error || 'AI service unavailable right now — you can still submit your request and our team will plan it manually.') + '</div>';
                return;
            }
            renderItinerary(res.data.itinerary);
            document.getElementById('aiItineraryJson').value = JSON.stringify(res.data.itinerary);
            aiHasGenerated = true;
            document.getElementById('aiRegenBox').style.display = 'block';
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-refresh"></i> Try Again';
            resultBox.innerHTML = '<div class="ai-error-box"><i class="fa fa-exclamation-triangle"></i> Could not reach the AI service. You can still submit your request and our team will plan it manually.</div>';
        });
}

function renderItinerary(itin) {
    var resultBox = document.getElementById('aiItineraryResult');
    if (!itin) { resultBox.innerHTML = ''; return; }
    var html = '';

    // Header + cost breakdown
    html += '<div class="ai-itin-header"><h3><i class="fa fa-map-signs"></i> ' + esc(itin.trip_title) + '</h3>';
    if (itin.best_visiting_time) html += '<p style="margin:0;opacity:0.9;font-size:13.5px;"><i class="fa fa-calendar"></i> Best time to visit: ' + esc(itin.best_visiting_time) + '</p>';
    if (itin.estimated_cost) {
        html += '<div class="ai-cost-grid">';
        var costLabels = {accommodation:'Stay', transport:'Transport', food:'Food', activities:'Activities', miscellaneous:'Misc', total:'Total'};
        for (var k in costLabels) {
            if (itin.estimated_cost[k] !== undefined) {
                html += '<div class="ai-cost-item"><div class="amt">৳' + Number(itin.estimated_cost[k]).toLocaleString() + '</div><div class="lbl">' + costLabels[k] + '</div></div>';
            }
        }
        html += '</div>';
    }
    html += '</div>';

    // Days (accordion)
    if (Array.isArray(itin.days)) {
        itin.days.forEach(function(day, idx) {
            html += '<div class="ai-day-card">';
            html += '<div class="ai-day-head" onclick="toggleAiDay(this)"><span><i class="fa fa-chevron-right" style="font-size:11px;margin-right:6px;"></i> Day ' + esc(day.day) + ': ' + esc(day.title) + '</span>' +
                    (day.daily_budget ? '<span style="color:#667eea;font-weight:700;">৳' + Number(day.daily_budget).toLocaleString() + '</span>' : '') + '</div>';
            html += '<div class="ai-day-body' + (idx === 0 ? ' open' : '') + '">';
            if (day.weather_note) html += '<p style="font-size:13px;color:#777;"><i class="fa fa-cloud"></i> ' + esc(day.weather_note) + '</p>';
            if (Array.isArray(day.schedule)) {
                day.schedule.forEach(function(s) {
                    html += '<div class="ai-sched-row"><span class="ai-sched-time">' + esc(s.time) + '</span><span><strong>' + esc(s.activity) + '</strong> — ' + esc(s.location) +
                            (s.cost ? ' <span style="color:#27ae60;">(৳' + Number(s.cost).toLocaleString() + ')</span>' : '') +
                            (s.notes ? '<br><span style="color:#999;font-size:12px;">' + esc(s.notes) + '</span>' : '') + '</span></div>';
                });
            }
            if (day.meal_suggestions) {
                html += '<p style="margin-top:10px;font-size:13px;"><i class="fa fa-cutlery" style="color:#f0ad4e;"></i> ' +
                    ['breakfast','lunch','dinner'].map(function(m){ return day.meal_suggestions[m] ? esc(m.charAt(0).toUpperCase()+m.slice(1)) + ': ' + esc(day.meal_suggestions[m]) : ''; }).filter(Boolean).join(' &nbsp;·&nbsp; ') + '</p>';
            }
            if (day.pro_tip) html += '<p style="font-size:12.5px;color:#888;margin-top:8px;"><i class="fa fa-lightbulb-o"></i> ' + esc(day.pro_tip) + '</p>';
            html += '</div></div>';
        });
    }

    // Hotel & restaurant recommendations
    if (Array.isArray(itin.hotel_recommendations) && itin.hotel_recommendations.length) {
        html += '<h5 style="margin-top:18px;"><i class="fa fa-bed" style="color:#667eea;"></i> Hotel Recommendations</h5><div class="ai-rec-grid">';
        itin.hotel_recommendations.forEach(function(h) {
            html += '<div class="ai-rec-card"><strong>' + esc(h.name) + '</strong>' + esc(h.tier || '') + (h.price_range ? ' · ' + esc(h.price_range) : '') + (h.why ? '<br>' + esc(h.why) : '') + '</div>';
        });
        html += '</div>';
    }
    if (Array.isArray(itin.restaurant_recommendations) && itin.restaurant_recommendations.length) {
        html += '<h5 style="margin-top:18px;"><i class="fa fa-cutlery" style="color:#667eea;"></i> Restaurant Recommendations</h5><div class="ai-rec-grid">';
        itin.restaurant_recommendations.forEach(function(r) {
            html += '<div class="ai-rec-card"><strong>' + esc(r.name) + '</strong>' + esc(r.cuisine || '') + (r.price_range ? ' · ' + esc(r.price_range) : '') + (r.must_try ? '<br>Try: ' + esc(r.must_try) : '') + '</div>';
        });
        html += '</div>';
    }

    // Transportation
    if (Array.isArray(itin.transportation_suggestions) && itin.transportation_suggestions.length) {
        html += '<h5 style="margin-top:18px;"><i class="fa fa-plane" style="color:#667eea;"></i> Getting There &amp; Around</h5><ul class="ai-tip-list">';
        itin.transportation_suggestions.forEach(function(t) { html += '<li>' + esc(t) + '</li>'; });
        html += '</ul>';
    }

    // Safety tips
    if (Array.isArray(itin.safety_tips) && itin.safety_tips.length) {
        html += '<h5 style="margin-top:18px;"><i class="fa fa-shield" style="color:#27ae60;"></i> Safety Tips</h5><ul class="ai-tip-list">';
        itin.safety_tips.forEach(function(s) { html += '<li>' + esc(s) + '</li>'; });
        html += '</ul>';
    }

    // Packing + money-saving + hidden gems
    var extras = [
        ['Packing Essentials', 'fa-suitcase', itin.packing_essentials],
        ['Money-Saving Tips', 'fa-money', itin.money_saving_tips],
        ['Hidden Gems', 'fa-diamond', itin.hidden_gems],
    ];
    extras.forEach(function(ex) {
        if (Array.isArray(ex[2]) && ex[2].length) {
            html += '<h5 style="margin-top:18px;"><i class="fa ' + ex[1] + '" style="color:#667eea;"></i> ' + ex[0] + '</h5><ul class="ai-tip-list">';
            ex[2].forEach(function(item) { html += '<li>' + esc(item) + '</li>'; });
            html += '</ul>';
        }
    });

    if (itin.ai_notes) {
        html += '<p style="margin-top:16px;font-size:12.5px;color:#999;border-top:1px solid #eee;padding-top:12px;"><i class="fa fa-info-circle"></i> ' + esc(itin.ai_notes) + '</p>';
    }

    resultBox.innerHTML = html;
}
</script>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

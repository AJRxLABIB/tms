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

$saved = false;
if(isset($_POST['saveplan']) && $_SESSION['login']) {
    tms_csrf_validate();
    $sql = "INSERT INTO tblbudgetplans (UserEmail,PlanName,Destination,TotalBudget,Accommodation,Food,Transport,Activities,Miscellaneous,Duration,Travelers)
            VALUES(:ue,:pn,:dest,:tb,:acc,:food,:trans,:act,:misc,:dur,:trav)";
    $q = $dbh->prepare($sql);
    $q->bindParam(':ue', $_SESSION['login']);
    $q->bindParam(':pn', $_POST['planname']);
    $q->bindParam(':dest', $_POST['destination']);
    $q->bindParam(':tb', $_POST['totalbudget']);
    $q->bindParam(':acc', $_POST['accommodation']);
    $q->bindParam(':food', $_POST['food']);
    $q->bindParam(':trans', $_POST['transport']);
    $q->bindParam(':act', $_POST['activities']);
    $q->bindParam(':misc', $_POST['miscellaneous']);
    $q->bindParam(':dur', $_POST['duration']);
    $q->bindParam(':trav', $_POST['travelers']);
    $q->execute();
    $saved = true;
}

// Load saved plans for logged-in user
$savedPlans = [];
if(!empty($_SESSION['login'])) {
    $sqlS = "SELECT * FROM tblbudgetplans WHERE UserEmail=:ue ORDER BY CreatedAt DESC";
    $qS = $dbh->prepare($sqlS);
    $qS->bindParam(':ue', $_SESSION['login']);
    $qS->execute();
    $savedPlans = $qS->fetchAll(PDO::FETCH_OBJ);
}

$sql2 = "SELECT PackageId, PackageName, PackageLocation, PackagePrice FROM tbltourpackages ORDER BY PackageName";
$q2 = $dbh->prepare($sql2);
$q2->execute();
$packages = $q2->fetchAll(PDO::FETCH_OBJ);
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
<title>TMS | Budget Planner</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<style>
.budget-hero { background: linear-gradient(135deg,#134e5e,#71b280); color:#fff; padding:55px 0 30px; text-align:center; }
.budget-hero h1 { font-size:2.3em; }
.budget-card { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:28px; margin-bottom:25px; }
.budget-card h4 { color:#333; font-size:1.1em; font-weight:700; border-bottom:2px solid #f0f0f0; padding-bottom:10px; margin-bottom:20px; }
.form-control { border:2px solid #e0e0e0; border-radius:8px; padding:10px 14px; }
.form-control:focus { border-color:#71b280; box-shadow:none; outline:none; }
.range-wrap { position:relative; }
input[type=range] { width:100%; -webkit-appearance:none; height:6px; border-radius:3px; background:#e0e0e0; outline:none; cursor:pointer; }
input[type=range]::-webkit-slider-thumb { -webkit-appearance:none; width:18px; height:18px; border-radius:50%; background:linear-gradient(135deg,#134e5e,#71b280); cursor:pointer; }
.range-value { display:inline-block; background:linear-gradient(135deg,#134e5e,#71b280); color:#fff; padding:3px 10px; border-radius:5px; font-size:13px; font-weight:700; }
.summary-box { background: linear-gradient(135deg,#134e5e,#71b280); color:#fff; border-radius:12px; padding:25px; }
.summary-box .total { font-size:2.5em; font-weight:700; }
.summary-box .label { opacity:0.85; font-size:14px; }
.summary-item { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.2); font-size:14px; }
.summary-item:last-child { border:none; }
.bar-item { display:flex; align-items:center; margin-bottom:10px; }
.bar-label { min-width:120px; font-size:13px; color:#555; font-weight:600; }
html[data-theme="dark"] .budget-card h4 { color: var(--text) !important; }
html[data-theme="dark"] .bar-label { color: var(--text-muted) !important; }
.bar-track { flex:1; height:10px; background:#f0f0f0; border-radius:5px; overflow:hidden; }
.bar-fill { height:100%; border-radius:5px; transition:width 0.8s ease; }
.tip-box { background:#e8f5e9; border-radius:8px; padding:15px; border-left:4px solid #71b280; margin-top:15px; }
.tip-box p { font-size:13px; color:#2e7d32; margin:0; }
.btn-save { background: linear-gradient(135deg,#134e5e,#71b280); color:#fff; border:none; border-radius:8px; padding:12px 30px; font-weight:700; cursor:pointer; font-size:15px; width:100%; }
.btn-save:hover { opacity:0.9; color:#fff; }
.saved-plan { background:#f8f9fa; border-radius:8px; padding:15px; margin-bottom:10px; border:1px solid #e0e0e0; }
.saved-plan h5 { color:#134e5e; font-weight:700; margin:0 0 5px; font-size:14px; }
.saved-plan p { font-size:13px; color:#777; margin:0; }
.budget-status { padding:10px 15px; border-radius:8px; font-weight:700; font-size:14px; margin-top:15px; text-align:center; }
.status-ok { background:#d4edda; color:#155724; }
.status-warn { background:#fff3cd; color:#856404; }
.status-over { background:#f8d7da; color:#721c24; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="budget-hero">
    <div class="container">
        <h1><i class="fa fa-calculator"></i> Travel Budget Planner</h1>
        <p>Plan your trip budget smartly — track every expense category</p>
    </div>
</div>

<div class="container" style="padding-top:30px;">
    <?php if($saved): ?><div class="alert alert-success"><i class="fa fa-check"></i> Budget plan saved successfully!</div><?php endif; ?>

    <div class="row">
        <!-- Input Form -->
        <div class="col-md-7">
            <div class="budget-card wow fadeInLeft">
                <h4><i class="fa fa-pencil" style="color:#134e5e"></i> Set Your Budget</h4>
                <form id="budgetForm" method="post">
                <?php tms_csrf_field(); ?>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="planname">Plan Name</label>
                                <input type="text" name="planname" id="planname" class="form-control" placeholder="My Paris Trip" value="My Travel Plan">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="destination">Destination</label>
                                <select name="destination" id="destination" class="form-control" onchange="fillFromPackage(this)">
                                    <option value="Custom">-- Custom Destination --</option>
                                    <?php foreach($packages as $p): ?>
                                    <option value="<?php echo htmlentities($p->PackageLocation); ?>" data-price="<?php echo $p->PackagePrice; ?>">
                                        <?php echo htmlentities($p->PackageName); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="travelers">Number of Travelers</label>
                                <input type="number" name="travelers" id="travelers" class="form-control" value="2" min="1" max="20" onchange="calculate()">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="duration">Trip Duration (days)</label>
                                <input type="number" name="duration" id="duration" class="form-control" value="7" min="1" max="30" onchange="calculate()">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="totalbudget">Total Budget &nbsp;<span class="range-value" id="totalVal">&nbsp;</span></label>
                        <input type="range" name="totalbudget" id="totalbudget" min="500" max="50000" step="100" value="5000" oninput="updateTotal()">
                    </div>

                    <hr>
                    <h5 style="color:#555;margin-bottom:15px;">Expense Breakdown</h5>

                    <?php
                    $expenses = [
                        ['key'=>'accommodation','label'=>'Accommodation','icon'=>'fa-bed','color'=>'#3498db','default'=>1500],
                        ['key'=>'food','label'=>'Food & Dining','icon'=>'fa-cutlery','color'=>'#e74c3c','default'=>800],
                        ['key'=>'transport','label'=>'Transport','icon'=>'fa-plane','color'=>'#2ecc71','default'=>1200],
                        ['key'=>'activities','label'=>'Activities & Tours','icon'=>'fa-camera','color'=>'#f39c12','default'=>800],
                        ['key'=>'miscellaneous','label'=>'Miscellaneous','icon'=>'fa-shopping-bag','color'=>'#9b59b6','default'=>400],
                    ];
                    foreach($expenses as $exp): ?>
                    <div class="form-group">
                        <label for="<?php echo $exp['key']; ?>"><i class="fa <?php echo $exp['icon']; ?>" style="color:<?php echo $exp['color']; ?>"></i>
                            <?php echo $exp['label']; ?> &nbsp;
                            <span class="range-value" id="<?php echo $exp['key']; ?>Val" style="background:<?php echo $exp['color']; ?>"><?php echo TMS_BASE_SYMBOL . ' ' . number_format((float)$exp['default']); ?></span>
                        </label>
                        <input type="range" name="<?php echo $exp['key']; ?>" id="<?php echo $exp['key']; ?>" min="0" max="15000" step="50" value="<?php echo $exp['default']; ?>"
                            oninput="updateSlider('<?php echo $exp['key']; ?>')" style="accent-color:<?php echo $exp['color']; ?>">
                    </div>
                    <?php endforeach; ?>

                    <?php if(!empty($_SESSION['login'])): ?>
                    <button type="submit" name="saveplan" class="btn-save" onclick="prepareSubmit()">
                        <i class="fa fa-save"></i> Save Budget Plan
                    </button>
                    <?php else: ?>
                    <a href="#" data-toggle="modal" data-target="#myModal4" class="btn-save" style="display:block;text-align:center;padding:12px;text-decoration:none;">
                        <i class="fa fa-sign-in"></i> Sign In to Save Plan
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Summary & Chart -->
        <div class="col-md-5">
            <div class="budget-card wow fadeInRight">
                <h4><i class="fa fa-pie-chart" style="color:#134e5e"></i> Budget Summary</h4>
                <canvas id="budgetChart" height="200"></canvas>
                <div id="budgetStatus" class="budget-status status-ok">✓ Budget looks balanced!</div>
            </div>

            <div class="summary-box wow fadeInRight">
                <div class="label">Total Budget</div>
                <div class="total" id="totalDisplay">&nbsp;</div>
                <div style="font-size:13px;opacity:0.75;margin-bottom:15px;">for <span id="travelersDisplay">2</span> travelers, <span id="durationDisplay">7</span> days</div>
                <?php foreach($expenses as $exp): ?>
                <div class="summary-item">
                    <span><i class="fa <?php echo $exp['icon']; ?>"></i> <?php echo $exp['label']; ?></span>
                    <span id="<?php echo $exp['key']; ?>Summary"><?php echo TMS_BASE_SYMBOL . ' ' . number_format((float)$exp['default']); ?></span>
                </div>
                <?php endforeach; ?>
                <div class="summary-item" style="font-weight:700;font-size:15px;">
                    <span>Remaining</span>
                    <span id="remainingDisplay">&nbsp;</span>
                </div>
                <div style="margin-top:15px;font-size:13px;opacity:0.85;">
                    Per person: <strong id="perPersonDisplay">&nbsp;</strong> &nbsp;|&nbsp;
                    Per day: <strong id="perDayDisplay">&nbsp;</strong>
                </div>
            </div>

            <div class="tip-box wow fadeInRight" style="margin-top:15px;">
                <p><i class="fa fa-lightbulb-o" style="color:#71b280"></i> <strong>Pro Tip:</strong> Keep 10–15% of your total budget as emergency reserve. Accommodation and transport usually make up 50–60% of travel costs.</p>
            </div>
        </div>
    </div>

    <?php if(count($savedPlans) > 0): ?>
    <div class="budget-card wow fadeInUp" style="margin-top:10px;">
        <h4><i class="fa fa-history" style="color:#134e5e"></i> Your Saved Plans</h4>
        <div class="row">
        <?php foreach($savedPlans as $plan): ?>
        <div class="col-md-4">
            <div class="saved-plan">
                <h5><?php echo htmlentities($plan->PlanName); ?></h5>
                <p><i class="fa fa-map-marker"></i> <?php echo htmlentities($plan->Destination); ?></p>
                <p>Total: <strong><?php echo TMS_BASE_SYMBOL . ' ' . number_format((float)$plan->TotalBudget); ?></strong> | <?php echo $plan->Duration; ?> days | <?php echo $plan->Travelers; ?> travelers</p>
                <p style="font-size:11px;color:#aaa;"><?php echo $plan->CreatedAt; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
/* Round 14: this page hardcoded the Taka symbol in a dozen places while the
   saved-plan list below rendered the same figures through tms_currency() — so a
   visitor on EUR saw "৳5,000" in the planner and "€ 38" for the identical saved
   plan. The planner deliberately works in the site's BASE currency, because that
   is the currency the plan is stored in; the symbol now comes from one place
   instead of being retyped, and the heading says which currency it is. */
var TMS_CUR = { symbol: <?php echo json_encode(TMS_BASE_SYMBOL); ?>, rate: 1, decimals: 0 };
function tmsMoney(bdtAmount) {
    var n = (Number(bdtAmount) || 0) * TMS_CUR.rate;
    return TMS_CUR.symbol + ' ' + n.toLocaleString(undefined, {
        minimumFractionDigits: TMS_CUR.decimals, maximumFractionDigits: TMS_CUR.decimals
    });
}
</script>
<script>
var chartInstance = null;
var colors = ['#3498db','#e74c3c','#2ecc71','#f39c12','#9b59b6'];
var expenseKeys = ['accommodation','food','transport','activities','miscellaneous'];
var expenseLabels = ['Accommodation','Food','Transport','Activities','Misc'];

function updateTotal() {
    var v = parseInt(document.getElementById('totalbudget').value);
    document.getElementById('totalVal').textContent = TMS_CUR.symbol + ' ' + v.toLocaleString();
    calculate();
}

function updateSlider(key) {
    var v = parseInt(document.getElementById(key).value);
    document.getElementById(key+'Val').textContent = TMS_CUR.symbol + ' ' + v.toLocaleString();
    calculate();
}

function calculate() {
    var total = parseInt(document.getElementById('totalbudget').value);
    var travelers = parseInt(document.getElementById('travelers').value) || 1;
    var duration = parseInt(document.getElementById('duration').value) || 1;
    var spent = 0;

    expenseKeys.forEach(function(k) {
        var v = parseInt(document.getElementById(k).value);
        document.getElementById(k+'Summary').textContent = TMS_CUR.symbol + ' ' + v.toLocaleString();
        spent += v;
    });

    var remaining = total - spent;
    document.getElementById('remainingDisplay').textContent = (remaining >= 0 ? '+' : '') + TMS_CUR.symbol + ' ' + remaining.toLocaleString();
    document.getElementById('totalDisplay').textContent = TMS_CUR.symbol + ' ' + total.toLocaleString();
    document.getElementById('travelersDisplay').textContent = travelers;
    document.getElementById('durationDisplay').textContent = duration;
    document.getElementById('perPersonDisplay').textContent = TMS_CUR.symbol + ' ' + Math.round(total/travelers).toLocaleString();
    document.getElementById('perDayDisplay').textContent = TMS_CUR.symbol + ' ' + Math.round(total/duration).toLocaleString();

    var pct = (spent/total)*100;
    var statusEl = document.getElementById('budgetStatus');
    if(pct > 100) { statusEl.className='budget-status status-over'; statusEl.textContent='⚠ Over Budget by ' + tmsMoney(Math.abs(remaining)); }
    else if(pct > 90) { statusEl.className='budget-status status-warn'; statusEl.textContent='⚡ Almost at budget limit!'; }
    else { statusEl.className='budget-status status-ok'; statusEl.textContent='✓ Budget looks balanced! ' + tmsMoney(remaining) + ' reserve.'; }

    updateChart();
}

function updateChart() {
    var data = expenseKeys.map(function(k){ return parseInt(document.getElementById(k).value)||0; });
    var total = parseInt(document.getElementById('totalbudget').value);
    var remaining = Math.max(0, total - data.reduce(function(a,b){return a+b;},0));
    var allData = data.concat([remaining]);
    var allLabels = expenseLabels.concat(['Remaining']);
    var allColors = colors.concat(['#ecf0f1']);

    if(chartInstance) chartInstance.destroy();
    var ctx = document.getElementById('budgetChart').getContext('2d');
    chartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: allLabels, datasets: [{ data: allData, backgroundColor: allColors, borderWidth: 2 }] },
        options: { plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }, cutout: '60%' }
    });
}

function fillFromPackage(sel) {
    var price = sel.options[sel.selectedIndex].dataset.price;
    if(price) {
        var travelers = parseInt(document.getElementById('travelers').value)||2;
        var total = price * travelers;
        document.getElementById('totalbudget').value = Math.min(50000, total + 2000);
        document.getElementById('accommodation').value = Math.round(total * 0.3);
        document.getElementById('transport').value = Math.round(total * 0.25);
        document.getElementById('food').value = Math.round(total * 0.2);
        document.getElementById('activities').value = Math.round(total * 0.15);
        document.getElementById('miscellaneous').value = Math.round(total * 0.1);
        expenseKeys.forEach(function(k){ updateSlider(k); });
        updateTotal();
    }
}

function prepareSubmit() { return true; }

window.onload = function() { calculate(); };
</script>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

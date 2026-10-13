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

$sql = "SELECT * FROM tblemergencycontacts ORDER BY Country";
$query = $dbh->prepare($sql);
$query->execute();
$contacts = $query->fetchAll(PDO::FETCH_OBJ);

$selected = isset($_GET['country']) ? $_GET['country'] : '';
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
<title>TMS | Emergency Contacts & Safety</title>
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
<style>
.safety-hero { background: linear-gradient(135deg,#c0392b,#e74c3c); color:#fff; padding:55px 0 30px; text-align:center; }
.safety-hero h1 { font-size:2.3em; }
.country-card { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:24px; margin-bottom:20px; border-left:5px solid #e74c3c; transition:0.3s; cursor:pointer; }
.country-card:hover { transform:translateY(-2px); box-shadow:0 8px 30px rgba(0,0,0,0.12); }
.country-card h4 { color:#333; font-size:1.1em; font-weight:700; margin-bottom:15px; }
html[data-theme="dark"] .country-card h4 { color: var(--text) !important; }
.emergency-num { display:flex; align-items:center; padding:10px 15px; background:#fff5f5; border-radius:8px; margin-bottom:8px; }
.emergency-num .icon { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-right:12px; font-size:16px; color:#fff; flex-shrink:0; }
.emergency-num .label { font-size:13px; color:#777; }
.emergency-num .number { font-size:1.5em; font-weight:700; color:#e74c3c; }
.safety-tips-box { background: linear-gradient(135deg,#fff5f5,#fff); border-radius:10px; padding:18px; border:1px solid #f5c6cb; margin-top:15px; }
.safety-tips-box h5 { color:#c0392b; font-weight:700; margin-bottom:10px; }
.safety-tips-box p { font-size:13px; color:#555; line-height:1.7; }
.sos-banner { background: linear-gradient(135deg,#c0392b,#e74c3c); color:#fff; border-radius:12px; padding:25px; text-align:center; margin-bottom:25px; }
.sos-banner h3 { font-size:2em; margin-bottom:5px; }
.sos-banner p { opacity:0.9; margin:0; }
.general-tips { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:25px; margin-bottom:25px; }
.tip-item { display:flex; align-items:flex-start; padding:12px 0; border-bottom:1px solid #f0f0f0; }
.tip-item:last-child { border:none; }
.tip-icon { width:40px; height:40px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; margin-right:15px; flex-shrink:0; font-size:16px; }
.tip-item h6 { font-weight:700; color:#333; margin:0 0 4px; font-size:14px; }
.tip-item p { font-size:13px; color:#777; margin:0; }
.search-bar { background:var(--white); border-radius:10px; padding:15px 20px; box-shadow:0 2px 10px rgba(0,0,0,0.08); margin-bottom:20px; }
.flag-emoji { font-size:24px; margin-right:10px; }
.print-btn { background:#333; color:#fff; border:none; border-radius:8px; padding:8px 20px; font-size:14px; cursor:pointer; float:right; }
.print-btn:hover { background:#555; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="safety-hero">
    <div class="container">
        <h1><i class="fa fa-shield"></i> Emergency Contacts & Travel Safety</h1>
        <p>Stay safe wherever you travel — know who to call in an emergency</p>
    </div>
</div>

<div class="container" style="padding-top:25px;">
    <!-- SOS Banner -->
    <div class="sos-banner wow zoomIn">
        <h3>🆘 In a Life-Threatening Emergency</h3>
        <p>Always dial the local emergency number first. Stay calm and provide your location clearly.</p>
    </div>

    <div class="row">
        <div class="col-md-8">
            <!-- Search -->
            <div class="search-bar">
                <input type="text" id="countrySearch" class="form-control" placeholder="🔍 Search country...">
            </div>

            <!-- Country Cards -->
            <?php
            $flags = ['India'=>'🇮🇳','France'=>'🇫🇷','USA'=>'🇺🇸','Thailand'=>'🇹🇭','Dubai'=>'🇦🇪','Bhutan'=>'🇧🇹','Indonesia'=>'🇮🇩'];
            foreach($contacts as $c): ?>
            <div class="country-card wow fadeInUp country-entry" data-country="<?php echo strtolower($c->Country); ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h4>
                        <span class="flag-emoji"><?php echo $flags[$c->Country] ?? '🌍'; ?></span>
                        <?php echo htmlentities($c->Country); ?>
                    </h4>
                    <button class="print-btn" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="emergency-num">
                            <div class="icon" style="background:#2196F3"><i class="fa fa-shield"></i></div>
                            <div><div class="label">Police</div><div class="number"><?php echo htmlentities($c->PoliceNumber); ?></div></div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="emergency-num">
                            <div class="icon" style="background:#e74c3c"><i class="fa fa-ambulance"></i></div>
                            <div><div class="label">Ambulance</div><div class="number"><?php echo htmlentities($c->AmbulanceNumber); ?></div></div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="emergency-num">
                            <div class="icon" style="background:#f39c12"><i class="fa fa-fire"></i></div>
                            <div><div class="label">Fire</div><div class="number"><?php echo htmlentities($c->FireNumber); ?></div></div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="emergency-num">
                            <div class="icon" style="background:#27ae60"><i class="fa fa-info-circle"></i></div>
                            <div><div class="label">Tourist Helpline</div><div class="number" style="font-size:1.1em"><?php echo htmlentities($c->TouristHelpline); ?></div></div>
                        </div>
                    </div>
                </div>
                <div class="safety-tips-box">
                    <h5><i class="fa fa-lightbulb-o"></i> Safety Tips for <?php echo htmlentities($c->Country); ?></h5>
                    <p><?php echo htmlentities($c->SafetyTips); ?></p>
                    <?php if($c->Embassy): ?>
                    <p style="margin-top:8px;"><strong><i class="fa fa-building"></i> Embassy Info:</strong> <?php echo htmlentities($c->Embassy); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$contacts): ?>
            <?php /* Round 14: the foreach had no else, so with an unseeded table this
                     page showed the SOS banner, a search box that filtered nothing,
                     and then simply nothing — no explanation at all. */ ?>
            <div class="emergency-card" style="text-align:center;padding:30px 20px;">
                <i class="fa fa-info-circle" style="font-size:34px;color:#94a3b8;"></i>
                <h4 style="margin-top:12px;">Country contacts aren't loaded yet</h4>
                <p style="color:#64748b;max-width:520px;margin:8px auto 0;">
                    In any emergency, dial your destination's national emergency number —
                    <strong>999</strong> in Bangladesh and the UK, <strong>911</strong> in the US and Canada,
                    <strong>112</strong> across the EU and from any mobile phone worldwide,
                    <strong>112</strong> or <strong>108</strong> in India. Your travel insurer's
                    24-hour assistance line is on your policy card.
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- General Safety Tips Sidebar -->
        <div class="col-md-4">
            <div class="general-tips wow fadeInRight">
                <h4 style="color:#c0392b;margin-bottom:20px;"><i class="fa fa-exclamation-triangle"></i> Universal Travel Safety Tips</h4>
                <?php
                $tips = [
                    ['#3498db','fa-id-card','Carry ID','Always have a copy of your passport and ID. Keep digital copies in email too.'],
                    ['#27ae60','fa-money','Money Safety','Use ATMs in secure locations. Carry limited cash and keep cards separate.'],
                    ['#f39c12','fa-phone','Save Numbers','Save local emergency numbers before you travel. Include your hotel number.'],
                    ['#9b59b6','fa-map','Share Itinerary','Share your travel itinerary with someone back home before departing.'],
                    ['#e74c3c','fa-medkit','Medical Kit','Carry basic medicines: pain relief, antidiarrheal, antiseptic, and prescriptions.'],
                    ['#1abc9c','fa-wifi','Stay Connected','Buy a local SIM or portable WiFi. Offline maps can be lifesavers.'],
                    ['#e67e22','fa-lock','Valuables','Use hotel safe for valuables. Avoid flashing expensive items in crowded areas.'],
                    ['#2c3e50','fa-flag','Embassy Contact','Know your country\'s embassy location in every country you visit.'],
                    ['#1F8A66','fa-shield','Travel Insurance','Always buy travel insurance covering medical emergencies and trip cancellation.'],
                ];
                foreach($tips as $tip): ?>
                <div class="tip-item">
                    <div class="tip-icon" style="background:<?php echo $tip[0]; ?>"><i class="fa <?php echo $tip[1]; ?>"></i></div>
                    <div><h6><?php echo $tip[2]; ?></h6><p><?php echo $tip[3]; ?></p></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Download Card -->
            <div style="background:linear-gradient(135deg,#c0392b,#e74c3c);color:#fff;border-radius:12px;padding:20px;margin-top:15px;text-align:center;">
                <i class="fa fa-download" style="font-size:40px;margin-bottom:10px;display:block;"></i>
                <h5>Emergency Contact Card</h5>
                <p style="font-size:13px;opacity:0.85;margin-bottom:15px;">Save or print this page before your trip as an offline backup.</p>
                <button onclick="window.print()" style="background:rgba(255,255,255,0.2);color:#fff;border:2px solid #fff;border-radius:8px;padding:10px 25px;cursor:pointer;font-weight:700;">
                    <i class="fa fa-print"></i> Print / Save PDF
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('countrySearch').addEventListener('input', function() {
    var val = this.value.toLowerCase();
    document.querySelectorAll('.country-entry').forEach(function(el) {
        el.style.display = el.dataset.country.includes(val) ? 'block' : 'none';
    });
});
window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});
</script>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

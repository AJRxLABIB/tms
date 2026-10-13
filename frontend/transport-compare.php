<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// Public content (like ai-recommend.php/compare.php) -- this is a planning
// tool, not an account feature, so it's intentionally not gated behind
// tms_require_user().

require_once dirname(__DIR__) . '/backend/TransportComparator.php';

$result = null;
// Round 115, Phase G (Bangladesh Travel Intelligence): pre-fill the form
// from a GET link -- e.g. bd-guide.php's "Compare Transport from Dhaka"
// button links here as ?origin=..&destination=.. -- without touching the
// POST logic at all. These are only the pre-submission defaults shown in
// the two text fields; submitting the form still goes through the exact
// same tms_csrf_validate()/$_POST['compare_transport'] branch below, which
// overwrites both with the posted values as before.
$origin = trim($_GET['origin'] ?? '');
$destination = trim($_GET['destination'] ?? '');
if (isset($_POST['compare_transport'])) {
    tms_csrf_validate();
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    if ($origin === '' || $destination === '') {
        $result = ['success' => false, 'error' => 'Please enter both an origin and a destination.'];
    } else {
        $comparator = new TransportComparator($dbh);
        $result = $comparator->compare($origin, $destination);
    }
}

$modeMeta = [
    'walking' => ['label' => 'Walking',  'icon' => 'fa-male'],
    'bicycle' => ['label' => 'Bicycle',  'icon' => 'fa-bicycle'],
    'car'     => ['label' => 'Car',      'icon' => 'fa-car'],
    'taxi'    => ['label' => 'Taxi',     'icon' => 'fa-taxi'],
    'bus'     => ['label' => 'Bus',      'icon' => 'fa-bus'],
    'train'   => ['label' => 'Train',    'icon' => 'fa-train'],
    'metro'   => ['label' => 'Metro',    'icon' => 'fa-subway'],
    'ferry'   => ['label' => 'Ferry',    'icon' => 'fa-ship'],
    'flight'  => ['label' => 'Flight',   'icon' => 'fa-plane'],
];
$bestLabels = [
    'fastest' => 'Fastest', 'cheapest' => 'Cheapest', 'most_comfortable' => 'Most Comfortable',
    'lowest_carbon' => 'Lowest Carbon', 'best_for_family' => 'Best for Family',
];

function tms_transport_duration_text(int $minutes): string {
    if ($minutes < 60) return "$minutes min";
    $h = intdiv($minutes, 60); $m = $minutes % 60;
    return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
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
<title>TMS | Transport Compare</title>
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
<style>
.tc-hero { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); padding: 60px 0; text-align:center; color:#fff; }
.tc-hero h1 { font-size: 2.3em; margin-bottom:10px; }
.tc-form-card { background:var(--white); border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.1); padding:30px; margin: -30px auto 30px; max-width:800px; position:relative; z-index:10; }
.tc-form-card .form-control { border:2px solid #e8ecf0; border-radius:8px; padding:10px 14px; }
.tc-btn { background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; border:none; border-radius:8px; padding:12px 35px; font-weight:700; cursor:pointer; width:100%; margin-top:10px; }
.tc-summary { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:25px; }
.tc-summary-card { flex:1; min-width:170px; background:var(--white); border-radius:10px; padding:16px; box-shadow:0 3px 14px rgba(0,0,0,.07); text-align:center; }
.tc-summary-card .lbl { font-size:11px; color:#999; text-transform:uppercase; letter-spacing:.5px; }
.tc-summary-card .val { font-size:1.05em; font-weight:700; color:#333; margin-top:6px; }
.tc-table-wrap { overflow-x:auto; background:var(--white); border-radius:12px; box-shadow:0 3px 16px rgba(0,0,0,.08); }
.tc-table { width:100%; border-collapse:collapse; min-width:820px; }
.tc-table th, .tc-table td { padding:12px 14px; text-align:left; border-bottom:1px solid #f0f0f0; font-size:13.5px; }
.tc-table th { background:#f8f9fb; color:#555; font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:.3px; }
.tc-table tr.tc-unrealistic { opacity:.45; }
.tc-tier { display:inline-block; }
.tc-tier .dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#e0e0e0; margin-right:2px; }
.tc-tier .dot.on { background:#667eea; }
.tc-badge-real { background:#d4edda; color:#155724; padding:2px 8px; border-radius:10px; font-size:10.5px; font-weight:700; }
.tc-badge-est { background:#fff3cd; color:#8a6d00; padding:2px 8px; border-radius:10px; font-size:10.5px; font-weight:700; }
html[data-theme="dark"] .tc-summary-card .val,
html[data-theme="dark"] .tc-table th { color: var(--text) !important; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="tc-hero">
    <div class="container">
        <h1><i class="fa fa-random"></i> Transport Compare</h1>
        <p>Walking to flights — see fastest, cheapest, most comfortable, and lowest-carbon side by side</p>
    </div>
</div>

<div class="container">
    <div class="tc-form-card wow fadeInUp">
        <form method="post">
            <?php tms_csrf_field(); ?>
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <label for="tcOriginField"><i class="fa fa-map-marker"></i> From</label>
                        <input type="text" name="origin" id="tcOriginField" class="form-control" placeholder="e.g. Dhaka, Bangladesh" required
                               value="<?php echo htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="form-group">
                        <label for="tcDestinationField"><i class="fa fa-flag"></i> To</label>
                        <input type="text" name="destination" id="tcDestinationField" class="form-control" placeholder="e.g. Cox's Bazar, Bangladesh" required
                               value="<?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <!-- ACCESSIBILITY FIX (Round 58, item 32): this label was pure
                             visual spacing (a blank &nbsp; to align the button with the
                             two fields beside it) with no form control to describe --
                             aria-hidden keeps that layout but removes it from the
                             accessibility tree instead of leaving an empty/meaningless
                             label exposed to screen readers. The button next to it was
                             a separate real bug: an icon-only <button> with no visible
                             text and no aria-label has NO accessible name at all --
                             fixed with aria-label below (same pattern as the Round 55
                             eye-toggle buttons). -->
                        <label aria-hidden="true">&nbsp;</label>
                        <button type="submit" name="compare_transport" class="tc-btn" aria-label="Compare transport options"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php if ($result !== null): ?>
        <?php if (empty($result['success'])): ?>
            <div class="errorWrap" style="max-width:800px;margin:0 auto 30px;padding:14px;background:var(--white);border-left:4px solid #dd3d36;">
                <strong>Couldn't compare:</strong> <?php echo htmlspecialchars($result['error'] ?? 'Something went wrong.', ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php else: ?>
            <div class="tms-sample-banner wow fadeIn" style="max-width:1100px;margin:0 auto 20px;">
                <i class="fa fa-info-circle"></i>
                Real routing data (green <strong>Live Route</strong> badge) comes from live map-routing services for walking, bicycle, car, and taxi.
                Bus, train, metro, and ferry (amber <strong>Estimated</strong> badge) have no live schedule/fare data available to this app, so their
                duration is calculated from real distance and a typical average speed for that mode — never an actual timetable. Cost, comfort, and
                "best for family" are shown as a general 1–5 comparison, not a live fare quote — always confirm real prices and timetables with the operator.
            </div>

            <div class="tc-summary">
                <?php foreach ($result['best'] as $key => $mode): ?>
                <div class="tc-summary-card wow fadeInUp">
                    <div class="lbl"><?php echo htmlspecialchars($bestLabels[$key] ?? $key, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="val">
                        <?php if ($mode): ?>
                            <i class="fa <?php echo htmlspecialchars($modeMeta[$mode]['icon'] ?? 'fa-circle', ENT_QUOTES, 'UTF-8'); ?>"></i>
                            <?php echo htmlspecialchars($modeMeta[$mode]['label'] ?? $mode, ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span style="color:#aaa;">N/A</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <p style="text-align:center;color:#888;margin-bottom:16px;">
                <?php echo htmlspecialchars($result['origin'], ENT_QUOTES, 'UTF-8'); ?> → <?php echo htmlspecialchars($result['destination'], ENT_QUOTES, 'UTF-8'); ?>
                — <strong><?php echo htmlspecialchars((string)$result['distance_km'], ENT_QUOTES, 'UTF-8'); ?> km</strong> (straight-line)
            </p>

            <div class="tc-table-wrap wow fadeInUp">
                <table class="tc-table">
                    <thead>
                        <tr>
                            <th>Mode</th>
                            <th>Duration</th>
                            <th>Data</th>
                            <th>Cost</th>
                            <th>Comfort</th>
                            <th>Family</th>
                            <th>Carbon</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['modes'] as $mode => $row): $meta = $modeMeta[$mode]; ?>
                        <tr class="<?php echo $row['realistic'] ? '' : 'tc-unrealistic'; ?>">
                            <td><i class="fa <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i> <?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!$row['realistic']): ?><br><small style="color:#c0392b;">Not realistic for this distance</small><?php endif; ?>
                            </td>
                            <td><?php echo tms_transport_duration_text($row['duration_min']); ?></td>
                            <td>
                                <?php if ($row['data_source'] === 'real_route'): ?>
                                    <span class="tc-badge-real"><i class="fa fa-check-circle"></i> Live Route</span>
                                <?php else: ?>
                                    <span class="tc-badge-est"><i class="fa fa-flask"></i> Estimated</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="tc-tier">
                                <?php for ($i=1;$i<=5;$i++): ?><span class="dot<?php echo $i<=$row['cost_tier']?' on':''; ?>"></span><?php endfor; ?>
                                </span>
                            </td>
                            <td>
                                <span class="tc-tier">
                                <?php for ($i=1;$i<=5;$i++): ?><span class="dot<?php echo $i<=$row['comfort_tier']?' on':''; ?>"></span><?php endfor; ?>
                                </span>
                            </td>
                            <td>
                                <span class="tc-tier">
                                <?php for ($i=1;$i<=5;$i++): ?><span class="dot<?php echo $i<=$row['family_tier']?' on':''; ?>"></span><?php endfor; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['emission_grams_per_km'] === null): ?>
                                    <span style="color:#aaa;font-size:12px;">Not tracked</span>
                                <?php elseif ($row['emission_grams_per_km'] === 0): ?>
                                    <span style="color:#27ae60;font-weight:600;">Zero</span>
                                <?php else: ?>
                                    ~<?php echo (int)round($row['emission_grams_per_km'] * $row['distance_km'] / 1000); ?> kg CO2e
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
</body>
</html>

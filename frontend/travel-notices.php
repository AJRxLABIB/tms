<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once 'backend/services/TravelNoticeService.php';
// Public browsing content (like emergency.php): intentionally not login-gated.
$country = trim((string)($_GET['country'] ?? ''));
$notices = TravelNoticeService::visible($dbh, $country !== '' ? $country : null);
$countries = TravelNoticeService::countries($dbh);
function tn_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$icons = ['disruption' => 'fa-exclamation-triangle', 'entry' => 'fa-id-card-o', 'sustainability' => 'fa-leaf'];
?>
<!DOCTYPE HTML>
<html lang="<?php echo tn_h(tms_active_language()); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<title>TMS | Travel Notices</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.tn-hero{background:linear-gradient(135deg,#104B3A,#176B50);color:#fff;padding:55px 0 30px;text-align:center}
.tn-hero h1{font-size:2.1em;color:#fff}.tn-hero p{color:#fff;opacity:.95;margin:0}
.tn-note{background:var(--white);color:var(--text);border:1px solid var(--border);border-left:5px solid #176B50;border-radius:10px;padding:16px 18px;margin:22px 0}
.tn-card{background:var(--white);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:16px}
.tn-card h4{font-size:1.1em;font-weight:700;margin:0 0 6px;color:var(--text)}
.tn-meta{font-size:13px;color:var(--text-muted);margin-bottom:10px}
.tn-kind{display:inline-block;font-size:12px;font-weight:700;padding:2px 10px;border-radius:999px;background:#DCF1E5;color:#104B3A;margin-right:6px}
.tn-kind.disruption{background:#FFF3D6;color:#7A4F0E}
.tn-stale{display:inline-block;font-size:12px;font-weight:700;padding:2px 10px;border-radius:999px;background:#FBE4E4;color:#8F2323}
html[data-theme="dark"] .tn-kind{background:#14321F;color:#9AE6BC}html[data-theme="dark"] .tn-kind.disruption{background:#3A2A0A;color:#FCD34D}html[data-theme="dark"] .tn-stale{background:#3A1414;color:#FCA5A5}
.tn-card a{text-decoration:underline}
.tn-filter{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
.tn-filter select{min-height:44px;min-width:220px}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="tn-hero"><div class="container"><h1><i class="fa fa-info-circle" aria-hidden="true"></i> Travel Notices</h1><p>Entry rules, disruptions and sustainability notes, each with its source</p></div></div>
<div class="container" style="padding-top:8px;padding-bottom:40px">
<div class="tn-note" role="note"><h2 style="font-size:1.05em;margin:0 0 6px;font-weight:700;color:var(--text)">How to read these notices</h2>These notices are typed in by our team from the official source shown on each one, on the date shown. They are <b>not a live feed</b> and can be out of date. Always confirm visa, health and safety rules with the official source or the embassy before you travel. If nothing is listed for your destination, that does not mean there are no restrictions.</div>
<h3 style="font-size:1.15em;font-weight:700;margin:0 0 12px">Current notices (<?php echo count($notices); ?>)</h3>
<form method="get" class="tn-filter"><label for="country" style="margin:0;font-weight:700">Country</label>
<select id="country" name="country" class="form-control" onchange="this.form.submit()"><option value="">All countries</option>
<?php foreach ($countries as $c): ?><option value="<?php echo tn_h($c); ?>"<?php echo strcasecmp($c, $country) === 0 ? ' selected' : ''; ?>><?php echo tn_h($c); ?></option><?php endforeach; ?></select>
<noscript><button class="btn btn-default" type="submit">Show</button></noscript></form>
<?php if (!$notices): ?><div class="tn-card" style="text-align:center;color:var(--text-muted)">No notices are published<?php echo $country !== '' ? ' for ' . tn_h($country) : ''; ?> yet. Check the official government travel advice for your destination.</div><?php endif; ?>
<?php foreach ($notices as $n): ?>
<article class="tn-card">
  <span class="tn-kind <?php echo tn_h($n['Kind']); ?>"><i class="fa <?php echo $icons[$n['Kind']] ?? 'fa-info'; ?>" aria-hidden="true"></i> <?php echo tn_h(TravelNoticeService::KINDS[$n['Kind']] ?? $n['Kind']); ?></span>
  <?php if ($n['stale']): ?><span class="tn-stale">Checked <?php echo (int)$n['age_days']; ?> days ago, may be out of date</span><?php endif; ?>
  <h4><?php echo tn_h($n['Country']); ?>: <?php echo tn_h($n['Title']); ?></h4>
  <div class="tn-meta">Checked on <?php echo tn_h($n['VerifiedOn']); ?><?php if ($n['ExpiresOn']): ?> · Shown until <?php echo tn_h($n['ExpiresOn']); ?><?php endif; ?></div>
  <p style="white-space:pre-line;margin-bottom:10px"><?php echo tn_h($n['Body']); ?></p>
  <div class="tn-meta" style="margin:0">Source: <a href="<?php echo tn_h($n['SourceUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php echo tn_h($n['SourceName']); ?><span class="sr-only"> (opens in a new tab)</span></a><?php if ($n['Kind'] === 'sustainability'): ?> · Information only, not an independent audit or a score<?php endif; ?></div>
</article>
<?php endforeach; ?>
</div>
<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>

<?php
/**
 * dashboard.php — Customer Dashboard
 *
 * Before this page existed, a logged-in traveler's account was scattered
 * across profile.php, tour-history.php, wallet.php, loyalty.php, and
 * wishlist.php with no single "here's where things stand" home base —
 * the topbar dropdown just linked straight into each silo. This page
 * pulls real numbers from each of those tables into one view, and gives
 * the single most useful thing a returning traveler wants: their next
 * trip, at a glance, styled like the boarding pass it will become.
 *
 * Every figure here is a real query against the user's own data — no
 * placeholder counts.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];

$user = $dbh->prepare("SELECT * FROM tblusers WHERE EmailId=:e LIMIT 1");
$user->execute([':e' => $email]);
$user = $user->fetch(PDO::FETCH_OBJ);
$firstName = $user && $user->FullName ? trim(explode(' ', $user->FullName)[0]) : tms_t('dash_traveler_fallback_name', 'Traveler');
$memberSince = $user && $user->RegDate ? date('F Y', strtotime($user->RegDate)) : null;

// ─── Next trip: nearest upcoming confirmed booking ─────────────────────────
$nextTrip = $dbh->prepare(
    "SELECT b.BookingId, b.FromDate, b.ToDate, b.VerificationCode, b.NoOfPersons,
            p.PackageName, p.PackageLocation, p.PackageImage
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.UserEmail = :e AND b.status = 1 AND b.FromDate >= CURDATE()
     ORDER BY b.FromDate ASC LIMIT 1"
);
$nextTrip->execute([':e' => $email]);
$nextTrip = $nextTrip->fetch(PDO::FETCH_OBJ);
$daysToGo = null;
if ($nextTrip && $nextTrip->FromDate) {
    try {
        $daysToGo = max(0, (int)(new DateTime('today'))->diff(new DateTime($nextTrip->FromDate))->format('%r%a'));
    } catch (Exception $e) { $daysToGo = null; }
}

// ─── Quick stats — every number below is a real COUNT/SUM, not a placeholder ─
$stmt = $dbh->prepare("SELECT COUNT(*) FROM tblbooking WHERE UserEmail=:e AND status=1");
$stmt->execute([':e' => $email]);
$totalTrips = (int)$stmt->fetchColumn();

$stmt = $dbh->prepare("SELECT COUNT(*) FROM tblbooking WHERE UserEmail=:e AND status=0");
$stmt->execute([':e' => $email]);
$pendingTrips = (int)$stmt->fetchColumn();

$stmt = $dbh->prepare("SELECT Points FROM tblloyalty WHERE UserEmail=:e LIMIT 1");
$stmt->execute([':e' => $email]);
$loyaltyPoints = (int)($stmt->fetchColumn() ?: 0);

$stmt = $dbh->prepare("SELECT COUNT(*) FROM tblwishlist WHERE UserEmail=:e");
$stmt->execute([':e' => $email]);
$wishlistCount = (int)$stmt->fetchColumn();

$stmt = $dbh->prepare("SELECT COUNT(*) FROM tbltraveldocuments WHERE UserEmail=:e");
$stmt->execute([':e' => $email]);
$documentCount = (int)$stmt->fetchColumn();

$stmt = $dbh->prepare("SELECT COUNT(*) FROM tblreviews WHERE UserEmail=:e");
$stmt->execute([':e' => $email]);
$reviewCount = (int)$stmt->fetchColumn();

$stmt = $dbh->prepare("SELECT COUNT(*) FROM tblissues WHERE UserEmail=:e AND (AdminRemark IS NULL OR AdminRemark = '')");
$stmt->execute([':e' => $email]);
$openTickets = (int)$stmt->fetchColumn();

// Marketplace bookings (Round 37 -- sample-data hotels & activities marketplace).
// Wrapped in try/catch since these tables only exist once a session has triggered
// the ad-hoc migration in backend/config.php; a brand-new account visiting before
// that has run should see 0, not an error.
$marketplaceBookings = 0;
try {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tblhotel_bookings WHERE UserEmail=:e AND status != 2");
    $stmt->execute([':e' => $email]);
    $marketplaceBookings += (int)$stmt->fetchColumn();
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tblactivity_bookings WHERE UserEmail=:e AND status != 2");
    $stmt->execute([':e' => $email]);
    $marketplaceBookings += (int)$stmt->fetchColumn();
} catch (Exception $e) { /* tables not migrated yet on this environment -- treat as 0 */ }

// ─── Recent bookings (last 4, any status) ──────────────────────────────────
$recent = $dbh->prepare(
    "SELECT b.BookingId, b.FromDate, b.status, p.PackageName, p.PackageLocation
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.UserEmail = :e ORDER BY b.RegDate DESC LIMIT 4"
);
$recent->execute([':e' => $email]);
$recent = $recent->fetchAll(PDO::FETCH_OBJ);

$statusMeta = [
    0 => ['label' => tms_t('label_pending', 'Pending'),   'color' => '#92400E', 'bg' => '#fef3c7'],
    1 => ['label' => tms_t('step_confirmed', 'Confirmed'), 'color' => '#065F46', 'bg' => '#d1fae5'],
    2 => ['label' => tms_t('label_cancelled', 'Cancelled'), 'color' => '#B91C1C', 'bg' => '#fee2e2'],
];
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<title>TMS | My Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/qrcode.js"></script>
<style>
.dash-hero{background:linear-gradient(160deg,var(--primary-dark),var(--primary) 60%,#0C3A4A);color:#fff;padding:44px 0 36px;}
.dash-hero h1{font-family:var(--font-display);font-weight:600;font-size:30px;margin:0 0 4px;}
.dash-hero p{opacity:.8;font-size:13.5px;margin:0;}

.dash-stats{display:flex;gap:14px;flex-wrap:wrap;margin-top:26px;}
.dash-stat{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.14);border-radius:var(--radius-md);padding:14px 20px;flex:1;min-width:130px;}
.dash-stat .num{font-family:var(--font-mono);font-size:24px;font-weight:700;line-height:1;}
.dash-stat .lbl{font-size:11.5px;opacity:.75;margin-top:6px;}

.dash-section{padding:36px 0;}
.dash-card{background:var(--white);border-radius:var(--radius-md);box-shadow:var(--shadow-sm);padding:22px;margin-bottom:22px;}
.dash-card h4{font-family:var(--font-display);font-weight:600;font-size:17px;color:var(--text);margin:0 0 16px;}

/* Signature: the "next trip" boarding-pass preview card */
.boarding-preview{position:relative;background:linear-gradient(120deg,var(--primary-dark),var(--primary));color:#fff;border-radius:var(--radius-lg);padding:26px 28px;display:flex;align-items:center;gap:24px;flex-wrap:wrap;overflow:hidden;}
.boarding-preview::before{content:'';position:absolute;inset:0;background-image:radial-gradient(circle at 15% 20%, rgba(255,255,255,0.5) 1.5px, transparent 1.5px),radial-gradient(circle at 80% 70%, rgba(255,255,255,0.35) 1px, transparent 1px);opacity:.5;}
.boarding-preview .content{position:relative;flex:1;min-width:220px;}
.boarding-preview .eyebrow{font-family:var(--font-mono);font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.75;margin-bottom:6px;}
.boarding-preview h3{font-family:var(--font-display);font-size:24px;font-weight:600;margin:0 0 6px;}
.boarding-preview .meta{font-size:13px;opacity:.85;}
.boarding-preview .countdown{position:relative;text-align:center;background:rgba(255,255,255,0.12);border:1px dashed rgba(255,255,255,0.4);border-radius:var(--radius-md);padding:14px 20px;flex-shrink:0;}
.boarding-preview .countdown .n{font-family:var(--font-mono);font-size:30px;font-weight:700;line-height:1;}
.boarding-preview .countdown .l{font-size:10.5px;opacity:.75;margin-top:4px;text-transform:uppercase;letter-spacing:.06em;}
.boarding-preview .cta{position:relative;flex-shrink:0;}
.boarding-preview .cta a{background:var(--accent);color:#fff;padding:11px 22px;border-radius:var(--radius-sm);font-weight:700;font-size:13.5px;display:inline-block;}

.no-trip{background:var(--white);border-radius:var(--radius-lg);border:2px dashed var(--bg-dark);padding:34px 28px;text-align:center;}
.no-trip i{font-size:34px;color:var(--secondary);margin-bottom:10px;}
html[data-theme="dark"] .dash-primary-btn{color:#0E3B2D !important;}
.no-trip a{background:var(--secondary);color:#fff;padding:10px 24px;border-radius:var(--radius-sm);font-weight:700;font-size:13.5px;display:inline-block;margin-top:12px;}
html[data-theme="dark"] .no-trip a{color:#0E3B2D;}

.quick-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;}
.quick-tile{background:var(--white);border-radius:var(--radius-md);box-shadow:var(--shadow-sm);padding:18px;text-align:center;transition:var(--transition);border:1px solid transparent;}
.quick-tile:hover{box-shadow:var(--shadow-md);transform:translateY(-3px);border-color:rgba(23,107,80,0.25);}
.quick-tile i{font-size:22px;color:var(--secondary);margin-bottom:8px;}
.quick-tile .n{font-family:var(--font-mono);font-size:20px;font-weight:700;color:var(--text);}
.quick-tile .l{font-size:11.5px;color:var(--text-muted);margin-top:2px;}
.quick-tile .badge-alert{background:var(--accent);color:#fff;font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:10px;margin-left:4px;vertical-align:top;}

.recent-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px dashed var(--bg-dark);}
.recent-row:last-child{border-bottom:none;}
.recent-status{font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:20px;flex-shrink:0;}
</style>
</head><body>
<?php include('backend/header.php'); ?>

<div class="dash-hero">
  <div class="container">
    <h1><?php echo htmlspecialchars(tms_t('dash_welcome_back_prefix','Welcome back,'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?php echo $memberSince ? htmlspecialchars(tms_t('dash_traveling_since_prefix','Traveling with TMS since'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($memberSince, ENT_QUOTES, 'UTF-8') : htmlspecialchars(tms_t('dash_command_center','Your travel command center'), ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="dash-stats">
      <div class="dash-stat"><div class="num"><?php echo $totalTrips; ?></div><div class="lbl"><?php echo htmlspecialchars(tms_t('dash_stat_trips_taken','Trips taken'), ENT_QUOTES, 'UTF-8'); ?></div></div>
      <div class="dash-stat"><div class="num"><?php echo $loyaltyPoints; ?></div><div class="lbl"><?php echo htmlspecialchars(tms_t('dash_stat_loyalty_points','Loyalty points'), ENT_QUOTES, 'UTF-8'); ?></div></div>
      <div class="dash-stat"><div class="num"><?php echo $wishlistCount; ?></div><div class="lbl"><?php echo htmlspecialchars(tms_t('dash_stat_wishlisted','Wishlisted'), ENT_QUOTES, 'UTF-8'); ?></div></div>
      <div class="dash-stat"><div class="num"><?php echo $documentCount; ?></div><div class="lbl"><?php echo htmlspecialchars(tms_t('dash_stat_wallet_documents','Wallet documents'), ENT_QUOTES, 'UTF-8'); ?></div></div>
    </div>
  </div>
</div>

<div class="dash-section">
  <div class="container">

    <?php if ($nextTrip): ?>
    <div class="boarding-preview">
      <div class="content">
        <div class="eyebrow"><?php echo htmlspecialchars(tms_t('dash_your_next_trip','Your next trip'), ENT_QUOTES, 'UTF-8'); ?></div>
        <h3><?php echo htmlspecialchars($nextTrip->PackageName, ENT_QUOTES, 'UTF-8'); ?></h3>
        <div class="meta"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($nextTrip->PackageLocation, ENT_QUOTES, 'UTF-8'); ?>
          &nbsp;·&nbsp; <i class="fa fa-calendar"></i> <?php echo htmlspecialchars($nextTrip->FromDate, ENT_QUOTES, 'UTF-8'); ?>
          &nbsp;·&nbsp; <i class="fa fa-users"></i> <?php echo (int)$nextTrip->NoOfPersons; ?> <?php echo htmlspecialchars((int)$nextTrip->NoOfPersons === 1 ? tms_t('label_traveler_word','traveler') : tms_t('label_travelers_word','travelers'), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <?php if ($daysToGo !== null): ?>
      <div class="countdown">
        <div class="n"><?php echo $daysToGo; ?></div>
        <div class="l"><?php echo htmlspecialchars($daysToGo === 1 ? tms_t('dash_day_to_go','Day to go') : tms_t('dash_days_to_go','Days to go'), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <?php endif; ?>
      <div class="cta"><a href="ticket.php?bkid=<?php echo (int)$nextTrip->BookingId; ?>"><i class="fa fa-ticket"></i> <?php echo htmlspecialchars(tms_t('dash_view_eticket','View E-Ticket'), ENT_QUOTES, 'UTF-8'); ?></a></div>
    </div>
    <?php else: ?>
    <div class="no-trip">
      <i class="fa fa-compass"></i>
      <h4 style="font-family:var(--font-display);color:var(--text);margin:0 0 6px;"><?php echo htmlspecialchars(tms_t('dash_no_upcoming_trips','No upcoming trips yet'), ENT_QUOTES, 'UTF-8'); ?></h4>
      <p style="color:var(--text-muted);font-size:13.5px;margin:0;"><?php echo htmlspecialchars(tms_t('dash_next_adventure','Your next adventure is one search away.'), ENT_QUOTES, 'UTF-8'); ?></p>
      <a href="package-list.php"><i class="fa fa-suitcase"></i> <?php echo htmlspecialchars(tms_t('btn_browse_packages','Browse Packages'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
    <?php endif; ?>

    <div class="row" style="margin-top:26px;">
      <div class="col-md-8">
        <div class="quick-grid">
          <a href="tour-history.php" class="quick-tile" style="text-decoration:none;">
            <i class="fa fa-suitcase"></i>
            <div class="n"><?php echo $totalTrips + $pendingTrips; ?></div>
            <div class="l"><?php echo htmlspecialchars(tms_t('heading_my_bookings','My Bookings'), ENT_QUOTES, 'UTF-8'); ?><?php echo $pendingTrips ? ' <span class="badge-alert">' . (int)$pendingTrips . ' ' . htmlspecialchars(tms_t('dash_pending_badge_suffix','pending'), ENT_QUOTES, 'UTF-8') . '</span>' : ''; ?></div>
          </a>
          <a href="loyalty.php" class="quick-tile" style="text-decoration:none;">
            <i class="fa fa-star"></i>
            <div class="n"><?php echo $loyaltyPoints; ?></div>
            <div class="l"><?php echo htmlspecialchars(tms_t('dash_tile_loyalty_points','Loyalty Points'), ENT_QUOTES, 'UTF-8'); ?></div>
          </a>
          <a href="wishlist.php" class="quick-tile" style="text-decoration:none;">
            <i class="fa fa-heart"></i>
            <div class="n"><?php echo $wishlistCount; ?></div>
            <div class="l"><?php echo htmlspecialchars(tms_t('nav_wishlist','Wishlist'), ENT_QUOTES, 'UTF-8'); ?></div>
          </a>
          <a href="wallet.php" class="quick-tile" style="text-decoration:none;">
            <i class="fa fa-briefcase"></i>
            <div class="n"><?php echo $documentCount; ?></div>
            <div class="l"><?php echo htmlspecialchars(tms_t('heading_travel_wallet','Travel Wallet'), ENT_QUOTES, 'UTF-8'); ?></div>
          </a>
          <a href="my-marketplace-bookings.php" class="quick-tile" style="text-decoration:none;">
            <i class="fa fa-hotel"></i>
            <div class="n"><?php echo $marketplaceBookings; ?></div>
            <div class="l"><?php echo htmlspecialchars(tms_t('dash_tile_marketplace_bookings','Marketplace Bookings'), ENT_QUOTES, 'UTF-8'); ?></div>
          </a>
          <a href="reviews.php" class="quick-tile" style="text-decoration:none;">
            <i class="fa fa-comment"></i>
            <div class="n"><?php echo $reviewCount; ?></div>
            <div class="l"><?php echo htmlspecialchars(tms_t('dash_tile_my_reviews','My Reviews'), ENT_QUOTES, 'UTF-8'); ?></div>
          </a>
          <a href="issuetickets.php" class="quick-tile" style="text-decoration:none;">
            <i class="fa fa-life-ring"></i>
            <div class="n"><?php echo $openTickets; ?></div>
            <div class="l"><?php echo htmlspecialchars(tms_t('link_support','Support'), ENT_QUOTES, 'UTF-8'); ?><?php echo $openTickets ? ' <span class="badge-alert">' . htmlspecialchars(tms_t('dash_open_badge','open'), ENT_QUOTES, 'UTF-8') . '</span>' : ''; ?></div>
          </a>
        </div>

        <div class="dash-card" style="margin-top:22px;">
          <h4><i class="fa fa-history"></i> <?php echo htmlspecialchars(tms_t('dash_recent_activity','Recent Activity'), ENT_QUOTES, 'UTF-8'); ?></h4>
          <?php if (empty($recent)): ?>
          <div style="color:var(--text-muted);font-size:13px;"><?php echo htmlspecialchars(tms_t('dash_no_bookings_recent',"No bookings yet — once you book a trip, it'll show up here."), ENT_QUOTES, 'UTF-8'); ?></div>
          <?php else: foreach ($recent as $r): $sm = $statusMeta[(int)$r->status] ?? $statusMeta[0]; ?>
          <div class="recent-row">
            <div style="flex:1;">
              <div style="font-size:13.5px;font-weight:600;color:var(--text);"><?php echo htmlspecialchars($r->PackageName, ENT_QUOTES, 'UTF-8'); ?></div>
              <div style="font-size:11.5px;color:var(--text-muted);"><?php echo htmlspecialchars($r->PackageLocation, ENT_QUOTES, 'UTF-8'); ?><?php echo $r->FromDate ? ' · ' . htmlspecialchars($r->FromDate, ENT_QUOTES, 'UTF-8') : ''; ?></div>
            </div>
            <span class="recent-status" style="background:<?php echo $sm['bg']; ?>;color:<?php echo $sm['color']; ?>;"><?php echo $sm['label']; ?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="col-md-4">
        <div class="dash-card">
          <h4><i class="fa fa-bolt"></i> <?php echo htmlspecialchars(tms_t('dash_quick_actions','Quick Actions'), ENT_QUOTES, 'UTF-8'); ?></h4>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <a href="ai-planner.php" class="btn dash-primary-btn" style="background:var(--secondary);color:#fff;text-align:left;font-weight:600;font-size:13px;padding:11px 14px;border-radius:var(--radius-sm);"><i class="fa fa-magic"></i>&nbsp; <?php echo htmlspecialchars(tms_t('dash_plan_trip_ai','Plan a trip with AI'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a href="package-list.php" class="btn" style="background:var(--bg-dark);color:var(--text);text-align:left;font-weight:600;font-size:13px;padding:11px 14px;border-radius:var(--radius-sm);"><i class="fa fa-search"></i>&nbsp; <?php echo htmlspecialchars(tms_t('btn_browse_packages','Browse Packages'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a href="profile.php" class="btn" style="background:var(--bg-dark);color:var(--text);text-align:left;font-weight:600;font-size:13px;padding:11px 14px;border-radius:var(--radius-sm);"><i class="fa fa-user"></i>&nbsp; <?php echo htmlspecialchars(tms_t('dash_edit_profile','Edit Profile'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a href="issuetickets.php" class="btn" style="background:var(--bg-dark);color:var(--text);text-align:left;font-weight:600;font-size:13px;padding:11px 14px;border-radius:var(--radius-sm);"><i class="fa fa-life-ring"></i>&nbsp; <?php echo htmlspecialchars(tms_t('dash_get_support','Get Support'), ENT_QUOTES, 'UTF-8'); ?></a>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<?php include('backend/footer.php'); ?>
</body></html>

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
<title>TourBuddy | My Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
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
<link rel="stylesheet" href="css/tms-account.css?v=2">
<style>
.tms-trip{position:relative;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;padding:26px 28px;border-radius:24px;color:#fff;overflow:hidden;margin-bottom:24px;
  background:linear-gradient(110deg,rgba(5,52,60,.88),rgba(14,124,134,.55)),var(--trip-img,none) center/cover,var(--tms-primary-strong);box-shadow:var(--tms-shadow-lg);}
.tms-trip h3{margin:2px 0 6px;font-size:24px;font-weight:800;color:#fff}.tms-trip .eyebrow{font-size:12px;letter-spacing:.1em;text-transform:uppercase;opacity:.85;font-weight:700}
.tms-trip .meta{opacity:.92;font-size:14px}.tms-trip .count{text-align:center;background:rgba(255,255,255,.16);border-radius:16px;padding:12px 20px;backdrop-filter:blur(6px)}
.tms-trip .count b{display:block;font-size:34px;line-height:1}.tms-trip .count span{font-size:12px;opacity:.85}
.tms-trip .cta{grid-column:1/-1}.tms-trip .cta a{display:inline-flex;gap:8px;align-items:center;background:var(--tms-accent);color:var(--tms-accent-ink,#1d0c05);font-weight:800;padding:12px 22px;border-radius:12px;text-decoration:none}
.tms-two{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:20px;align-items:start}
@media(max-width:860px){.tms-two{grid-template-columns:1fr}.tms-trip{grid-template-columns:1fr}}
.recent-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px dashed var(--tms-border)}.recent-row:last-child{border:0}
.recent-row .t{flex:1;font-weight:700}.recent-row .t small{display:block;color:var(--tms-muted);font-weight:500}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<?php
$chipOf = function ($st) { $st=(int)$st; return $st===1 ? ['ok','check-circle'] : ($st===2 ? ['bad','times-circle'] : ['warn','clock-o']); };
?>
<main class="tms-acct">
  <section class="tms-acct-hero reveal">
    <h1><?php echo htmlspecialchars(tms_t('dash_welcome_back_prefix','Welcome back,'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?php echo $memberSince ? htmlspecialchars(tms_t('dash_traveling_since_prefix','Traveling with TourBuddy since'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($memberSince, ENT_QUOTES, 'UTF-8') : htmlspecialchars(tms_t('dash_command_center','Your travel command center'), ENT_QUOTES, 'UTF-8'); ?></p>
  </section>

  <div class="tms-stats">
    <a class="tms-stat" href="<?php echo $nextTrip ? 'ticket.php?bkid=' . (int)$nextTrip->BookingId : 'package-list.php'; ?>"><span class="ico"><i class="fa fa-plane" aria-hidden="true"></i></span><div><b><?php echo $daysToGo !== null ? (int)$daysToGo : '–'; ?></b><span><?php echo $daysToGo !== null ? htmlspecialchars($daysToGo === 1 ? tms_t('dash_day_to_go','Day to go') : tms_t('dash_days_to_go','Days to go'), ENT_QUOTES, 'UTF-8') : 'No trip planned'; ?></span></div></a>
    <a class="tms-stat" href="tour-history.php"><span class="ico"><i class="fa fa-suitcase" aria-hidden="true"></i></span><div><b><?php echo $totalTrips + $pendingTrips; ?></b><span><?php echo htmlspecialchars(tms_t('heading_my_bookings','My Bookings'), ENT_QUOTES, 'UTF-8'); ?><?php echo $pendingTrips ? ' · ' . (int)$pendingTrips . ' ' . htmlspecialchars(tms_t('dash_pending_badge_suffix','pending'), ENT_QUOTES, 'UTF-8') : ''; ?></span></div></a>
    <a class="tms-stat" href="loyalty.php"><span class="ico"><i class="fa fa-star" aria-hidden="true"></i></span><div><b><?php echo $loyaltyPoints; ?></b><span><?php echo htmlspecialchars(tms_t('dash_tile_loyalty_points','Loyalty Points'), ENT_QUOTES, 'UTF-8'); ?></span></div></a>
    <a class="tms-stat" href="wishlist.php"><span class="ico"><i class="fa fa-heart" aria-hidden="true"></i></span><div><b><?php echo $wishlistCount; ?></b><span><?php echo htmlspecialchars(tms_t('nav_wishlist','Wishlist'), ENT_QUOTES, 'UTF-8'); ?></span></div></a>
  </div>

  <?php if ($nextTrip): ?>
  <section class="tms-trip" style="--trip-img:url('<?php echo htmlspecialchars('../images/' . basename((string)($nextTrip->PackageImage ?: 'h1.jpg')), ENT_QUOTES, 'UTF-8'); ?>')">
    <div>
      <div class="eyebrow"><?php echo htmlspecialchars(tms_t('dash_your_next_trip','Your next trip'), ENT_QUOTES, 'UTF-8'); ?></div>
      <h3><?php echo htmlspecialchars($nextTrip->PackageName, ENT_QUOTES, 'UTF-8'); ?></h3>
      <div class="meta"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo htmlspecialchars($nextTrip->PackageLocation, ENT_QUOTES, 'UTF-8'); ?>
        &nbsp;·&nbsp; <i class="fa fa-calendar" aria-hidden="true"></i> <?php echo htmlspecialchars($nextTrip->FromDate, ENT_QUOTES, 'UTF-8'); ?>
        &nbsp;·&nbsp; <i class="fa fa-users" aria-hidden="true"></i> <?php echo (int)$nextTrip->NoOfPersons; ?> <?php echo htmlspecialchars((int)$nextTrip->NoOfPersons === 1 ? tms_t('label_traveler_word','traveler') : tms_t('label_travelers_word','travelers'), ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <?php if ($daysToGo !== null): ?><div class="count"><b><?php echo $daysToGo; ?></b><span><?php echo htmlspecialchars($daysToGo === 1 ? tms_t('dash_day_to_go','Day to go') : tms_t('dash_days_to_go','Days to go'), ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
    <div class="cta"><a href="ticket.php?bkid=<?php echo (int)$nextTrip->BookingId; ?>"><i class="fa fa-ticket" aria-hidden="true"></i> <?php echo htmlspecialchars(tms_t('dash_view_eticket','View E-Ticket'), ENT_QUOTES, 'UTF-8'); ?></a></div>
  </section>
  <?php else: ?>
  <section class="tms-card tms-empty">
    <svg viewBox="0 0 120 96" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="60" cy="48" r="30"/><path d="M60 18v12M60 66v12M30 48h12M78 48h12"/><path d="M72 36 54 54l-6 12 12-6 18-18z" fill="currentColor" opacity=".25"/></svg>
    <h3><?php echo htmlspecialchars(tms_t('dash_no_upcoming_trips','No upcoming trips yet'), ENT_QUOTES, 'UTF-8'); ?></h3>
    <p><?php echo htmlspecialchars(tms_t('dash_next_adventure','Your next adventure is one search away.'), ENT_QUOTES, 'UTF-8'); ?></p>
    <a class="tms-btn-primary" style="width:auto;display:inline-flex;padding:0 26px" href="package-list.php"><i class="fa fa-suitcase" aria-hidden="true"></i> <?php echo htmlspecialchars(tms_t('btn_browse_packages','Browse Packages'), ENT_QUOTES, 'UTF-8'); ?></a>
  </section>
  <?php endif; ?>

  <div class="tms-actions">
    <a class="tms-btn-ghost" href="ai-planner.php"><i class="fa fa-magic" aria-hidden="true"></i> <?php echo htmlspecialchars(tms_t('dash_plan_trip_ai','Plan a trip with AI'), ENT_QUOTES, 'UTF-8'); ?></a>
    <a class="tms-btn-ghost" href="package-list.php"><i class="fa fa-search" aria-hidden="true"></i> <?php echo htmlspecialchars(tms_t('btn_browse_packages','Browse Packages'), ENT_QUOTES, 'UTF-8'); ?></a>
    <a class="tms-btn-ghost" href="tour-history.php"><i class="fa fa-suitcase" aria-hidden="true"></i> <?php echo htmlspecialchars(tms_t('heading_my_bookings','My Bookings'), ENT_QUOTES, 'UTF-8'); ?></a>
    <a class="tms-btn-ghost" href="issuetickets.php"><i class="fa fa-life-ring" aria-hidden="true"></i> <?php echo htmlspecialchars(tms_t('dash_get_support','Get Support'), ENT_QUOTES, 'UTF-8'); ?><?php echo $openTickets ? ' (' . (int)$openTickets . ')' : ''; ?></a>
  </div>

  <div class="tms-two">
    <section class="tms-card">
      <h2><?php echo htmlspecialchars(tms_t('dash_recent_activity','Recent Activity'), ENT_QUOTES, 'UTF-8'); ?></h2>
      <?php if (empty($recent)): ?>
      <p style="color:var(--tms-muted);margin:0;"><?php echo htmlspecialchars(tms_t('dash_no_bookings_recent',"No bookings yet — once you book a trip, it'll show up here."), ENT_QUOTES, 'UTF-8'); ?></p>
      <?php else: foreach ($recent as $r): $sm = $statusMeta[(int)$r->status] ?? $statusMeta[0]; [$cc, $ci] = $chipOf($r->status); ?>
      <div class="recent-row">
        <div class="t"><?php echo htmlspecialchars($r->PackageName, ENT_QUOTES, 'UTF-8'); ?>
          <small><?php echo htmlspecialchars($r->PackageLocation, ENT_QUOTES, 'UTF-8'); ?><?php echo $r->FromDate ? ' · ' . htmlspecialchars($r->FromDate, ENT_QUOTES, 'UTF-8') : ''; ?></small></div>
        <span class="tms-chip <?php echo $cc; ?>"><i class="fa fa-<?php echo $ci; ?>" aria-hidden="true"></i> <?php echo $sm['label']; ?></span>
      </div>
      <?php endforeach; endif; ?>
    </section>

    <section class="tms-card">
      <h2>Account &amp; more</h2>
      <ul class="tms-list">
        <li><a href="profile.php"><i class="fa fa-user l" aria-hidden="true"></i><span><?php echo htmlspecialchars(tms_t('dash_edit_profile','Edit Profile'), ENT_QUOTES, 'UTF-8'); ?><small>Name, password, privacy, 2FA</small></span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
        <li><a href="wallet.php"><i class="fa fa-briefcase l" aria-hidden="true"></i><span><?php echo htmlspecialchars(tms_t('heading_travel_wallet','Travel Wallet'), ENT_QUOTES, 'UTF-8'); ?><small><?php echo (int)$documentCount; ?> documents</small></span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
        <li><a href="my-marketplace-bookings.php"><i class="fa fa-hotel l" aria-hidden="true"></i><span><?php echo htmlspecialchars(tms_t('dash_tile_marketplace_bookings','Marketplace Bookings'), ENT_QUOTES, 'UTF-8'); ?><small><?php echo (int)$marketplaceBookings; ?> bookings</small></span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
        <li><a href="reviews.php"><i class="fa fa-comment l" aria-hidden="true"></i><span><?php echo htmlspecialchars(tms_t('dash_tile_my_reviews','My Reviews'), ENT_QUOTES, 'UTF-8'); ?><small><?php echo (int)$reviewCount; ?> reviews</small></span><i class="fa fa-chevron-right r" aria-hidden="true"></i></a></li>
      </ul>
    </section>
  </div>
</main>

<?php include('backend/footer.php'); ?>
</body></html>

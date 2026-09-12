<?php
/**
 * ticket.php — Downloadable e-ticket / itinerary for a confirmed booking.
 *
 * This project has no vendored PDF library (no Composer/vendor folder, no
 * FPDF/TCPDF), so rather than hand-rolling a raw PDF binary generator with
 * no way to test it in this environment, this page is a clean, print-
 * optimized HTML ticket. The "Save as PDF" button uses the browser's own
 * print dialog (window.print() -> "Save as PDF" destination), which every
 * modern browser supports natively and reliably, with zero new dependencies.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$bid   = tms_int($_GET['bkid'] ?? 0, 1);
$email = $_SESSION['login'];

$q = $dbh->prepare(
    "SELECT b.BookingId, b.PackageId, b.FromDate, b.ToDate, b.Comment, b.status, b.RegDate,
            b.NoOfPersons, b.TotalCost, b.MobileNumber, b.CouponCode, b.DiscountApplied, b.VerificationCode,
            p.PackageName, p.PackageLocation, p.PackageDuration, p.PackagePrice
     FROM tblbooking b
     JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.BookingId=:bid AND b.UserEmail=:email LIMIT 1"
);
$q->execute([':bid' => $bid, ':email' => $email]);
$booking = $q->fetch(PDO::FETCH_OBJ);

$uq = $dbh->prepare("SELECT FullName FROM tblusers WHERE EmailId=:e LIMIT 1");
$uq->execute([':e' => $email]);
$passenger = $uq->fetchColumn() ?: $email;

$notFound   = !$booking;
$notReady   = $booking && (int)$booking->status !== 1;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | E-Ticket<?php echo $booking ? ' #BK'.str_pad($booking->BookingId,3,'0',STR_PAD_LEFT) : ''; ?></title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; background:#f1f3f6; margin:0; padding:30px 16px; color:#26313f; }
  .ticket-wrap { max-width:720px; margin:0 auto; }
  .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
  .toolbar a { color:var(--secondary); text-decoration:none; font-size:14px; }
  .btn-print { background:var(--secondary); color:#fff; border:none; padding:10px 22px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
  .btn-print:hover { background:#2c6089; }
  .ticket { background:#fff; border-radius:14px; box-shadow:0 6px 30px rgba(0,0,0,0.09); overflow:hidden; }
  .ticket-head { background:linear-gradient(135deg,var(--primary-dark),var(--primary)); color:#fff; padding:26px 30px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
  .ticket-head h1 { margin:0; font-size:20px; }
  .ticket-head .brand { font-size:13px; opacity:.9; margin-top:4px; }
  .ticket-head .ref { text-align:right; }
  .ticket-head .ref .lbl { font-size:11px; opacity:.85; letter-spacing:.5px; }
  .ticket-head .ref .val { font-size:22px; font-weight:800; }
  .status-pill { display:inline-block; margin-top:6px; background:rgba(255,255,255,0.2); padding:3px 12px; border-radius:20px; font-size:11.5px; font-weight:700; }
  .ticket-body { padding:28px 30px; }
  .row { display:flex; flex-wrap:wrap; gap:24px; margin-bottom:22px; }
  .field { flex:1; min-width:170px; }
  .field .lbl { font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
  .field .val { font-size:15px; font-weight:600; color:#1e293b; }
  hr.dashed { border:none; border-top:2px dashed #e2e8f0; margin:22px 0; }
  .cost-box { background:#f8fafc; border-radius:10px; padding:16px 20px; }
  .cost-row { display:flex; justify-content:space-between; font-size:13.5px; padding:4px 0; color:#475569; }
  .cost-row.total { font-size:16px; font-weight:800; color:#1e293b; border-top:1px solid #e2e8f0; margin-top:6px; padding-top:10px; }
  .footer-note { padding:18px 30px 28px; font-size:12px; color:#94a3b8; line-height:1.6; }
  .empty-box { background:#fff; border-radius:14px; padding:60px 30px; text-align:center; box-shadow:0 6px 30px rgba(0,0,0,0.09); }
  .empty-box i { font-size:48px; color:#e2e8f0; margin-bottom:16px; display:block; }
  .boarding-stub { position:relative; display:flex; align-items:center; gap:16px; padding:20px 30px; border-top:1px dashed #e2e8f0; }
  .stub-perf { position:absolute; top:-11px; left:0; right:0; height:22px; background:
      radial-gradient(circle at 0 11px, transparent 10px, #f1f3f6 11px) left/22px 22px repeat-x; }
  .qr-box { width:96px; height:96px; flex-shrink:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:6px; box-sizing:border-box; display:flex; align-items:center; justify-content:center; }
  .qr-noscript { font-size:11px; color:#94a3b8; text-align:center; }
  .stub-info { font-size:12.5px; color:#475569; line-height:1.6; }
  @media print {
    body { background:#fff; padding:0; }
    .toolbar { display:none; }
    .ticket { box-shadow:none; border-radius:0; }
  }
</style>
</head>
<body>
<div class="ticket-wrap">
  <div class="toolbar">
    <a href="tour-history.php"><i class="fa fa-arrow-left"></i> Back to My Bookings</a>
    <?php if ($booking && !$notReady): ?>
    <button class="btn-print" onclick="window.print()"><i class="fa fa-download"></i> Save as PDF / Print</button>
    <?php endif; ?>
  </div>

  <?php if ($notFound): ?>
    <div class="empty-box">
      <i class="fa fa-ticket"></i>
      <h3>Ticket not found</h3>
      <p style="color:#94a3b8;">We couldn't find that booking on your account.</p>
    </div>
  <?php elseif ($notReady): ?>
    <div class="empty-box">
      <i class="fa fa-clock-o"></i>
      <h3>Ticket not ready yet</h3>
      <p style="color:#94a3b8;">
        <?php echo (int)$booking->status === 2
          ? 'This booking was cancelled, so no ticket is available.'
          : 'This booking is still pending confirmation. Your e-ticket will be ready once our team confirms it.'; ?>
      </p>
    </div>
  <?php else: ?>
    <div class="ticket">
      <div class="ticket-head">
        <div>
          <h1><i class="fa fa-plane"></i> TMS E-Ticket</h1>
          <div class="brand">Tourism Management System</div>
          <div class="status-pill"><i class="fa fa-check-circle"></i> Confirmed</div>
        </div>
        <div class="ref">
          <div class="lbl">BOOKING REFERENCE</div>
          <div class="val">#BK<?php echo str_pad($booking->BookingId,3,'0',STR_PAD_LEFT); ?></div>
        </div>
      </div>
      <div class="ticket-body">
        <div class="row">
          <div class="field">
            <div class="lbl">Passenger</div>
            <div class="val"><?php echo htmlspecialchars($passenger, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="field">
            <div class="lbl">Contact Email</div>
            <div class="val"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <?php if (!empty($booking->MobileNumber)): ?>
          <div class="field">
            <div class="lbl">Mobile</div>
            <div class="val"><?php echo htmlspecialchars($booking->MobileNumber, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <?php endif; ?>
        </div>

        <hr class="dashed">

        <div class="row">
          <div class="field" style="flex-basis:100%;">
            <div class="lbl">Tour Package</div>
            <div class="val" style="font-size:17px;"><?php echo htmlspecialchars($booking->PackageName, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>
        <div class="row">
          <div class="field">
            <div class="lbl">Destination</div>
            <div class="val"><i class="fa fa-map-marker" style="color:var(--secondary);"></i> <?php echo htmlspecialchars($booking->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="field">
            <div class="lbl">From</div>
            <div class="val"><?php echo htmlspecialchars($booking->FromDate, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="field">
            <div class="lbl">To</div>
            <div class="val"><?php echo htmlspecialchars($booking->ToDate, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <?php if (!empty($booking->NoOfPersons)): ?>
          <div class="field">
            <div class="lbl">Travelers</div>
            <div class="val"><?php echo (int)$booking->NoOfPersons; ?></div>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($booking->Comment)): ?>
        <div class="row">
          <div class="field" style="flex-basis:100%;">
            <div class="lbl">Special Notes</div>
            <div class="val" style="font-weight:400;font-size:13.5px;"><?php echo nl2br(htmlspecialchars($booking->Comment, ENT_QUOTES, 'UTF-8')); ?></div>
          </div>
        </div>
        <?php endif; ?>

        <hr class="dashed">

        <div class="cost-box">
          <?php
            $unitPrice = (float)$booking->PackagePrice;
            $persons   = max(1, (int)($booking->NoOfPersons ?: 1));
            $subtotal  = !empty($booking->TotalCost) ? (float)$booking->TotalCost + (float)($booking->DiscountApplied ?? 0) : $unitPrice * $persons;
            $discount  = (float)($booking->DiscountApplied ?? 0);
            $total     = !empty($booking->TotalCost) ? (float)$booking->TotalCost : max(0, $subtotal - $discount);
          ?>
          <div class="cost-row"><span>Package price × <?php echo $persons; ?> traveler(s)</span><span><?php echo tms_currency($subtotal); ?></span></div>
          <?php if ($discount > 0): ?>
          <div class="cost-row"><span>Discount <?php echo !empty($booking->CouponCode) ? '(' . htmlspecialchars($booking->CouponCode, ENT_QUOTES, 'UTF-8') . ')' : ''; ?></span><span>&minus; <?php echo tms_currency($discount); ?></span></div>
          <?php endif; ?>
          <div class="cost-row total"><span>Total Paid</span><span><?php echo tms_currency($total); ?></span></div>
        </div>
      </div>
      <div class="footer-note">
        Booked on <?php echo htmlspecialchars($booking->RegDate, ENT_QUOTES, 'UTF-8'); ?>.
        Please carry a valid photo ID matching the passenger name above. For cancellations, refunds, or changes,
        visit <strong>My Bookings</strong> or contact TMS support at +880 1759-443004.
      </div>
      <?php if (!empty($booking->VerificationCode)): ?>
      <div class="boarding-stub">
        <div class="stub-perf" aria-hidden="true"></div>
        <div class="stub-qr">
          <div id="tmsTicketQr" class="qr-box" data-qr-value="TMS-BK<?php echo (int)$booking->BookingId; ?>-<?php echo htmlspecialchars($booking->VerificationCode, ENT_QUOTES, 'UTF-8'); ?>"></div>
          <noscript><div class="qr-noscript">Enable JavaScript to display the scannable QR code.</div></noscript>
        </div>
        <div class="stub-info">
          <strong style="color:#1e293b;">Verification code: <?php echo htmlspecialchars($booking->VerificationCode, ENT_QUOTES,'UTF-8'); ?></strong><br>
          Scan this code or show it at check-in to confirm this booking is genuine. This QR is generated on your device — no booking data is sent to any third-party QR service.
        </div>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<script src="js/qrcode.js"></script>
<script>
(function () {
  var box = document.getElementById('tmsTicketQr');
  if (!box || typeof qrcode === 'undefined') return;
  var value = box.getAttribute('data-qr-value') || '';
  try {
    var qr = qrcode(0, 'M'); // type 0 = auto-detect smallest size, level M error correction
    qr.addData(value);
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
    var svg = box.querySelector('svg');
    if (svg) { svg.style.width = '96px'; svg.style.height = '96px'; svg.style.display = 'block'; }
  } catch (e) {
    box.innerHTML = '<div class="qr-noscript">Could not render QR code.</div>';
  }
})();
</script>
</body>
</html>

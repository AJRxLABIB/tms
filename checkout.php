<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
tms_require_user();

$email = $_SESSION['login'];
$error = '';

$q = $dbh->prepare(
    "SELECT c.id, c.PackageId, c.FromDate, c.ToDate, c.Comment, c.NoOfPersons, c.DynamicPrice,
            p.PackageName, p.PackageLocation, p.PackagePrice, p.PackageImage
     FROM tblcart c JOIN tbltourpackages p ON p.PackageId = c.PackageId
     WHERE c.UserEmail=:e ORDER BY c.id DESC"
);
$q->execute([':e' => $email]);
$items = $q->fetchAll(PDO::FETCH_OBJ);

$total = 0;
$hasInvalidPrice = false;
foreach ($items as $it) {
    // PackagePrice is nullable in the schema. Without this check, an item
    // with no price set would silently contribute ৳0 to both the total
    // shown here and the TotalCost actually saved to the booking — the
    // same class of bug fixed in package-details.php and the API's
    // api_book, just reached via the cart instead.
    if ($it->PackagePrice === null || (float)$it->PackagePrice <= 0) {
        $hasInvalidPrice = true;
    }
    // Use the price locked in at add-to-cart time (DynamicPrice) so what
    // was shown in the cart is exactly what gets charged here — falls back
    // to a fresh calc only for cart rows added before this column existed.
    $it->EffectivePrice = ($it->DynamicPrice !== null)
        ? (float)$it->DynamicPrice
        : (float)tms_dynamic_price($dbh, (int)$it->PackageId, (float)$it->PackagePrice, $it->FromDate)['final'];
    $total += $it->EffectivePrice * max(1, (int)$it->NoOfPersons);
}

if (empty($items)) {
    header('Location: cart.php');
    exit;
}

if (isset($_POST['confirm_checkout'])) {
    tms_csrf_validate();
    $mobile = tms_clean($_POST['mobile'] ?? '');

    if ($hasInvalidPrice) {
        $error = "One or more items in your cart don't have a price set yet, so checkout can't proceed. Please remove them from your cart or contact support.";
    } else {
    // CONCURRENCY FIX (Rule 9 -- prevent double booking): everything from the
    // capacity check through the booking inserts now runs inside one real
    // database transaction, and the capacity check takes a row lock
    // (SELECT ... FOR UPDATE) on every package involved via
    // tms_remaining_seats_for_update(). Before this, two customers checking
    // out the last seat on the same package at the same moment could both
    // read "1 seat left" and both succeed -- a classic check-then-act race.
    // Now the second transaction blocks on the lock until the first commits
    // (or rolls back), then re-checks capacity against the first one's
    // now-committed booking, so overselling a capacity-tracked package is no
    // longer possible. Packages that don't track capacity (SeatsAvailable
    // NULL, tms_remaining_seats*() returns null) are completely unaffected,
    // exactly as before.
    $requestedPerPackage = [];
    foreach ($items as $it) {
        $requestedPerPackage[$it->PackageId] = ($requestedPerPackage[$it->PackageId] ?? 0) + max(1, (int)$it->NoOfPersons);
    }

    $dbh->beginTransaction();
    $capacityError = '';
    try {
        // Lock package rows in a fixed order (ascending PackageId) to avoid a
        // deadlock if two checkouts touch the same two packages in different
        // orders.
        $lockOrderIds = $requestedPerPackage;
        ksort($lockOrderIds);
        foreach ($lockOrderIds as $pkgIdCheck => $requested) {
            $available = tms_remaining_seats_for_update($dbh, (int)$pkgIdCheck);
            if ($available !== null && $requested > $available) {
                $pkgNameQ = $dbh->prepare("SELECT PackageName FROM tbltourpackages WHERE PackageId=:p");
                $pkgNameQ->execute([':p' => $pkgIdCheck]);
                $pkgNameForError = $pkgNameQ->fetchColumn() ?: 'One of your packages';
                $capacityError .= "\"{$pkgNameForError}\" only has {$available} seat" . ($available===1?'':'s') . " left, but you're requesting {$requested}. ";
            }
        }
    } catch (Exception $e) {
        $dbh->rollBack();
        error_log('[TMS Checkout] Capacity lock check failed: ' . $e->getMessage());
        $error = "Something went wrong checking availability. Please try again.";
        $capacityError = null; // signal: already handled, skip the block below
    }

    if ($capacityError === null) {
        // already set $error above and rolled back -- fall through to render
    } elseif ($capacityError !== '') {
        $dbh->rollBack();
        $error = trim($capacityError) . " Please adjust the traveler count in your cart.";
    } else {
    $createdIds = [];
    try {
    foreach ($items as $it) {
        // Previously this dropped NoOfPersons and TotalCost entirely — every
        // cart checkout became a 1-traveler, NULL-cost booking regardless of
        // what was actually in the cart, even though the cart itself tracked
        // persons correctly (see $total calc above) and downstream features
        // (e-ticket, expense tracker, loyalty spend totals, AI trip copilot)
        // all read TotalCost assuming it was populated.
        $itemPersons = max(1, (int)$it->NoOfPersons);
        $itemTotal   = (float)$it->EffectivePrice * $itemPersons;
        $verificationCode = strtoupper(bin2hex(random_bytes(5)));

        $ins = $dbh->prepare(
            "INSERT INTO tblbooking(PackageId,UserEmail,FromDate,ToDate,Comment,status,NoOfPersons,MobileNumber,TotalCost,VerificationCode,BookingDate)
             VALUES(:pid,:email,:from,:to,:comment,0,:persons,:mobile,:total,:vcode,:bdate)"
        );
        $ins->execute([
            ':pid' => $it->PackageId, ':email' => $email,
            ':from' => $it->FromDate, ':to' => $it->ToDate, ':comment' => $it->Comment,
            ':persons' => $itemPersons, ':mobile' => $mobile ?: null,
            ':total' => $itemTotal, ':vcode' => $verificationCode,
            // BUG FIX (customer-facing audit): BookingDate was never set by
            // any of the 3 places a booking gets created, even though the
            // seasonal "Live Crowd Insights" prediction, the AI Trip
            // Copilot's "upcoming trip" detection, the "Early Planner"
            // achievement, travel-memory.php, and expense-tracker.php all
            // read it expecting the trip date. It was always NULL, so all
            // five of those silently never worked as designed. Set to the
            // trip's start date, matching what every consumer of this column
            // actually expects (RegDate already covers "when the booking
            // record was created").
            ':bdate' => $it->FromDate,
        ]);
        $newId = $dbh->lastInsertId();
        if ($newId) {
            $createdIds[] = $newId;
            $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,100,100)
                ON DUPLICATE KEY UPDATE Points=Points+100, TotalEarned=TotalEarned+100")->execute([':e'=>$email]);
            try {
                $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,100,:d)")
                    ->execute([':e'=>$email, ':d'=>'Booking #BK'.$newId.' confirmed']);
            } catch (Exception $e) {
                error_log('[TMS Loyalty] Log insert failed (booking still succeeded): ' . $e->getMessage());
            }
        }
    }

    // Clear the cart now that everything has become real bookings
    $dbh->prepare("DELETE FROM tblcart WHERE UserEmail=:e")->execute([':e' => $email]);

    // Everything above (capacity lock, booking inserts, loyalty credit, cart
    // clear) commits together now, or none of it does.
    $dbh->commit();

    $_SESSION['checkout_success'] = count($createdIds);
    // LIVE FEATURE (payment architecture, Rule 10/11): previously this went
    // straight to tour-history.php and the booking just sat at
    // status=0/PaymentStatus=unpaid until an admin manually confirmed it --
    // there was no payment step in the app at all. Now the customer is sent
    // to the new payment.php first, which lets them pay immediately via the
    // sandbox provider (auto-confirming the booking) or skip it and leave
    // the booking exactly as Pending/unpaid as before -- nothing about the
    // old manual-admin-confirms-it path was removed.
    if (!empty($createdIds)) {
        $_SESSION['pending_payment_booking_ids'] = $createdIds;
        header('Location: payment.php');
        exit;
    }
    header('Location: tour-history.php');
    exit;
    } catch (Exception $e) {
        $dbh->rollBack();
        error_log('[TMS Checkout] Booking transaction failed, rolled back: ' . $e->getMessage());
        $error = "Something went wrong while confirming your booking. Nothing was charged or reserved -- please try again.";
    }
    }
    }
}
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | Checkout</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.checkout-item{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #eef1f6;font-size:14px;}
.checkout-item:last-child{border-bottom:none;}
.summary-box{background:var(--white);border-radius:12px;padding:26px;box-shadow:0 3px 15px rgba(0,0,0,0.08);}
.summary-row.total{display:flex;justify-content:space-between;font-size:19px;font-weight:800;color:#1e293b;border-top:2px solid #eee;margin-top:14px;padding-top:16px;}
html[data-theme="dark"] .summary-row.total { color: var(--text) !important; }
.btn-confirm{background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:14px;font-weight:700;font-size:15px;width:100%;cursor:pointer;margin-top:18px;}
.btn-confirm:hover{background:#2d6a97;}
</style>
</head><body>
<?php include('includes/header.php'); ?>
<div class="page-title">
  <div class="container">
    <h2 style="margin:0 0 20px;"><i class="fa fa-lock"></i> Checkout</h2>
    <div class="tms-steps">
      <div class="tms-step done"><div class="tms-step-circle"><i class="fa fa-check"></i></div><div class="tms-step-label">Cart</div></div>
      <div class="tms-step active"><div class="tms-step-circle">2</div><div class="tms-step-label">Checkout</div></div>
      <div class="tms-step"><div class="tms-step-circle">3</div><div class="tms-step-label">Confirmed</div></div>
    </div>
  </div>
</div>
<div class="container" style="padding-bottom:50px;max-width:700px;">
  <div class="summary-box">
    <h5 style="font-weight:700;margin-bottom:10px;">Review Your <?php echo count($items); ?> Booking(s)</h5>
    <?php if ($error): ?>
    <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13.5px;">
      <i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>
    <?php foreach ($items as $it): ?>
    <div class="checkout-item">
      <div>
        <strong><?php echo htmlspecialchars($it->PackageName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <small style="color:#888;"><?php echo date('d M Y', strtotime($it->FromDate)); ?> &rarr; <?php echo date('d M Y', strtotime($it->ToDate)); ?></small>
      </div>
      <?php $pax = max(1, (int)$it->NoOfPersons); ?>
      <div style="text-align:right;">
        <?php if ($it->PackagePrice === null || (float)$it->PackagePrice <= 0): ?>
          <span style="color:#e11d48;">Price not set</span>
        <?php else: ?>
          <?php echo tms_currency((float)$it->EffectivePrice); ?>
          <div style="font-size:12px;color:#888;">
            × <?php echo $pax; ?> traveller<?php echo $pax > 1 ? 's' : ''; ?>
            = <strong><?php echo tms_currency((float)$it->EffectivePrice * $pax); ?></strong>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="summary-row total"><span>Total</span><span><?php echo tms_currency($total); ?></span></div>

    <form method="post">
      <?php tms_csrf_field(); ?>
      <div class="form-group" style="margin-top:16px;">
        <label style="font-weight:600;font-size:13px;">Mobile Number <small style="color:#94a3b8;">(optional, for booking contact)</small></label>
        <input type="tel" name="mobile" class="form-control" placeholder="e.g. 017XXXXXXXX" maxlength="20">
      </div>
      <?php if ($hasInvalidPrice): ?>
      <button type="button" class="btn-confirm" disabled style="opacity:.5;cursor:not-allowed;" title="Remove the unpriced item(s) from your cart first">
        <i class="fa fa-check-circle"></i> Confirm &amp; Book All
      </button>
      <a href="cart.php" style="display:block;text-align:center;margin-top:10px;font-size:13px;color:var(--secondary);">&larr; Back to cart</a>
      <?php else: ?>
      <button type="submit" name="confirm_checkout" class="btn-confirm">
        <i class="fa fa-check-circle"></i> Confirm &amp; Book All
      </button>
      <?php endif; ?>
    </form>
    <p style="text-align:center;margin-top:12px;"><a href="cart.php" style="font-size:13px;color:#94a3b8;">← Back to Cart</a></p>
    <p style="text-align:center;font-size:11.5px;color:#cbd5e1;margin-top:8px;">
      Each booking starts as <strong>pending</strong> and will be confirmed by our team shortly — just like booking one package at a time.
    </p>
  </div>
</div>
<?php include('includes/footer.php'); ?>
</body></html>

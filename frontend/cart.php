<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];

if (isset($_GET['remove'])) {
    $removeId = tms_int($_GET['remove'], 1);
    // Round 91: no FK CASCADE in this project's convention (see config.php's
    // tblcartaddons comment), so a removed cart item's add-on selections
    // must be cleared explicitly here or they'd silently orphan.
    $dbh->prepare("DELETE FROM tblcartaddons WHERE CartId=:id")->execute([':id' => $removeId]);
    $dbh->prepare("DELETE FROM tblcart WHERE id=:id AND UserEmail=:e")
        ->execute([':id' => $removeId, ':e' => $email]);
    header('Location: cart.php');
    exit;
}

$q = $dbh->prepare(
    "SELECT c.id, c.PackageId, c.FromDate, c.ToDate, c.Comment, c.NoOfPersons, c.DynamicPrice,
            p.PackageName, p.PackageLocation, p.PackagePrice, p.PackageImage
     FROM tblcart c JOIN tbltourpackages p ON p.PackageId = c.PackageId
     WHERE c.UserEmail=:e ORDER BY c.id DESC"
);
$q->execute([':e' => $email]);
$items = $q->fetchAll(PDO::FETCH_OBJ);

$total = 0;
foreach ($items as $it) {
    // DynamicPrice is the price locked in when this item was added to the
    // cart (see package-details.php). Older cart rows added before this
    // column existed fall back to a fresh live calculation instead of the
    // flat base price, so dynamic pricing stays consistent everywhere.
    // Round 84 (master prompt items 8/37 -- "never use floating-point
    // arithmetic for money"): PackagePrice/DynamicPrice/TotalCost are all
    // whole-currency-unit INT columns (no cents/paisa subunit in this
    // schema) -- (int) is exact for the same real values (float) was
    // carrying, without the theoretical risk the spec warns about.
    $it->EffectivePrice = ($it->DynamicPrice !== null)
        ? (int)$it->DynamicPrice
        : (int)tms_dynamic_price($dbh, (int)$it->PackageId, (float)$it->PackagePrice, $it->FromDate)['final'];

    // Round 91 (items 10/81): add-ons selected via package-details.php's
    // "Add to Cart" button. Read live from tblpackageaddons (not
    // snapshotted -- see config.php's tblcartaddons comment for why), and
    // silently skip any that were deactivated/deleted since being added --
    // the same "don't crash, just don't charge for it" approach checkout.php
    // already uses for an unpriced package.
    $aq = $dbh->prepare(
        "SELECT ca.Quantity, pa.Name, pa.Price
         FROM tblcartaddons ca JOIN tblpackageaddons pa ON pa.AddOnId = ca.AddOnId
         WHERE ca.CartId = :cid AND pa.IsActive = 1"
    );
    $aq->execute([':cid' => $it->id]);
    $it->Addons = $aq->fetchAll(PDO::FETCH_OBJ);
    $it->AddonTotal = 0;
    foreach ($it->Addons as $ao) {
        $it->AddonTotal += (int)$ao->Price * (int)$ao->Quantity;
    }

    $total += ($it->EffectivePrice * max(1, (int)$it->NoOfPersons)) + $it->AddonTotal;
}
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | My Cart</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.cart-item{display:flex;gap:16px;background:var(--white);border-radius:12px;padding:16px;box-shadow:0 3px 15px rgba(0,0,0,0.06);margin-bottom:14px;align-items:center;}
.cart-item img{width:100px;height:80px;object-fit:cover;border-radius:8px;flex-shrink:0;}
.cart-item .info{flex:1;}
.cart-item .info h5{margin:0 0 4px;font-weight:700;}
.cart-item .info .meta{font-size:12.5px;color:#888;}
.cart-item .price{font-weight:800;color:var(--secondary);font-size:16px;white-space:nowrap;}
.cart-remove{color:#e74c3c;background:none;border:none;font-size:16px;cursor:pointer;padding:6px;}
.summary-box{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);position:sticky;top:20px;}
.summary-row{display:flex;justify-content:space-between;font-size:14px;padding:6px 0;color:#555;}
.summary-row.total{font-size:18px;font-weight:800;color:#1e293b;border-top:1px solid #eee;margin-top:8px;padding-top:12px;}
html[data-theme="dark"] .cart-item .info .meta,
html[data-theme="dark"] .summary-row { color: var(--text-muted) !important; }
html[data-theme="dark"] .summary-row.total { color: var(--text) !important; }
.btn-checkout{background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:13px;font-weight:700;font-size:15px;width:100%;cursor:pointer;margin-top:14px;}
.btn-checkout:hover{background:#2d6a97;}
.empty-cart{text-align:center;padding:60px 20px;background:var(--white);border-radius:12px;box-shadow:0 3px 15px rgba(0,0,0,0.06);}
.empty-cart i{font-size:56px;color:#e2e8f0;display:block;margin-bottom:16px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container">
    <h2 style="margin:0 0 20px;"><i class="fa fa-shopping-cart"></i> <?php echo tms_t('heading_my_cart','My Cart'); ?></h2>
    <div class="tms-steps">
      <div class="tms-step active"><div class="tms-step-circle">1</div><div class="tms-step-label"><?php echo tms_t('step_cart','Cart'); ?></div></div>
      <div class="tms-step"><div class="tms-step-circle">2</div><div class="tms-step-label"><?php echo tms_t('step_checkout','Checkout'); ?></div></div>
      <div class="tms-step"><div class="tms-step-circle">3</div><div class="tms-step-label"><?php echo tms_t('step_confirmed','Confirmed'); ?></div></div>
    </div>
  </div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php if (empty($items)): ?>
  <div class="empty-cart">
    <i class="fa fa-shopping-cart"></i>
    <h4><?php echo tms_t('msg_cart_empty','Your cart is empty'); ?></h4>
    <p style="color:#94a3b8;margin-bottom:18px;"><?php echo tms_t('dest_cart_empty_subtitle','Browse packages and add a few to compare before booking all at once.'); ?></p>
    <a href="package-list.php" class="btn-checkout" style="display:inline-block;width:auto;padding:12px 30px;text-decoration:none;"><?php echo tms_t('btn_browse_packages','Browse Packages'); ?></a>
  </div>
<?php else: ?>
  <div class="row">
    <div class="col-md-8">
      <?php foreach ($items as $it): $itSeatsLeft = tms_remaining_seats($dbh, (int)$it->PackageId); ?>
      <div class="cart-item">
        <img loading="lazy" src="<?php echo htmlspecialchars(tms_package_image_src($it->PackageImage), ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='images/no-image-placeholder.jpg'" alt="">
        <div class="info">
          <h5><?php echo htmlspecialchars($it->PackageName, ENT_QUOTES, 'UTF-8'); ?></h5>
          <div class="meta"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($it->PackageLocation, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="meta"><i class="fa fa-calendar"></i> <?php echo date('d M Y', strtotime($it->FromDate)); ?> &rarr; <?php echo date('d M Y', strtotime($it->ToDate)); ?></div>
          <?php if ($itSeatsLeft !== null && (int)$it->NoOfPersons > $itSeatsLeft): ?>
          <div class="meta" style="color:#dc2626;font-weight:600;"><i class="fa fa-exclamation-triangle"></i> Only <?php echo $itSeatsLeft; ?> seat<?php echo $itSeatsLeft===1?'':'s'; ?> left — you're requesting <?php echo (int)$it->NoOfPersons; ?>. Reduce travelers or this item will block checkout.</div>
          <?php elseif ($itSeatsLeft !== null && $itSeatsLeft <= 5): ?>
          <div class="meta" style="color:#b45309;font-weight:600;"><i class="fa fa-users"></i> <?php echo tms_t('msg_only_prefix','Only'); ?> <?php echo $itSeatsLeft; ?> <?php echo tms_t('label_seats_left_for_package','seats left for this package'); ?></div>
          <?php endif; ?>
          <?php if (!empty($it->Addons)): ?>
          <div class="meta" style="margin-top:4px;">
            <i class="fa fa-plus-circle"></i> <?php echo tms_t('label_addons_colon','Add-ons:'); ?>
            <?php foreach ($it->Addons as $ao): ?>
              <?php echo htmlspecialchars($ao->Name, ENT_QUOTES, 'UTF-8'); ?><?php echo $ao->Quantity > 1 ? ' ×' . (int)$ao->Quantity : ''; ?><?php echo $ao !== end($it->Addons) ? ',' : ''; ?>
            <?php endforeach; ?>
            (<?php echo tms_currency($it->AddonTotal); ?>)
          </div>
          <?php endif; ?>
        </div>
        <?php /* FIX (Round 14): this showed the PER-PERSON price while the Total
                 multiplies by NoOfPersons — and the traveller count appeared nowhere
                 on the page. One item for 3 people read "৳12,000" beside a total of
                 "৳36,000" with nothing explaining the difference, which reads as an
                 overcharge. ticket.php already did this correctly. */
           $pax = max(1, (int)$it->NoOfPersons); ?>
        <div class="price">
          <?php echo tms_currency((int)$it->EffectivePrice); ?>
          <div style="font-weight:400;font-size:12px;opacity:.75;">
            × <?php echo $pax; ?> <?php echo tms_t('label_traveller_word','traveller(s)'); ?>
            <?php if ($pax > 1): ?>
              = <strong><?php echo tms_currency((int)$it->EffectivePrice * $pax); ?></strong>
            <?php endif; ?>
          </div>
          <?php if ($it->AddonTotal > 0): ?>
          <div style="font-weight:400;font-size:12px;opacity:.75;">+ add-ons: <?php echo tms_currency($it->AddonTotal); ?></div>
          <div style="font-weight:700;font-size:13px;border-top:1px solid #eee;margin-top:4px;padding-top:4px;">
            <?php echo tms_currency(((int)$it->EffectivePrice * $pax) + $it->AddonTotal); ?>
          </div>
          <?php endif; ?>
        </div>
        <button class="cart-remove" onclick="if(confirm('<?php echo addslashes(tms_t('msg_confirm_remove_cart_item','Remove this item from your cart?')); ?>')) window.location='cart.php?remove=<?php echo (int)$it->id; ?>'"><i class="fa fa-trash"></i></button>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="col-md-4">
      <div class="summary-box">
        <h5 style="font-weight:700;margin-bottom:14px;"><?php echo tms_t('heading_order_summary','Order Summary'); ?></h5>
        <div class="summary-row"><span><?php echo count($items); ?> <?php echo tms_t('label_packages_count_suffix','package(s)'); ?></span><span><?php echo tms_currency($total); ?></span></div>
        <div class="summary-row total"><span><?php echo tms_t('label_total','Total'); ?></span><span><?php echo tms_currency($total); ?></span></div>
        <a href="checkout.php" style="text-decoration:none;"><button class="btn-checkout"><i class="fa fa-lock"></i> <?php echo tms_t('btn_proceed_checkout','Proceed to Checkout'); ?></button></a>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

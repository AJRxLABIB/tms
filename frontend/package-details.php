<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once 'backend/services/SupplierService.php';
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see backend/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.

$error = '';
$msg   = '';

if (isset($_POST['submit2']) || isset($_POST['addcart'])) {
    tms_csrf_validate();

    if (empty($_SESSION['login'])) {
        $error = 'You must be signed in to book a package.';
    } else {
        $pid       = tms_int($_GET['pkgid'] ?? 0, 1);
        $email     = $_SESSION['login'];
        $fromdate  = tms_clean($_POST['fromdate'] ?? '');
        $todate    = tms_clean($_POST['todate']   ?? '');
        $comment   = tms_clean($_POST['comment']  ?? '');
        // Previously missing entirely: every booking silently became 1
        // traveler with no contact number and no stored price, regardless
        // of what the visitor actually needed. The "Apply" coupon button
        // also only ever validated-and-displayed — apply-coupon.php, which
        // was meant to persist it, has no caller anywhere in the codebase,
        // so DiscountApplied stayed 0 and coupons were never marked used.
        $persons   = tms_int($_POST['persons'] ?? 1, 1, 20);
        $mobile    = tms_clean($_POST['mobile'] ?? '');
        $couponRaw = tms_clean($_POST['couponCode'] ?? '');

        // Date validation
        $from = DateTime::createFromFormat('d-m-Y', $fromdate) ?: DateTime::createFromFormat('Y-m-d', $fromdate);
        $to   = DateTime::createFromFormat('d-m-Y', $todate)   ?: DateTime::createFromFormat('Y-m-d', $todate);
        $now  = new DateTime();

        if (!$from || !$to) {
            $error = 'Please enter valid dates in dd-mm-yyyy format.';
        } elseif ($from <= $now) {
            $error = 'Departure date must be in the future.';
        } elseif ($to <= $from) {
            $error = 'Return date must be after the departure date.';
        } elseif ($pid === 0) {
            $error = 'Invalid package.';
        } elseif (!tms_package_bookable($dbh, $pid)) {
            $error = 'This package is not available for booking.'; // unpublished / pending review / removed
        } elseif (($blackoutMsg = SupplierService::blackoutConflict($dbh, $pid, $from->format('Y-m-d'), $to->format('Y-m-d'))) !== null) {
            $error = $blackoutMsg; // supplier blackout dates are enforced server-side
        } elseif (isset($_POST['addcart'])) {
            $cartPriceQ = $dbh->prepare("SELECT PackagePrice FROM tbltourpackages WHERE PackageId=:pid LIMIT 1");
            $cartPriceQ->execute([':pid' => $pid]);
            $cartRawPrice = $cartPriceQ->fetchColumn();
            $cartDynamic  = ($cartRawPrice !== false && $cartRawPrice !== null && (float)$cartRawPrice > 0)
                ? tms_dynamic_price($dbh, $pid, (float)$cartRawPrice, $from->format('Y-m-d'))['final']
                : null;

            $cartIns = $dbh->prepare("INSERT INTO tblcart(UserEmail,PackageId,FromDate,ToDate,Comment,NoOfPersons,DynamicPrice) VALUES(:e,:pid,:from,:to,:comment,:persons,:dprice)");
            $cartIns->execute([
                ':e' => $email, ':pid' => $pid,
                ':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d'), ':comment' => $comment,
                ':persons' => $persons, ':dprice' => $cartDynamic,
            ]);
            // Round 91 (items 10/81): add-ons selected on this form now carry
            // through to the cart too, closing the scope gap Round 87
            // deliberately left open ("Book Now" only). Re-validated against
            // the DB exactly like the Book Now path below -- never trust a
            // posted price -- and stored unsnapshotted in tblcartaddons since
            // a cart is transient; the real snapshot happens at checkout.
            $newCartId = $dbh->lastInsertId();
            if ($newCartId) {
                $cartAddonIds = array_map('intval', (array)($_POST['addons'] ?? []));
                if (!empty($cartAddonIds)) {
                    $placeholders = implode(',', array_fill(0, count($cartAddonIds), '?'));
                    $caq = $dbh->prepare("SELECT AddOnId FROM tblpackageaddons WHERE PackageId = ? AND IsActive = 1 AND AddOnId IN ($placeholders)");
                    $caq->execute(array_merge([$pid], $cartAddonIds));
                    $caIns = $dbh->prepare("INSERT INTO tblcartaddons (CartId, AddOnId, Quantity) VALUES (:cid, :aid, :q)");
                    foreach ($caq->fetchAll(PDO::FETCH_COLUMN) as $validAddOnId) {
                        $qty = tms_int($_POST['addon_qty'][$validAddOnId] ?? 1, 1, 20);
                        $caIns->execute([':cid' => $newCartId, ':aid' => (int)$validAddOnId, ':q' => $qty]);
                    }
                }
            }
            $msg = 'Added to your cart! <a href="cart.php">View Cart →</a>';
        } else {
            // Price + coupon are resolved here, at creation time, in the same
            // request — not via a separate AJAX call to a booking that
            // doesn't exist yet (the old, unreachable design).
            $priceQ = $dbh->prepare("SELECT PackagePrice FROM tbltourpackages WHERE PackageId=:pid LIMIT 1");
            $priceQ->execute([':pid' => $pid]);
            $rawPrice = $priceQ->fetchColumn();

            // PackagePrice is nullable in the schema (a package can exist with
            // no price set yet). Without this check, (float)null = 0.0 would
            // silently create a free booking instead of surfacing the real
            // problem — a package that isn't actually ready to sell.
            if ($rawPrice === false || $rawPrice === null || (float)$rawPrice <= 0) {
                $error = "This package doesn't have a price set yet — please contact support instead of booking it directly.";
            } elseif (($seatsAvail = tms_remaining_seats($dbh, $pid)) !== null && $persons > $seatsAvail) {
                $error = "Sorry, only {$seatsAvail} seat" . ($seatsAvail===1?'':'s') . " left for this package, but you requested {$persons}. Please reduce the number of travelers.";
            } else {
                $pricing   = tms_dynamic_price($dbh, $pid, (float)$rawPrice, $from->format('Y-m-d'));
                $unitPrice = (float)$pricing['final'];
                $subtotal  = (int)round($unitPrice) * $persons; // whole units, exact

                $discountAmt = 0;
                $couponCode  = null;
                if ($couponRaw !== '') {
                    $cq = $dbh->prepare("SELECT * FROM tblcoupons WHERE Code=:c AND (UserEmail=:e OR UserEmail IS NULL) AND IsUsed=0 AND (ExpiryDate IS NULL OR ExpiryDate >= CURDATE()) LIMIT 1");
                    $cq->execute([':c' => $couponRaw, ':e' => $email]);
                    $coupon = $cq->fetch(PDO::FETCH_OBJ);
                    if ($coupon) {
                        // FIX (Round 14): a coupon with UserEmail IS NULL is a
                        // SITE-WIDE promo — admin/manage-coupons.php broadcasts
                        // those to every registered user with tms_notify_all().
                        // But this claimed them with the same single IsUsed flag
                        // as a personal coupon, so the FIRST customer to book
                        // consumed the code for everybody and everyone else saw
                        // "Invalid or expired coupon code". The seeded WELCOME10 /
                        // SUMMER20 / TMS2026 codes had this out of the box.
                        // Personal coupons still burn on use; global ones don't.
                        $isGlobal = ($coupon->UserEmail === null || $coupon->UserEmail === '');
                        $claimed  = true;
                        if (!$isGlobal) {
                            // Atomic claim: the UPDATE re-checks IsUsed=0, so two
                            // simultaneous requests can't both succeed.
                            $claim = $dbh->prepare("UPDATE tblcoupons SET IsUsed=1 WHERE id=:id AND IsUsed=0");
                            $claim->execute([':id' => $coupon->id]);
                            $claimed = ($claim->rowCount() === 1);
                        }
                        if ($claimed) {
                            // Clamp: never trust a stored percentage. A legacy row
                            // written before the cap existed could be 100 or 120.
                            $discountAmt = tms_coupon_discount((int)$subtotal, (float)$coupon->DiscountPercent); // clamped, integer-exact
                            $couponCode  = $couponRaw;
                        } else {
                            $error = 'Note: coupon code was just used by another request, so it was not applied.';
                        }
                    } else {
                        // Invalid/expired/already-used — booking still proceeds, just without the discount.
                        $error = 'Note: coupon code was not valid or already used, so it was not applied.';
                    }
                }
                // Round 87 (item 7): optional add-ons. Originally scoped to
                // this direct "Book Now" path only; Round 91 (items 10/81)
                // extended the same selection through Add to Cart / cart.php /
                // checkout.php too (addcart's own handler above does its own
                // equivalent re-validation and insert into tblcartaddons).
                // Selected add-ons are re-validated against the DB here (never
                // trust posted price/name), and never discounted by a coupon
                // — that's still true, coupons remain Book-Now-only.
                $selectedAddons = [];
                $addonTotal = 0;
                $postedAddonIds = array_map('intval', (array)($_POST['addons'] ?? []));
                if (!empty($postedAddonIds)) {
                    $placeholders = implode(',', array_fill(0, count($postedAddonIds), '?'));
                    $aq = $dbh->prepare("SELECT * FROM tblpackageaddons WHERE PackageId = ? AND IsActive = 1 AND AddOnId IN ($placeholders)");
                    $aq->execute(array_merge([$pid], $postedAddonIds));
                    foreach ($aq->fetchAll(PDO::FETCH_OBJ) as $a) {
                        $qty = tms_int($_POST['addon_qty'][$a->AddOnId] ?? 1, 1, 20);
                        $selectedAddons[] = ['AddOnId' => (int)$a->AddOnId, 'Name' => $a->Name, 'Price' => (int)$a->Price, 'Quantity' => $qty];
                        $addonTotal += (int)$a->Price * $qty;
                    }
                }

                $total = max(0, $subtotal - $discountAmt) + $addonTotal;
                $verificationCode = strtoupper(bin2hex(random_bytes(5)));
                // FEATURE (Round 115 continuation, master prompt item 37 --
                // Currency): records what the customer was actually shown
                // (source/rate/timestamp) if they had a non-BDT display
                // currency selected -- see tms_capture_booking_fx()'s own
                // docblock. TotalCost itself is untouched, still BDT.
                $fx = tms_capture_booking_fx((float)$total);
                // Defensive (Rule 8): migration 013 may not have run yet on
                // this deployment -- never let a pending migration break
                // this booking path.
                $hasBookingFx = tms_booking_fx_columns_exist($dbh);

                $sql = "INSERT INTO tblbooking(PackageId,UserEmail,FromDate,ToDate,Comment,status,NoOfPersons,MobileNumber,TotalCost,CouponCode,DiscountApplied,VerificationCode,BookingDate" . ($hasBookingFx ? ",DisplayCurrency,DisplayAmount,FxRate,FxRateSource,FxRateTimestamp" : "") . ")
                        VALUES(:pid,:email,:from,:to,:comment,0,:persons,:mobile,:total,:coupon,:discount,:vcode,:bdate" . ($hasBookingFx ? ",:dcur,:damt,:frate,:fsrc,:fts" : "") . ")";
                $q = $dbh->prepare($sql);
                $q->execute(array_merge([
                    ':pid'     => $pid,
                    ':email'   => $email,
                    ':from'    => $from->format('Y-m-d'),
                    ':to'      => $to->format('Y-m-d'),
                    ':comment' => $comment,
                    ':persons' => $persons,
                    ':mobile'  => $mobile ?: null,
                    ':total'   => $total,
                    ':coupon'  => $couponCode,
                    ':discount'=> $discountAmt,
                    ':vcode'   => $verificationCode,
                    // BUG FIX: see checkout.php's identical comment —
                    // BookingDate was never populated by any booking path.
                    ':bdate'   => $from->format('Y-m-d'),
                ], $hasBookingFx ? [
                    ':dcur'    => $fx['currency'],
                    ':damt'    => $fx['converted_amount'],
                    ':frate'   => $fx['rate'],
                    ':fsrc'    => $fx['source'],
                    ':fts'     => $fx['rate_timestamp'],
                ] : []));
                $newBkId = $dbh->lastInsertId();
                if ($newBkId) {
                    // Snapshot each selected add-on's Name/Price at booking time
                    // (Round 87): a later catalog edit or delete in
                    // admin/manage-package-addons.php must never rewrite this
                    // booking's history, so AddOnId is kept only as an optional
                    // back-reference, not the source of truth for the receipt.
                    if (!empty($selectedAddons)) {
                        $baIns = $dbh->prepare("INSERT INTO tblbookingaddons (BookingId, AddOnId, Name, Price, Quantity) VALUES (:bid,:aid,:n,:p,:q)");
                        foreach ($selectedAddons as $sa) {
                            $baIns->execute([
                                ':bid' => $newBkId, ':aid' => $sa['AddOnId'],
                                ':n' => $sa['Name'], ':p' => $sa['Price'], ':q' => $sa['Quantity'],
                            ]);
                        }
                    }
                    // Award 100 loyalty points
                    $email2 = $_SESSION['login'];
                    $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,100,100)
                        ON DUPLICATE KEY UPDATE Points=Points+100, TotalEarned=TotalEarned+100")->execute([':e'=>$email2]);
                    try {
                        $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,100,:d)")
                            ->execute([':e'=>$email2,':d'=>'Booking #BK'.$newBkId.' confirmed']);
                    } catch (Exception $e) {
                        error_log('[TMS Loyalty] Log insert failed (booking still succeeded): ' . $e->getMessage());
                    }
                    $msg = ($error ?: '') . ' Package booked successfully! Check your booking in <a href="tour-history.php">My Tours</a>. You earned <strong>100 loyalty points!</strong>';
                    $error = '';
                } else {
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

$pid = tms_int($_GET['pkgid'] ?? 0, 1);
$q   = $dbh->prepare("SELECT * FROM tbltourpackages WHERE PackageId=:pid AND " . TMS_PKG_PUBLIC_SQL . " LIMIT 1");
$q->bindParam(':pid', $pid, PDO::PARAM_INT);
$q->execute();
$pkg = $q->fetch(PDO::FETCH_OBJ);

if ($pkg) {
    tms_track_recently_viewed($dbh, (int)$pkg->PackageId);
}

// Round 100 (items 23/31/66 -- recommendation transparency): a real,
// deterministic personalized match score for THIS package, computed only
// when the visitor actually stated preferences -- either typed straight
// onto this page's URL, or carried through automatically from an AI
// Travel Recommender "View & Book" click (see TravelRecommender.php's new
// buildMatchQueryString()). A plain, un-personalized visit to this page
// (no query params at all) shows no widget -- never a fabricated or
// generic score. Uses TravelRecommender::getMatchScore(), which replaced
// (this same round) that class's previous dead, AI-based scorer whose
// failure path fabricated a flat 75/100 -- this one only ever returns a
// number built from real platform data (price, live weather, seat
// availability, rating, geocoded distance), and honestly omits any factor
// it can't back with real data instead of guessing.
$matchScore = null;
if ($pkg && (isset($_GET['budget']) || isset($_GET['travelers']) || isset($_GET['travel_type']) || isset($_GET['start_date']) || isset($_GET['duration']))) {
    $matchPrefs = [
        'budget'      => isset($_GET['budget']) ? tms_int($_GET['budget'], 0) : 0,
        'travelers'   => isset($_GET['travelers']) ? tms_int($_GET['travelers'], 1, 50) : 1,
        'travel_type' => tms_clean($_GET['travel_type'] ?? ''),
        'start_date'  => tms_clean($_GET['start_date'] ?? ''),
        'duration'    => isset($_GET['duration']) ? tms_int($_GET['duration'], 1, 90) : 1,
    ];
    try {
        require_once dirname(__DIR__) . '/backend/ai/TravelRecommender.php';
        $recommenderForScore = new TravelRecommender($dbh);
        $matchScore = $recommenderForScore->getMatchScore((int)$pkg->PackageId, $matchPrefs, $_SESSION['login'] ?? 'guest');
    } catch (Exception $e) {
        $matchScore = null;
    }
}

// Round 87 (item 7): active add-ons available for selection below, on both
// the "Book Now" and "Add to Cart" buttons in the same form (Round 91,
// items 10/81, extended the cart/checkout path to carry these through too —
// see the addcart and submit2 handlers above).
$packageAddons = [];
if ($pkg) {
    try {
        $paq = $dbh->prepare("SELECT * FROM tblpackageaddons WHERE PackageId=:pid AND IsActive=1 ORDER BY Price ASC");
        $paq->execute([':pid' => $pkg->PackageId]);
        $packageAddons = $paq->fetchAll(PDO::FETCH_OBJ);
    } catch (Exception $e) { $packageAddons = []; }
}

// Translate this package's name/description for the active language (cached — see backend/i18n.php)
$pkgNameT = $pkgDescT = null;
if ($pkg && tms_active_language() !== 'en') {
    $t = tms_translate_batch(['name' => $pkg->PackageName, 'desc' => (string)($pkg->PackageDetails ?? '')], tms_active_language(), $dbh);
    $pkgNameT = $t['name'] ?? $pkg->PackageName;
    $pkgDescT = $t['desc'] ?? ($pkg->PackageDetails ?? '');
}

if ($pkg) {
    // Track view count + recently-viewed (used by destinations.php)
    try { $dbh->prepare("UPDATE tbltourpackages SET ViewCount = ViewCount + 1 WHERE PackageId=:pid")->execute([':pid' => $pkg->PackageId]); } catch (Exception $e) {}
    if (!isset($_SESSION['recently_viewed'])) $_SESSION['recently_viewed'] = [];
    $_SESSION['recently_viewed'] = array_values(array_diff($_SESSION['recently_viewed'], [(int)$pkg->PackageId]));
    array_unshift($_SESSION['recently_viewed'], (int)$pkg->PackageId);
    $_SESSION['recently_viewed'] = array_slice($_SESSION['recently_viewed'], 0, 8);
}

// Dynamic pricing for the initial card display: no travel date has been
// chosen yet at this point in the page, so only the demand signal applies
// here. The full demand+timing price is calculated live via price-preview.php
// once the visitor picks dates in the booking form below (see JS at the
// bottom of this file), and again server-side at actual booking time.
$pkgPricing = null;
if ($pkg && (float)$pkg->PackagePrice > 0) {
    $pkgPricing = tms_dynamic_price($dbh, (int)$pkg->PackageId, (float)$pkg->PackagePrice);
}

// Price history (Rule 26: "Track historical prices where legitimate data is
// available"). tbl_price_history is populated going forward by
// scripts/check_alerts.php's cron run (see tms_snapshot_all_package_prices())
// -- never backfilled/fabricated (Rule 3), so this is empty for every
// package until the cron has actually run at least twice. Rendered further
// down only when at least 2 real points exist (a single point isn't a
// "history"), never as a placeholder.
$priceHistory = $pkg ? tms_get_price_history($dbh, (int)$pkg->PackageId, 90) : [];

// ROOT-CAUSE FIX (Section 6 audit): this page has only ever shown a single
// hero image per package — no gallery, no slider, despite both being
// explicitly required. Builds a real multi-photo set: the package's own
// uploaded image first (if it has one that isn't the generic placeholder),
// followed by the rest of its curated destination-category photo pool
// (same photos tms_destination_image()/tms_backfill_package_images()
// already use elsewhere — not new/unvetted images).
$gallery = [];
$galleryOwnCount = 0;
if ($pkg) {
    $ownImg = tms_package_image_src($pkg->PackageImage);
    if ($ownImg && strpos($ownImg, 'no-image-placeholder') === false) {
        $gallery[] = $ownImg;
        $galleryOwnCount = 1;
    }
    foreach (tms_destination_gallery($pkg->PackageName, $pkg->PackageLocation, $pkg->PackageType) as $g) {
        if (!in_array($g, $gallery, true)) $gallery[] = $g;
    }
}
// Master prompt item 65 (photo/content provenance): everything past the
// package's own uploaded photo (if any) comes from tms_destination_gallery()'s
// curated Unsplash stock-photo pool, picked by keyword match on the
// package's name/location/type -- NOT photos of this specific property or
// tour. They were previously shown in the same slider with identical
// styling and zero indication they're generic stock, which reads as this
// project's own photography of a package it may never have actually
// photographed. This flag drives a small disclosure note rendered next to
// the gallery (see below) -- it's true whenever the gallery contains any
// photo beyond the package's own.
$galleryHasStockPhotos = count($gallery) > $galleryOwnCount;

// Reviews for this package
$revQ = $dbh->prepare("SELECT r.*, u.FullName as UserFullName
    FROM tblreviews r LEFT JOIN tblusers u ON r.UserEmail=u.EmailId
    WHERE r.PackageId=:pid AND r.Status=1 ORDER BY r.id DESC LIMIT 5");
$revQ->bindParam(':pid', $pid, PDO::PARAM_INT);
$revQ->execute();
$reviews = $revQ->fetchAll(PDO::FETCH_OBJ);

$totalReviewCount = 0;
if (!empty($pkg)) {
    $rcQ = $dbh->prepare("SELECT COUNT(*) FROM tblreviews WHERE PackageId=:pid AND Status=1");
    $rcQ->execute([':pid' => (int)$pkg->PackageId]);
    $totalReviewCount = (int)$rcQ->fetchColumn();
}

// AI Review Summary (reviewer ask #14) — only meaningful with enough
// analyzed reviews; a "summary" of 1-2 reviews isn't a summary, it's just
// restating them, so this stays hidden below that threshold rather than
// showing a misleadingly confident-looking card for a thin sample.
$aiReviewSummary = null;
if (!empty($pkg) && $totalReviewCount >= 3) {
    require_once 'backend/ai/SentimentAnalyser.php';
    $sentimentEngine = new SentimentAnalyser($dbh);
    $aiReviewSummary = $sentimentEngine->getPackageHighlights((int)$pkg->PackageId);
    if (empty($aiReviewSummary['sentiment']['total'])) $aiReviewSummary = null; // nothing analyzed yet
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
<title>TMS | <?php echo $pkg ? htmlspecialchars($pkgNameT ?? $pkg->PackageName,ENT_QUOTES,'UTF-8') : 'Package Details'; ?></title>
<?php
// NEW (Round 20, spec item 53 — SEO): only emitted when a real package was
// found. Rating is only included when real reviews exist ($totalReviewCount
// > 0) -- never fabricates a rating from nothing. Price/currency are the
// package's own real stored values, not estimates.
if ($pkg) {
    $seoDesc = trim((string)($pkgDescT ?? $pkg->PackageDetails ?? ''));
    $seoDesc = trim(preg_replace('/\s+/', ' ', strip_tags($seoDesc)));
    if (mb_strlen($seoDesc) > 200) { $seoDesc = mb_substr($seoDesc, 0, 197) . '...'; }
    $jsonld = [
        '@context'    => 'https://schema.org',
        '@type'       => 'TouristTrip',
        'name'        => $pkgNameT ?? $pkg->PackageName,
        'description' => $seoDesc,
        'offers'      => [
            '@type'         => 'Offer',
            'price'         => (string)$pkg->PackagePrice,
            'priceCurrency' => 'BDT',
            'availability'  => 'https://schema.org/InStock',
        ],
    ];
    if ($totalReviewCount > 0 && !empty($pkg->AvgRating)) {
        $jsonld['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => (string)$pkg->AvgRating,
            'reviewCount' => (string)$totalReviewCount,
        ];
    }
    // FIX (caught in live testing): tms_seo_meta()'s default canonical
    // strips the whole query string, which is correct for a filter/listing
    // page (destinations.php) but wrong here -- ?pkgid= IS this page's
    // resource identifier, not a filter. Stripping it made every package
    // canonicalize to the same bare URL, which would tell search engines
    // all packages are duplicates of one page. Rebuild the canonical from
    // the real request path (not hardcoded, so this still works whatever
    // subfolder the app is installed under) with only pkgid kept -- drops
    // any other incidental query params (utm_*, session leftovers, etc.)
    // without inventing a URL structure that doesn't exist.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    tms_seo_meta([
        'title'       => ($pkgNameT ?? $pkg->PackageName) . ' — ' . $pkg->PackageLocation,
        'description' => $seoDesc,
        'image'       => $gallery[0] ?? '',
        'type'        => 'product',
        'canonical'   => $scheme . '://' . $host . $path . '?pkgid=' . (int)$pkg->PackageId,
        'jsonld'      => $jsonld,
        'breadcrumbs' => [
            ['label' => 'Home', 'url' => 'index.php'],
            ['label' => 'Tour Packages', 'url' => 'package-list.php'],
            ['label' => ($pkgNameT ?? $pkg->PackageName)],
        ],
    ]);
}
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.errorWrap{padding:10px;margin:0 0 18px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.succWrap {padding:10px;margin:0 0 18px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.pkg-card{background:var(--white);border-radius:14px;box-shadow:0 4px 22px rgba(0,0,0,.09);overflow:hidden;margin-bottom:28px;}
.pkg-card img{width:100%;height:320px;object-fit:cover;}
.pkg-gallery{position:relative;}
.pkg-gallery-nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.45);color:#fff;border:none;width:38px;height:38px;border-radius:50%;font-size:22px;line-height:1;cursor:pointer;transition:.2s;}
.pkg-gallery-nav:hover{background:rgba(0,0,0,.7);}
.pkg-gallery-nav.prev{left:14px;}
.pkg-gallery-nav.next{right:14px;}
.pkg-gallery-count{position:absolute;bottom:12px;right:14px;background:rgba(0,0,0,.55);color:#fff;font-size:12px;padding:3px 10px;border-radius:20px;}
.pkg-gallery-thumbs{display:flex;gap:8px;padding:12px 12px 0;overflow-x:auto;}
.pkg-card .pkg-gallery-thumb{width:74px;height:56px;object-fit:cover;border-radius:6px;cursor:pointer;opacity:.6;border:2px solid transparent;transition:.15s;flex-shrink:0;}
.pkg-card .pkg-gallery-thumb:hover{opacity:.9;}
.pkg-card .pkg-gallery-thumb.active{opacity:1;border-color:var(--secondary);}
.pkg-body{padding:28px;}
.pkg-body h2{font-size:1.6em;font-weight:700;color:var(--text);margin-bottom:6px;}
.meta-row{display:flex;flex-wrap:wrap;gap:18px;margin:14px 0;font-size:14px;color:var(--text-muted);}
.meta-row span i{margin-right:5px;color:var(--secondary);}
.price-box{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;border-radius:10px;padding:18px 22px;margin-bottom:22px;text-align:center;}
.price-box .price{font-size:2.2em;font-weight:700;}
.price-box small{opacity:.85;font-size:13px;}
.form-control{border:2px solid #e0e0e0;border-radius:7px;height:42px;font-size:14px;}
.form-control:focus{border-color:var(--secondary);box-shadow:none;outline:none;}
.btn-book{background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:12px 34px;font-weight:700;font-size:15px;cursor:pointer;transition:.2s;width:100%;}
.btn-book:hover{background:#2d6a97;}
.btn-book-outline{background:var(--white);color:var(--secondary);border:2px solid var(--secondary);border-radius:8px;padding:12px 34px;font-weight:700;font-size:15px;cursor:pointer;transition:.2s;width:100%;margin-top:8px;}
.review-mini{background:var(--bg);border-radius:8px;padding:14px;margin-bottom:12px;}
.review-mini h6{font-size:13px;font-weight:700;color:var(--text);margin:0 0 4px;}
.stars-sm{color:var(--brass);font-size:13px;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="banner-3">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s">Package Details</h1>
    </div>
</div>

<div class="selectroom">
    <div class="container">
        <?php if ($error): ?>
            <div class="errorWrap"><strong>ERROR:</strong> <?php echo $error; ?></div>
        <?php elseif ($msg): ?>
            <div class="succWrap"><strong>SUCCESS:</strong> <?php echo $msg; ?></div>
        <?php endif; ?>

        <?php if ($pkg): ?>
        <?php tms_render_breadcrumb_nav([
            ['label' => 'Home', 'url' => 'index.php'],
            ['label' => 'Tour Packages', 'url' => 'package-list.php'],
            ['label' => ($pkgNameT ?? $pkg->PackageName)],
        ]); ?>
        <div class="row">
            <div class="col-md-8">
                <div class="pkg-card wow fadeInLeft">
                    <div class="pkg-gallery" id="pkgGallery">
                        <img loading="lazy" id="pkgGalleryMain"
                             src="<?php echo htmlspecialchars($gallery[0] ?? tms_package_image_src($pkg->PackageImage),ENT_QUOTES,'UTF-8'); ?>"
                             <?php echo tms_responsive_img_attrs($gallery[0] ?? ''); ?>
                             onerror="this.src='images/no-image-placeholder.jpg'"
                             alt="<?php echo htmlspecialchars($pkgNameT ?? $pkg->PackageName,ENT_QUOTES,'UTF-8'); ?>">
                        <?php if (count($gallery) > 1): ?>
                        <button type="button" class="pkg-gallery-nav prev" onclick="tmsGalleryStep(-1)" aria-label="Previous photo">&#8249;</button>
                        <button type="button" class="pkg-gallery-nav next" onclick="tmsGalleryStep(1)" aria-label="Next photo">&#8250;</button>
                        <div class="pkg-gallery-count"><span id="pkgGalleryPos">1</span> / <?php echo count($gallery); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (count($gallery) > 1): ?>
                    <div class="pkg-gallery-thumbs">
                        <?php foreach ($gallery as $i => $g): ?>
                        <img loading="lazy" src="<?php echo htmlspecialchars($g,ENT_QUOTES,'UTF-8'); ?>"
                             class="pkg-gallery-thumb<?php echo $i===0?' active':''; ?>"
                             data-idx="<?php echo $i; ?>" onclick="tmsGalleryGoto(<?php echo $i; ?>)"
                             alt="<?php echo htmlspecialchars(($pkgNameT ?? $pkg->PackageName) . ' photo ' . ($i+1),ENT_QUOTES,'UTF-8'); ?>">
                        <?php endforeach; ?>
                    </div>
                    <script>
                    (function(){
                        var photos = <?php echo json_encode($gallery, JSON_UNESCAPED_SLASHES); ?>;
                        var idx = 0;
                        window.tmsGalleryGoto = function(i){
                            idx = ((i % photos.length) + photos.length) % photos.length;
                            document.getElementById('pkgGalleryMain').src = photos[idx];
                            document.getElementById('pkgGalleryPos').textContent = idx + 1;
                            document.querySelectorAll('.pkg-gallery-thumb').forEach(function(t, ti){
                                t.classList.toggle('active', ti === idx);
                            });
                        };
                        window.tmsGalleryStep = function(delta){ tmsGalleryGoto(idx + delta); };
                    })();
                    </script>
                    <?php endif; ?>
                    <?php if ($galleryHasStockPhotos): ?>
                    <div class="pkg-gallery-stock-note" style="font-size:11.5px;color:var(--text-muted);padding:6px 2px 0;">
                        <i class="fa fa-info-circle"></i> Some photos are representative stock images of this destination, not exclusive property photos.
                    </div>
                    <?php endif; ?>
                    <div class="pkg-body">
                        <p style="font-size:12px;color:var(--text-muted);margin:0;">#PKG-<?php echo (int)$pkg->PackageId; ?></p>
                        <h2><?php echo htmlspecialchars($pkgNameT ?? $pkg->PackageName,ENT_QUOTES,'UTF-8'); ?></h2>
                        <?php if (!empty($pkg->AvgRating) && $pkg->AvgRating > 0): ?>
                        <div style="color:var(--brass);font-size:16px;margin-bottom:8px;">
                            <?php for ($i=1;$i<=5;$i++) echo $i<=round($pkg->AvgRating)?'★':'☆'; ?>
                            <span style="font-size:13px;color:var(--text-muted);"><?php echo number_format($pkg->AvgRating,1); ?> / 5</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($matchScore && $matchScore['overall_match'] !== null): ?>
                        <div style="background:#f4f9ff;border:1px solid #d6e8fb;border-radius:8px;padding:12px 14px;margin-bottom:14px;">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                                <span style="font-size:22px;font-weight:700;color:#1d6fd6;"><?php echo (int)$matchScore['overall_match']; ?>%</span>
                                <span style="font-size:13px;color:#444;">match for your preferences</span>
                            </div>
                            <ul style="list-style:none;margin:0;padding:0;font-size:12.5px;color:#555;">
                                <?php foreach ($matchScore['factors'] as $factor):
                                    $good = $factor['score'] >= 60; ?>
                                <li style="padding:2px 0;">
                                    <span style="color:<?php echo $good ? '#2e9e4f' : '#c0392b'; ?>;"><?php echo $good ? '✓' : '✗'; ?></span>
                                    <strong><?php echo htmlspecialchars($factor['label'],ENT_QUOTES,'UTF-8'); ?>:</strong>
                                    <?php echo htmlspecialchars($factor['detail'],ENT_QUOTES,'UTF-8'); ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="font-size:11px;color:var(--text-muted);margin:8px 0 0;">Computed from real platform data (price, live weather forecast, seat availability, ratings, distance) -- not an AI guess.</p>
                        </div>
                        <?php elseif ($matchScore && !empty($matchScore['note'])): ?>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;"><i class="fa fa-info-circle"></i> <?php echo htmlspecialchars($matchScore['note'],ENT_QUOTES,'UTF-8'); ?></div>
                        <?php endif; ?>
                        <div class="meta-row">
                            <span><i class="fa fa-tag"></i> <?php echo htmlspecialchars($pkg->PackageType,ENT_QUOTES,'UTF-8'); ?></span>
                            <span><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($pkg->PackageLocation,ENT_QUOTES,'UTF-8'); ?></span>
                            <?php if (!empty($pkg->PackageDuration)): ?>
                            <span><i class="fa fa-clock-o"></i> <?php echo htmlspecialchars($pkg->PackageDuration,ENT_QUOTES,'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p><strong>Features:</strong> <?php echo htmlspecialchars($pkg->PackageFetures ?? '',ENT_QUOTES,'UTF-8'); ?></p>
                        <h4 style="margin-top:20px;">Package Details</h4>
                        <p style="color:#555;line-height:1.7;"><?php echo nl2br(htmlspecialchars($pkgDescT ?? ($pkg->PackageDetails ?? ''),ENT_QUOTES,'UTF-8')); ?></p>

                        <?php
                        // Round 87 (item 7): Inclusions/Exclusions/Cancellation
                        // Policy — plain newline-separated text set by the admin
                        // in add-package.php/edit-package.php, rendered as lists.
                        $splitLines = function($text) {
                            $lines = preg_split('/\r\n|\r|\n/', (string)$text);
                            return array_values(array_filter(array_map('trim', $lines), function($l) { return $l !== ''; }));
                        };
                        $inclusionLines = !empty($pkg->Inclusions) ? $splitLines($pkg->Inclusions) : [];
                        $exclusionLines = !empty($pkg->Exclusions) ? $splitLines($pkg->Exclusions) : [];
                        $cancellationLines = !empty($pkg->CancellationPolicy) ? $splitLines($pkg->CancellationPolicy) : [];
                        ?>
                        <?php if (!empty($inclusionLines) || !empty($exclusionLines)): ?>
                        <div class="row" style="margin-top:20px;">
                            <?php if (!empty($inclusionLines)): ?>
                            <div class="col-sm-6">
                                <h4><i class="fa fa-check-circle" style="color:#2e9e5b;"></i> What's Included</h4>
                                <ul style="list-style:none;padding:0;font-size:13.5px;color:#555;line-height:2;">
                                    <?php foreach ($inclusionLines as $line): ?>
                                    <li><i class="fa fa-check" style="color:#2e9e5b;margin-right:6px;"></i><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($exclusionLines)): ?>
                            <div class="col-sm-6">
                                <h4><i class="fa fa-times-circle" style="color:#c0392b;"></i> What's Excluded</h4>
                                <ul style="list-style:none;padding:0;font-size:13.5px;color:#555;line-height:2;">
                                    <?php foreach ($exclusionLines as $line): ?>
                                    <li><i class="fa fa-times" style="color:#c0392b;margin-right:6px;"></i><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($cancellationLines)): ?>
                        <h4 style="margin-top:20px;"><i class="fa fa-info-circle" style="color:var(--secondary)"></i> Cancellation Policy</h4>
                        <ul style="font-size:13.5px;color:#555;line-height:2;padding-left:20px;">
                            <?php foreach ($cancellationLines as $line): ?>
                            <li><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <?php
                        $meta = null;
                        if (!empty($pkg->PackageMeta)) {
                            $meta = json_decode($pkg->PackageMeta, true);
                        }
                        if (is_array($meta)):
                            $esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
                        ?>
                        <hr style="margin:24px 0;border-color:#eee;">

                        <?php if (!empty($meta['history'])): ?>
                        <h4><i class="fa fa-book" style="color:var(--secondary)"></i> History</h4>
                        <p style="color:#555;line-height:1.7;"><?php echo $esc($meta['history']); ?></p>
                        <?php endif; ?>

                        <div class="row" style="margin-top:16px;">
                            <div class="col-sm-6">
                                <ul style="list-style:none;padding:0;font-size:13.5px;color:#555;line-height:2;">
                                    <?php if (!empty($meta['best_time_to_visit'])): ?><li><strong>Best time to visit:</strong> <?php echo $esc($meta['best_time_to_visit']); ?></li><?php endif; ?>
                                    <?php if (!empty($meta['climate'])): ?><li><strong>Climate:</strong> <?php echo $esc($meta['climate']); ?></li><?php endif; ?>
                                    <?php if (!empty($meta['entry_fee'])): ?><li><strong>Entry fee:</strong> <?php echo $esc($meta['entry_fee']); ?></li><?php endif; ?>
                                    <?php if (!empty($meta['opening_hours'])): ?><li><strong>Opening hours:</strong> <?php echo $esc($meta['opening_hours']); ?></li><?php endif; ?>
                                </ul>
                            </div>
                            <div class="col-sm-6">
                                <ul style="list-style:none;padding:0;font-size:13.5px;color:#555;line-height:2;">
                                    <?php if (!empty($meta['budget_estimate'])): ?><li><strong>Budget estimate:</strong> <?php echo $esc($meta['budget_estimate']); ?></li><?php endif; ?>
                                    <?php if (!empty($meta['luxury_estimate']) && $meta['luxury_estimate'] !== 'N/A'): ?><li><strong>Luxury estimate:</strong> <?php echo $esc($meta['luxury_estimate']); ?></li><?php endif; ?>
                                    <?php if (!empty($meta['transportation_guide'])): ?><li><strong>Getting there:</strong> <?php echo $esc($meta['transportation_guide']); ?></li><?php endif; ?>
                                </ul>
                            </div>
                        </div>

                        <!-- Live crowd insights (Phase 2e): loads async, grounded in real
                             booking history when enough exists — see CrowdPredictor.php -->
                        <div id="crowdInsightsBox" style="display:none;margin-top:6px;margin-bottom:12px;padding:14px 16px;background:var(--white);border:1px solid var(--border);border-radius:10px;font-size:13.5px;color:var(--text);"></div>

                        <!-- Carbon footprint / sustainability (Phase 2i): loads async,
                             real distance-based estimate — see SustainabilityCalculator.php -->
                        <div id="sustainabilityBox" style="display:none;margin-top:6px;margin-bottom:18px;padding:14px 16px;background:var(--white);border:1px solid var(--border);border-left:4px solid var(--primary);border-radius:10px;font-size:13.5px;color:var(--text);"></div>

                        <?php
                        $suitLabels = ['adventure_level'=>'Adventure Level','family_suitability'=>'Family','couple_suitability'=>'Couple','solo_suitability'=>'Solo Traveler'];
                        $hasSuit = false;
                        foreach ($suitLabels as $k=>$_) if (isset($meta[$k])) $hasSuit = true;
                        ?>
                        <?php if ($hasSuit): ?>
                        <h4 style="margin-top:20px;">Suitability</h4>
                        <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:10px;">
                            <?php foreach ($suitLabels as $key=>$label): if (!isset($meta[$key])) continue; ?>
                            <div style="font-size:13px;color:#555;">
                                <?php echo $label; ?>:
                                <span style="color:var(--brass);"><?php for($i=1;$i<=5;$i++) echo $i<=intval($meta[$key])?'★':'☆'; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($meta['activities'])): ?>
                        <h4 style="margin-top:16px;">Activities &amp; Things to Do</h4>
                        <p style="font-size:13.5px;color:#555;"><?php echo $esc(implode(' • ', $meta['activities'])); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($meta['local_foods'])): ?>
                        <h4 style="margin-top:16px;">Local Foods to Try</h4>
                        <p style="font-size:13.5px;color:#555;"><?php echo $esc(implode(' • ', $meta['local_foods'])); ?></p>
                        <?php endif; ?>

                        <div class="row" style="margin-top:16px;">
                            <?php if (!empty($meta['nearby_hotels'])): ?>
                            <div class="col-sm-6" style="margin-bottom:14px;">
                                <h5><i class="fa fa-bed" style="color:var(--secondary)"></i> Nearby Hotels</h5>
                                <ul style="font-size:13px;color:#666;padding-left:18px;">
                                    <?php foreach ($meta['nearby_hotels'] as $h): ?><li><?php echo $esc($h); ?></li><?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($meta['nearby_restaurants'])): ?>
                            <div class="col-sm-6" style="margin-bottom:14px;">
                                <h5><i class="fa fa-cutlery" style="color:var(--secondary)"></i> Nearby Restaurants</h5>
                                <ul style="font-size:13px;color:#666;padding-left:18px;">
                                    <?php foreach ($meta['nearby_restaurants'] as $r): ?><li><?php echo $esc($r); ?></li><?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($meta['nearby_attractions'])): ?>
                            <div class="col-sm-6" style="margin-bottom:14px;">
                                <h5><i class="fa fa-camera" style="color:var(--secondary)"></i> Nearby Attractions</h5>
                                <ul style="font-size:13px;color:#666;padding-left:18px;">
                                    <?php foreach ($meta['nearby_attractions'] as $a): ?><li><?php echo $esc($a); ?></li><?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($meta['nearby_hospitals'])): ?>
                            <div class="col-sm-6" style="margin-bottom:14px;">
                                <h5><i class="fa fa-plus-square" style="color:var(--danger)"></i> Nearby Hospitals / Emergency</h5>
                                <ul style="font-size:13px;color:#666;padding-left:18px;">
                                    <?php foreach ($meta['nearby_hospitals'] as $hos): ?><li><?php echo $esc($hos); ?></li><?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($meta['travel_tips'])): ?>
                        <h4 style="margin-top:10px;"><i class="fa fa-lightbulb-o" style="color:var(--brass)"></i> Travel Tips</h4>
                        <ul style="font-size:13.5px;color:#555;padding-left:18px;">
                            <?php foreach ($meta['travel_tips'] as $tip): ?><li><?php echo $esc($tip); ?></li><?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <?php if (!empty($meta['safety_guidelines'])): ?>
                        <h4 style="margin-top:10px;"><i class="fa fa-shield" style="color:var(--success)"></i> Safety Guidelines</h4>
                        <ul style="font-size:13.5px;color:#555;padding-left:18px;">
                            <?php foreach ($meta['safety_guidelines'] as $s): ?><li><?php echo $esc($s); ?></li><?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- FAQs -->
                <div class="pkg-card wow fadeInUp" style="padding:24px;">
                    <h4 style="font-weight:700;margin-bottom:16px;"><i class="fa fa-question-circle" style="color:var(--secondary)"></i> Frequently Asked Questions</h4>
                    <?php
                    $pkgFaqs = [
                        ['q' => 'What does the package price include?', 'a' => 'The listed price covers the core package as described above — see the "What\'s Included" / "What\'s Excluded" lists on this page for the exact breakdown where the package operator has provided one. Optional extras such as meals, hotel upgrades, or private transport can be added directly in the booking form below when a package offers them, or mention a custom request in "Special Requests," or ask via the chat assistant.'],
                        ['q' => 'Can I change my travel dates after booking?', 'a' => 'Yes — contact support or raise a ticket from "My Tours" as soon as possible. Changes are easiest more than 7 days before departure.'],
                        ['q' => 'Is the coupon code stackable with other offers?', 'a' => 'One coupon code can be applied per booking. Loyalty points earned from a booking are separate and always added on top.'],
                        ['q' => 'What if the weather looks bad for my travel dates?', 'a' => 'Check the live forecast via the "Weather Here" button above. For serious weather disruption, contact support — options depend on how close it is to your departure date.'],
                    ];
                    foreach ($pkgFaqs as $fi => $faq):
                    ?>
                    <div style="border-bottom:1px solid #eee;padding:12px 0;">
                        <div style="font-weight:600;color:#333;cursor:pointer;display:flex;justify-content:space-between;align-items:center;"
                             onclick="var a=document.getElementById('pkgFaqA<?php echo $fi; ?>');a.style.display=a.style.display==='block'?'none':'block';this.querySelector('i').classList.toggle('fa-chevron-down');this.querySelector('i').classList.toggle('fa-chevron-up');">
                            <span><?php echo htmlspecialchars($faq['q'],ENT_QUOTES,'UTF-8'); ?></span>
                            <i class="fa fa-chevron-down" style="font-size:12px;color:var(--text-muted);"></i>
                        </div>
                        <div id="pkgFaqA<?php echo $fi; ?>" style="display:none;font-size:13.5px;color:#666;margin-top:8px;line-height:1.6;">
                            <?php echo htmlspecialchars($faq['a'],ENT_QUOTES,'UTF-8'); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- AI Review Summary — reviewer ask #14: instead of making
                     visitors read every review, surface what people actually
                     loved/flagged at a glance. Only rendered when there's a
                     real analyzed sample (see $totalReviewCount >= 3 check
                     above) — never fabricated for thin or unanalyzed data. -->
                <?php if ($aiReviewSummary): ?>
                <div class="pkg-card wow fadeInUp" style="padding:24px;background:linear-gradient(135deg,#f0fdfa,#fff);">
                    <h4 style="font-weight:700;margin-bottom:4px;"><i class="fa fa-magic" style="color:var(--secondary)"></i> AI Review Summary</h4>
                    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">Based on <?php echo (int)$aiReviewSummary['sentiment']['total']; ?> analyzed review<?php echo $aiReviewSummary['sentiment']['total']===1?'':'s'; ?> for this package.</p>
                    <?php
                    $s = $aiReviewSummary['sentiment'];
                    $posPct = $s['total'] > 0 ? round($s['positive'] / $s['total'] * 100) : 0;
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                        <div style="flex:1;height:8px;background:#fee2e2;border-radius:4px;overflow:hidden;">
                            <div style="width:<?php echo $posPct; ?>%;height:100%;background:#22c55e;"></div>
                        </div>
                        <span style="font-size:12.5px;font-weight:700;color:#16a34a;white-space:nowrap;"><?php echo $posPct; ?>% positive</span>
                    </div>
                    <?php if (!empty($aiReviewSummary['highlights'])): ?>
                    <div style="margin-bottom:12px;">
                        <div style="font-size:11.5px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;"><i class="fa fa-thumbs-up"></i> People loved</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                            <?php foreach ($aiReviewSummary['highlights'] as $h): ?>
                            <span style="background:#dcfce7;color:#166534;font-size:12px;font-weight:600;padding:4px 12px;border-radius:14px;"><?php echo htmlspecialchars($h['phrase'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($aiReviewSummary['issues'])): ?>
                    <div>
                        <div style="font-size:11.5px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;"><i class="fa fa-exclamation-circle"></i> Worth knowing</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                            <?php foreach ($aiReviewSummary['issues'] as $iss): ?>
                            <span style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:600;padding:4px 12px;border-radius:14px;"><?php echo htmlspecialchars($iss['phrase'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Recent Reviews -->
                <?php if (count($reviews) > 0): ?>
                <div class="pkg-card wow fadeInUp" style="padding:24px;">
                    <h4 style="font-weight:700;margin-bottom:16px;"><i class="fa fa-star" style="color:var(--brass)"></i> <?php echo tms_t('heading_reviews','Recent Reviews'); ?></h4>
                    <?php foreach ($reviews as $rev): ?>
                    <div class="review-mini">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <h6><?php echo htmlspecialchars($rev->UserName,ENT_QUOTES,'UTF-8'); ?></h6>
                            <div class="stars-sm"><?php for ($i=1;$i<=5;$i++) echo $i<=$rev->Rating?'★':'☆'; ?></div>
                        </div>
                        <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;"><?php echo htmlspecialchars(substr($rev->ReviewText,0,150),ENT_QUOTES,'UTF-8'); ?>…</p>
                    </div>
                    <?php endforeach; ?>
                    <a href="reviews.php?pkg=<?php echo (int)$pkg->PackageId; ?>" style="font-size:13px;color:var(--secondary);">
                        View all reviews <i class="fa fa-arrow-right"></i>
                    </a>
                </div>
                <?php endif; ?>

                <!-- Virtual Preview — real 360° Street View imagery of the actual
                     destination coordinates, via Google's Embed API. This is the
                     one genuinely honest way to deliver "virtual tour" without
                     fake/stock panoramas: real imagery of the real place, or
                     nothing shown at all. Requires GOOGLE_MAPS_API_KEY (same
                     optional key already used for Directions upgrades in
                     ExploreService.php) — without it, this section simply
                     doesn't render rather than showing a broken iframe. Street
                     View coverage itself isn't guaranteed for every location
                     (rural/remote destinations may have none); the iframe
                     itself handles that gracefully with Google's own "no
                     imagery available" message rather than us guessing. -->
                <?php
                // LIVE BUG FIX (customer-facing screenshot): this section already
                // correctly hid itself with no key configured, but once a key
                // IS configured and something is wrong with it on Google's side
                // (the Embed API not activated for that Cloud project, an
                // invalid key, a referrer restriction...), Google's own iframe
                // response is a raw, technical error banner -- "Google Maps
                // Platform rejected your request..." -- shown directly to a
                // paying customer looking at a tour package. That's exactly the
                // kind of raw third-party error this project stopped exposing
                // everywhere else this round. A JS-side fix can't detect this:
                // the iframe is cross-origin, so the page can never read what's
                // actually inside it. Instead, do one real check server-side and
                // cache the verdict for an hour (same pattern as the weather and
                // i18n circuit breakers elsewhere in this file) -- and fail OPEN
                // (assume it's fine) if the check itself can't run, so a slow or
                // blocked outbound request never hides a perfectly working
                // feature.
                $tmsStreetViewOk = true;
                if (!empty($pkg->MapLat) && !empty($pkg->MapLng) && defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '') {
                    $tmsSvCacheKey = 'streetview_embed_health';
                    $tmsSvCached = isset($tmsCache) ? $tmsCache->get($tmsSvCacheKey) : null;
                    if ($tmsSvCached !== null) {
                        $tmsStreetViewOk = (bool)$tmsSvCached;
                    } else {
                        try {
                            $ch = curl_init('https://www.google.com/maps/embed/v1/streetview?key=' . urlencode(GOOGLE_MAPS_API_KEY) . '&location=0,0');
                            curl_setopt_array($ch, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT        => 5,
                                CURLOPT_CONNECTTIMEOUT => 3,
                            ]);
                            $tmsSvBody = curl_exec($ch);
                            curl_close($ch);
                            if ($tmsSvBody === false) {
                                // Couldn't reach Google at all right now -- not the
                                // same thing as the key being broken. Fail open and
                                // don't cache a guess either way.
                                $tmsStreetViewOk = true;
                            } else {
                                $tmsStreetViewOk = !preg_match(
                                    '/rejected your request|ApiNotActivatedMapError|InvalidKeyMapError|RefererNotAllowedMapError|MissingKeyMapError/i',
                                    $tmsSvBody
                                );
                                if (isset($tmsCache)) $tmsCache->set($tmsSvCacheKey, $tmsStreetViewOk, 3600);
                            }
                        } catch (Throwable $e) {
                            error_log('[TMS] Street View health check failed: ' . $e->getMessage());
                            $tmsStreetViewOk = true; // fail open
                        }
                    }
                }
                ?>
                <?php if(!empty($pkg->MapLat) && !empty($pkg->MapLng) && defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '' && $tmsStreetViewOk): ?>
                <div class="pkg-card wow fadeInUp" style="padding:24px;">
                    <h4 style="font-weight:700;margin-bottom:4px;"><i class="fa fa-street-view" style="color:#1F8A66"></i> Virtual Preview</h4>
                    <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Real 360° street-level imagery of this destination — drag to look around.</p>
                    <div style="border-radius:var(--radius-sm);overflow:hidden;box-shadow:var(--shadow-sm);">
                        <iframe
                            width="100%" height="320" style="border:0;display:block;" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            src="https://www.google.com/maps/embed/v1/streetview?key=<?php echo urlencode(GOOGLE_MAPS_API_KEY); ?>&location=<?php echo urlencode($pkg->MapLat . ',' . $pkg->MapLng); ?>&heading=0&pitch=0&fov=90">
                        </iframe>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Nearby Essentials — real interactive map + live nearby places
                     (Overpass/OpenStreetMap, or Google Places if a key is configured),
                     reusing the same api/explore.php?action=places endpoint already
                     proven in Explore Mode. Only shown when the package actually has
                     coordinates set — no fake pins for packages without them. -->
                <?php if(!empty($pkg->MapLat) && !empty($pkg->MapLng)): ?>
                <div class="pkg-card wow fadeInUp" style="padding:24px;">
                    <h4 style="font-weight:700;margin-bottom:4px;"><i class="fa fa-map-marker" style="color:var(--primary)"></i> Nearby Essentials</h4>
                    <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Live data around this destination — hotels, food, and emergency services.</p>
                    <div id="nearbyMap" style="height:280px;border-radius:var(--radius-sm);margin-bottom:14px;background:#eef2f5;"></div>
                    <div id="nearbyTabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                        <button type="button" class="nearby-tab active" data-cat="hotel"><i class="fa fa-bed"></i> Hotels</button>
                        <button type="button" class="nearby-tab" data-cat="restaurant"><i class="fa fa-cutlery"></i> Restaurants</button>
                        <button type="button" class="nearby-tab" data-cat="attraction"><i class="fa fa-camera"></i> Attractions</button>
                        <button type="button" class="nearby-tab" data-cat="hospital"><i class="fa fa-plus-square"></i> Hospitals</button>
                        <button type="button" class="nearby-tab" data-cat="police"><i class="fa fa-shield"></i> Police</button>
                    </div>
                    <div id="nearbyList" style="font-size:13px;color:#666;">
                        <div style="text-align:center;padding:16px;color:var(--text-muted);"><i class="fa fa-spinner fa-spin"></i> Loading hotels nearby…</div>
                    </div>
                </div>
                <style>
                .nearby-tab{background:var(--white);border:1.5px solid #e2e8f0;border-radius:20px;padding:6px 14px;font-size:12.5px;font-weight:600;color:#64748b;cursor:pointer;transition:.2s;}
                .nearby-tab:hover{border-color:var(--secondary);color:var(--secondary);}
                .nearby-tab.active{background:var(--secondary);border-color:var(--secondary);color:#fff;}
                .nearby-item{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .15s;border-radius:6px;}
                .nearby-item:hover{background:var(--bg);padding-left:6px;padding-right:6px;}
                .nearby-item:last-child{border-bottom:none;}
                .nearby-item .name{font-weight:600;color:var(--text);font-size:13.5px;}
                .nearby-item .addr{font-size:11.5px;color:var(--text-muted);margin-top:2px;}
                .nearby-item .dist{font-family:var(--font-mono);font-size:11.5px;color:var(--secondary);font-weight:700;white-space:nowrap;}
html[data-theme="dark"] .pkg-body h2,
html[data-theme="dark"] .nearby-item .name { color: var(--text) !important; }
html[data-theme="dark"] .meta-row { color: var(--text-muted) !important; }
                /* Place details modal — shows only real, live fields from the
                   nearby-places API. Fields the free data source doesn't have
                   (which is common — OpenStreetMap often lacks phone/website
                   for smaller places) are honestly labeled "Not listed" rather
                   than invented, and a real map link is always offered so the
                   visitor can dig further on an authoritative source. */
                #placeDetailsOverlay{display:none;position:fixed;inset:0;background:rgba(16,75,58,0.55);z-index:10000;align-items:center;justify-content:center;padding:20px;}
                #placeDetailsOverlay.open{display:flex;}
                #placeDetailsModal{background:#fff;border-radius:16px;max-width:420px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);}
                .pd-head{padding:20px 22px 14px;border-bottom:1px solid #f0f0f0;position:relative;}
                .pd-head h3{margin:0 18px 0 0;font-size:17px;font-weight:700;color:var(--text);}
                .pd-close{position:absolute;top:16px;right:18px;background:none;border:none;font-size:20px;color:var(--text-muted);cursor:pointer;line-height:1;}
                .pd-close:hover{color:#333;}
                .pd-body{padding:16px 22px 22px;}
                .pd-row{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f8fafc;font-size:13px;}
                .pd-row:last-of-type{border-bottom:none;}
                .pd-row i{width:16px;color:var(--secondary);margin-top:2px;flex-shrink:0;}
                .pd-row .pd-val{color:var(--text);}
                .pd-row .pd-val.pd-missing{color:#c2c9d1;font-style:italic;}
                .pd-actions{display:flex;gap:8px;margin-top:16px;}
                .pd-actions a{flex:1;text-align:center;padding:10px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;}
                .pd-actions .pd-map-link{background:var(--secondary);color:#fff;}
                .pd-actions .pd-directions-link{background:var(--bg);color:var(--text);border:1.5px solid #e2e8f0;}
                .pd-source-note{font-size:10.5px;color:#c2c9d1;margin-top:14px;text-align:center;}
                html[data-theme="dark"] #placeDetailsModal{background:#1b222c;}
                html[data-theme="dark"] .pd-row .pd-val{color:var(--text);}
                /* FIX (Round 114, package/places/map upgrade): this modal used to
                   hand-build a thin 6-field view (address/distance/rating/phone/
                   hours/website) from data already sitting in the Nearby Places
                   card, instead of calling the same api/explore.php?action=
                   place_details endpoint (PlaceDetailsService) Explore Mode uses --
                   so the same live place looked materially more complete on
                   explore-view.php than it did right here, on the page a paying
                   customer is actually deciding on. These rules extend the modal
                   above with the same categories of information Explore Mode's
                   modal shows (open/closed + rating badges, category tips,
                   emergency guidance for hospitals/police, provenance note),
                   styled to match this page's own pd-* classes rather than
                   pulling in Explore Mode's separate stylesheet. */
                .pd-badges{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
                .pd-badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:14px;background:var(--bg);color:var(--text);display:inline-flex;align-items:center;gap:5px;}
                .pd-badge-rating{background:#fff4dd;color:#a9750c;}
                .pd-badge-open{background:#e8f8ee;color:#1f8a4c;}
                .pd-badge-closed{background:#fdecec;color:#c0392b;}
                .pd-section{margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f0;}
                .pd-section-title{font-size:12.5px;font-weight:700;color:var(--text);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
                .pd-section-title i{color:var(--secondary);}
                .pd-section-text{font-size:12.5px;color:#64748b;line-height:1.5;margin:0;}
                .pd-list-item{font-size:12.5px;color:var(--text);padding:4px 0;display:flex;gap:8px;align-items:flex-start;}
                .pd-list-item i{color:var(--success);margin-top:3px;flex-shrink:0;}
                html[data-theme="dark"] .pd-section{border-top-color:#2a3441;}
                html[data-theme="dark"] .pd-badge{background:#232b37;color:#eef2f7;}
                </style>
                <div id="placeDetailsOverlay">
                    <div id="placeDetailsModal">
                        <div class="pd-head">
                            <h3 id="pdName"></h3>
                            <button type="button" class="pd-close" onclick="tmsClosePlaceDetails()">&times;</button>
                        </div>
                        <div class="pd-body" id="pdBody"></div>
                    </div>
                </div>
                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                <script>
                (function(){
                    var lat = <?php echo (float)$pkg->MapLat; ?>, lng = <?php echo (float)$pkg->MapLng; ?>;
                    var map = L.map('nearbyMap').setView([lat, lng], 13);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors', maxZoom: 18
                    }).addTo(map);
                    var destIcon = L.divIcon({className:'', html:'<div style="width:16px;height:16px;background:var(--accent);border:3px solid #fff;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>', iconSize:[16,16]});
                    // FIX (Round 114, package/places/map upgrade): this used to be
                    // htmlspecialchars(addslashes(...)) -- HTML-entity-encoding a
                    // string that's embedded in a <script> block, not HTML. A
                    // package name containing an apostrophe or an ampersand (e.g.
                    // "O'Reilly Tour", "Bed & Breakfast Retreat") rendered literally
                    // as "O&#039;Reilly Tour" in the Leaflet popup, because the
                    // browser only decodes HTML entities when parsing markup, never
                    // inside a JS string literal. json_encode() produces a properly
                    // escaped JS string literal instead -- the same tool this file's
                    // own `packages` array (tour-map.php) already relies on.
                    L.marker([lat, lng], {icon: destIcon}).addTo(map).bindPopup(<?php echo json_encode((string)$pkg->PackageName, JSON_UNESCAPED_UNICODE); ?>);
                    var markers = [];
                    function clearMarkers(){ markers.forEach(function(m){ map.removeLayer(m); }); markers = []; }
                    function escHtml(s){ var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

                    function pdField(iconClass, label, value) {
                        if (value) {
                            return '<div class="pd-row"><i class="fa ' + iconClass + '"></i><div><strong>' + label + ':</strong> <span class="pd-val">' + escHtml(value) + '</span></div></div>';
                        }
                        return '<div class="pd-row"><i class="fa ' + iconClass + '"></i><div><strong>' + label + ':</strong> <span class="pd-val pd-missing">Not listed</span></div></div>';
                    }

                    window.tmsClosePlaceDetails = function() {
                        document.getElementById('placeDetailsOverlay').classList.remove('open');
                    };

                    // FIX (Round 114, package/places/map upgrade -- was finding #8
                    // in this round's audit): this used to build the whole modal
                    // from the 6 fields already sitting on the Nearby Places card
                    // (address/distance/rating/phone/hours/website), computed
                    // entirely client-side. Explore Mode's identical modal
                    // (js/explore.js's openPlaceDetails()) instead calls
                    // api/explore.php?action=place_details, backed by
                    // PlaceDetailsService -- which adds a "Verified details" grid,
                    // real emergency guidance for hospitals/police, honest
                    // non-specific category tips, and a data-provenance note, none
                    // of which this page showed for the exact same live place. This
                    // now calls that same endpoint and renders the same categories
                    // of information PlaceDetailsService actually returns (sections
                    // are always one of 'info_grid' | 'list' | 'text' -- see
                    // renderPdSection() below), styled with this page's own pd-*
                    // classes rather than pulling in Explore Mode's separate
                    // stylesheet.
                    function openPlaceDetails(p, category) {
                        document.getElementById('pdName').textContent = p.name || 'Place';
                        document.getElementById('pdBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);"><i class="fa fa-spinner fa-spin"></i> Loading details…</div>';
                        document.getElementById('placeDetailsOverlay').classList.add('open');

                        var qs = new URLSearchParams({
                            action: 'place_details',
                            category: category || 'hotel',
                            name: p.name || '',
                            lat: p.lat || '',
                            lng: p.lng || '',
                            address: p.address || '',
                            phone: p.phone || '',
                            opening_hours: p.opening_hours || '',
                            website: p.website || '',
                            rating: (p.rating !== null && p.rating !== undefined) ? p.rating : '',
                            distance_m: (p.distance_m !== null && p.distance_m !== undefined) ? p.distance_m : '',
                            // FIX (Round 114, F12-adjacent): p.is_open_now is now a
                            // real boolean when the nearby-places list already knew
                            // it (Overpass opening_hours, or Google's live open_now
                            // flag -- see ExploreService::parseOverpassPlaces()/
                            // parseGooglePlaces()). Passing it through here lets
                            // PlaceDetailsService use that real value directly
                            // instead of re-parsing the display string a second
                            // time (and for a Google-sourced place, re-parsing
                            // "Open now"/"Closed now" as an OSM-style hours string
                            // never actually worked -- it always fell back to null).
                            is_open_now: (p.is_open_now === true || p.is_open_now === false) ? p.is_open_now : ''
                        });

                        fetch('api/explore.php?' + qs.toString())
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (!res || !res.success) {
                                    document.getElementById('pdBody').innerHTML = '<div style="text-align:center;padding:16px;color:#e11d48;">' +
                                        escHtml((res && res.error) || 'Could not load details right now.') + '</div>';
                                    return;
                                }
                                document.getElementById('pdBody').innerHTML = renderPlaceDetailBody(res.data.place);
                            })
                            .catch(function(){
                                document.getElementById('pdBody').innerHTML = '<div style="text-align:center;padding:16px;color:#e11d48;">Could not load details right now.</div>';
                            });
                    }

                    function renderPlaceDetailBody(pl) {
                        var badges = '<span class="pd-badge"><i class="fa fa-tag"></i> ' + escHtml(pl.category_label) + '</span>';
                        if (pl.rating) badges += '<span class="pd-badge pd-badge-rating"><i class="fa fa-star"></i> ' + pl.rating + ' / 5</span>';
                        if (pl.is_open_now === true) badges += '<span class="pd-badge pd-badge-open"><i class="fa fa-clock-o"></i> Open now</span>';
                        else if (pl.is_open_now === false) badges += '<span class="pd-badge pd-badge-closed"><i class="fa fa-clock-o"></i> Closed now</span>';
                        if (pl.distance_km !== null && pl.distance_km !== undefined) badges += '<span class="pd-badge"><i class="fa fa-road"></i> ' + pl.distance_km + ' km away</span>';

                        var html = '<div class="pd-badges">' + badges + '</div>';
                        html += pdField('fa-map-marker', 'Address', pl.address);
                        html += pdField('fa-phone', 'Phone', pl.phone);
                        html += pdField('fa-clock-o', 'Hours', pl.opening_hours);
                        // Website is now a real link (this used to render the URL as
                        // inert text -- a visitor could see a place had a website
                        // but couldn't click through to it).
                        html += pl.website
                            ? '<div class="pd-row"><i class="fa fa-globe"></i><div><strong>Website:</strong> <span class="pd-val"><a href="' + escHtml(pl.website) + '" target="_blank" rel="noopener nofollow">' + escHtml(pl.website) + '</a></span></div></div>'
                            : pdField('fa-globe', 'Website', null);

                        (pl.sections || []).forEach(function(s){ html += renderPdSection(s); });

                        html += '<div class="pd-actions">' +
                            '<a class="pd-map-link" href="' + escHtml(pl.map_url) + '" target="_blank" rel="noopener"><i class="fa fa-map"></i> View on Map</a>' +
                            '<a class="pd-directions-link" href="https://www.google.com/maps/dir/?api=1&destination=' + pl.lat + ',' + pl.lng + '" target="_blank" rel="noopener"><i class="fa fa-location-arrow"></i> Directions</a>' +
                            '</div>';
                        return html;
                    }

                    // PlaceDetailsService::sectionsFor() only ever emits these three
                    // types -- see backend/PlaceDetailsService.php. Unlike Explore
                    // Mode's older renderer (js/explore.js), this was never carrying
                    // dead branches for section types the backend can't produce
                    // (rooms/menu/doctors/reviews/gallery, from a pre-Round-14
                    // version that fabricated them) in the first place, since this
                    // is a fresh port -- see this round's CHANGELOG for the note
                    // about those dead branches in js/explore.js itself.
                    function renderPdSection(s) {
                        var icon = s.icon || 'fa-info-circle';
                        var head = '<div class="pd-section-title"><i class="fa ' + icon + '"></i> ' + escHtml(s.title) + '</div>';
                        if (s.type === 'text') {
                            return '<div class="pd-section">' + head + '<p class="pd-section-text">' + escHtml(s.content) + '</p></div>';
                        }
                        if (s.type === 'list') {
                            return '<div class="pd-section">' + head + (s.items || []).map(function(i){
                                return '<div class="pd-list-item"><i class="fa fa-check"></i> ' + escHtml(i) + '</div>';
                            }).join('') + '</div>';
                        }
                        if (s.type === 'info_grid') {
                            return '<div class="pd-section">' + head + (s.items || []).map(function(i){
                                return '<div class="pd-row"><i class="fa ' + (i.icon || 'fa-circle') + '"></i><div><strong>' + escHtml(i.label) + ':</strong> <span class="pd-val">' + escHtml(i.value) + '</span></div></div>';
                            }).join('') + '</div>';
                        }
                        return '';
                    }
                    document.getElementById('placeDetailsOverlay').addEventListener('click', function(e){
                        if (e.target === this) tmsClosePlaceDetails();
                    });

                    function loadCategory(cat, label){
                        document.getElementById('nearbyList').innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-muted);"><i class="fa fa-spinner fa-spin"></i> Loading ' + label + ' nearby…</div>';
                        clearMarkers();
                        fetch('api/explore.php?action=places&lat=' + lat + '&lng=' + lng + '&category=' + cat)
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                // api/explore.php wraps its response as
                                // {success, data:{places, source}, ts}.
                                // FIX (Round 14): a failed lookup used to be turned into an
                                // empty array here and then reported as "No hotels found
                                // nearby yet" — asserting a fact about the world when what
                                // actually happened was an upstream outage. Overpass
                                // rate-limits aggressively, so this fired regularly. The API
                                // already returns a real message; show it.
                                if (!res || !res.success) {
                                    document.getElementById('nearbyList').innerHTML =
                                        '<div style="text-align:center;padding:16px;color:#e11d48;">' +
                                        escHtml((res && res.error) || 'Nearby places are temporarily unavailable — please try again shortly.') +
                                        '</div>';
                                    return;
                                }
                                var results = (res.data && res.data.places) ? res.data.places : [];
                                if (!results.length) {
                                    document.getElementById('nearbyList').innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-muted);">No ' + label + ' found within a few kilometres of this destination.</div>';
                                    return;
                                }
                                var listEl = document.getElementById('nearbyList');
                                listEl.innerHTML = '';
                                results.slice(0, 8).forEach(function(p){
                                    // BUG FIX: distance_m can be null (e.g. a
                                    // Google Places result missing lat/lng —
                                    // see ExploreService::placesFromGoogle).
                                    // null / 1000 evaluates to 0 in JS, which
                                    // used to render a false "0.0 km" instead
                                    // of admitting the distance is unknown —
                                    // same guard openPlaceDetails() already
                                    // uses a few lines above for this exact field.
                                    var distKm = (p.distance_m || p.distance_m === 0) ? (p.distance_m / 1000).toFixed(1) + ' km' : 'Distance unknown';
                                    // FIX (Round 114, package/places/map upgrade):
                                    // ExploreService now attaches a real is_open_now
                                    // to each place in the list (Overpass opening_hours
                                    // parsed, or Google's live open_now flag) -- surface
                                    // it right on the compact list row too, not only
                                    // after opening the full detail modal, so a visitor
                                    // scanning "Hotels" or "Restaurants" nearby doesn't
                                    // have to open each one individually to see which
                                    // are actually open right now.
                                    var openBadge = p.is_open_now === true
                                        ? '<span class="pd-badge pd-badge-open" style="margin-top:3px;"><i class="fa fa-clock-o"></i> Open</span>'
                                        : (p.is_open_now === false ? '<span class="pd-badge pd-badge-closed" style="margin-top:3px;"><i class="fa fa-clock-o"></i> Closed</span>' : '');
                                    var row = document.createElement('div');
                                    row.className = 'nearby-item';
                                    row.innerHTML = '<div><div class="name">' + escHtml(p.name) + '</div>' +
                                        (p.address ? '<div class="addr">' + escHtml(p.address) + '</div>' : '') + openBadge + '</div>' +
                                        '<div class="dist">' + distKm + '</div>';
                                    row.addEventListener('click', function(){ openPlaceDetails(p, cat); });
                                    listEl.appendChild(row);
                                    // Round 14: L.marker() with a null lat/lng throws
                                    // "Invalid LatLng object", which aborted the rest of
                                    // the list render. Google Places results can legitimately
                                    // arrive without coordinates.
                                    if (typeof p.lat === 'number' && typeof p.lng === 'number') {
                                        var m = L.marker([p.lat, p.lng]).addTo(map).bindPopup(escHtml(p.name));
                                        m.on('click', function(){ openPlaceDetails(p, cat); });
                                        markers.push(m);
                                    }
                                });
                            })
                            .catch(function(){
                                document.getElementById('nearbyList').innerHTML = '<div style="text-align:center;padding:16px;color:#e11d48;">Nearby places service is temporarily unavailable.</div>';
                            });
                    }
                    document.querySelectorAll('.nearby-tab').forEach(function(btn){
                        btn.addEventListener('click', function(){
                            document.querySelectorAll('.nearby-tab').forEach(function(b){ b.classList.remove('active'); });
                            btn.classList.add('active');
                            loadCategory(btn.dataset.cat, btn.textContent.trim());
                        });
                    });
                    loadCategory('hotel', 'hotels');
                })();
                </script>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <div class="wow fadeInRight" style="position:sticky;top:20px;">
                    <div class="price-box">
                        <?php $seatsLeft = $pkgPricing['remaining_seats'] ?? null; ?>
                        <?php if ($seatsLeft !== null): ?>
                            <?php if ($seatsLeft <= 2): ?>
                            <div style="background:#fef2f2;color:#dc2626;font-size:12px;font-weight:700;padding:6px 12px;border-radius:8px;margin-bottom:10px;text-align:center;">
                                <i class="fa fa-exclamation-triangle"></i> Only <?php echo $seatsLeft; ?> seat<?php echo $seatsLeft===1?'':'s'; ?> left!
                            </div>
                            <?php elseif ($seatsLeft <= 8): ?>
                            <div style="background:#fffbeb;color:#b45309;font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;margin-bottom:10px;text-align:center;">
                                <i class="fa fa-users"></i> <?php echo $seatsLeft; ?> seats left — filling up
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="price" id="tmsLivePrice" data-bdt="<?php echo (float)($pkgPricing['final'] ?? $pkg->PackagePrice); ?>"><?php echo tms_currency((float)($pkgPricing['final'] ?? $pkg->PackagePrice)); ?></div>
                        <small><?php echo tms_t('lbl_per_person','per person'); ?></small>
                        <?php
                        // Currency Converter (reviewer ask #21) — live BDT rates,
                        // cached 12h via the same CacheService the weather feature
                        // uses. Free open.er-api.com endpoint, degrades to nothing
                        // shown (not a broken widget) if the API is unreachable.
                        $fx = tms_get_fx_rates($tmsCache);
                        if ($fx['ok']):
                            $fxCommon = ['USD','EUR','GBP','INR','AED','SAR','MYR','SGD','AUD','CAD'];
                            $fxCommon = array_values(array_intersect($fxCommon, array_keys($fx['rates'])));
                        ?>
                        <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0;">
                            <select id="tmsFxSelect" class="form-control" style="height:32px;font-size:12px;padding:2px 8px;display:inline-block;width:auto;">
                                <option value="">See price in…</option>
                                <?php foreach ($fxCommon as $cur): ?>
                                <option value="<?php echo htmlspecialchars($cur, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cur, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="tmsFxResult" style="font-size:12.5px;font-weight:700;color:var(--secondary);margin-left:6px;"></span>
                            <div style="font-size:9.5px;color:#c2c9d1;margin-top:3px;">Rates by <a href="https://www.exchangerate-api.com" target="_blank" rel="noopener" style="color:#c2c9d1;">exchangerate-api.com</a>, updated daily — estimate only, not the checkout rate.</div>
                        </div>
                        <script>
                        (function(){
                            var FX_RATES = <?php echo json_encode($fx['rates'], JSON_UNESCAPED_SLASHES); ?>;
                            var sel = document.getElementById('tmsFxSelect');
                            var out = document.getElementById('tmsFxResult');
                            var priceEl = document.getElementById('tmsLivePrice');
                            function render() {
                                var code = sel.value;
                                if (!code || !FX_RATES[code]) { out.textContent = ''; return; }
                                var bdt = parseFloat(priceEl.dataset.bdt || '0');
                                var converted = bdt * FX_RATES[code];
                                out.textContent = '≈ ' + converted.toLocaleString(undefined, {maximumFractionDigits: 2}) + ' ' + code;
                            }
                            sel.addEventListener('change', render);
                            // Re-render automatically if the dynamic price preview updates data-bdt
                            new MutationObserver(render).observe(priceEl, {attributes:true, attributeFilter:['data-bdt']});
                        })();
                        </script>
                        <?php endif; ?>
                        <?php if (!empty($pkgPricing) && $pkgPricing['label'] !== 'Standard pricing'): ?>
                        <div id="tmsPriceBadge" style="margin-top:6px;font-size:11.5px;font-weight:600;color:#f97316;background:#fff7ed;display:inline-block;padding:3px 10px;border-radius:20px;">
                            <i class="fa fa-bolt"></i> <?php echo htmlspecialchars($pkgPricing['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php else: ?>
                        <div id="tmsPriceBadge" style="margin-top:6px;font-size:11.5px;color:var(--text-muted);"></div>
                        <?php endif; ?>
                        <div style="margin-top:4px;font-size:10.5px;color:#b0b7c3;">Dynamic pricing — updates once you pick travel dates below.</div>
                        <?php if (count($priceHistory) >= 2):
                            $histPrices = array_column($priceHistory, 'price');
                            $histLow = min($histPrices);
                            $histHigh = max($histPrices);
                            $svgW = 220; $svgH = 36; $svgPad = 3;
                            $n = count($priceHistory);
                            $range = max(1, $histHigh - $histLow);
                            $svgPts = [];
                            foreach ($priceHistory as $i => $pt) {
                                $x = $svgPad + ($n > 1 ? ($i / ($n - 1)) : 0) * ($svgW - 2 * $svgPad);
                                $y = $svgH - $svgPad - (($pt['price'] - $histLow) / $range) * ($svgH - 2 * $svgPad);
                                $svgPts[] = round($x, 1) . ',' . round($y, 1);
                            }
                        ?>
                        <div style="margin-top:12px;padding-top:10px;border-top:1px dashed rgba(255,255,255,0.3);">
                            <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,0.85);margin-bottom:4px;"><i class="fa fa-line-chart"></i> Price history (<?php echo $n; ?> recorded day<?php echo $n === 1 ? '' : 's'; ?>)</div>
                            <svg width="<?php echo $svgW; ?>" height="<?php echo $svgH; ?>" viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>" style="display:block;max-width:100%;" role="img" aria-label="Recorded price trend over the last 90 days">
                                <polyline points="<?php echo htmlspecialchars(implode(' ', $svgPts), ENT_QUOTES, 'UTF-8'); ?>" fill="none" stroke="#ffffff" stroke-width="2"/>
                            </svg>
                            <div style="font-size:10.5px;color:rgba(255,255,255,0.7);margin-top:2px;">Low <?php echo tms_currency((float)$histLow); ?> · High <?php echo tms_currency((float)$histHigh); ?></div>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top:10px;">
                            <a href="alerts.php?pkgid=<?php echo (int)$pkg->PackageId; ?>" style="font-size:12px;font-weight:700;color:#fff;background:rgba(255,255,255,0.18);padding:5px 12px;border-radius:20px;text-decoration:none;display:inline-block;"><i class="fa fa-bell"></i> Set a price alert</a>
                        </div>
                    </div>

                    <?php require_once 'backend/services/TravelNoticeService.php'; $tnList = (!empty($pkg->Country)) ? TravelNoticeService::visible($dbh, (string)$pkg->Country) : []; if ($tnList): ?>
                    <div class="pkg-card" style="padding:18px;" role="note">
                        <h5 style="font-weight:700;margin:0 0 8px;"><i class="fa fa-info-circle" aria-hidden="true"></i> Travel notices for <?php echo htmlspecialchars((string)$pkg->Country, ENT_QUOTES, 'UTF-8'); ?></h5>
                        <?php foreach (array_slice($tnList, 0, 3) as $tn): ?><p style="font-size:13px;margin:0 0 6px;"><b><?php echo htmlspecialchars($tn['Title'], ENT_QUOTES, 'UTF-8'); ?></b> <span style="color:var(--text-muted)">(checked <?php echo htmlspecialchars($tn['VerifiedOn'], ENT_QUOTES, 'UTF-8'); ?><?php echo $tn['stale'] ? ', may be out of date' : ''; ?>)</span></p><?php endforeach; ?>
                        <a href="travel-notices.php?country=<?php echo urlencode((string)$pkg->Country); ?>" style="font-size:13px;text-decoration:underline;">See sources and details</a>
                    </div>
                    <?php endif; ?>
                    <div class="pkg-card" style="padding:24px;">
                        <h5 style="font-weight:700;margin-bottom:18px;"><i class="fa fa-calendar-check-o" style="color:var(--secondary)"></i> <?php echo tms_t('heading_book_package','Book This Package'); ?></h5>
                        <form method="post">
                            <?php tms_csrf_field(); ?>
                            <div class="form-group">
                                <label style="font-weight:600;font-size:13px;" for="datepicker">Departure Date</label>
                                <?php /* FIX (Round 14): these were bare text boxes with a
                                         "dd-mm-yyyy" placeholder and NO datepicker — js/jquery-ui.js
                                         ships in the repo but no page ever loads it, and nothing
                                         calls .datepicker(). Anyone typing 12/03/2026 or their own
                                         locale format lost the whole form to a validation error
                                         after a full round-trip. A native date input needs no
                                         library, gives a real calendar on desktop and mobile, and
                                         submits Y-m-d — which the handler above already accepts. */ ?>
                                <input type="date" name="fromdate" class="form-control date"
                                       id="datepicker" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="fld_todate" style="font-weight:600;font-size:13px;">Return Date</label>
                                <input id="fld_todate" type="date" name="todate" class="form-control date"
                                       id="datepicker1" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                            </div>
                            <script>
                            // Keep the return date at or after the departure date, so an
                            // impossible range can't be submitted at all (the server still
                            // validates it — this just stops the pointless round-trip).
                            document.addEventListener('DOMContentLoaded', function () {
                                var from = document.getElementById('datepicker');
                                var to   = document.getElementById('datepicker1');
                                if (!from || !to) return;
                                from.addEventListener('change', function () {
                                    if (!from.value) return;
                                    var next = new Date(from.value);
                                    next.setDate(next.getDate() + 1);
                                    to.min = next.toISOString().slice(0, 10);
                                    if (to.value && to.value <= from.value) to.value = to.min;
                                });
                            });
                            </script>
                            <div class="form-group">
                                <label style="font-weight:600;font-size:13px;" for="personsInput">Number of Travelers</label>
                                <input type="number" name="persons" id="personsInput" class="form-control" value="1" min="1" max="20" required>
                            </div>
                            <div class="form-group">
                                <label style="font-weight:600;font-size:13px;" for="fld_mobile">Mobile Number <small style="color:var(--text-muted);">(optional)</small></label>
                                <input type="tel" name="mobile" class="form-control" placeholder="e.g. 017XXXXXXXX" maxlength="20" id="fld_mobile">
                            </div>
                            <?php if (!empty($packageAddons)): ?>
                            <div class="form-group">
                                <label style="font-weight:600;font-size:13px;">Add-ons <small style="color:var(--text-muted);">(optional — works with either button below)</small></label>
                                <div style="border:1px solid #eee;border-radius:8px;padding:10px 12px;">
                                    <?php foreach ($packageAddons as $ao): ?>
                                    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;<?php echo $ao !== end($packageAddons) ? 'border-bottom:1px solid #f2f2f2;' : ''; ?>">
                                        <input type="checkbox" name="addons[]" value="<?php echo (int)$ao->AddOnId; ?>" id="addon-<?php echo (int)$ao->AddOnId; ?>">
                                        <label for="addon-<?php echo (int)$ao->AddOnId; ?>" style="flex:1;margin:0;font-weight:400;font-size:13.5px;cursor:pointer;">
                                            <?php echo htmlspecialchars($ao->Name, ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($ao->Description)): ?><br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($ao->Description, ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                                        </label>
                                        <span style="font-weight:600;font-size:13px;white-space:nowrap;"><?php echo tms_currency((float)$ao->Price); ?></span>
                                        <input type="number" name="addon_qty[<?php echo (int)$ao->AddOnId; ?>]" value="1" min="1" max="20" class="form-control" style="width:60px;padding:4px 6px;">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label style="font-weight:600;font-size:13px;" for="couponCode">Coupon Code <small style="color:var(--text-muted);">(optional)</small></label>
                                <div style="display:flex;gap:8px;">
                                    <input type="text" name="couponCode" id="couponCode" placeholder="e.g. WELCOME10" class="form-control" style="flex:1;">
                                    <button type="button" onclick="checkCoupon()" class="btn btn-info" style="white-space:nowrap;">Check</button>
                                </div>
                                <div id="couponResult" style="margin-top:5px;font-size:13px;"></div>
                            </div>
                            <div class="form-group">
                                <label style="font-weight:600;font-size:13px;" for="fld_comment">Special Requests</label>
                                <input type="text" name="comment" class="form-control"
                                       placeholder="Any special request (optional)" maxlength="500" id="fld_comment">
                            </div>

                            <?php if (!empty($_SESSION['login'])): ?>
                            <button type="submit" name="submit2" class="btn-book">
                                <i class="fa fa-check-circle"></i> <?php echo tms_t('btn_book_now','Book Now'); ?>
                            </button>
                            <button type="submit" name="addcart" class="btn-book-outline">
                                <i class="fa fa-cart-plus"></i> Add to Cart
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn-book"
                                    onclick="document.getElementById('myModal4').style.display='flex'"
                                    data-toggle="modal" data-target="#myModal4">
                                <i class="fa fa-lock"></i> Sign In to Book
                            </button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div style="display:flex;gap:10px;margin-top:14px;">
                        <a href="weather.php?dest=<?php echo (int)$pkg->PackageId; ?>" class="btn btn-default" style="flex:1;border:2px solid #eee;border-radius:8px;font-size:13px;padding:10px;text-align:center;">
                            <i class="fa fa-cloud" style="color:#1e3c72"></i> Weather Here
                        </a>
                        <?php if(!empty($pkg->MapLat) && !empty($pkg->MapLng)): ?>
                        <a href="tour-map.php?focus=<?php echo (int)$pkg->PackageId; ?>" class="btn btn-default" style="flex:1;border:2px solid #eee;border-radius:8px;font-size:13px;padding:10px;text-align:center;">
                            <i class="fa fa-map-marker" style="color:var(--primary)"></i> View on Map
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
            <div style="text-align:center;padding:60px 20px;">
                <i class="fa fa-exclamation-triangle" style="font-size:60px;color:#ddd;display:block;margin-bottom:20px;"></i>
                <h3 style="color:var(--text-muted);">Package not found</h3>
                <a href="package-list.php" class="btn btn-primary" style="margin-top:10px;">Browse All Packages</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Recently Viewed strip — logged-in users only (see tms_track_recently_viewed),
// excludes the package currently being viewed.
if (!empty($_SESSION['login']) && $pkg) {
    $rvQ = $dbh->prepare(
        "SELECT p.PackageId, p.PackageName, p.PackagePrice, p.PackageImage, p.PackageLocation
         FROM tblrecentlyviewed r JOIN tbltourpackages p ON p.PackageId = r.PackageId
         WHERE r.UserEmail = :e AND r.PackageId != :cur AND p.Status = 1
         ORDER BY r.ViewedAt DESC LIMIT 6"
    );
    $rvQ->execute([':e' => $_SESSION['login'], ':cur' => (int)$pkg->PackageId]);
    $recentlyViewed = $rvQ->fetchAll(PDO::FETCH_OBJ);
    if ($recentlyViewed) {
        ?>
        <div class="container" style="padding:30px 15px 40px;">
            <h4 style="font-weight:700;margin-bottom:16px;"><i class="fa fa-history" style="color:var(--secondary)"></i> Recently Viewed</h4>
            <div style="display:flex;gap:16px;overflow-x:auto;padding-bottom:8px;">
                <?php foreach ($recentlyViewed as $rv): ?>
                <a href="package-details.php?pkgid=<?php echo (int)$rv->PackageId; ?>"
                   style="flex:0 0 200px;background:var(--white);border-radius:var(--radius-sm);box-shadow:var(--shadow-sm);overflow:hidden;text-decoration:none;color:inherit;transition:var(--transition);"
                   onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='var(--shadow-md)';"
                   onmouseout="this.style.transform='none';this.style.boxShadow='var(--shadow-sm)';">
                    <img loading="lazy" src="<?php echo htmlspecialchars(tms_package_image_src($rv->PackageImage), ENT_QUOTES, 'UTF-8'); ?>"
                         alt="" style="width:100%;height:110px;object-fit:cover;">
                    <div style="padding:10px 12px;">
                        <div style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($rv->PackageName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div style="font-size:11.5px;color:var(--text-muted);margin:2px 0 6px;"><?php echo htmlspecialchars($rv->PackageLocation ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        <div style="font-family:var(--font-mono);font-weight:700;color:var(--secondary);font-size:13px;"><?php echo tms_currency((float)$rv->PackagePrice); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
?>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
<?php include('backend/signin.php'); ?>

<script>
function checkCoupon() {
    var code = document.getElementById('couponCode').value.trim();
    if (!code) { document.getElementById('couponResult').innerHTML = '<span style="color:var(--danger)">Enter a coupon code first.</span>'; return; }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'coupon-check.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        var res = JSON.parse(xhr.responseText);
        var el = document.getElementById('couponResult');
        if (res.valid) {
            el.innerHTML = '<span style="color:var(--success);font-weight:700;"><i class="fa fa-check"></i> ' + res.msg + '</span>';
        } else {
            el.innerHTML = '<span style="color:var(--danger);"><i class="fa fa-times"></i> ' + res.msg + '</span>';
        }
    };
    xhr.send('code=' + encodeURIComponent(code));
}

// Live dynamic-price preview (surge/early-bird pricing): re-fetches the
// real price — via the exact same tms_dynamic_price() function the actual
// booking handler uses — whenever the visitor changes dates or traveler
// count, so the number on screen always matches what they'll be charged.
(function () {
    var pkgId = <?php echo (int)($pkg->PackageId ?? 0); ?>;
    if (!pkgId) return;
    var fromEl = document.getElementById('datepicker');
    var personsEl = document.getElementById('personsInput');
    var priceEl = document.getElementById('tmsLivePrice');
    var badgeEl = document.getElementById('tmsPriceBadge');
    if (!fromEl || !priceEl) return;

    var debounceTimer = null;
    function refreshPrice() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'price-preview.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (!res.success) return;
                    priceEl.textContent = res.formatted_unit;
                    priceEl.dataset.bdt = res.unit_price;
                    if (badgeEl) {
                        // TMS V2 Phase 8 (pricing transparency): the badge
                        // itself still just shows the collapsed label,
                        // exactly as before -- this only adds a `title`
                        // tooltip with the itemized breakdown when the
                        // server sent one, so hovering explains WHY the
                        // price moved. Additive only: nothing here changes
                        // for a response with no 'breakdown' key (older
                        // cached JS, or a non-adjusted price).
                        badgeEl.innerHTML = res.is_adjusted
                            ? '<i class="fa fa-bolt"></i> ' + res.label
                            : '';
                        badgeEl.style.cssText = res.is_adjusted
                            ? 'margin-top:6px;font-size:11.5px;font-weight:600;color:#f97316;background:#fff7ed;display:inline-block;padding:3px 10px;border-radius:20px;cursor:help;'
                            : 'margin-top:6px;font-size:11.5px;color:var(--text-muted);';
                        if (res.is_adjusted && Array.isArray(res.breakdown) && res.breakdown.length) {
                            badgeEl.title = res.breakdown.map(function (b) {
                                return b.label + ': +' + b.pct + '%';
                            }).join('\n');
                        } else {
                            badgeEl.removeAttribute('title');
                        }
                    }
                } catch (e) { /* leave last known price on screen */ }
            };
            var persons = personsEl ? (personsEl.value || 1) : 1;
            xhr.send('pkgid=' + pkgId + '&fromdate=' + encodeURIComponent(fromEl.value || '') + '&persons=' + encodeURIComponent(persons));
        }, 350);
    }

    fromEl.addEventListener('change', refreshPrice);
    fromEl.addEventListener('blur', refreshPrice);
    if (personsEl) personsEl.addEventListener('change', refreshPrice);
    if (personsEl) personsEl.addEventListener('input', refreshPrice);
})();

// Live crowd insights (Phase 2e) — deferred, non-blocking fetch so this
// never slows down the initial page render for the AI/DB round-trip.
(function loadCrowdInsights() {
    var box = document.getElementById('crowdInsightsBox');
    if (!box) return; // package has no PackageMeta block, widget wasn't rendered
    var destination = <?php echo json_encode($pkg->PackageLocation, JSON_UNESCAPED_UNICODE); ?>;
    if (!destination) return;

    var body = new URLSearchParams({ action: 'crowd_prediction', destination: destination });
    fetch('api/ai.php?action=crowd_prediction', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data || !res.data.prediction) return;
            var p = res.data.prediction;
            var html = '<i class="fa fa-users" style="color:var(--secondary);"></i> <strong>Crowd Insight';
            html += (p.data_source === 'historical_bookings') ? ' (based on ' + p.total_bookings_analysed + ' real bookings)' : ' (general estimate)';
            html += ':</strong> ' + p.ai_narrative;
            if (p.busiest_months && p.busiest_months.length) {
                html += '<br><span style="font-size:12px;color:var(--text-muted);">Busiest: ' + p.busiest_months.join(', ') + ' &middot; Quietest: ' + p.quietest_months.join(', ') + '</span>';
            }
            box.innerHTML = html;
            box.style.display = 'block';
        })
        .catch(function() { /* silent — this is a bonus insight, not core functionality */ });
})();

// Sustainability / carbon footprint (Phase 2i) — deferred, non-blocking.
(function loadSustainability() {
    var box = document.getElementById('sustainabilityBox');
    if (!box) return;
    var destination = <?php echo json_encode($pkg->PackageLocation, JSON_UNESCAPED_UNICODE); ?>;
    if (!destination) return;

    var body = new URLSearchParams({ action: 'sustainability', destination: destination, travelers: '1' });
    fetch('api/ai.php?action=sustainability', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data || !res.data.footprint || !res.data.footprint.has_data) return;
            var f = res.data.footprint;
            var lowest = f.by_mode[f.lowest_footprint_mode];
            var html = '<i class="fa fa-leaf" style="color:' + f.score.color + ';"></i> '
                + '<strong>Sustainability Grade ' + f.score.grade + '</strong> (' + f.score.label + ') &mdash; '
                + f.round_trip_km + ' km round trip from Dhaka.';
            if (f.lowest_footprint_mode) {
                html += '<br><span style="font-size:12px;">Lowest footprint option: <strong>' + f.lowest_footprint_mode + '</strong> (~' + lowest.kg_co2e_per_person + ' kg CO&#8322;e per person).';
                if (f.lowest_footprint_mode !== 'flight') {
                    html += ' Flying would emit more for this distance &mdash; consider ' + f.lowest_footprint_mode + ' where practical.';
                } else {
                    html += ' At this distance, ground transport isn\'t realistic, so flying is the practical option.';
                }
                html += '</span>';
            }
            if (f.offset_estimate) {
                var o = f.offset_estimate;
                html += '<div style="margin-top:8px;padding-top:8px;border-top:1px dashed #bbf7d0;font-size:12px;">'
                    + '<i class="fa fa-tree"></i> Offsetting the flight\'s ' + o.tonnes_co2e + ' tonnes CO&#8322;e costs roughly <strong>$' + o.est_cost_usd_low + '&ndash;$' + o.est_cost_usd_high + '</strong> '
                    + '(&asymp; ' + o.trees_equivalent + ' trees\' worth of annual absorption) through a verified registry like Gold Standard or Verra. '
                    + '<span style="color:#65a30d;">' + o.note + '</span></div>';
            }
            box.innerHTML = html;
            box.style.display = 'block';
        })
        .catch(function() { /* silent — bonus insight */ });
})();
</script>
</body>
</html>

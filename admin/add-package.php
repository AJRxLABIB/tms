<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['package_manager', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Add Package</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<?php

$error   = '';
$success = '';

if (isset($_POST['submit'])) {
    tms_csrf_validate();

    $name      = tms_clean($_POST['packagename']     ?? '');
    $type      = tms_clean($_POST['packagetype']     ?? '');
    $location  = tms_clean($_POST['packagelocation'] ?? '');
    $price     = tms_int($_POST['packageprice']      ?? 0, 0, 9999999);
    $features  = tms_clean($_POST['packagefeatures'] ?? '');
    $details   = tms_clean($_POST['packagedetails']  ?? '');
    $image     = '';

    if (empty($name) || empty($type) || empty($location) || $price <= 0) {
        $error = 'Name, type, location and price are required.';
    } else {
        // Image upload
        if (!empty($_FILES['packageimage']['size'])) {
            $allowed = ['jpg','jpeg','png','gif','webp'];
            $ext     = strtolower(pathinfo($_FILES['packageimage']['name'], PATHINFO_EXTENSION));
            $maxSize = 5 * 1024 * 1024;

            $sigCheck = ['ok' => true, 'error' => null];
            if (in_array($ext, $allowed)) {
                $sigCheck = tms_validate_upload_signature($_FILES['packageimage']['tmp_name'], $allowed);
            }

            if (!in_array($ext, $allowed)) {
                $error = 'Only JPG, JPEG, PNG, GIF, WEBP images allowed.';
            } elseif ($_FILES['packageimage']['size'] > $maxSize) {
                $error = 'Image must be under 5 MB.';
            } elseif (!$sigCheck['ok']) {
                $error = $sigCheck['error'];
            } else {
                $uploadDir = 'pacakgeimages/'; // keep existing folder name
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                move_uploaded_file($_FILES['packageimage']['tmp_name'], $uploadDir . $filename);
                tms_compress_uploaded_image($uploadDir . $filename);
                $image = $filename;
            }
        }

        if (!$error) {
            $packageduration = tms_clean($_POST['packageduration'] ?? '');
            $maplat = ($_POST['maplat'] ?? '') !== '' ? (float)$_POST['maplat'] : null;
            $maplng = ($_POST['maplng'] ?? '') !== '' ? (float)$_POST['maplng'] : null;
            $hotelname = tms_clean($_POST['hotelname'] ?? '');
            $hotelrating = ($_POST['hotelrating'] ?? '') !== '' ? (float)$_POST['hotelrating'] : null;
            $mealplan = tms_clean($_POST['mealplan'] ?? '');
            $transportmode = tms_clean($_POST['transportmode'] ?? '');
            $seatsavailable = ($_POST['seatsavailable'] ?? '') !== '' ? (int)$_POST['seatsavailable'] : null;
            // Round 87 (item 7): structured Inclusions/Exclusions/Cancellation
            // Policy fields — one item per line, stored as plain newline-separated
            // text in new MEDIUMTEXT columns (see backend/config.php).
            $inclusions          = tms_clean($_POST['inclusions']          ?? '');
            $exclusions          = tms_clean($_POST['exclusions']          ?? '');
            $cancellationpolicy  = tms_clean($_POST['cancellationpolicy']  ?? '');
            $sql = "INSERT INTO tbltourpackages
                (PackageName, PackageType, PackageLocation, PackagePrice, PackageFetures, PackageDetails, PackageImage, PackageDuration, MapLat, MapLng, HotelName, HotelRating, MealPlan, TransportMode, SeatsAvailable, Inclusions, Exclusions, CancellationPolicy)
                VALUES (:n,:t,:l,:p,:f,:d,:i,:dur,:lat,:lng,:hotelname,:hotelrating,:mealplan,:transportmode,:seatsavailable,:inclusions,:exclusions,:cancellationpolicy)";
            $q = $dbh->prepare($sql);
            $q->bindValue(':n',$name); $q->bindValue(':t',$type); $q->bindValue(':l',$location);
            $q->bindValue(':p',$price); $q->bindValue(':f',$features); $q->bindValue(':d',$details);
            $q->bindValue(':i',$image); $q->bindValue(':dur',$packageduration);
            $q->bindValue(':lat',$maplat, $maplat === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $q->bindValue(':lng',$maplng, $maplng === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $q->bindValue(':hotelname',$hotelname);
            $q->bindValue(':hotelrating',$hotelrating, $hotelrating === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $q->bindValue(':mealplan',$mealplan);
            $q->bindValue(':transportmode',$transportmode);
            $q->bindValue(':seatsavailable',$seatsavailable, $seatsavailable === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $q->bindValue(':inclusions',$inclusions);
            $q->bindValue(':exclusions',$exclusions);
            $q->bindValue(':cancellationpolicy',$cancellationpolicy);
            $q->execute();
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Package Created', "Name: \"$name\", Location: \"$location\", Price: $price");
            $success = 'Package "' . htmlspecialchars($name,ENT_QUOTES,'UTF-8') . '" added successfully!';
        }
    }
}
?>






    <div class="form-section">
        <h4 style="font-size:1.05em;font-weight:700;border-bottom:2px solid #f0f0f0;padding-bottom:10px;margin-bottom:22px;">
            <i class="fa fa-plus-circle" style="color:#5cb85c"></i> Add New Tour Package
        </h4>

        <?php if ($error):   ?><div class="alert-v6 danger"><?php   echo htmlspecialchars($error,  ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert-v6 success"><?php  echo htmlspecialchars($success,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php tms_csrf_field(); ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgNameField">Package Name *</label>
                        <input type="text" id="pkgNameField" name="packagename" class="form-control" required maxlength="200"
                               value="<?php echo htmlspecialchars($_POST['packagename']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgTypeField">Package Type *</label>
                        <select id="pkgTypeField" name="packagetype" class="form-control" required>
                            <option value="">-- Select Type --</option>
                            <?php $types=['Family Package','Couple Package','Group Package','Domestic Packages','International Package','Adventure Package'];
                            foreach ($types as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo (($_POST['packagetype']??'')===$t)?'selected':''; ?>><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgLocationField">Package Location *</label>
                        <input type="text" id="pkgLocationField" name="packagelocation" class="form-control" required maxlength="200"
                               value="<?php echo htmlspecialchars($_POST['packagelocation']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="packagePriceInput">Package Price (৳) *</label>
                        <div style="display:flex;gap:8px;">
                            <input type="number" name="packageprice" id="packagePriceInput" class="form-control" required min="1"
                                   value="<?php echo (int)($_POST['packageprice']??0) ?: ''; ?>">
                            <button type="button" id="suggestPriceBtn" class="btn btn-info" style="white-space:nowrap;"
                                    title="Suggest a price based on comparable packages already on this platform">
                                <i class="fa fa-magic"></i> Suggest
                            </button>
                        </div>
                        <div id="priceSuggestionBox" style="display:none;margin-top:8px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12.5px;color:#1e40af;"></div>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label for="pkgFeaturesField">Features</label>
                        <input type="text" id="pkgFeaturesField" name="packagefeatures" class="form-control" maxlength="500"
                               placeholder="e.g. Hotel, Meals, Transport"
                               value="<?php echo htmlspecialchars($_POST['packagefeatures']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgDurationField">Duration <small style="color:#aaa;">(e.g. "5 Days 4 Nights")</small></label>
                        <input type="text" id="pkgDurationField" name="packageduration" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['packageduration']??'',ENT_QUOTES,'UTF-8'); ?>" placeholder="5 Days 4 Nights">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label id="pkgMapCoordsGroupLabel">Map Coordinates <small style="color:#aaa;">(powers the interactive map &amp; nearby-places widget — optional)</small></label>
                        <div style="display:flex;gap:8px;" role="group" aria-labelledby="pkgMapCoordsGroupLabel">
                            <input type="number" step="any" name="maplat" class="form-control" aria-label="Latitude" placeholder="Latitude" value="<?php echo htmlspecialchars($_POST['maplat']??'',ENT_QUOTES,'UTF-8'); ?>">
                            <input type="number" step="any" name="maplng" class="form-control" aria-label="Longitude" placeholder="Longitude" value="<?php echo htmlspecialchars($_POST['maplng']??'',ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgHotelNameField">Hotel Name <small style="color:#aaa;">(shown in package comparison)</small></label>
                        <input type="text" id="pkgHotelNameField" name="hotelname" class="form-control" placeholder="e.g. Sea Pearl Beach Resort"
                               value="<?php echo htmlspecialchars($_POST['hotelname']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgHotelRatingField">Hotel Rating <small style="color:#aaa;">(1&ndash;5 stars, optional)</small></label>
                        <input type="number" step="0.5" min="1" max="5" id="pkgHotelRatingField" name="hotelrating" class="form-control" placeholder="4.5"
                               value="<?php echo htmlspecialchars($_POST['hotelrating']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgMealPlanField">Meal Plan</label>
                        <input type="text" id="pkgMealPlanField" name="mealplan" class="form-control" placeholder="e.g. Breakfast Included"
                               value="<?php echo htmlspecialchars($_POST['mealplan']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgTransportModeField">Transport Mode</label>
                        <input type="text" id="pkgTransportModeField" name="transportmode" class="form-control" placeholder="e.g. AC Bus, Flight"
                               value="<?php echo htmlspecialchars($_POST['transportmode']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgSeatsField">Seats Available <small style="color:#aaa;">(leave blank = unlimited/not tracked)</small></label>
                        <input type="number" min="0" id="pkgSeatsField" name="seatsavailable" class="form-control" placeholder="e.g. 30"
                               value="<?php echo htmlspecialchars($_POST['seatsavailable']??'',ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label for="pkgDetailsField">Package Details</label>
                        <textarea id="pkgDetailsField" name="packagedetails" class="form-control" rows="5"
                                  placeholder="Full description of the package…"><?php echo htmlspecialchars($_POST['packagedetails']??'',ENT_QUOTES,'UTF-8'); ?></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgInclusionsField">Inclusions <small style="color:#aaa;">(one item per line, e.g. "Hotel accommodation")</small></label>
                        <textarea id="pkgInclusionsField" name="inclusions" class="form-control" rows="4"
                                  placeholder="Hotel accommodation&#10;Daily breakfast&#10;Airport transfers"><?php echo htmlspecialchars($_POST['inclusions']??'',ENT_QUOTES,'UTF-8'); ?></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pkgExclusionsField">Exclusions <small style="color:#aaa;">(one item per line, e.g. "International airfare")</small></label>
                        <textarea id="pkgExclusionsField" name="exclusions" class="form-control" rows="4"
                                  placeholder="International airfare&#10;Travel insurance&#10;Personal expenses"><?php echo htmlspecialchars($_POST['exclusions']??'',ENT_QUOTES,'UTF-8'); ?></textarea>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label for="pkgCancellationPolicyField">Cancellation Policy <small style="color:#aaa;">(one rule per line, shown to travelers before booking)</small></label>
                        <textarea id="pkgCancellationPolicyField" name="cancellationpolicy" class="form-control" rows="4"
                                  placeholder="Free cancellation up to 7 days before departure&#10;50% refund within 3-7 days&#10;No refund within 48 hours"><?php echo htmlspecialchars($_POST['cancellationpolicy']??'',ENT_QUOTES,'UTF-8'); ?></textarea>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label for="pkgImageField">Package Image <small style="color:#aaa;">(JPG/PNG/GIF/WEBP, max 5 MB)</small></label>
                        <input type="file" id="pkgImageField" name="packageimage" class="form-control" accept="image/*"
                               onchange="previewImg(this)">
                        <img id="imgPreview" class="img-preview" alt="Preview">
                    </div>
                </div>
            </div>
            <button type="submit" name="submit" class="btn-v6 success">
                <i class="fa fa-save"></i> Add Package
            </button>
            <a href="manage-packages.php" class="btn-v6 ghost" style="margin-left:8px;">Cancel</a>
        </form>
    </div>
</div>

</div></div>

</div>



<script>
function previewImg(input) {
    var preview = document.getElementById('imgPreview');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) { preview.src = e.target.result; preview.style.display='block'; };
        reader.readAsDataURL(input.files[0]);
    }
}

// AI price suggestion — grounded in real comparable-package data already on
// this platform (see BudgetOptimizer::predictPackagePrice), not a raw AI guess.
document.getElementById('suggestPriceBtn').addEventListener('click', function() {
    var btn = this;
    var location = document.querySelector('[name="packagelocation"]').value.trim();
    var type = document.querySelector('[name="packagetype"]').value;
    var box = document.getElementById('priceSuggestionBox');

    if (!location && !type) {
        box.style.display = 'block';
        box.innerHTML = 'Enter a location or select a type first.';
        return;
    }

    btn.disabled = true;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Analysing…';
    box.style.display = 'block';
    box.innerHTML = 'Comparing against similar packages…';

    var body = new URLSearchParams({ action: 'suggest_price', location: location, type: type });
    fetch('../api/ai.php?action=suggest_price', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (!res.success || !res.data || !res.data.prediction || !res.data.prediction.has_data) {
                box.innerHTML = (res.data && res.data.prediction && res.data.prediction.message) || 'No comparable packages found yet — you\'ll need to set the price based on judgment for this one.';
                return;
            }
            var p = res.data.prediction;
            var html = '<strong>' + p.comparable_count + ' comparable package(s)</strong> (matched by ' + p.match_basis + '): '
                + '৳' + p.min_price + ' – ৳' + p.max_price + ' (median ৳' + p.median_price + ', avg ৳' + p.avg_price + ').';
            if (p.ai_verdict) html += '<br><em>' + p.ai_verdict + '</em>';
            html += '<br><button type="button" class="btn btn-xs btn-primary" style="margin-top:6px;" onclick="document.getElementById(\'packagePriceInput\').value=' + p.suggested_price + '">Use ৳' + p.suggested_price + '</button>';
            box.innerHTML = html;
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            box.innerHTML = 'Could not fetch a suggestion right now — you can still enter a price manually.';
        });
});
</script>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

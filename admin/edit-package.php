<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Edit Package</title>
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
tms_require_admin(); // SECURITY FIX (Round 11): old check had no exit/else, so an unauthenticated POST could edit any package
tms_admin_require_role($dbh, ['package_manager', 'manager']); // Round 20 RBAC (item 46)

$error = '';
$success = '';
$pid = intval($_GET['pid'] ?? 0);

// Fetch existing package
$sql = "SELECT * FROM tbltourpackages WHERE PackageId=:pid";
$query = $dbh->prepare($sql);
$query->bindParam(':pid', $pid, PDO::PARAM_INT);
$query->execute();
$package = $query->fetch(PDO::FETCH_OBJ);
if(!$package) { echo "<script>alert('Package not found.'); document.location='manage-packages.php';</script>"; exit; }

if(isset($_POST['submit'])) {
    tms_csrf_validate();
    $packagename = $_POST['packagename'];
    $packagetype = $_POST['packagetype'];
    $packagelocation = $_POST['packagelocation'];
    $packageprice = $_POST['packageprice'];
    $packagefeatures = $_POST['packagefeatures'];
    $packagedetails = $_POST['packagedetails'];
    $packageduration = tms_clean($_POST['packageduration'] ?? '');
    $maplat = ($_POST['maplat'] ?? '') !== '' ? (float)$_POST['maplat'] : null;
    $maplng = ($_POST['maplng'] ?? '') !== '' ? (float)$_POST['maplng'] : null;
    $hotelname = tms_clean($_POST['hotelname'] ?? '');
    $hotelrating = ($_POST['hotelrating'] ?? '') !== '' ? (float)$_POST['hotelrating'] : null;
    $mealplan = tms_clean($_POST['mealplan'] ?? '');
    $transportmode = tms_clean($_POST['transportmode'] ?? '');
    $seatsavailable = ($_POST['seatsavailable'] ?? '') !== '' ? (int)$_POST['seatsavailable'] : null;
    // Round 87 (item 7): structured Inclusions/Exclusions/Cancellation Policy.
    $inclusions         = tms_clean($_POST['inclusions']         ?? '');
    $exclusions         = tms_clean($_POST['exclusions']         ?? '');
    $cancellationpolicy = tms_clean($_POST['cancellationpolicy'] ?? '');
    $packageimage = $package->PackageImage;

    if(isset($_FILES['packageimage']) && $_FILES['packageimage']['size'] > 0) {
        $allowed = array('jpg','jpeg','png','gif');
        $ext = strtolower(pathinfo($_FILES['packageimage']['name'], PATHINFO_EXTENSION));
        $maxSize = 5 * 1024 * 1024;
        // FILE UPLOAD SECURITY (master prompt item 45): this endpoint used
        // to trust the extension alone and keep the caller's original
        // filename (time() . '_' . basename(...)) with no size cap at all
        // -- the one upload path in this project still missing both the
        // real content-type/signature check every other image upload has,
        // and a fully randomized filename. Brought in line with
        // add-package.php/change-image.php's pattern.
        $sigCheck = ['ok' => true, 'error' => null];
        if (in_array($ext, $allowed) && $_FILES['packageimage']['size'] <= $maxSize) {
            $sigCheck = tms_validate_upload_signature($_FILES['packageimage']['tmp_name'], $allowed);
        }

        if (!in_array($ext, $allowed)) {
            $error = 'Only JPG, JPEG, PNG, GIF files are allowed.';
        } elseif ($_FILES['packageimage']['size'] > $maxSize) {
            $error = 'Image must be under 5 MB.';
        } elseif (!$sigCheck['ok']) {
            $error = $sigCheck['error'];
        } else {
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploaddir = 'pacakgeimages/'; // keep existing folder name (matches add-package.php / change-image.php and tms_package_image_src())
            if(!is_dir($uploaddir)) { mkdir($uploaddir, 0755, true); }
            move_uploaded_file($_FILES['packageimage']['tmp_name'], $uploaddir . $filename);
            tms_compress_uploaded_image($uploaddir . $filename);
            $packageimage = $filename;
        }
    }

    if(empty($error)) {
        $sql2 = "UPDATE tbltourpackages SET PackageName=:pname, PackageType=:ptype, PackageLocation=:ploc, PackagePrice=:pprice, PackageFetures=:pfeatures, PackageDetails=:pdetails, PackageImage=:pimage, PackageDuration=:pduration, MapLat=:maplat, MapLng=:maplng, HotelName=:hotelname, HotelRating=:hotelrating, MealPlan=:mealplan, TransportMode=:transportmode, SeatsAvailable=:seatsavailable, Inclusions=:inclusions, Exclusions=:exclusions, CancellationPolicy=:cancellationpolicy WHERE PackageId=:pid";
        $query2 = $dbh->prepare($sql2);
        $query2->bindParam(':pname', $packagename, PDO::PARAM_STR);
        $query2->bindParam(':ptype', $packagetype, PDO::PARAM_STR);
        $query2->bindParam(':ploc', $packagelocation, PDO::PARAM_STR);
        $query2->bindParam(':pprice', $packageprice, PDO::PARAM_INT);
        $query2->bindParam(':pfeatures', $packagefeatures, PDO::PARAM_STR);
        $query2->bindParam(':pdetails', $packagedetails, PDO::PARAM_STR);
        $query2->bindParam(':pimage', $packageimage, PDO::PARAM_STR);
        $query2->bindParam(':pduration', $packageduration, PDO::PARAM_STR);
        $query2->bindValue(':maplat', $maplat, $maplat === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query2->bindValue(':maplng', $maplng, $maplng === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query2->bindParam(':hotelname', $hotelname, PDO::PARAM_STR);
        $query2->bindValue(':hotelrating', $hotelrating, $hotelrating === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query2->bindParam(':mealplan', $mealplan, PDO::PARAM_STR);
        $query2->bindParam(':transportmode', $transportmode, PDO::PARAM_STR);
        $query2->bindValue(':seatsavailable', $seatsavailable, $seatsavailable === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query2->bindParam(':inclusions', $inclusions, PDO::PARAM_STR);
        $query2->bindParam(':exclusions', $exclusions, PDO::PARAM_STR);
        $query2->bindParam(':cancellationpolicy', $cancellationpolicy, PDO::PARAM_STR);
        $query2->bindParam(':pid', $pid, PDO::PARAM_INT);
        $query2->execute();
        // Audit log coverage (Round 52). Round 96 (item 47): $package still
        // holds the PRE-update row here (the refresh re-fetch happens a few
        // lines below) -- the price can change on any edit but was never
        // logged either way before this.
        tms_log_activity($dbh, $_SESSION['alogin'], 'Package Edited', "PackageId=$pid, Name: \"$packagename\"", 'package', $pid, (string)$package->PackagePrice, (string)$packageprice);
        $success = 'Package updated successfully!';
        // Refresh package data
        $query->execute();
        $package = $query->fetch(PDO::FETCH_OBJ);
    }
}
?>



    



    <div class="table-agile-info">
        <div class="card">
            <div class="card-header"><h3>Edit Tour Package</h3></div>
            <div class="card-body">
                <?php if($error): ?><div class="alert-v6 danger"><?php echo $error; ?></div><?php endif; ?>
                <?php if($success): ?><div class="alert-v6 success"><?php echo $success; ?></div><?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                <?php tms_csrf_field(); ?>
                    <div class="form-group">
                        <label for="pkgNameField">Package Name *</label>
                        <input type="text" id="pkgNameField" name="packagename" class="form-control" value="<?php echo htmlentities($package->PackageName); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="pkgTypeField">Package Type *</label>
                        <select id="pkgTypeField" name="packagetype" class="form-control" required>
                            <?php $types = ['Family Package','Couple Package','Group Package','Domestic Packages','International Package'];
                            foreach($types as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo ($package->PackageType==$t)?'selected':''; ?>><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pkgLocationField">Package Location *</label>
                        <input type="text" id="pkgLocationField" name="packagelocation" class="form-control" value="<?php echo htmlentities($package->PackageLocation); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="packagePriceInput">Package Price (৳) *</label>
                        <div style="display:flex;gap:8px;">
                            <input type="number" name="packageprice" id="packagePriceInput" class="form-control" value="<?php echo htmlentities($package->PackagePrice); ?>" required>
                            <button type="button" id="suggestPriceBtn" class="btn btn-info" style="white-space:nowrap;"
                                    title="Suggest a price based on comparable packages already on this platform">
                                <i class="fa fa-magic"></i> Suggest
                            </button>
                        </div>
                        <div id="priceSuggestionBox" style="display:none;margin-top:8px;padding:10px 12px;background:#EEF7F1;border:1px solid #B8E4C9;border-radius:8px;font-size:12.5px;color:#104B3A;"></div>
                    </div>
                    <div class="form-group">
                        <label for="pkgFeaturesField">Package Features</label>
                        <textarea id="pkgFeaturesField" name="packagefeatures" class="form-control" rows="3"><?php echo htmlentities($package->PackageFetures); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="pkgDetailsField">Package Details</label>
                        <textarea id="pkgDetailsField" name="packagedetails" class="form-control" rows="5"><?php echo htmlentities($package->PackageDetails); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="pkgDurationField">Duration <small style="font-weight:400;color:var(--text-muted);">(e.g. "5 Days 4 Nights")</small></label>
                        <input type="text" id="pkgDurationField" name="packageduration" class="form-control" value="<?php echo htmlentities($package->PackageDuration ?? ''); ?>" placeholder="5 Days 4 Nights">
                    </div>
                    <div class="form-group">
                        <label id="pkgMapCoordsGroupLabel">Map Coordinates <small style="font-weight:400;color:var(--text-muted);">(powers the interactive map, nearby-places widget, and price comparison — leave blank if unknown)</small></label>
                        <div style="display:flex;gap:8px;" role="group" aria-labelledby="pkgMapCoordsGroupLabel">
                            <input type="number" step="any" name="maplat" class="form-control" aria-label="Latitude" placeholder="Latitude" value="<?php echo htmlentities($package->MapLat ?? ''); ?>">
                            <input type="number" step="any" name="maplng" class="form-control" aria-label="Longitude" placeholder="Longitude" value="<?php echo htmlentities($package->MapLng ?? ''); ?>">
                        </div>
                        <p><small><a href="https://www.latlong.net/" target="_blank" rel="noopener">Look up coordinates for this destination &rarr;</a></small></p>
                    </div>
                    <div class="form-group">
                        <label for="pkgHotelNameField">Hotel Name <small style="font-weight:400;color:var(--text-muted);">(shown in package comparison)</small></label>
                        <input type="text" id="pkgHotelNameField" name="hotelname" class="form-control" value="<?php echo htmlentities($package->HotelName ?? ''); ?>" placeholder="e.g. Sea Pearl Beach Resort">
                    </div>
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:1;">
                            <label for="pkgHotelRatingField">Hotel Rating <small style="font-weight:400;color:var(--text-muted);">(1&ndash;5 stars)</small></label>
                            <input type="number" step="0.5" min="1" max="5" id="pkgHotelRatingField" name="hotelrating" class="form-control" value="<?php echo htmlentities($package->HotelRating ?? ''); ?>" placeholder="4.5">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label for="pkgMealPlanField">Meal Plan</label>
                            <input type="text" id="pkgMealPlanField" name="mealplan" class="form-control" value="<?php echo htmlentities($package->MealPlan ?? ''); ?>" placeholder="e.g. Breakfast Included">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:1;">
                            <label for="pkgTransportModeField">Transport Mode</label>
                            <input type="text" id="pkgTransportModeField" name="transportmode" class="form-control" value="<?php echo htmlentities($package->TransportMode ?? ''); ?>" placeholder="e.g. AC Bus, Flight">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label for="pkgSeatsField">Seats Available <small style="font-weight:400;color:var(--text-muted);">(leave blank = unlimited/not tracked)</small></label>
                            <input type="number" min="0" id="pkgSeatsField" name="seatsavailable" class="form-control" value="<?php echo htmlentities($package->SeatsAvailable ?? ''); ?>" placeholder="e.g. 30">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="pkgInclusionsField">Inclusions <small style="font-weight:400;color:var(--text-muted);">(one item per line)</small></label>
                        <textarea id="pkgInclusionsField" name="inclusions" class="form-control" rows="4"
                                  placeholder="Hotel accommodation&#10;Daily breakfast&#10;Airport transfers"><?php echo htmlentities($package->Inclusions ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="pkgExclusionsField">Exclusions <small style="font-weight:400;color:var(--text-muted);">(one item per line)</small></label>
                        <textarea id="pkgExclusionsField" name="exclusions" class="form-control" rows="4"
                                  placeholder="International airfare&#10;Travel insurance&#10;Personal expenses"><?php echo htmlentities($package->Exclusions ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="pkgCancellationPolicyField">Cancellation Policy <small style="font-weight:400;color:var(--text-muted);">(one rule per line, shown to travelers before booking)</small></label>
                        <textarea id="pkgCancellationPolicyField" name="cancellationpolicy" class="form-control" rows="4"
                                  placeholder="Free cancellation up to 7 days before departure&#10;50% refund within 3-7 days&#10;No refund within 48 hours"><?php echo htmlentities($package->CancellationPolicy ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="pkgImageField">Package Image (leave blank to keep current)</label>
                        <?php if($package->PackageImage): ?>
                        <p><small>Current image: <strong><?php echo htmlentities($package->PackageImage); ?></strong></small></p>
                        <?php endif; ?>
                        <input type="file" id="pkgImageField" name="packageimage" class="form-control">
                    </div>
                    <button type="submit" name="submit" class="btn-v6 success">Update Package</button>
                    <a href="manage-package-addons.php?pkgid=<?php echo (int)$pid; ?>" class="btn-v6 ghost">
                        <i class="fa fa-plus-square"></i> Manage Add-ons
                    </a>
                    <a href="manage-packages.php" class="btn-v6 ghost">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>

</div>
</div>

</div>
<?php include('includes/footer.php'); ?>
<script>
document.getElementById('suggestPriceBtn').addEventListener('click', function() {
    var btn = this;
    var location = document.querySelector('[name="packagelocation"]').value.trim();
    var type = document.querySelector('[name="packagetype"]').value;
    var box = document.getElementById('priceSuggestionBox');

    btn.disabled = true;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Analysing…';
    box.style.display = 'block';
    box.innerHTML = 'Comparing against similar packages…';

    var body = new URLSearchParams({ action: 'suggest_price', package_id: '<?php echo (int)$pid; ?>', location: location, type: type });
    fetch('../api/ai.php?action=suggest_price', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (!res.success || !res.data || !res.data.prediction || !res.data.prediction.has_data) {
                box.textContent = (res.data && res.data.prediction && res.data.prediction.message) || 'No comparable packages found — you\'ll need to set the price based on judgment for this one.';
                return;
            }
            var p = res.data.prediction;
            var E = function (s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
            var N = function (v) { v = Number(v); return isFinite(v) ? v : 0; };
            var html = '<strong>' + N(p.comparable_count) + ' comparable package(s)</strong> (matched by ' + E(p.match_basis) + '): '
                + '৳' + N(p.min_price) + ' – ৳' + N(p.max_price) + ' (median ৳' + N(p.median_price) + ', avg ৳' + N(p.avg_price) + ').';
            if (p.own_price) {
                html += '<br>This package (৳' + N(p.own_price) + ') is currently <strong>' + E(String(p.position).replace('_',' ')) + '</strong> (' + (p.diff_percent > 0 ? '+' : '') + N(p.diff_percent) + '% vs avg).';
            }
            if (p.ai_verdict) html += '<br><em>' + E(p.ai_verdict) + '</em>';
            html += '<br><button type="button" class="btn btn-xs btn-primary" style="margin-top:6px;" onclick="document.getElementById(\'packagePriceInput\').value=' + N(p.suggested_price) + '">Use ৳' + N(p.suggested_price) + '</button>';
            box.innerHTML = html;
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            box.innerHTML = 'Could not fetch a suggestion right now — you can still enter a price manually.';
        });
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

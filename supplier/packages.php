<?php
require __DIR__ . '/_layout.php';
$sup = sup_require_login($dbh); $sid = (int)$sup['SupplierId'];
$errors = []; $saved = false;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$showForm = $editId > 0 || !empty($_GET['new']);
$cur = $editId ? SupplierService::package($dbh, $sid, $editId) : null;   // tenant-scoped: someone else's id is simply "not found"
if ($editId && !$cur) { $showForm = false; $errors['form'] = 'Package not found.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $pid = (int)($_POST['package_id'] ?? 0) ?: null;
    $newImage = null;
    if (!empty($_FILES['image']['size'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo((string)$_FILES['image']['name'], PATHINFO_EXTENSION));
        $sig = in_array($ext, $allowed, true) ? tms_validate_upload_signature($_FILES['image']['tmp_name'], $allowed) : ['ok' => false, 'error' => 'Only JPG, PNG or WEBP images are allowed.'];
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) $errors['image'] = 'Image must be under 5 MB.';
        elseif (!$sig['ok']) $errors['image'] = $sig['error'] ?: 'That file is not a valid image.';
        else {
            $dir = dirname(__DIR__) . '/admin/pacakgeimages/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $name = 'sup' . $sid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $dir . $name)) { tms_compress_uploaded_image($dir . $name); $newImage = $name; }
            else $errors['image'] = 'Could not store the image.';
        }
    }
    if (!$errors) {
        $r = SupplierService::savePackage($dbh, $sid, $pid, $_POST, $newImage);
        if ($r['ok']) { header('Location: packages.php?saved=1'); exit; }
        $errors = $r['errors']; $showForm = true; $editId = (int)$pid; $cur = $_POST;
    } else { $showForm = true; $editId = (int)$pid; $cur = $_POST; }
}
$val = function (string $postKey, string $dbKey) use ($cur) { return h($cur[$postKey] ?? $cur[$dbKey] ?? ''); };
$field = function (string $k, string $label, string $dbKey, string $type = 'text', string $attrs = '', string $hint = '', bool $full = false) use ($val, $errors) {
    echo '<div' . ($full ? ' class="full"' : '') . '><label for="f_' . $k . '">' . h($label) . '</label>';
    if ($type === 'textarea') echo '<textarea id="f_' . $k . '" name="' . $k . '" ' . $attrs . '>' . $val($k, $dbKey) . '</textarea>';
    else echo '<input id="f_' . $k . '" name="' . $k . '" type="' . $type . '" ' . $attrs . ' value="' . $val($k, $dbKey) . '">';
    if ($hint) echo '<div class="sp-hint">' . h($hint) . '</div>';
    if (isset($errors[$k])) echo '<div class="sp-err" role="alert">' . h($errors[$k]) . '</div>';
    echo '</div>';
};
sup_head('Packages', $sup, 'packages.php');
?>
<h1>Packages</h1>
<p class="sp-sub">Packages you submit are reviewed by TourBuddy before they appear to customers. Editing a live package takes it offline until it is approved again.</p>
<?php if (!empty($_GET['saved'])): ?><div class="sp-alert ok" role="status">Saved. Your package is now in review.</div><?php endif; ?>
<?php if (!empty($errors['form'])): ?><div class="sp-alert bad" role="alert"><?php echo h($errors['form']); ?></div><?php endif; ?>
<?php if ($sup['Status'] !== 'verified'): ?><div class="sp-alert warn">Your business must be verified before you can list packages.</div><?php endif; ?>

<?php if ($showForm && $sup['Status'] === 'verified'): ?>
<form method="post" enctype="multipart/form-data" class="sp-card sp-form">
  <?php tms_csrf_field(); ?>
  <input type="hidden" name="package_id" value="<?php echo (int)$editId; ?>">
  <div class="full"><h2 style="margin:0"><?php echo $editId ? 'Edit package' : 'New package'; ?></h2></div>
  <?php
  $field('name', 'Package name', 'PackageName', 'text', 'required maxlength="200"');
  $field('type', 'Type (e.g. Beach, Hills, Wildlife)', 'PackageType', 'text', 'required maxlength="150"');
  $field('location', 'Location', 'PackageLocation', 'text', 'required maxlength="100"');
  $field('country', 'Country', 'Country', 'text', 'maxlength="100"');
  $field('price', 'Price per person (BDT, whole number)', 'PackagePrice', 'number', 'required min="1" step="1" inputmode="numeric"');
  $field('seats', 'Total seats (leave empty if unlimited)', 'SeatsAvailable', 'number', 'min="1" step="1" inputmode="numeric"', 'Used to stop overselling.');
  $field('duration', 'Duration (e.g. 3 Days / 2 Nights)', 'PackageDuration', 'text', 'maxlength="50"');
  $field('features', 'Highlights (one line)', 'PackageFetures', 'text', 'maxlength="255"');
  $field('details', 'Description', 'PackageDetails', 'textarea', 'required maxlength="5000"', '', true);
  $field('inclusions', 'What is included', 'Inclusions', 'textarea', 'maxlength="3000"', '', true);
  $field('exclusions', 'What is not included', 'Exclusions', 'textarea', 'maxlength="3000"', '', true);
  $field('cancellation', 'Cancellation and refund terms', 'CancellationPolicy', 'textarea', 'required maxlength="3000"', 'Shown to customers at checkout. Be specific (for example: full refund 14+ days before the start date).', true);
  ?>
  <div class="full"><label for="f_image">Cover photo (JPG, PNG or WEBP, max 5 MB)</label><input id="f_image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
    <div class="sp-hint">Only upload photos you own or have the right to use, and that show this actual service.</div>
    <?php if (isset($errors['image'])): ?><div class="sp-err" role="alert"><?php echo h($errors['image']); ?></div><?php endif; ?></div>
  <div class="full"><button class="btn" type="submit">Submit for review</button> <a class="btn secondary" href="packages.php">Cancel</a></div>
</form>
<?php else: ?>
  <p><?php if ($sup['Status'] === 'verified'): ?><a class="btn" href="packages.php?new=1">Add a package</a><?php endif; ?></p>
<?php endif; ?>

<?php $pk = SupplierService::packages($dbh, $sid); ?>
<?php if (!$pk): ?><div class="sp-card sp-empty">You have not added any packages yet.</div><?php else: ?>
<div class="sp-table-wrap" tabindex="0" role="region" aria-label="Your packages"><table>
  <thead><tr><th scope="col">Package</th><th scope="col">Price</th><th scope="col">Seats</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
  <?php foreach ($pk as $p): ?>
    <tr><td><b><?php echo h($p['PackageName']); ?></b><br><span class="sp-hint"><?php echo h($p['PackageLocation']); ?> &middot; <?php echo h($p['PackageType']); ?></span>
        <?php if ($p['ApprovalStatus'] === 'rejected' && $p['ReviewNote']): ?><div class="sp-err">Needs changes: <?php echo h($p['ReviewNote']); ?></div><?php endif; ?></td>
      <td><?php echo sup_money($p['PackagePrice']); ?></td>
      <td><?php echo $p['SeatsAvailable'] === null ? 'Unlimited' : (int)$p['SeatsAvailable']; ?></td>
      <td><?php echo sup_status_badge((string)$p['ApprovalStatus']); ?></td>
      <td><?php if ($sup['Status'] === 'verified'): ?><a class="btn secondary sm" href="packages.php?edit=<?php echo (int)$p['PackageId']; ?>">Edit<span class="sr-only"> <?php echo h($p['PackageName']); ?></span></a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php sup_foot();

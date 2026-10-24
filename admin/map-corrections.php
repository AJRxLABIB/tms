<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require __DIR__ . '/includes/map-admin-boot.php';

$msg = ''; $msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $cid = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    if ($cid > 0 && in_array($act, ['accept', 'reject'], true)) {
        $r = MapAdmin::reviewCorrection($dbh, $places, $cid, $act === 'accept', $actor, (string)($_POST['note'] ?? ''));
        if (!empty($r['ok'])) {
            $msg = $act === 'accept'
                ? ($r['applied'] ? 'Correction accepted and applied. Re-check the place and re-verify it if needed.' : 'Correction marked as reviewed. It cannot be applied automatically, so edit the place by hand if needed.')
                : 'Correction rejected.';
            $msgType = 'success';
        } else { $msg = (string)($r['error'] ?? 'Could not review this correction.'); $msgType = 'error'; }
    }
}
$list = MapAdmin::pendingCorrections($dbh, 100);
$labels = ['name' => 'Name', 'name_bn' => 'Bangla name', 'category' => 'Category', 'location' => 'Location', 'summary' => 'Summary', 'locality' => 'Locality', 'website' => 'Website',
    'phone' => 'Phone', 'opening_hours' => 'Opening hours', 'fee_info' => 'Admission fee', 'closed' => 'Permanently closed', 'other' => 'Other'];
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Map corrections</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/map-admin.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<p><a href="map-places.php">&larr; All map places</a></p>
<h1 class="mp-title">Suggested corrections</h1>
<p class="mp-sub">Visitors can suggest changes. Nothing changes on the map until you accept a suggestion here. Accepting applies it through the same validation as a manual edit and records who did it.</p>
<?php if ($msg): ?><div class="mp-alert <?php echo e($msgType); ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>

<?php if (!$list): ?><div class="mp-card card"><p>No corrections are waiting for review.</p></div><?php endif; ?>
<?php foreach ($list as $c): $field = (string)$c['Field']; ?>
  <div class="mp-card card">
    <h2><?php echo e($labels[$field] ?? $field); ?>
      <?php if ($c['PlaceId']): ?> for <a href="map-place-edit.php?id=<?php echo (int)$c['PlaceId']; ?>"><?php echo e($c['PlaceName'] ?: ('place #' . (int)$c['PlaceId'])); ?></a><?php else: ?> (no place linked)<?php endif; ?></h2>
    <p class="mp-help">Submitted <?php echo e($c['CreatedAt']); ?><?php echo $c['SubmittedBy'] ? ' by ' . e($c['SubmittedBy']) : ' anonymously'; ?><?php echo $c['IsVerified'] ? ' &middot; this place is verified' : ''; ?></p>
    <?php if ($field !== 'other'): ?>
    <div class="mp-cmp">
      <div><b>Current</b><br><?php $cur = MapAdmin::currentValue($c); echo $cur === '' ? '<i>empty</i>' : e($cur); ?></div>
      <div><b>Suggested</b><br><?php $pr = MapAdmin::proposedText($c); echo $pr === '' ? '<i>empty</i>' : e($pr); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($c['Note']): ?><p style="margin-top:10px"><b>Note from visitor:</b> <?php echo e($c['Note']); ?></p><?php endif; ?>
    <?php if ($field === 'location' && isset($c['CurLat'])): ?><p class="mp-help">Check the new point on a reliable map before accepting a location change.</p><?php endif; ?>
    <form method="post" class="mp-filters" style="margin-top:10px"><?php tms_csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$c['CorrectionId']; ?>">
      <div><label for="n<?php echo (int)$c['CorrectionId']; ?>">Reviewer note (optional)</label><input class="mp-in" id="n<?php echo (int)$c['CorrectionId']; ?>" name="note" maxlength="150"></div>
      <button class="mp-btn ok" name="action" value="accept" <?php echo $field === 'closed' ? 'onclick="return confirm(\'Accepting archives this place. Continue?\');"' : ''; ?>><?php echo $field === 'other' ? 'Mark reviewed' : 'Accept and apply'; ?></button>
      <button class="mp-btn danger" name="action" value="reject">Reject</button>
    </form>
  </div>
<?php endforeach; ?>
</div></main></div>
<script src="../js/tms-a11y.js" defer></script>
</body>
</html>

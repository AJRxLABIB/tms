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
    $act = (string)($_POST['action'] ?? '');
    $a = (int)($_POST['a'] ?? 0); $b = (int)($_POST['b'] ?? 0);
    if ($act === 'dismiss') {
        $ok = MapAdmin::dismissDuplicate($dbh, $a, $b, $actor);
        $msg = $ok ? 'Marked as not a duplicate. This pair will not be suggested again.' : 'Invalid pair.'; $msgType = $ok ? 'success' : 'error';
    } elseif ($act === 'merge') {
        $keep = (int)($_POST['keep'] ?? 0);
        $drop = $keep === $a ? $b : ($keep === $b ? $a : 0);
        $r = $drop ? MapAdmin::mergePlaces($dbh, $places, $keep, $drop, $actor) : ['ok' => false, 'error' => 'Choose which place to keep.'];
        $msg = !empty($r['ok']) ? 'Merged. The other place was archived (not deleted), and its names and photos moved to the kept place.' : (string)$r['error'];
        $msgType = !empty($r['ok']) ? 'success' : 'error';
    }
}
$pairs = MapAdmin::duplicateSuspects($dbh, 60);
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Map duplicates</title>
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
<h1 class="mp-title">Suspected duplicate places</h1>
<p class="mp-sub">Pairs within 150 m of each other with very similar names. Check each pair before merging. Merging keeps one place, moves the other's names and photos across and archives the other. Nothing is deleted.</p>
<?php if ($msg): ?><div class="mp-alert <?php echo e($msgType); ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>
<?php if (!$pairs): ?><div class="mp-card card"><p>No suspected duplicates.</p></div><?php endif; ?>
<?php foreach ($pairs as $p): $A = $p['a']; $B = $p['b']; ?>
  <div class="mp-card card">
    <p class="mp-help"><?php echo (int)$p['distance_m']; ?> m apart &middot; name similarity <?php echo (int)round($p['similarity'] * 100); ?>%</p>
    <div class="mp-cmp">
      <?php foreach ([$A, $B] as $X): ?>
      <div><a href="map-place-edit.php?id=<?php echo (int)$X['PlaceId']; ?>"><b><?php echo e($X['Name']); ?></b></a><?php echo $X['NameBn'] ? '<br><span lang="bn">' . e($X['NameBn']) . '</span>' : ''; ?><br>
        <?php echo e(MapPlaces::categoryLabel((string)$X['Category'])); ?> &middot; <?php echo e(trim(($X['Locality'] ?? '') . ' ' . ($X['District'] ?? ''))); ?><br>
        <span class="mp-pill"><?php echo e($X['Status']); ?></span> <span class="mp-pill <?php echo $X['IsVerified'] ? 'ok' : 'warn'; ?>"><?php echo $X['IsVerified'] ? 'verified' : 'unverified'; ?></span> <span class="mp-pill"><?php echo e($X['Source']); ?></span><?php echo $X['IsDemo'] ? ' <span class="mp-pill bad">demo</span>' : ''; ?></div>
      <?php endforeach; ?>
    </div>
    <form method="post" class="mp-filters" style="margin-top:10px"><?php tms_csrf_field(); ?><input type="hidden" name="a" value="<?php echo (int)$A['PlaceId']; ?>"><input type="hidden" name="b" value="<?php echo (int)$B['PlaceId']; ?>">
      <div><label for="k<?php echo (int)$A['PlaceId'] . '-' . (int)$B['PlaceId']; ?>">Keep</label>
        <select class="mp-in" id="k<?php echo (int)$A['PlaceId'] . '-' . (int)$B['PlaceId']; ?>" name="keep"><option value="<?php echo (int)$A['PlaceId']; ?>"><?php echo e($A['Name']); ?> (#<?php echo (int)$A['PlaceId']; ?>)</option><option value="<?php echo (int)$B['PlaceId']; ?>"><?php echo e($B['Name']); ?> (#<?php echo (int)$B['PlaceId']; ?>)</option></select></div>
      <button class="mp-btn primary" name="action" value="merge" onclick="return confirm('Merge these two places? The other one will be archived.');">Merge</button>
      <button class="mp-btn neutral" name="action" value="dismiss">Not a duplicate</button>
    </form>
  </div>
<?php endforeach; ?>
</div></main></div>
<script src="../js/tms-a11y.js" defer></script>
</body>
</html>

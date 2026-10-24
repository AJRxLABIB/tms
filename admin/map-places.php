<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require __DIR__ . '/includes/map-admin-boot.php';

$msg = ''; $msgType = ''; $csvResult = null; $csvPreview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'status') {
        $pid = (int)($_POST['id'] ?? 0); $to = (string)($_POST['to'] ?? '');
        $row = $places->find($pid, false);
        if ($row && in_array($to, ['active', 'pending', 'archived'], true)) {
            $places->setStatus($pid, $to);
            MapAdmin::audit($dbh, $actor, 'status', $pid, $row['Status'] . ' -> ' . $to);
            $msg = 'Status of "' . $row['Name'] . '" changed to ' . $to . '.'; $msgType = 'success';
        } else { $msg = 'Place not found or status invalid.'; $msgType = 'error'; }
    } elseif ($act === 'csv_preview') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || (int)$f['error'] !== 0 || !is_uploaded_file((string)$f['tmp_name'])) { $msg = 'Choose a CSV file to preview.'; $msgType = 'error'; }
        elseif ((int)$f['size'] > MapAdmin::CSV_MAX_BYTES) { $msg = 'The file is larger than 512 KB.'; $msgType = 'error'; }
        else {
            $text = (string)file_get_contents((string)$f['tmp_name']);
            $parsed = MapAdmin::parseCsv($text);
            if (!$parsed['ok']) { $msg = (string)$parsed['error']; $msgType = 'error'; }
            else {
                // the validated text is kept server-side so the apply step cannot be fed different data by the browser
                $_SESSION['map_csv'] = ['text' => $text, 'hash' => hash('sha256', $text), 'at' => time()];
                $csvPreview = ['parsed' => $parsed, 'dry' => MapAdmin::importRows($dbh, $places, $parsed['rows'], $actor, false), 'hash' => $_SESSION['map_csv']['hash']];
            }
        }
    } elseif ($act === 'csv_apply') {
        $st = $_SESSION['map_csv'] ?? null;
        if (!$st || !hash_equals((string)$st['hash'], (string)($_POST['hash'] ?? '')) || (time() - (int)$st['at']) > 1800) {
            $msg = 'The preview expired. Upload the file again and review it before importing.'; $msgType = 'error';
        } else {
            $parsed = MapAdmin::parseCsv((string)$st['text']);
            if ($parsed['ok']) {
                $csvResult = MapAdmin::importRows($dbh, $places, $parsed['rows'], $actor, true);
                MapAdmin::audit($dbh, $actor, 'csv_import_done', null, 'created ' . $csvResult['created'] . ', skipped ' . $csvResult['skipped_duplicate'] . ', invalid ' . $csvResult['invalid']);
                $msg = 'Import finished: ' . $csvResult['created'] . ' new place(s) created as pending and unverified, ' . $csvResult['skipped_duplicate'] . ' skipped as possible duplicates, ' . $csvResult['invalid'] . ' invalid row(s) ignored.'; $msgType = 'success';
            } else { $msg = (string)$parsed['error']; $msgType = 'error'; }
            unset($_SESSION['map_csv']);
        }
    }
}

$filters = [];
foreach (['status', 'category', 'verified', 'source', 'demo', 'q'] as $k) $filters[$k] = isset($_GET[$k]) ? trim((string)$_GET[$k]) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$list = MapAdmin::listPlaces($dbh, $filters, $page, 25);
$pendingCorr = MapAdmin::pendingCorrectionCount($dbh);
$dupCount = count(MapAdmin::duplicateSuspects($dbh, 50));
$cov = MapAdmin::coverage($dbh);
$cats = MapPlaces::categories();
$qs = static function (array $over = []) use ($filters) { return '?' . http_build_query(array_filter(array_merge($filters, $over), static fn($v) => $v !== '' && $v !== null)); };
$pages = max(1, (int)ceil($list['total'] / $list['per_page']));
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Map places</title>
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
<h1 class="mp-title"><i class="fa fa-map-marker" style="color:#176B50;margin-right:10px;"></i>Map places</h1>
<p class="mp-sub">The records behind the tourism map. Places imported from OpenStreetMap or the package catalogue start <b>unverified</b>. Only an administrator can mark a place verified, and nothing public can change a place without review.</p>

<?php if ($msg): ?><div class="mp-alert <?php echo e($msgType); ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>

<div class="mp-stats">
  <div class="mp-stat"><b><?php echo (int)($cov['totals']['active'] ?? 0); ?></b>Active</div>
  <div class="mp-stat"><b><?php echo (int)($cov['totals']['pending'] ?? 0); ?></b>Pending</div>
  <div class="mp-stat"><b><?php echo (int)($cov['totals']['archived'] ?? 0); ?></b>Archived</div>
  <a class="mp-stat" href="map-corrections.php"><b><?php echo $pendingCorr; ?></b>Corrections to review</a>
  <a class="mp-stat" href="map-duplicates.php"><b><?php echo $dupCount; ?><?php echo $dupCount >= 50 ? '+' : ''; ?></b>Suspected duplicates</a>
  <a class="mp-btn primary" href="map-place-edit.php" style="align-self:center"><i class="fa fa-plus"></i> Add place</a>
</div>

<form method="get" class="mp-filters" role="search" aria-label="Filter places">
  <div><label for="fq">Search</label><input class="mp-in" id="fq" name="q" value="<?php echo e($filters['q']); ?>" placeholder="Name, Bangla name, locality" maxlength="80"></div>
  <div><label for="fs">Status</label><select class="mp-in" id="fs" name="status"><option value="">Any</option>
    <?php foreach (['active', 'pending', 'archived'] as $s): ?><option value="<?php echo $s; ?>"<?php echo $filters['status'] === $s ? ' selected' : ''; ?>><?php echo ucfirst($s); ?></option><?php endforeach; ?></select></div>
  <div><label for="fc">Category</label><select class="mp-in" id="fc" name="category"><option value="">Any</option>
    <?php foreach ($cats as $c): ?><option value="<?php echo e($c['id']); ?>"<?php echo $filters['category'] === $c['id'] ? ' selected' : ''; ?>><?php echo e($c['label']); ?></option><?php endforeach; ?></select></div>
  <div><label for="fv">Verification</label><select class="mp-in" id="fv" name="verified"><option value="">Any</option><option value="1"<?php echo $filters['verified'] === '1' ? ' selected' : ''; ?>>Verified</option><option value="0"<?php echo $filters['verified'] === '0' ? ' selected' : ''; ?>>Unverified</option></select></div>
  <div><label for="fo">Source</label><select class="mp-in" id="fo" name="source"><option value="">Any</option>
    <?php foreach (['admin', 'osm', 'package', 'guide', 'user'] as $s): ?><option value="<?php echo $s; ?>"<?php echo $filters['source'] === $s ? ' selected' : ''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
  <div><label for="fd">Demo data</label><select class="mp-in" id="fd" name="demo"><option value="">Any</option><option value="1"<?php echo $filters['demo'] === '1' ? ' selected' : ''; ?>>Demo only</option><option value="0"<?php echo $filters['demo'] === '0' ? ' selected' : ''; ?>>Not demo</option></select></div>
  <button class="mp-btn primary" type="submit"><i class="fa fa-search"></i> Filter</button>
  <a class="mp-btn neutral" href="map-places.php">Reset</a>
</form>

<div class="mp-wrap card" style="margin-bottom:8px">
<table class="table">
  <caption class="mp-sr">Map places, <?php echo (int)$list['total']; ?> matching</caption>
  <thead><tr><th>Place</th><th>Category</th><th>Where</th><th>Coordinates</th><th>State</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (!$list['rows']): ?><tr><td colspan="6" style="text-align:center;padding:22px">No places match. <?php echo $list['total'] === 0 && !array_filter($filters) ? 'Run <code>php scripts/map_sync_places.php --apply</code> to copy packages that have coordinates, or add a place.' : ''; ?></td></tr><?php endif; ?>
  <?php foreach ($list['rows'] as $r): ?>
    <tr>
      <td><a href="map-place-edit.php?id=<?php echo (int)$r['PlaceId']; ?>"><b><?php echo e($r['Name']); ?></b></a>
        <?php if ($r['NameBn']): ?><br><span lang="bn"><?php echo e($r['NameBn']); ?></span><?php endif; ?></td>
      <td><?php echo e(MapPlaces::categoryLabel((string)$r['Category'])); ?></td>
      <td><?php echo e(trim(implode(', ', array_filter([$r['Locality'], $r['District']])))); ?></td>
      <td style="white-space:nowrap"><?php echo e($r['Lat']); ?>, <?php echo e($r['Lng']); ?></td>
      <td>
        <span class="mp-pill <?php echo $r['Status'] === 'active' ? 'ok' : ($r['Status'] === 'archived' ? 'bad' : 'warn'); ?>"><?php echo e($r['Status']); ?></span>
        <span class="mp-pill <?php echo $r['IsVerified'] ? 'ok' : 'warn'; ?>"><?php echo $r['IsVerified'] ? 'Verified' : 'Unverified'; ?></span>
        <span class="mp-pill"><?php echo e($r['Source']); ?></span>
        <?php if ($r['IsDemo']): ?><span class="mp-pill bad">Demo</span><?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <a class="mp-btn neutral" href="map-place-edit.php?id=<?php echo (int)$r['PlaceId']; ?>">Edit</a>
        <form method="post" style="display:inline"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo (int)$r['PlaceId']; ?>">
          <?php if ($r['Status'] !== 'active'): ?><button class="mp-btn ok" name="to" value="active" title="Show on the public map">Publish</button><?php endif; ?>
          <?php if ($r['Status'] !== 'archived'): ?><button class="mp-btn danger" name="to" value="archived" onclick="return confirm('Hide this place from the map?');">Archive</button><?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="mp-pager" aria-label="Pages">
  <span><?php echo (int)$list['total']; ?> place(s). Page <?php echo (int)$list['page']; ?> of <?php echo $pages; ?></span>
  <?php if ($list['page'] > 1): ?><a class="mp-btn neutral" href="<?php echo e($qs(['page' => $list['page'] - 1])); ?>">&larr; Previous</a><?php endif; ?>
  <?php if ($list['page'] < $pages): ?><a class="mp-btn neutral" href="<?php echo e($qs(['page' => $list['page'] + 1])); ?>">Next &rarr;</a><?php endif; ?>
</div>

<h2 class="mp-title" style="font-size:18px;margin-top:30px">Coverage report</h2>
<p class="mp-sub">Counts of <b>active</b> places only, taken from the database. This report shows gaps. It does not create or guess any data.</p>
<div class="mp-card card">
  <h2>Bangladesh target destinations</h2>
  <div class="mp-wrap"><table class="table">
    <thead><tr><th>Destination</th><th>Active places</th><th>By category</th><th>Missing categories</th></tr></thead><tbody>
    <?php foreach ($cov['targets'] as $t): ?>
      <tr><td><b><?php echo e($t['target']); ?></b></td><td><?php echo (int)$t['total']; ?></td>
        <td><?php $parts = []; foreach ($t['categories'] as $c => $n) $parts[] = e(MapPlaces::categoryLabel($c)) . ' ' . (int)$n; echo $parts ? implode(' &middot; ', $parts) : '<span class="mp-pill bad">None yet</span>'; ?></td>
        <td><?php echo $t['missing'] ? e(implode(', ', array_map([MapPlaces::class, 'categoryLabel'], $t['missing']))) : '<span class="mp-pill ok">All expected categories present</span>'; ?></td></tr>
    <?php endforeach; ?>
      <tr><td><b>Archaeological / historic sites</b></td><td colspan="3"><?php echo (int)$cov['archaeological']; ?> active historic place(s) in total</td></tr>
    </tbody></table></div>
  <p class="mp-help">A destination is matched by its district, region or locality text. To fill a gap, add verified places by hand, import a reviewed CSV below, or run the OpenStreetMap import script (<code>php scripts/map_import_osm.php</code>) and then verify what it brings in.</p>
</div>
<div class="mp-grid">
  <div class="mp-card card"><h2>Active places by category</h2>
    <?php $max = max(1, max($cov['by_category'])); foreach ($cov['by_category'] as $id => $n): ?>
      <div style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:5px"><span style="width:170px"><?php echo e(MapPlaces::categoryLabel($id)); ?></span>
        <div class="mp-bar" style="flex:1" role="img" aria-label="<?php echo (int)$n; ?>"><i style="width:<?php echo (int)round($n / $max * 100); ?>%"></i></div><b style="width:36px;text-align:right"><?php echo (int)$n; ?></b></div>
    <?php endforeach; ?>
    <?php if ($cov['empty_categories']): ?><p class="mp-help">No places yet in: <?php echo e(implode(', ', array_map([MapPlaces::class, 'categoryLabel'], $cov['empty_categories']))); ?>.</p><?php endif; ?>
  </div>
  <div class="mp-card card"><h2>Active places by district</h2>
    <?php if (!$cov['by_district']): ?><p>No active places.</p><?php endif; ?>
    <table class="table"><tbody><?php foreach ($cov['by_district'] as $d): ?><tr><td><?php echo e($d['district']); ?></td><td style="text-align:right"><?php echo (int)$d['total']; ?></td></tr><?php endforeach; ?></tbody></table>
  </div>
</div>

<h2 class="mp-title" style="font-size:18px;margin-top:10px">Import places from CSV</h2>
<div class="mp-card card">
  <p class="mp-sub">Columns: <code>name,name_bn,category,lat,lng,locality</code> (required: name, category, lat, lng). UTF-8, up to <?php echo MapAdmin::CSV_MAX_ROWS; ?> rows and 512 KB. Latitude comes first, then longitude.
     Rows become <b>new pending, unverified</b> places. Existing places are never changed, and rows that look like an existing place are skipped. You always see a preview first.</p>
  <form method="post" enctype="multipart/form-data" class="mp-filters">
    <?php tms_csrf_field(); ?><input type="hidden" name="action" value="csv_preview">
    <div><label for="csvf">CSV file</label><input class="mp-in" type="file" id="csvf" name="csv" accept=".csv,text/csv" required></div>
    <button class="mp-btn primary" type="submit"><i class="fa fa-eye"></i> Preview</button>
  </form>
  <p class="mp-help">Valid categories: <?php echo e(implode(', ', array_column($cats, 'id'))); ?></p>
  <?php if ($csvPreview): $dry = $csvPreview['dry']; ?>
    <div class="mp-alert warn" role="status">Preview only, nothing is saved yet: <?php echo (int)$dry['created']; ?> would be created, <?php echo (int)$dry['skipped_duplicate']; ?> skipped as possible duplicates, <?php echo (int)$dry['invalid']; ?> invalid.</div>
    <div class="mp-wrap"><table class="table"><thead><tr><th>Line</th><th>Name</th><th>Category</th><th>Lat, Lng</th><th>Result</th></tr></thead><tbody>
    <?php $byLine = []; foreach ($dry['lines'] as $l) $byLine[$l['line']] = $l['result'];
    foreach (array_slice($csvPreview['parsed']['rows'], 0, 200) as $r): ?>
      <tr><td><?php echo (int)$r['line']; ?></td><td><?php echo e($r['data']['name'] ?? ''); ?></td><td><?php echo e($r['data']['category'] ?? ''); ?></td>
      <td><?php echo e(($r['data']['lat'] ?? '') . ', ' . ($r['data']['lng'] ?? '')); ?></td>
      <td><?php if ($r['status'] === 'error'): ?><span class="mp-pill bad"><?php echo e(implode(' ', $r['errors'])); ?></span>
          <?php else: ?><span class="mp-pill <?php echo strpos($byLine[$r['line']] ?? '', 'skipped') === 0 ? 'warn' : 'ok'; ?>"><?php echo e($byLine[$r['line']] ?? ''); ?></span><?php foreach ($r['warnings'] as $w): ?> <span class="mp-pill warn"><?php echo e($w); ?></span><?php endforeach; endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if ($dry['created'] > 0): ?>
    <form method="post" style="margin-top:12px"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="csv_apply"><input type="hidden" name="hash" value="<?php echo e($csvPreview['hash']); ?>">
      <button class="mp-btn primary" type="submit" onclick="return confirm('Create <?php echo (int)$dry['created']; ?> pending place(s)?');"><i class="fa fa-upload"></i> Import <?php echo (int)$dry['created']; ?> new place(s)</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>

</div></main></div>
<script src="../js/tms-a11y.js" defer></script>
</body>
</html>

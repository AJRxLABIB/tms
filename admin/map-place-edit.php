<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require __DIR__ . '/includes/map-admin-boot.php';
require_once dirname(__DIR__) . '/backend/MapConfig.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$row = $id > 0 ? $places->find($id, false) : null;
if ($id > 0 && !$row) { http_response_code(404); exit('Place not found.'); }
$msg = (string)($_SESSION['map_flash'] ?? ''); $msgType = $msg !== '' ? 'success' : ''; unset($_SESSION['map_flash']);
$errors = []; $warnings = []; $dups = [];

/** Stored JSON (or text) => the plain values shown in the structured form controls. */
function mp_decode($raw) { if ($raw === null || $raw === '') return null; $j = json_decode((string)$raw, true); return json_last_error() === JSON_ERROR_NONE ? $j : (string)$raw; }
function mp_hours_text($raw): string { $v = mp_decode($raw); if ($v === null) return ''; if (is_array($v)) { if (isset($v['text'])) return (string)$v['text']; $o = []; foreach ($v as $k => $x) $o[] = (is_int($k) ? '' : $k . ': ') . (is_scalar($x) ? $x : json_encode($x, JSON_UNESCAPED_UNICODE)); return implode("\n", $o); } return (string)$v; }
function mp_fee_form($raw): array
{
    $v = mp_decode($raw);
    if ($v === null) return ['state' => 'unknown', 'amount' => '', 'currency' => 'BDT', 'text' => ''];
    if (is_array($v)) {
        $free = $v['is_free'] ?? ($v['free'] ?? null);
        $amt = $v['amount'] ?? ($v['adult'] ?? '');
        $state = $free === true ? 'free' : (($free === false || ($amt !== '' && is_numeric($amt) && (float)$amt > 0)) ? 'paid' : 'unknown');
        return ['state' => $state, 'amount' => $amt === null ? '' : (string)$amt, 'currency' => (string)($v['currency'] ?? 'BDT'), 'text' => (string)($v['text'] ?? '')];
    }
    return ['state' => 'unknown', 'amount' => '', 'currency' => 'BDT', 'text' => (string)$v];
}
function mp_acc_form($raw): array
{
    $v = mp_decode($raw);
    if ($v === null) return ['state' => 'unknown', 'text' => ''];
    if (is_array($v)) { $w = $v['wheelchair'] ?? ($v['accessible'] ?? null); return ['state' => in_array($w, ['yes', 'limited', 'no'], true) ? $w : ($w === true ? 'yes' : ($w === false ? 'no' : 'unknown')), 'text' => (string)($v['text'] ?? '')]; }
    return ['state' => 'unknown', 'text' => (string)$v];
}

// ───────────── build the form model (database row, or the posted values after an error) ─────────────
$form = [
    'name' => $row['Name'] ?? '', 'name_bn' => $row['NameBn'] ?? '', 'category' => $row['Category'] ?? 'attraction', 'subcategory' => $row['Subcategory'] ?? '',
    'summary' => $row['Summary'] ?? '', 'description' => $row['Description'] ?? '', 'locality' => $row['Locality'] ?? '', 'district' => $row['District'] ?? '',
    'region' => $row['Region'] ?? '', 'country' => $row['Country'] ?? ($row ? '' : 'Bangladesh'), 'lat' => $row['Lat'] ?? '', 'lng' => $row['Lng'] ?? '',
    'website' => $row['Website'] ?? '', 'phone' => $row['Phone'] ?? '', 'hours_state' => ($row && $row['OpeningHours'] !== null && $row['OpeningHours'] !== '') ? 'known' : 'unknown',
    'hours_text' => $row ? mp_hours_text($row['OpeningHours']) : '',
    'fee' => $row ? mp_fee_form($row['FeeInfo']) : mp_fee_form(null), 'acc' => $row ? mp_acc_form($row['Accessibility']) : mp_acc_form(null),
    'family' => $row ? ($row['FamilyFriendly'] === null ? 'unknown' : ((int)$row['FamilyFriendly'] === 1 ? 'yes' : 'no')) : 'unknown',
    'facilities' => $row ? implode(', ', (array)(json_decode((string)$row['Facilities'], true) ?: [])) : '',
    'status' => $row['Status'] ?? 'pending',
];

/** Posted structured controls => the values MapPlaces::validate() understands (null = unknown). */
function mp_from_post(array $p): array
{
    $in = [];
    foreach (['name', 'name_bn', 'subcategory', 'summary', 'description', 'locality', 'district', 'region', 'country', 'website', 'phone', 'facilities'] as $k) $in[$k] = (string)($p[$k] ?? '');
    $in['category'] = strtolower(trim((string)($p['category'] ?? 'attraction')));
    $in['lat'] = trim((string)($p['lat'] ?? '')); $in['lng'] = trim((string)($p['lng'] ?? ''));
    $ht = trim((string)($p['hours_text'] ?? ''));
    $in['opening_hours'] = (($p['hours_state'] ?? 'unknown') === 'known' && $ht !== '') ? ['text' => mb_substr($ht, 0, 1500, 'UTF-8')] : null;
    $fs = (string)($p['fee_state'] ?? 'unknown'); $ft = trim((string)($p['fee_text'] ?? '')); $fa = trim((string)($p['fee_amount'] ?? ''));
    $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($p['fee_currency'] ?? 'BDT')) ?: 'BDT');
    if ($fs === 'free') $in['fee_info'] = array_filter(['is_free' => true, 'text' => $ft], static fn($v) => $v !== '');
    elseif ($fs === 'paid') { $f = ['is_free' => false, 'currency' => substr($cur, 0, 3)]; if ($fa !== '' && is_numeric($fa) && (float)$fa >= 0) $f['amount'] = (float)$fa; if ($ft !== '') $f['text'] = $ft; $in['fee_info'] = $f; }
    else $in['fee_info'] = null;
    $as = (string)($p['acc_state'] ?? 'unknown'); $at = trim((string)($p['acc_text'] ?? ''));
    if (in_array($as, ['yes', 'limited', 'no'], true) || $at !== '') { $a = []; if (in_array($as, ['yes', 'limited', 'no'], true)) $a['wheelchair'] = $as; if ($at !== '') $a['text'] = $at; $in['accessibility'] = $a; }
    else $in['accessibility'] = null;
    $in['family_friendly'] = in_array($p['family'] ?? 'unknown', ['yes', 'no'], true) ? $p['family'] : 'unknown';
    return $in;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = (string)($_POST['action'] ?? '');
    $flash = static function (string $m) use (&$id) { $_SESSION['map_flash'] = $m; header('Location: map-place-edit.php?id=' . (int)$id); exit; };

    if ($act === 'save') {
        $in = mp_from_post($_POST);
        $status = in_array($_POST['status'] ?? '', ['active', 'pending', 'archived'], true) ? $_POST['status'] : 'pending';
        // keep the typed values on screen when something is wrong
        $form = array_merge($form, array_intersect_key($_POST, $form), [
            'hours_state' => (string)($_POST['hours_state'] ?? 'unknown'), 'hours_text' => (string)($_POST['hours_text'] ?? ''),
            'fee' => ['state' => (string)($_POST['fee_state'] ?? 'unknown'), 'amount' => (string)($_POST['fee_amount'] ?? ''), 'currency' => (string)($_POST['fee_currency'] ?? 'BDT'), 'text' => (string)($_POST['fee_text'] ?? '')],
            'acc' => ['state' => (string)($_POST['acc_state'] ?? 'unknown'), 'text' => (string)($_POST['acc_text'] ?? '')], 'family' => (string)($_POST['family'] ?? 'unknown'), 'status' => $status]);
        if ($row) {
            $r = $places->update($id, $in, $actor);
            if (!empty($r['ok'])) {
                if ($row['Status'] !== $status) $places->setStatus($id, $status);
                $after = $places->find($id, false);
                MapAdmin::audit($dbh, $actor, 'update', $id, 'Edited', array_intersect_key($row, array_flip(['Name', 'NameBn', 'Category', 'Lat', 'Lng', 'Locality', 'District', 'Status', 'OpeningHours', 'FeeInfo'])), array_intersect_key((array)$after, array_flip(['Name', 'NameBn', 'Category', 'Lat', 'Lng', 'Locality', 'District', 'Status', 'OpeningHours', 'FeeInfo'])));
                $flash('Saved.' . (!empty($r['warnings']) ? ' Note: ' . implode(' ', $r['warnings']) : '') . ($row['IsVerified'] ? ' This place is still marked verified. Re-check it and re-verify if you changed the name or location.' : ''));
            }
            $errors = $r['errors'] ?? [];
        } else {
            $in['status'] = $status; $in['source'] = 'admin';
            $r = $places->create($in, $actor, ['allow_duplicates' => !empty($_POST['confirm_duplicate'])]);
            if (!empty($r['ok'])) { $id = (int)$r['id']; MapAdmin::audit($dbh, $actor, 'create', $id, 'Created by administrator'); $flash('Place created' . ($status === 'pending' ? ' as pending. Publish it when its details are checked.' : '.') . (!empty($r['warnings']) ? ' Note: ' . implode(' ', $r['warnings']) : '')); }
            $errors = $r['errors'] ?? []; $dups = $r['duplicates'] ?? [];
        }
        $msg = 'Please fix the highlighted problems.'; $msgType = 'error';
    } elseif ($row) {
        if ($act === 'alias_add') {
            $ok = $places->addAlias($id, (string)($_POST['alias'] ?? ''), (string)($_POST['lang'] ?? 'en'));
            if ($ok) MapAdmin::audit($dbh, $actor, 'alias_add', $id, mb_substr((string)$_POST['alias'], 0, 100));
            $flash($ok ? 'Alias added.' : 'The alias is empty or too long.');
        } elseif ($act === 'alias_remove') {
            $aid = (int)($_POST['alias_id'] ?? 0); $mine = false;
            foreach ($places->aliases($id) as $a) if ($a['id'] === $aid) $mine = true;
            if ($mine) { $places->removeAlias($aid); MapAdmin::audit($dbh, $actor, 'alias_remove', $id, 'Alias #' . $aid); }
            $flash($mine ? 'Alias removed.' : 'Alias not found.');
        } elseif ($act === 'verify') {
            $to = ($_POST['to'] ?? '') === '1';
            if ($to && empty($_POST['confirm_checked'])) $flash('Tick the box to confirm you checked the name, location and details.');
            if ($to && $row['Status'] === 'archived') $flash('Restore the place before verifying it.');
            $places->setVerified($id, $to, $actor);
            MapAdmin::audit($dbh, $actor, $to ? 'verify' : 'unverify', $id, $to ? 'Marked verified' : 'Verification removed');
            $flash($to ? 'Marked verified.' : 'Verification removed.');
        } elseif ($act === 'status') {
            $to = (string)($_POST['to'] ?? '');
            if (in_array($to, ['active', 'pending', 'archived'], true)) { $places->setStatus($id, $to); MapAdmin::audit($dbh, $actor, 'status', $id, $row['Status'] . ' -> ' . $to); $flash('Status is now ' . $to . '.'); }
            $flash('Invalid status.');
        } elseif ($act === 'image_upload') {
            $meta = MapImages::validateMeta($_POST);
            if (!$meta['ok']) $flash('Photo not saved: ' . implode(' ', $meta['errors']));
            $f = $_FILES['photo'] ?? null;
            if (!$f) $flash('Choose a photo.');
            $st = MapImages::storeUpload($f);
            if (!$st['ok']) $flash('Photo not saved: ' . $st['error']);
            $first = count(MapImages::listFor($dbh, $id)) === 0;
            $m = $meta['data'];
            $imgId = $places->addImage($id, $st['file'], $st['thumb'], $m['alt'], $m['credit'], $m['license'], $m['source_url'], $first || !empty($_POST['make_primary']));
            MapAdmin::audit($dbh, $actor, 'image_add', $id, 'Image #' . $imgId . ' (' . $m['license'] . ')');
            $flash('Photo added.');
        } elseif ($act === 'image_primary') {
            $ok = MapImages::setPrimary($dbh, $id, (int)($_POST['image_id'] ?? 0));
            if ($ok) MapAdmin::audit($dbh, $actor, 'image_primary', $id, 'Image #' . (int)$_POST['image_id']);
            $flash($ok ? 'Main photo changed.' : 'Photo not found.');
        } elseif ($act === 'image_delete') {
            $ok = MapImages::delete($dbh, $id, (int)($_POST['image_id'] ?? 0));
            if ($ok) MapAdmin::audit($dbh, $actor, 'image_delete', $id, 'Image #' . (int)$_POST['image_id']);
            $flash($ok ? 'Photo deleted.' : 'Photo not found.');
        } elseif ($act === 'image_move') {
            $order = array_map(static fn($r) => (int)$r['ImageId'], MapImages::listFor($dbh, $id));
            $iid = (int)($_POST['image_id'] ?? 0); $pos = array_search($iid, $order, true); $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
            if ($pos !== false && isset($order[$pos + $dir])) { [$order[$pos], $order[$pos + $dir]] = [$order[$pos + $dir], $order[$pos]]; MapImages::reorder($dbh, $id, $order); }
            $flash('Photo order updated.');
        }
    }
}

$cats = MapPlaces::categories();
$aliases = $row ? $places->aliases($id) : [];
$images = $row ? MapImages::listFor($dbh, $id) : [];
$audit = $row ? MapAdmin::auditFor($dbh, $id, 12) : [];
$cfg = MapConfig::publicConfig();
$cfgJson = json_encode(['styles' => $cfg['styles'] ?? [], 'default_style' => $cfg['default_style'] ?? '', 'attribution' => $cfg['attribution'] ?? '', 'center' => $cfg['center'] ?? [90.4125, 23.8103], 'zoom' => $cfg['zoom'] ?? 6, 'lang' => 'en'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$err = static function (string $k) use ($errors) { return isset($errors[$k]) ? '<div class="mp-err" role="alert">' . e($errors[$k]) . '</div>' : ''; };
$sel = static function ($a, $b) { return (string)$a === (string)$b ? ' selected' : ''; };
$bd = MapPlaces::BD_BOUNDS;
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | <?php echo $row ? 'Edit place' : 'Add place'; ?></title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/map-admin.css'); ?>" rel="stylesheet">
<link href="../js/vendor/maplibre/maplibre-gl.css" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<p><a href="map-places.php">&larr; All map places</a></p>
<h1 class="mp-title"><?php echo $row ? 'Edit place: ' . e($row['Name']) : 'Add a place'; ?></h1>
<?php if ($row): ?>
<p class="mp-sub">
  <span class="mp-pill <?php echo $row['Status'] === 'active' ? 'ok' : ($row['Status'] === 'archived' ? 'bad' : 'warn'); ?>"><?php echo e($row['Status']); ?></span>
  <span class="mp-pill <?php echo $row['IsVerified'] ? 'ok' : 'warn'; ?>"><?php echo $row['IsVerified'] ? 'Verified by ' . e($row['VerifiedBy']) . ' on ' . e(substr((string)$row['VerifiedAt'], 0, 10)) : 'Unverified'; ?></span>
  <span class="mp-pill">Source: <?php echo e($row['Source']); ?></span>
  <?php if ($row['IsDemo']): ?><span class="mp-pill bad">Demo data</span><?php endif; ?>
  <?php if ($row['Source'] === 'osm'): ?>
    <br><small>Imported from OpenStreetMap<?php if ($row['SourceRef'] && preg_match('#^(node|way|relation)/\d+$#', (string)$row['SourceRef'])): ?> (<a href="https://www.openstreetmap.org/<?php echo e($row['SourceRef']); ?>" target="_blank" rel="noopener"><?php echo e($row['SourceRef']); ?></a>)<?php endif; ?>.
    Licence and attribution: <?php echo e($row['Attribution'] ?: 'OpenStreetMap contributors (ODbL)'); ?>. Source fields are read-only.</small>
  <?php endif; ?>
</p>
<?php endif; ?>

<?php if ($msg): ?><div class="mp-alert <?php echo e($msgType); ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>
<?php foreach ($warnings as $w): ?><div class="mp-alert warn"><?php echo e($w); ?></div><?php endforeach; ?>
<?php if ($dups): ?>
  <div class="mp-alert warn" role="alert"><b>A similar place already exists nearby:</b>
    <ul style="margin:6px 0 0 18px"><?php foreach ($dups as $d): ?><li><a href="map-place-edit.php?id=<?php echo (int)$d['id']; ?>"><?php echo e($d['name']); ?></a> &middot; <?php echo (int)$d['distance_m']; ?> m away</li><?php endforeach; ?></ul>
    Edit the existing place instead, or tick &ldquo;Create anyway&rdquo; below if this really is a different place.</div>
<?php endif; ?>

<form method="post" id="placeForm" novalidate>
<?php tms_csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
<div class="mp-grid">
  <div class="mp-card card">
    <h2>Basics</h2>
    <div class="mp-field"><label for="f_name">Name (English or common name) *</label><input class="mp-in wide" id="f_name" name="name" maxlength="200" required value="<?php echo e($form['name']); ?>"><?php echo $err('name'); ?></div>
    <div class="mp-field"><label for="f_bn">Name in Bangla</label><input class="mp-in wide" lang="bn" id="f_bn" name="name_bn" maxlength="200" value="<?php echo e($form['name_bn']); ?>"><?php echo $err('name_bn'); ?>
      <div class="mp-help">Use the official or commonly used Bangla name. It is also added as a searchable alias.</div></div>
    <div class="mp-field"><label for="f_cat">Category *</label><select class="mp-in wide" id="f_cat" name="category"><?php foreach ($cats as $c): ?><option value="<?php echo e($c['id']); ?>"<?php echo $sel($form['category'], $c['id']); ?>><?php echo e($c['label']); ?></option><?php endforeach; ?></select><?php echo $err('category'); ?></div>
    <div class="mp-field"><label for="f_sub">Subcategory</label><input class="mp-in wide" id="f_sub" name="subcategory" maxlength="60" value="<?php echo e($form['subcategory']); ?>"><div class="mp-help">Optional, for example &ldquo;archaeological site&rdquo; or &ldquo;guest house&rdquo;.</div></div>
    <div class="mp-field"><label for="f_sum">Short summary (max 500 characters)</label><textarea class="mp-in wide" id="f_sum" name="summary" rows="3" maxlength="500"><?php echo e($form['summary']); ?></textarea><?php echo $err('summary'); ?></div>
    <div class="mp-field"><label for="f_desc">Description</label><textarea class="mp-in wide" id="f_desc" name="description" rows="6"><?php echo e($form['description']); ?></textarea></div>
    <div class="mp-field"><label for="f_status">Visibility</label><select class="mp-in wide" id="f_status" name="status">
      <option value="pending"<?php echo $sel($form['status'], 'pending'); ?>>Pending (not shown on the public map)</option>
      <option value="active"<?php echo $sel($form['status'], 'active'); ?>>Active (shown on the public map)</option>
      <option value="archived"<?php echo $sel($form['status'], 'archived'); ?>>Archived (hidden)</option></select></div>
  </div>

  <div class="mp-card card">
    <h2>Location</h2>
    <div class="mp-field"><label for="f_loc">Locality</label><input class="mp-in wide" id="f_loc" name="locality" maxlength="150" value="<?php echo e($form['locality']); ?>"></div>
    <div class="mp-field"><label for="f_dist">District</label><input class="mp-in wide" id="f_dist" name="district" maxlength="100" value="<?php echo e($form['district']); ?>"></div>
    <div class="mp-field"><label for="f_reg">Division / region</label><input class="mp-in wide" id="f_reg" name="region" maxlength="100" value="<?php echo e($form['region']); ?>"></div>
    <div class="mp-field"><label for="f_cty">Country</label><input class="mp-in wide" id="f_cty" name="country" maxlength="100" value="<?php echo e($form['country']); ?>"></div>
    <div class="mp-grid" style="grid-template-columns:1fr 1fr">
      <div class="mp-field"><label for="f_lat">Latitude *</label><input class="mp-in wide" id="f_lat" name="lat" inputmode="decimal" required value="<?php echo e($form['lat']); ?>" aria-describedby="coordHelp"></div>
      <div class="mp-field"><label for="f_lng">Longitude *</label><input class="mp-in wide" id="f_lng" name="lng" inputmode="decimal" required value="<?php echo e($form['lng']); ?>" aria-describedby="coordHelp"></div>
    </div>
    <?php echo $err('coordinates'); ?>
    <div class="mp-help" id="coordHelp">Latitude first (north/south, Bangladesh is about 20.6 to 26.6), then longitude (east/west, about 88.0 to 92.7). Type the values, click the map or drag the pin. They stay in sync.</div>
    <div class="mp-alert warn" id="coordWarn" role="status" hidden style="margin-top:10px"></div>
    <p class="mp-help">Only enter coordinates you have checked against a reliable source. Never guess.</p>
  </div>
</div>

<div class="mp-card card">
  <h2>Place the pin</h2>
  <div class="mp-map" id="mpMap" role="application" aria-label="Map for choosing the location. Use the latitude and longitude fields for keyboard entry."></div>
  <p class="mp-help">Click the map to move the pin, or drag it. The map style is loaded from OpenFreeMap. If it cannot load, type the coordinates instead.</p>
</div>

<div class="mp-grid">
  <div class="mp-card card">
    <h2>Visitor information</h2>
    <p class="mp-help" style="margin-top:-4px">Leave anything you have not verified as &ldquo;Unknown&rdquo;. Unknown is shown to visitors as nothing, never as &ldquo;no&rdquo; or &ldquo;free&rdquo;.</p>
    <div class="mp-field"><label for="f_hs">Opening hours</label>
      <select class="mp-in" id="f_hs" name="hours_state"><option value="unknown"<?php echo $sel($form['hours_state'], 'unknown'); ?>>Unknown</option><option value="known"<?php echo $sel($form['hours_state'], 'known'); ?>>Known</option></select>
      <textarea class="mp-in wide" id="f_ht" name="hours_text" rows="3" maxlength="1500" aria-label="Opening hours text" placeholder="e.g. Sat to Thu 09:00 to 17:00, closed Friday"><?php echo e($form['hours_text']); ?></textarea><?php echo $err('opening_hours'); ?></div>
    <div class="mp-field"><label for="f_fs">Admission fee</label>
      <select class="mp-in" id="f_fs" name="fee_state"><option value="unknown"<?php echo $sel($form['fee']['state'], 'unknown'); ?>>Unknown</option><option value="free"<?php echo $sel($form['fee']['state'], 'free'); ?>>Free</option><option value="paid"<?php echo $sel($form['fee']['state'], 'paid'); ?>>Paid</option></select>
      <div style="display:flex;gap:6px;margin-top:6px"><input class="mp-in" style="width:110px" name="fee_amount" inputmode="decimal" placeholder="Amount" aria-label="Fee amount" value="<?php echo e($form['fee']['amount']); ?>"><input class="mp-in" style="width:80px" name="fee_currency" maxlength="3" aria-label="Currency" value="<?php echo e($form['fee']['currency']); ?>"></div>
      <input class="mp-in wide" style="margin-top:6px" name="fee_text" maxlength="200" placeholder="Note, e.g. 20 for locals and 100 for foreigners" aria-label="Fee note" value="<?php echo e($form['fee']['text']); ?>"></div>
    <div class="mp-field"><label for="f_as">Wheelchair / step-free access</label>
      <select class="mp-in" id="f_as" name="acc_state"><option value="unknown"<?php echo $sel($form['acc']['state'], 'unknown'); ?>>Unknown</option><option value="yes"<?php echo $sel($form['acc']['state'], 'yes'); ?>>Yes</option><option value="limited"<?php echo $sel($form['acc']['state'], 'limited'); ?>>Limited</option><option value="no"<?php echo $sel($form['acc']['state'], 'no'); ?>>No</option></select>
      <input class="mp-in wide" style="margin-top:6px" name="acc_text" maxlength="300" placeholder="Details, e.g. ramp at the main gate" aria-label="Accessibility details" value="<?php echo e($form['acc']['text']); ?>"></div>
    <div class="mp-field"><label for="f_fam">Family-friendly</label>
      <select class="mp-in" id="f_fam" name="family"><option value="unknown"<?php echo $sel($form['family'], 'unknown'); ?>>Unknown</option><option value="yes"<?php echo $sel($form['family'], 'yes'); ?>>Yes</option><option value="no"<?php echo $sel($form['family'], 'no'); ?>>No</option></select></div>
    <div class="mp-field"><label for="f_fac">Facilities (comma separated)</label><input class="mp-in wide" id="f_fac" name="facilities" value="<?php echo e($form['facilities']); ?>" placeholder="parking, toilets, guided tours"></div>
    <div class="mp-field"><label for="f_web">Website</label><input class="mp-in wide" id="f_web" name="website" maxlength="255" value="<?php echo e($form['website']); ?>" placeholder="https://"><?php echo $err('website'); ?></div>
    <div class="mp-field"><label for="f_ph">Phone</label><input class="mp-in wide" id="f_ph" name="phone" maxlength="40" value="<?php echo e($form['phone']); ?>"><?php echo $err('phone'); ?></div>
  </div>
</div>

<?php if ($dups): ?><label style="display:block;margin:6px 0 12px"><input type="checkbox" name="confirm_duplicate" value="1"> Create anyway, this is a different place</label><?php endif; ?>
<button class="mp-btn primary" type="submit"><i class="fa fa-save"></i> <?php echo $row ? 'Save changes' : 'Create place'; ?></button>
</form>

<?php if ($row): ?>
<div class="mp-grid" style="margin-top:24px">
  <div class="mp-card card">
    <h2>Verification</h2>
    <p style="font-size:13.5px"><?php echo $row['IsVerified'] ? 'This place is verified. Visitors see a verified badge.' : 'This place is unverified. Visitors see it marked as unverified.'; ?></p>
    <form method="post"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <?php if (!$row['IsVerified']): ?>
        <label style="display:block;margin-bottom:8px;font-size:13px"><input type="checkbox" name="confirm_checked" value="1"> I checked the name, the coordinates and the details against a reliable source.</label>
        <button class="mp-btn ok" name="to" value="1">Mark verified</button>
      <?php else: ?><button class="mp-btn danger" name="to" value="0">Remove verification</button><?php endif; ?>
    </form>
    <hr>
    <form method="post"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <?php if ($row['Status'] !== 'active'): ?><button class="mp-btn ok" name="to" value="active">Publish</button><?php endif; ?>
      <?php if ($row['Status'] !== 'archived'): ?><button class="mp-btn danger" name="to" value="archived" onclick="return confirm('Archive this place? It disappears from the public map.');">Archive</button>
      <?php else: ?><button class="mp-btn neutral" name="to" value="pending">Restore as pending</button><?php endif; ?>
    </form>
  </div>

  <div class="mp-card card">
    <h2>Search aliases</h2>
    <p class="mp-help" style="margin-top:-4px">Verified alternative spellings and local names so visitors can find this place. Add only names that are really used.</p>
    <?php if (!$aliases): ?><p>No aliases yet.</p><?php endif; ?>
    <ul style="list-style:none;padding:0">
    <?php foreach ($aliases as $a): ?>
      <li style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><span class="mp-pill"><?php echo e($a['lang']); ?></span><span <?php echo $a['lang'] === 'bn' ? 'lang="bn"' : ''; ?>><?php echo e($a['alias']); ?></span>
        <form method="post" style="margin-left:auto"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="alias_remove"><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><input type="hidden" name="alias_id" value="<?php echo (int)$a['id']; ?>"><button class="mp-btn danger" aria-label="Remove alias <?php echo e($a['alias']); ?>">Remove</button></form></li>
    <?php endforeach; ?></ul>
    <form method="post" class="mp-filters"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="alias_add"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <div><label for="al_t">New alias</label><input class="mp-in" id="al_t" name="alias" maxlength="200" required></div>
      <div><label for="al_l">Language</label><select class="mp-in" id="al_l" name="lang"><option value="en">English / Latin spelling</option><option value="bn">Bangla</option><option value="und">Other</option></select></div>
      <button class="mp-btn primary">Add</button></form>
  </div>
</div>

<div class="mp-card card">
  <h2>Photographs</h2>
  <p class="mp-help" style="margin-top:-4px">Only upload photos you own or have permission to show. Every photo needs alt text, a credit and a licence. Photos are re-encoded on upload (metadata such as GPS tags is removed). JPEG, PNG or WebP, up to 5 MB.</p>
  <?php if (!MapImages::gdAvailable()): ?><div class="mp-alert warn">The PHP GD extension is not enabled, so photos cannot be uploaded. Enable <code>extension=gd</code> in php.ini and restart Apache.</div><?php endif; ?>
  <?php if (!$images): ?><p>No photographs yet. Visitors see a neutral placeholder.</p><?php endif; ?>
  <div class="mp-thumbs">
  <?php foreach ($images as $im): ?>
    <div class="mp-thumb">
      <img src="../<?php echo e($im['Thumb'] ?: $im['File']); ?>" alt="<?php echo e($im['Alt']); ?>" loading="lazy" width="160" height="120">
      <div><?php echo $im['IsPrimary'] ? '<span class="mp-pill ok">Main photo</span>' : ''; ?> <span class="mp-pill"><?php echo e($im['License']); ?></span></div>
      <div style="margin:4px 0"><?php echo e($im['Credit']); ?></div>
      <form method="post" style="display:flex;gap:4px;flex-wrap:wrap"><?php tms_csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><input type="hidden" name="image_id" value="<?php echo (int)$im['ImageId']; ?>">
        <?php if (!$im['IsPrimary']): ?><button class="mp-btn neutral" name="action" value="image_primary">Make main</button><?php endif; ?>
        <button class="mp-btn neutral" name="action" value="image_move" onclick="this.form.dir.value='up'" aria-label="Move earlier">&uarr;</button>
        <button class="mp-btn neutral" name="action" value="image_move" onclick="this.form.dir.value='down'" aria-label="Move later">&darr;</button>
        <input type="hidden" name="dir" value="down">
        <button class="mp-btn danger" name="action" value="image_delete" onclick="return confirm('Delete this photo permanently?');">Delete</button></form>
    </div>
  <?php endforeach; ?>
  </div>
  <form method="post" enctype="multipart/form-data" style="margin-top:16px"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="image_upload"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
    <div class="mp-grid">
      <div class="mp-field"><label for="ph_f">Photo file *</label><input class="mp-in wide" type="file" id="ph_f" name="photo" accept="image/jpeg,image/png,image/webp" required></div>
      <div class="mp-field"><label for="ph_a">Alt text (describe the photo) *</label><input class="mp-in wide" id="ph_a" name="alt" maxlength="200" required minlength="3"></div>
      <div class="mp-field"><label for="ph_c">Credit (photographer or owner) *</label><input class="mp-in wide" id="ph_c" name="credit" maxlength="200" required></div>
      <div class="mp-field"><label for="ph_l">Licence *</label><select class="mp-in wide" id="ph_l" name="license" required><option value="">Choose&hellip;</option>
        <?php foreach (MapImages::LICENSES as $k => $lbl): ?><option value="<?php echo e($k); ?>"><?php echo e($lbl); ?></option><?php endforeach; ?></select></div>
      <div class="mp-field"><label for="ph_s">Source link (optional)</label><input class="mp-in wide" id="ph_s" name="source_url" maxlength="255" placeholder="https://"></div>
    </div>
    <label style="display:block;margin:6px 0 10px;font-size:13px"><input type="checkbox" name="make_primary" value="1"> Use as the main photo</label>
    <button class="mp-btn primary"<?php echo MapImages::gdAvailable() ? '' : ' disabled'; ?>><i class="fa fa-upload"></i> Upload photo</button>
  </form>
</div>

<div class="mp-card card">
  <h2>Change history</h2>
  <?php if (!$audit): ?><p>No recorded changes yet.</p><?php else: ?>
  <table class="table"><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>
  <?php foreach ($audit as $a): ?><tr><td style="white-space:nowrap"><?php echo e($a['CreatedAt']); ?></td><td><?php echo e($a['Actor']); ?></td><td><?php echo e($a['Action'] . ($a['Detail'] ? ': ' . $a['Detail'] : '')); ?></td></tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>

</div></main></div>
<script src="../js/vendor/maplibre/maplibre-gl-csp.js"></script>
<script src="../js/tms-maplibre.js"></script>
<script>
(function () {
    'use strict';
    var BD = <?php echo json_encode($bd); ?>;           // [west, south, east, north]
    var cfg = <?php echo $cfgJson; ?>;
    var latEl = document.getElementById('f_lat'), lngEl = document.getElementById('f_lng'), ctyEl = document.getElementById('f_cty');
    var warn = document.getElementById('coordWarn'), mapEl = document.getElementById('mpMap');
    var marker = null, ctl = null;

    function num(el) { var v = parseFloat(String(el.value).replace(',', '.')); return isFinite(v) ? v : null; }
    function valid(lat, lng) { return lat !== null && lng !== null && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && !(lat === 0 && lng === 0); }
    function inBd(lat, lng) { return lng >= BD[0] && lng <= BD[2] && lat >= BD[1] && lat <= BD[3]; }
    function check() {
        var lat = num(latEl), lng = num(lngEl), msg = '';
        if ((latEl.value !== '' || lngEl.value !== '') && !valid(lat, lng)) msg = 'Latitude must be between -90 and 90, longitude between -180 and 180.';
        else if (valid(lat, lng)) {
            var c = (ctyEl.value || '').toLowerCase();
            var swapped = Math.abs(lat) > 40 && Math.abs(lng) <= 40 && inBd(lng, lat);
            if (swapped) msg = 'These look swapped. Latitude comes first: for Bangladesh try latitude ' + lng + ' and longitude ' + lat + '.';
            else if ((c === '' || c.indexOf('bangladesh') !== -1) && !inBd(lat, lng)) msg = 'These coordinates are outside Bangladesh. Check that latitude and longitude are not swapped and that the place is really abroad.';
        }
        warn.hidden = !msg; warn.textContent = msg;
    }
    function setMarker(lat, lng, fly) {
        if (!ctl || !window.maplibregl) return;
        if (!marker) {
            marker = new maplibregl.Marker({ draggable: true, color: '#176B50' }).setLngLat([lng, lat]).addTo(ctl.map);
            marker.on('dragend', function () { var p = marker.getLngLat(); latEl.value = p.lat.toFixed(6); lngEl.value = p.lng.toFixed(6); check(); });
        } else marker.setLngLat([lng, lat]);
        if (fly) ctl.flyTo(lng, lat, Math.max(ctl.map.getZoom(), 13));
    }
    function fromInputs(fly) { var lat = num(latEl), lng = num(lngEl); check(); if (valid(lat, lng)) setMarker(lat, lng, fly); }

    try {
        var start = valid(num(latEl), num(lngEl)) ? { center: [num(lngEl), num(latEl)], zoom: 13 } : {};
        ctl = TmsMapGL.createMap(mapEl, { config: cfg, base: '../', lang: 'en', center: start.center, zoom: start.zoom, controls: { geolocate: false } });
        ctl.map.on('click', function (ev) { latEl.value = ev.lngLat.lat.toFixed(6); lngEl.value = ev.lngLat.lng.toFixed(6); setMarker(ev.lngLat.lat, ev.lngLat.lng, false); check(); });
        ctl.map.on('load', function () { fromInputs(false); });
    } catch (e) {
        mapEl.innerHTML = '<p style="padding:16px">The map could not be loaded. Enter the latitude and longitude by hand.</p>';
    }
    ['input', 'change'].forEach(function (ev) { latEl.addEventListener(ev, function () { fromInputs(false); }); lngEl.addEventListener(ev, function () { fromInputs(false); }); });
    ctyEl.addEventListener('input', check);
    check();
})();
</script>
<script src="../js/tms-a11y.js" defer></script>
</body>
</html>

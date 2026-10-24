<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
require_once dirname(__DIR__) . '/backend/services/TravelNoticeService.php';
tms_require_admin();
tms_admin_require_role($dbh, ['operations', 'manager']);
$actor = (string)($_SESSION['alogin'] ?? 'admin');
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$msg = ''; $msgType = ''; $errors = []; $form = []; $editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0) ?: null;
        $r = TravelNoticeService::save($dbh, $id, $_POST, $actor);
        if ($r['ok']) { $msg = 'Notice saved.'; $msgType = 'success'; $editId = 0; }
        else { $errors = $r['errors']; $form = $_POST; $editId = (int)$id; }
    } elseif ($act === 'status') {
        $ok = TravelNoticeService::setStatus($dbh, (int)($_POST['id'] ?? 0), (string)($_POST['to'] ?? ''));
        $msg = $ok ? 'Notice updated.' : 'Could not update the notice.'; $msgType = $ok ? 'success' : 'error';
    }
}
if ($editId && !$form) { $row = TravelNoticeService::find($dbh, $editId); if ($row) $form = ['kind' => $row['Kind'], 'country' => $row['Country'], 'title' => $row['Title'], 'body' => $row['Body'], 'source_name' => $row['SourceName'], 'source_url' => $row['SourceUrl'], 'verified_on' => $row['VerifiedOn'], 'expires_on' => $row['ExpiresOn']]; else $editId = 0; }
$v = fn($k) => e($form[$k] ?? '');
$all = TravelNoticeService::all($dbh);
?>
<!DOCTYPE html>
<html lang="<?php echo e(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Travel Notices</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row h1{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:var(--text-primary);margin:0 0 14px;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.nx-form{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;padding:18px;margin-bottom:26px}.nx-form .full{grid-column:1/-1}
@media(max-width:760px){.nx-form{grid-template-columns:1fr}}
.nx-form label{display:block;font-size:12.5px;font-weight:700;margin-bottom:4px}.nx-form input,.nx-form select,.nx-form textarea{width:100%;min-height:40px;padding:8px 10px;border:1px solid #cbd5cf;border-radius:6px;font:inherit}
.nx-err{color:#991b1b;font-size:12.5px;margin-top:3px}.nx-hint{color:#5F6D65;font-size:12px;margin-top:3px}
.nx-btn{display:inline-flex;align-items:center;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;border:1px solid transparent;cursor:pointer;min-height:32px;text-decoration:none}
.nx-ok{background:#d1fae5;color:#065f46}.nx-neutral{background:#E6EFE9;color:#104B3A}.nx-bad{background:#fee2e2;color:#991b1b}
.nx-wrap{overflow-x:auto;position:relative}.nx-wrap table{min-width:820px}
html[data-theme="dark"] .nx-hint{color:#AAB8AF}html[data-theme="dark"] .nx-err{color:#FCA5A5}
html[data-theme="dark"] .nx-neutral,html[data-theme="dark"] .sx-neutral{background:#24332B;color:#EAF1EC}html[data-theme="dark"] .nx-ok,html[data-theme="dark"] .sx-ok{background:#14321F;color:#9AE6BC}html[data-theme="dark"] .nx-bad,html[data-theme="dark"] .sx-bad{background:#3A1414;color:#FCA5A5}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row"><h1><i class="fa fa-info-circle" style="color:#176B50;margin-right:10px;"></i>Travel Notices</h1></div>
<p style="color:#5F6D65;max-width:780px">Enter entry requirements, disruptions and sustainability notes <b>only from an official or named source</b>. Every notice needs the source link and the date you checked it. Notices are shown to customers with that date and are flagged as possibly out of date after 7 days (disruptions), 90 days (entry rules) or 365 days (sustainability). Disruptions must have an expiry date. This is not a live feed and does not score anything.</p>
<?php if ($msg): ?><div class="alert-msg alert-<?php echo $msgType; ?>" role="status"><?php echo e($msg); ?></div><?php endif; ?>
<?php if (!empty($errors['form'])): ?><div class="alert-msg alert-error" role="alert"><?php echo e($errors['form']); ?></div><?php endif; ?>
<form method="post" class="card nx-form" aria-label="<?php echo $editId ? 'Edit notice' : 'Add notice'; ?>"><?php tms_csrf_field(); ?>
<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$editId; ?>">
<?php
$fld = function (string $k, string $label, string $type = 'text', string $attrs = '', string $hint = '', bool $full = false) use ($v, $errors) {
    echo '<div' . ($full ? ' class="full"' : '') . '><label for="f_' . $k . '">' . e($label) . '</label>';
    if ($type === 'textarea') echo '<textarea id="f_' . $k . '" name="' . $k . '" rows="4" ' . $attrs . '>' . $v($k) . '</textarea>';
    else echo '<input id="f_' . $k . '" name="' . $k . '" type="' . $type . '" ' . $attrs . ' value="' . $v($k) . '">';
    if ($hint) echo '<div class="nx-hint">' . e($hint) . '</div>';
    if (!empty($errors[$k])) echo '<div class="nx-err" role="alert">' . e($errors[$k]) . '</div>';
    echo '</div>';
};
?>
<div><label for="f_kind">Type</label><select id="f_kind" name="kind" required>
<?php foreach (TravelNoticeService::KINDS as $k => $lbl): ?><option value="<?php echo $k; ?>"<?php echo ($form['kind'] ?? '') === $k ? ' selected' : ''; ?>><?php echo e($lbl); ?></option><?php endforeach; ?></select>
<?php if (!empty($errors['kind'])): ?><div class="nx-err" role="alert"><?php echo e($errors['kind']); ?></div><?php endif; ?></div>
<?php
$fld('country', 'Country', 'text', 'required maxlength="100"', 'Must match the package country spelling to appear on package pages.');
$fld('title', 'Title', 'text', 'required maxlength="160"', '', true);
$fld('body', 'Details (plain text)', 'textarea', 'required maxlength="2000"', '', true);
$fld('source_name', 'Source name', 'text', 'required maxlength="120"', 'For example: Ministry of Foreign Affairs.');
$fld('source_url', 'Source link', 'url', 'required maxlength="255" placeholder="https://"');
$fld('verified_on', 'Checked against the source on', 'date', 'required max="' . date('Y-m-d') . '"');
$fld('expires_on', 'Hide after (required for disruptions, max 60 days)', 'date');
?>
<div class="full"><button class="nx-btn nx-ok" type="submit"><?php echo $editId ? 'Save changes' : 'Add notice'; ?></button> <?php if ($editId): ?><a class="nx-btn nx-neutral" href="manage-travel-notices.php">Cancel</a><?php endif; ?></div>
</form>

<h2 style="font-size:17px;margin:6px 0 10px">All notices (<?php echo count($all); ?>)</h2>
<div class="nx-wrap card"><table class="table"><thead><tr><th>Country</th><th>Type</th><th>Title</th><th>Checked</th><th>Hides after</th><th>State</th><th>Actions</th></tr></thead><tbody>
<?php if (!$all): ?><tr><td colspan="7" style="text-align:center;color:#5F6D65;padding:22px">No notices yet. Nothing is shown to customers until you add one.</td></tr><?php endif; ?>
<?php foreach ($all as $n): ?>
<tr><td><?php echo e($n['Country']); ?></td><td><?php echo e(TravelNoticeService::KINDS[$n['Kind']] ?? $n['Kind']); ?></td><td><?php echo e($n['Title']); ?><br><small><a href="<?php echo e($n['SourceUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($n['SourceName']); ?><span class="sr-only"> (opens in a new tab)</span></a></small></td>
<td><?php echo e($n['VerifiedOn']); ?><?php if ($n['stale']): ?><br><small style="color:#8F2323;font-weight:700">stale (<?php echo (int)$n['age_days']; ?> days)</small><?php endif; ?></td>
<td><?php echo e($n['ExpiresOn'] ?: '—'); ?></td>
<td><?php echo $n['Status'] === 'archived' ? 'Archived' : ($n['expired'] ? 'Expired' : 'Live'); ?></td>
<td><a class="nx-btn nx-neutral" href="manage-travel-notices.php?edit=<?php echo (int)$n['NoticeId']; ?>">Edit</a>
<form method="post" style="display:inline"><?php tms_csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo (int)$n['NoticeId']; ?>">
<?php if ($n['Status'] === 'active'): ?><button class="nx-btn nx-bad" name="to" value="archived" onclick="return confirm('Archive this notice? Customers will stop seeing it.')">Archive</button>
<?php else: ?><button class="nx-btn nx-ok" name="to" value="active">Restore</button><?php endif; ?></form></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div></main></div>
</body></html>

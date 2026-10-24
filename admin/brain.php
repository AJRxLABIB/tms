<?php
/**
 * admin/brain.php - TourBuddy Brain: try it, see what people asked that it could not answer, and edit the knowledge base.
 * Destination files are data/brain/destinations/<id>.json; every save is validated first and the old file is kept as .bak.
 */
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['ai_manager', 'manager']);
require_once '../backend/brain/BrainChat.php';
require_once '../backend/brain/BrainKbValidator.php';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$dir = realpath(__DIR__ . '/../data/brain/destinations');
$logFile = __DIR__ . '/../data/brain/unanswered.log';
$msg = ''; $err = ''; $reply = null; $testQ = ''; $editId = preg_match('/^[a-z0-9-]{2,41}$/', (string)($_GET['edit'] ?? '')) ? (string)$_GET['edit'] : '';
$editJson = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'test') {
        $testQ = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 300, 'UTF-8');
        $reply = BrainChat::reply($testQ, []);
        $cls = BrainIntent::classify($testQ);
    } elseif ($act === 'clear_log') {
        @unlink($logFile); $msg = 'Unanswered log cleared.';
    } elseif ($act === 'new') {
        $id = strtolower(trim((string)($_POST['new_id'] ?? ''))); $name = trim((string)($_POST['new_name'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $id) || $name === '') $err = 'Use a lowercase id (letters, digits, hyphens) and a name.';
        elseif (is_file("$dir/$id.json")) $err = 'That id already exists.';
        else { file_put_contents("$dir/$id.json", json_encode(BrainKbValidator::template($id, $name), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); tms_log_activity($dbh, $_SESSION['alogin'], 'Brain KB created', $id); header('Location: brain.php?edit=' . rawurlencode($id)); exit; }
    } elseif ($act === 'save') {
        $id = (string)($_POST['id'] ?? '');
        if (!preg_match('/^[a-z0-9-]{2,41}$/', $id) || !is_file("$dir/$id.json")) $err = 'Unknown destination.';
        else {
            $raw = (string)($_POST['json'] ?? ''); $data = json_decode($raw, true);
            $probs = BrainKbValidator::check($data, $id);
            if ($probs) { $err = 'Not saved: ' . implode('; ', $probs); $editId = $id; $editJson = $raw; }
            else {
                copy("$dir/$id.json", "$dir/$id.json.bak");
                file_put_contents("$dir/$id.json", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                tms_log_activity($dbh, $_SESSION['alogin'], 'Brain KB saved', $id);
                $msg = "Saved $id. It is live immediately."; $editId = '';
            }
        }
    }
}
if ($editId !== '' && $editJson === null && is_file("$dir/$editId.json")) $editJson = (string)file_get_contents("$dir/$editId.json");

$dests = [];
foreach (glob("$dir/*.json") as $f) { $d = json_decode((string)file_get_contents($f), true); $id = basename($f, '.json'); $p = BrainKbValidator::check($d, $id); $dests[] = ['id' => $id, 'name' => $d['name'] ?? $id, 'country' => $d['country'] ?? 'Bangladesh', 'attr' => count($d['attractions'] ?? []), 'problems' => $p]; }
$unans = [];
if (is_file($logFile)) { $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -200); foreach (array_reverse($lines) as $l) { $j = json_decode($l, true); if ($j) $unans[] = $j; } }
$counts = []; foreach ($unans as $u) { $k = mb_strtolower($u['place'] !== '' ? $u['place'] : $u['q'], 'UTF-8'); $counts[$k] = ($counts[$k] ?? 0) + 1; } arsort($counts);
?>
<!DOCTYPE html>
<html lang="<?php echo $h(tms_active_language()); ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy Admin | Brain</title>
<link href="css/font-awesome.min2.css" rel="stylesheet"><link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.bn-card{background:var(--card-bg,#fff);border:1px solid var(--border,#d7e0db);border-radius:10px;padding:16px 18px;margin:14px 0;color:var(--text-primary,#17231E)}
.bn-bad{color:#B3302B;font-weight:700}.bn-ok{color:#0B6B2E;font-weight:700}
html[data-theme="dark"] .bn-bad{color:#FCA5A5}html[data-theme="dark"] .bn-ok{color:#5CC79C}
textarea.bn-json{width:100%;min-height:420px;font-family:monospace;font-size:12.5px}
.bn-table td,.bn-table th{font-size:13px;vertical-align:top}
.bn-reply{white-space:pre-wrap;background:rgba(23,107,80,.07);padding:10px 12px;border-radius:8px;margin-top:10px}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<h2><i class="fa fa-bolt"></i> TourBuddy Brain</h2>
<p>The built-in travel engine (no outside AI). It knows <strong><?php echo count($dests); ?></strong> destinations. Provider: <code><?php echo $h(defined('AI_BUILTIN') && AI_BUILTIN ? 'builtin' : (defined('AI_PROVIDER') ? AI_PROVIDER : '')); ?></code>, planner engine: <code><?php echo $h(defined('AI_PLANNER_ENGINE') ? AI_PLANNER_ENGINE : 'auto'); ?></code>.</p>
<?php if ($msg): ?><div class="alert alert-success"><?php echo $h($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?php echo $h($err); ?></div><?php endif; ?>

<?php if ($editId !== '' && $editJson !== null): ?>
<div class="bn-card"><h3>Edit <code><?php echo $h($editId); ?></code></h3>
<p>Valid JSON in the same shape as the other files. Prices are BDT. The previous version is kept as <code><?php echo $h($editId); ?>.json.bak</code>.</p>
<form method="post"><?php tms_csrf_field(); ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?php echo $h($editId); ?>">
<textarea class="bn-json" name="json" spellcheck="false"><?php echo $h($editJson); ?></textarea>
<p style="margin-top:10px"><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> Validate &amp; save</button> <a class="btn btn-default" href="brain.php">Cancel</a></p></form></div>
<?php endif; ?>

<div class="bn-card"><h3>Try it</h3>
<form method="post" class="form-inline"><?php tms_csrf_field(); ?><input type="hidden" name="act" value="test">
<input class="form-control" style="min-width:60%" name="q" maxlength="300" value="<?php echo $h($testQ); ?>" placeholder="e.g. plan 3 days in sylhet for 4 under 30k">
<button class="btn btn-primary" type="submit">Ask</button></form>
<?php if ($testQ !== ''): ?><p style="margin-top:8px">Intent: <code><?php echo $h($cls['intent']); ?></code> (confidence <?php echo $h($cls['confidence']); ?>)</p>
<?php if ($reply): ?><div class="bn-reply"><?php echo $h($reply['reply']); ?></div><?php else: ?><div class="bn-reply">No answer: this would go to the catalogue assistant and be logged below.</div><?php endif; endif; ?></div>

<div class="bn-card"><h3>Questions it could not answer (latest 200)</h3>
<?php if (!$unans): ?><p>None logged yet.</p><?php else: ?>
<p><strong>Most asked:</strong> <?php $i = 0; foreach ($counts as $k => $c) { if ($i++ >= 10) break; echo $h($k) . ' (' . $c . ') &nbsp; '; } ?></p>
<div class="table-responsive" style="max-height:300px;overflow:auto"><table class="table table-striped bn-table"><thead><tr><th>When</th><th>Question</th><th>Place not known</th></tr></thead><tbody>
<?php foreach (array_slice($unans, 0, 60) as $u): ?><tr><td><?php echo $h($u['t'] ?? ''); ?></td><td><?php echo $h($u['q'] ?? ''); ?></td><td><?php echo $h($u['place'] ?? ''); ?></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post" onsubmit="return confirm('Clear the log?')"><?php tms_csrf_field(); ?><input type="hidden" name="act" value="clear_log"><button class="btn btn-default btn-sm">Clear log</button></form>
<?php endif; ?></div>

<div class="bn-card"><h3>Add a destination</h3>
<form method="post" class="form-inline"><?php tms_csrf_field(); ?><input type="hidden" name="act" value="new">
<input class="form-control" name="new_id" placeholder="id, e.g. rome" pattern="[a-z0-9][a-z0-9-]{1,40}" required> <input class="form-control" name="new_name" placeholder="Name, e.g. Rome" required>
<button class="btn btn-primary" type="submit">Create from template</button></form></div>

<div class="bn-card"><h3>Knowledge base</h3>
<div class="table-responsive"><table class="table table-striped bn-table"><thead><tr><th>Id</th><th>Name</th><th>Country</th><th>Attractions</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($dests as $d): ?><tr><td><code><?php echo $h($d['id']); ?></code></td><td><?php echo $h($d['name']); ?></td><td><?php echo $h($d['country']); ?></td><td><?php echo (int)$d['attr']; ?></td>
<td><?php echo $d['problems'] ? '<span class="bn-bad" title="' . $h(implode('; ', $d['problems'])) . '">Invalid</span>' : '<span class="bn-ok">OK</span>'; ?></td>
<td><a href="brain.php?edit=<?php echo $h(rawurlencode($d['id'])); ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table></div></div>
</div></main></div>
</body></html>

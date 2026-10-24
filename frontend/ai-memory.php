<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();
require_once __DIR__ . '/../backend/ai/TravelMemory.php';

/**
 * ai-memory.php — "My AI memory": everything the assistant is allowed to remember about THIS user, in one place.
 * The user can add, edit, delete one, delete all, and switch the feature off. See backend/ai/TravelMemory.php for the rules
 * (explicit saves only, no secrets, per-user, constraints expire after 90 days).
 */
$email = $_SESSION['login'];
$msg = ''; $error = '';
$errText = [
    'sensitive' => 'That looks like a password, card/ID/phone number or e-mail address. These are never saved.',
    'too_short' => 'Please write a little more.', 'too_long' => 'Please keep it under ' . TravelMemory::MAX_LEN . ' characters.',
    'limit' => 'Your memory is full (' . TravelMemory::MAX_ITEMS . ' items). Delete something first.',
    'disabled' => 'Memory is switched off. Turn it on first.', 'duplicate' => 'You already saved that.',
    'not_found' => 'That item was not found.', 'unavailable' => 'Memory is not available right now.', 'bad_category' => 'Choose a type.',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    if (isset($_POST['add'])) {
        $r = TravelMemory::add($dbh, $email, (string)($_POST['category'] ?? ''), (string)($_POST['content'] ?? ''), 'form');
        $r['ok'] ? $msg = $r['created'] ? 'Saved.' : 'You already had that saved.' : $error = $errText[$r['error']] ?? 'Could not save.';
    } elseif (isset($_POST['update'])) {
        $r = TravelMemory::update($dbh, $email, (int)($_POST['id'] ?? 0), (string)($_POST['category'] ?? ''), (string)($_POST['content'] ?? ''));
        $r['ok'] ? $msg = 'Updated.' : $error = $errText[$r['error']] ?? 'Could not update.';
    } elseif (isset($_POST['delete'])) {
        TravelMemory::delete($dbh, $email, (int)($_POST['id'] ?? 0)) ? $msg = 'Deleted.' : $error = 'That item was not found.';
    } elseif (isset($_POST['clear_all'])) {
        $msg = TravelMemory::clearAll($dbh, $email) . ' item(s) deleted.';
    } elseif (isset($_POST['toggle'])) {
        TravelMemory::setEnabled($dbh, $email, !empty($_POST['enabled']));
        $msg = !empty($_POST['enabled']) ? 'Memory is on.' : 'Memory is off. Nothing will be used or saved until you turn it on again.';
    }
}
$items = TravelMemory::all($dbh, $email);
$enabled = TravelMemory::enabled($dbh, $email);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | My AI Memory</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.errorWrap{padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.succWrap{padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.tms-page-hero{position:relative;min-height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;
  background:radial-gradient(ellipse 80% 60% at 30% 0%, rgba(23,107,80,0.35), transparent 60%),
  linear-gradient(160deg, var(--primary-dark) 0%, var(--primary) 55%, #0C3A4A 100%);}
.tms-page-hero h1{position:relative;z-index:2;color:#fff;font-family:var(--font-display);font-size:clamp(26px,4vw,38px);font-weight:600;margin:0;}
.priv-card{background:var(--white);border-radius:var(--radius-md);box-shadow:var(--shadow-sm);border:1px solid #eef2f4;padding:26px;max-width:680px;margin:0 auto 24px;}
.priv-card h3{margin:0 0 6px;font-size:18px;font-weight:700;color:var(--text);}
.priv-card p.desc{color:var(--text-muted);font-size:14px;margin:0 0 16px;}
.priv-card.danger{border:1px solid var(--danger-bg);}
html[data-theme="dark"] .priv-card .btn-danger{color:#3A1414;}
.priv-card.danger h3{color:var(--danger);}
.priv-toggle{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f0f0;}
.priv-toggle:last-child{border-bottom:none;}
.priv-toggle b{font-size:14px;font-weight:600;color:var(--text);}
.priv-toggle small{display:block;color:var(--text-muted);font-size:12.5px;margin-top:2px;}
.mem-item{display:flex;gap:10px;align-items:flex-start;padding:12px 0;border-bottom:1px solid #f0f0f0;}
.mem-item:last-child{border-bottom:none;}
.mem-cat{display:inline-block;font-size:12px;font-weight:700;color:#104b3a;background:#e4f2ec;border-radius:999px;padding:2px 10px;margin-bottom:4px;}
@media (prefers-color-scheme: dark){.mem-cat{color:#d8f3e8;background:#1f3a2f;}}
[data-theme="dark"] .mem-cat{color:#d8f3e8;background:#1f3a2f;}
.mem-note a{text-decoration:underline;}
.mem-text{font-size:14px;color:var(--text);word-break:break-word;}
.mem-meta{font-size:12px;color:var(--text-muted);}
.mem-actions{margin-left:auto;display:flex;gap:6px;flex-shrink:0;}
.mem-edit{display:none;margin-top:8px;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="tms-page-hero"><h1>My AI memory</h1></div>
<div class="container" style="padding:40px 15px 60px;">
    <?php if ($error): ?><div class="errorWrap" role="alert" style="max-width:680px;margin:0 auto 20px;"><strong>ERROR:</strong> <?php echo $e($error); ?></div>
    <?php elseif ($msg): ?><div class="succWrap" role="status" style="max-width:680px;margin:0 auto 20px;"><?php echo $e($msg); ?></div><?php endif; ?>

    <div class="priv-card">
        <h3><i class="fa fa-info-circle"></i> What this is</h3>
        <p class="desc">The assistant only remembers what you choose to save here, or tell it directly ("remember that I prefer quiet hill stations", or in Bangla "মনে রাখো ..."). It never saves things on its own, and never saves passwords, card or ID numbers, phone numbers or e-mail addresses. You can edit or delete anything, any time. Trip constraints expire after <?php echo (int)TravelMemory::CONSTRAINT_DAYS; ?> days. Saying "don't recommend X" hides matching packages from the assistant's results.</p>
        <form method="post" class="priv-toggle" style="border:0;padding:0;">
            <?php tms_csrf_field(); ?>
            <div><b>Use my saved preferences</b><small class="mem-note">Also controlled by "Personalized recommendations" on the <a href="privacy.php" style="text-decoration:underline">Privacy page</a>.</small></div>
            <input type="hidden" name="toggle" value="1">
            <label style="margin:0;"><input type="checkbox" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> onchange="this.form.submit()" aria-label="Use my saved preferences" style="width:20px;height:20px;"></label>
        </form>
    </div>

    <div class="priv-card">
        <h3><i class="fa fa-plus"></i> Add something</h3>
        <form method="post">
            <?php tms_csrf_field(); ?>
            <div class="form-group"><label for="mcat">Type</label>
                <select id="mcat" name="category" class="form-control">
                    <?php foreach (TravelMemory::CATEGORIES as $k => $lab): ?><option value="<?php echo $e($k); ?>"><?php echo $e($lab); ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group"><label for="mtxt">What should I remember?</label>
                <input id="mtxt" name="content" class="form-control" maxlength="<?php echo (int)TravelMemory::MAX_LEN; ?>" required placeholder="e.g. I prefer quiet places and vegetarian food"></div>
            <button class="btn btn-primary" name="add" value="1" type="submit"<?php echo $enabled ? '' : ' disabled'; ?>>Save</button>
        </form>
    </div>

    <div class="priv-card">
        <h3><i class="fa fa-list"></i> Saved (<?php echo (int)count($items); ?>/<?php echo (int)TravelMemory::MAX_ITEMS; ?>)</h3>
        <?php if (!$items): ?><p class="desc">Nothing saved yet.</p><?php endif; ?>
        <?php foreach ($items as $m): $id = (int)$m['MemoryId']; ?>
            <div class="mem-item">
                <div style="flex:1;min-width:0;">
                    <span class="mem-cat"><?php echo $e(TravelMemory::CATEGORIES[$m['Category']] ?? $m['Category']); ?></span>
                    <div class="mem-text"><?php echo $e($m['Content']); ?></div>
                    <div class="mem-meta">Saved <?php echo $e(substr((string)$m['CreatedAt'], 0, 10)); ?> &middot; <?php echo $m['Source'] === 'chat' ? 'told in chat' : 'added here'; ?><?php if (!empty($m['ExpiresAt'])): ?> &middot; expires <?php echo $e(substr((string)$m['ExpiresAt'], 0, 10)); ?><?php endif; ?></div>
                    <form method="post" class="mem-edit" id="edit<?php echo $id; ?>">
                        <?php tms_csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <select name="category" class="form-control" aria-label="Type" style="margin-bottom:6px;">
                            <?php foreach (TravelMemory::CATEGORIES as $k => $lab): ?><option value="<?php echo $e($k); ?>"<?php echo $k === $m['Category'] ? ' selected' : ''; ?>><?php echo $e($lab); ?></option><?php endforeach; ?>
                        </select>
                        <input name="content" class="form-control" value="<?php echo $e($m['Content']); ?>" maxlength="<?php echo (int)TravelMemory::MAX_LEN; ?>" aria-label="Text" required>
                        <button class="btn btn-primary btn-sm" name="update" value="1" type="submit" style="margin-top:6px;">Save change</button>
                    </form>
                </div>
                <div class="mem-actions">
                    <button type="button" class="btn btn-default btn-sm" onclick="var f=document.getElementById('edit<?php echo $id; ?>');f.style.display=f.style.display==='block'?'none':'block';" aria-label="Edit this item"><i class="fa fa-pencil"></i></button>
                    <form method="post" onsubmit="return confirm('Delete this item?');"><?php tms_csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <button class="btn btn-default btn-sm" name="delete" value="1" type="submit" aria-label="Delete this item"><i class="fa fa-trash"></i></button></form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($items): ?>
        <form method="post" onsubmit="return confirm('Delete everything the assistant has saved about you?');" style="margin-top:14px;">
            <?php tms_csrf_field(); ?><button class="btn btn-danger" name="clear_all" value="1" type="submit" style="background:var(--danger);border-color:var(--danger);"><i class="fa fa-trash"></i> Delete everything</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php include('backend/footer.php'); ?>
</body>
</html>

<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['content_manager', 'manager']); // Round 20 RBAC (item 46)

// SECTION 14 AUDIT (Admin Panel — "Translation management"): there was no
// admin-facing way to see or fix a translation at all. tms_t() (the static
// nav/chrome dictionary) lives in a PHP array in includes/i18n.php — safest
// left as a code-level edit, not something to expose write access to from a
// web request. tms_translate_batch()'s AI cache (tbl_translation_cache) is
// real, database-backed content though, and until this page it was
// completely invisible: no way to see what had been auto-translated, no way
// to fix a bad one short of manually deleting a row in phpMyAdmin, and
// (before the source_text column added alongside this page) no way to even
// tell what English string a cached row corresponded to.

$msg = ''; $msgType = '';

if (isset($_POST['update_translation'])) {
    tms_csrf_validate();
    $hash = tms_clean($_POST['source_hash'] ?? '');
    $lang = tms_clean($_POST['lang'] ?? '');
    $newText = trim($_POST['translated'] ?? '');
    if ($hash && $lang && $newText !== '') {
        try {
            $dbh->prepare("UPDATE tbl_translation_cache SET translated=:t WHERE source_hash=:h AND lang=:l")
                ->execute([':t' => $newText, ':h' => $hash, ':l' => $lang]);
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Translation Edited', "lang=$lang, hash=$hash");
            $msg = "Translation updated."; $msgType = 'success';
        } catch (Exception $e) { $msg = "Error: " . $e->getMessage(); $msgType = 'error'; }
    } else {
        $msg = "Translation text can't be empty."; $msgType = 'error';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['hash'], $_POST['lang'])) {
    tms_csrf_validate();
    // Deleting just clears the cache entry — tms_translate_batch() will
    // silently re-request it from the AI the next time that string is
    // needed in that language, rather than deleting content outright.
    $tHash = tms_clean($_POST['hash']); $tLang = tms_clean($_POST['lang']);
    $dbh->prepare("DELETE FROM tbl_translation_cache WHERE source_hash=:h AND lang=:l")
        ->execute([':h' => $tHash, ':l' => $tLang]);
    tms_log_activity($dbh, $_SESSION['alogin'], 'Translation Cache Cleared', "lang=$tLang, hash=$tHash");
    $msg = "Cleared — will be re-translated on next view."; $msgType = 'success';
}

// Filters + pagination
$langFilter = tms_clean($_GET['lang_filter'] ?? '');
$searchQ    = trim($_GET['q'] ?? '');
$perPage    = 25;
$page       = max(1, (int)($_GET['p'] ?? 1));

$where = []; $params = [];
if ($langFilter !== '') { $where[] = 'lang = :lang'; $params[':lang'] = $langFilter; }
if ($searchQ !== '') {
    $where[] = '(source_text LIKE :q OR translated LIKE :q)';
    $params[':q'] = '%' . $searchQ . '%';
}
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $countQ = $dbh->prepare("SELECT COUNT(*) FROM tbl_translation_cache $whereSQL");
    $countQ->execute($params);
    $total = (int)$countQ->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $q = $dbh->prepare("SELECT source_hash, lang, source_text, translated, created_at FROM tbl_translation_cache $whereSQL ORDER BY created_at DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) { $q->bindValue($k, $v, PDO::PARAM_STR); }
    $q->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $q->bindValue(':off', $offset, PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll(PDO::FETCH_OBJ);

    $langCounts = $dbh->query("SELECT lang, COUNT(*) c FROM tbl_translation_cache GROUP BY lang ORDER BY c DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $rows = []; $total = 0; $totalPages = 1; $page = 1; $langCounts = [];
}

// Static dictionary coverage (read-only — tms_t()'s $TMS_TRANSLATIONS array
// lives in code on purpose; this just reports on it, doesn't edit it).
global $TMS_TRANSLATIONS;
$staticCoverage = [];
if (isset($TMS_TRANSLATIONS) && is_array($TMS_TRANSLATIONS)) {
    $enKeyCount = count($TMS_TRANSLATIONS['en'] ?? []);
    foreach ($TMS_TRANSLATIONS as $lc => $dict) {
        $staticCoverage[$lc] = ['count' => count($dict), 'of' => $enKeyCount];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Translation Management</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-edit{background:#dbeafe;color:#1e40af;}.btn-edit:hover{background:#bfdbfe;}
.btn-delete{background:#fee2e2;color:#991b1b;}.btn-delete:hover{background:#fecaca;}
.lang-chip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#eef2ff;color:#4338ca;text-transform:uppercase;}
.cov-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;min-width:140px;}
.cov-bar{height:6px;border-radius:3px;background:#e2e8f0;overflow:hidden;margin-top:6px;}
.cov-bar-fill{height:100%;background:#4338ca;}
.text-cell{max-width:280px;font-size:12.5px;line-height:1.5;}
html[data-theme="dark"] .cov-card { background:#1b222c !important; border-color:#2b3542 !important; }
html[data-theme="dark"] .cov-bar { background:#2b3542 !important; }
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-language" style="color:#4338ca;margin-right:10px;"></i>Translation Management</h2>
</div>

<?php if ($msg): ?><div class="alert-msg alert-<?= $msgType ?>"><i class="fa fa-check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Static dictionary coverage (read-only) -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;">Site Chrome Dictionary (nav/footer/buttons)</h3>
  <p style="font-size:12.5px;color:#64748b;margin-bottom:14px;">
    Read-only — these are the always-instant, hand-written translations used for navigation, footer, and common buttons
    (<code>tms_t()</code> in <code>includes/i18n.php</code>). Editing these is a code change, not a database one, so
    there's no edit action here — this is just visibility into what's covered per language.
  </p>
  <div style="display:flex;gap:14px;flex-wrap:wrap;">
    <?php foreach ($staticCoverage as $lc => $cov):
        $pct = $cov['of'] > 0 ? round(($cov['count'] / $cov['of']) * 100) : 0; ?>
    <div class="cov-card">
      <span class="lang-chip"><?= htmlspecialchars($lc) ?></span>
      <div style="font-size:13px;font-weight:600;margin-top:8px;"><?= $cov['count'] ?> / <?= $cov['of'] ?> keys</div>
      <div class="cov-bar"><div class="cov-bar-fill" style="width:<?= $pct ?>%;"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- AI translation cache — real, database-backed, editable -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px;">
  <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;">AI-Translated Content Cache</h3>
  <p style="font-size:12.5px;color:#64748b;margin-bottom:14px;">
    Package descriptions, the About/Contact page, and similar longer content are translated on first view and cached
    here (<code>tbl_translation_cache</code>) so the AI is only ever called once per string per language. Fix a bad
    translation directly, or clear it to force a fresh AI re-translation next time that page is viewed in that language.
  </p>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:4px;">
    <div><label style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;" for="tr_q">Search</label>
    <input type="text" id="tr_q" name="q" value="<?= htmlspecialchars($searchQ) ?>" placeholder="Search source or translated text..." style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:240px;"></div>
    <div><label style="font-size:12px;font-weight:500;color:#64748b;display:block;margin-bottom:4px;" for="tr_lang_filter">Language</label>
    <select id="tr_lang_filter" name="lang_filter" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      <option value="">All languages</option>
      <?php foreach ($langCounts as $lc => $cnt): ?>
      <option value="<?= htmlspecialchars($lc) ?>" <?= $langFilter === $lc ? 'selected' : '' ?>><?= htmlspecialchars($lc) ?> (<?= $cnt ?>)</option>
      <?php endforeach; ?>
    </select></div>
    <button type="submit" class="btn-v6 primary"><i class="fa fa-search"></i> Filter</button>
    <?php if ($searchQ !== '' || $langFilter !== ''): ?>
    <a href="manage-translations.php" class="action-btn" style="background:#f1f5f9;color:#334155;padding:8px 14px;">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div style="font-size:12.5px;color:#94a3b8;margin-bottom:10px;"><?= $total ?> cached translation<?= $total !== 1 ? 's' : '' ?> &nbsp;·&nbsp; page <?= $page ?> of <?= $totalPages ?></div>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>Lang</th><th>Source (English)</th><th>Translated</th><th>Cached</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (empty($rows)): ?>
  <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">No cached translations<?= ($searchQ !== '' || $langFilter !== '') ? ' match this filter.' : ' yet — they appear here as visitors view pages in a non-English language.' ?></td></tr>
  <?php else: foreach ($rows as $r): ?>
  <tr>
    <td><span class="lang-chip"><?= htmlspecialchars($r->lang) ?></span></td>
    <td class="text-cell"><?= htmlspecialchars($r->source_text ?? '(cached before source tracking was added)') ?></td>
    <td class="text-cell">
      <form method="post" style="display:flex;gap:6px;align-items:flex-start;">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="source_hash" value="<?= htmlspecialchars($r->source_hash) ?>">
        <input type="hidden" name="lang" value="<?= htmlspecialchars($r->lang) ?>">
        <textarea name="translated" rows="2" style="flex:1;font-size:12.5px;padding:6px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-family:inherit;"><?= htmlspecialchars($r->translated) ?></textarea>
        <button type="submit" name="update_translation" class="action-btn btn-edit" title="Save fix"><i class="fa fa-save"></i></button>
      </form>
    </td>
    <td style="font-size:11.5px;color:#94a3b8;white-space:nowrap;"><?= htmlspecialchars($r->created_at) ?></td>
    <td><form method="post" style="display:contents" onsubmit="return confirm('Clear this cached translation? It will be re-translated by AI next time it\'s needed.')">
           <input type="hidden" name="action" value="delete">
           <input type="hidden" name="hash" value="<?= htmlspecialchars($r->source_hash) ?>">
           <input type="hidden" name="lang" value="<?= htmlspecialchars($r->lang) ?>">
           <?php tms_csrf_field(); ?>
           <button type="submit" class="action-btn btn-delete"><i class="fa fa-refresh"></i> Clear</button>
           </form></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
    <?php for ($pn = 1; $pn <= $totalPages; $pn++):
        $qs = http_build_query(array_merge($_GET, ['p' => $pn])); ?>
    <a href="?<?= $qs ?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;
        <?= $pn === $page ? 'background:#1d4ed8;color:#fff;' : 'background:#f1f5f9;color:#334155;' ?>"><?= $pn ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>

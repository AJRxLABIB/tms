<?php
require __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/backend/services/SupplierQuality.php';
$sup = sup_require_login($dbh); $sid = (int)$sup['SupplierId'];
$msg = ''; $err = '';
try { $dbh->query('SELECT 1 FROM tbl_supplier_disputes LIMIT 1'); $ready = true; } catch (Throwable $e) { $ready = false; }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $r = SupplierQuality::fileDispute($dbh, $sid, (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''));
    if ($r['ok']) $msg = 'Your dispute was sent to the TMS team. A person will review it and reply here.'; else $err = (string)$r['error'];
}
sup_head('Quality', $sup, 'quality.php');
?>
<h1>Quality &amp; disputes</h1>
<?php if (!$ready): ?>
<div class="sp-alert warn" role="status">This section is not available yet. Ask the TMS administrator to finish the latest database update.</div>
<?php else:
  $res = SupplierQuality::score(SupplierQuality::metrics($dbh, $sid));
  $disputes = SupplierQuality::disputes($dbh, $sid);
  $review = SupplierQuality::reviewHistory($dbh, $sid, 5);
?>
<p class="sp-sub">This is exactly how TMS measures your account. It is a guide for people at TMS, never an automatic penalty: only an administrator can change your status, and you can dispute anything below.</p>
<?php if ($msg): ?><div class="sp-alert ok" role="status"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($err): ?><div class="sp-alert bad" role="alert"><?php echo h($err); ?></div><?php endif; ?>

<div class="sp-card">
  <h2 style="margin-top:0">Your score: <?php echo $res['score'] === null ? 'not enough data yet' : h($res['score']) . ' / 100'; ?></h2>
  <p><?php echo h($res['note']); ?></p>
  <?php if ($res['needs_review']): ?><div class="sp-alert warn">A TMS team member may review your account for: <?php echo h(implode(' ', $res['review_reasons'])); ?></div><?php endif; ?>
  <div class="sp-table-wrap"><table class="sp-table"><caption class="sr-only">Score criteria</caption>
    <thead><tr><th scope="col">Criterion</th><th scope="col">Weight</th><th scope="col">Result</th><th scope="col">How it was measured</th></tr></thead><tbody>
    <?php foreach ($res['criteria'] as $c): ?>
      <tr><td><?php echo h($c['label']); ?></td><td><?php echo (int)$c['weight']; ?></td>
      <td><?php echo $c['measured'] ? h(round($c['value'] * 100)) . '%' : '<span class="badge info">Not enough data</span>'; ?></td><td><?php echo h($c['explanation']); ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<?php if ($review): ?><div class="sp-card"><h2 style="margin-top:0">Recent decisions by TMS staff</h2><ul>
<?php foreach ($review as $rv): ?><li><?php echo h($rv['CreatedAt']); ?> &mdash; <b><?php echo h(str_replace('_', ' ', $rv['Decision'])); ?></b>: <?php echo h($rv['Note']); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="sp-card">
  <h2 style="margin-top:0">File a dispute</h2>
  <p class="sp-sub">Think a number, review or complaint is wrong? Tell us what and why. Up to 3 can be open at once.</p>
  <form method="post" class="sp-form"><?php tms_csrf_field(); ?>
    <label for="dsub">Subject</label><input id="dsub" name="subject" maxlength="160" required minlength="4">
    <label for="dbody">What is wrong?</label><textarea id="dbody" name="body" rows="5" maxlength="4000" required minlength="20"></textarea>
    <button class="btn" type="submit">Send dispute</button>
  </form>
</div>

<?php if ($disputes): ?><div class="sp-card"><h2 style="margin-top:0">Your disputes</h2>
<?php foreach ($disputes as $d): ?>
  <div style="margin-bottom:14px"><b><?php echo h($d['Subject']); ?></b> &middot; <span class="badge <?php echo $d['Status'] === 'resolved' ? 'ok' : 'warn'; ?>"><?php echo h(str_replace('_', ' ', $d['Status'])); ?></span>
  <div style="font-size:13px"><?php echo nl2br(h($d['Body'])); ?></div>
  <?php if ($d['Resolution']): ?><div class="sp-alert info" style="margin-top:6px"><b>TMS response:</b> <?php echo h($d['Resolution']); ?></div><?php endif; ?></div>
<?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
<?php sup_foot();

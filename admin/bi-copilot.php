<?php
/**
 * admin/bi-copilot.php — natural-language questions over approved, READ-ONLY business metrics.
 *
 * The question is matched to one of a fixed set of metric templates (backend/services/BusinessMetrics.php); fixed
 * prepared SQL produces the figures. No model writes SQL and no database access is exposed to free text. Every
 * answer shows its period, its metric definition, and the figures it is based on. Aggregates only.
 */
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['manager']);   // superadmin is always allowed; 'manager' is the broad business role
require_once '../backend/services/BusinessMetrics.php';

$answer = null; $question = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question'])) {
    tms_csrf_validate();
    $question = mb_substr(trim((string)$_POST['question']), 0, 300, 'UTF-8');
    if ($question !== '') {
        $answer = BusinessMetrics::answer($dbh, $question);
        tms_log_activity($dbh, $_SESSION['alogin'], 'BI Copilot Query',
            mb_substr($question, 0, 150, 'UTF-8') . ' -> ' . ($answer['result']['template'] ?? 'no match'));
    }
}

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$examples = [
    'Which destinations had the highest completed-booking volume last month?',
    'How did booking conversion change over the last quarter?',
    'Which suppliers have elevated cancellation rates?',
    'Which payment transactions remain unreconciled?',
    'What is the difference between gross booking value, collected revenue, refunds, and platform commission?',
    'গত মাসে কোন গন্তব্য সবচেয়ে জনপ্রিয় ছিল?',
];
$pageTitle = 'Business Copilot';
?>
<!DOCTYPE html>
<html lang="<?php echo $h(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy Admin | Business Copilot</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.bi-note{border-left:4px solid var(--primary,#176B50);padding:10px 14px;background:var(--card-bg,#fff);border-radius:6px;margin:14px 0;color:var(--text-primary,#17231E);}
.bi-card{background:var(--card-bg,#fff);border:1px solid var(--border,#d7e0db);border-radius:10px;padding:16px 18px;margin:16px 0;color:var(--text-primary,#17231E);}
.bi-q{width:100%;max-width:760px;}
.bi-chip{display:inline-block;margin:4px 6px 4px 0;padding:5px 11px;border:1px solid var(--border,#d7e0db);border-radius:16px;background:transparent;color:var(--text-primary,#17231E);font-size:12.5px;cursor:pointer;}
.bi-chip:hover,.bi-chip:focus{border-color:var(--primary,#176B50);outline:2px solid transparent;box-shadow:0 0 0 2px rgba(23,107,80,.3);}
.bi-meta{color:var(--text-secondary,#5D7481);font-size:13px;margin:4px 0;}
.bi-table td,.bi-table th{font-size:13px;vertical-align:top;} .bi-table > tbody > tr > td{background:transparent !important;color:var(--text-primary,#17231E);}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
  <h2><i class="fa fa-comments-o"></i> Business Copilot</h2>
  <p class="bi-meta">Ask about approved metrics in English, Bengali or Banglish. Answers come from fixed, read-only queries, never from model-written SQL, and always show the period, the definition and the figures.</p>

  <form method="post" aria-label="Ask a business question">
    <?php tms_csrf_field(); ?>
    <label for="biq" style="display:block;font-weight:600;margin-bottom:4px;">Your question</label>
    <input id="biq" class="form-control bi-q" type="text" name="question" maxlength="300" required value="<?php echo $h($question); ?>" placeholder="e.g. Which suppliers have elevated cancellation rates last quarter?">
    <button type="submit" class="btn btn-primary" style="margin-top:10px;"><i class="fa fa-search"></i> Ask</button>
  </form>
  <div style="margin-top:10px;" aria-label="Example questions">
    <?php foreach ($examples as $ex): ?>
      <button type="button" class="bi-chip" data-q="<?php echo $h($ex); ?>"><?php echo $h($ex); ?></button>
    <?php endforeach; ?>
  </div>

  <?php if ($answer): $res = $answer['result']; ?>
    <div class="bi-card" role="region" aria-live="polite" aria-label="Answer">
      <?php if (!$answer['matched']): ?>
        <p><i class="fa fa-info-circle"></i> <?php echo $h($answer['summary']); ?></p>
      <?php else: ?>
        <h4 style="margin-top:0;"><?php echo $h($res['title']); ?></h4>
        <p class="bi-meta"><strong>Period:</strong> <?php echo $h($res['period']['label']); ?> (<?php echo $h($res['period']['start']); ?> to <?php echo $h($res['period']['end']); ?>)</p>
        <p><?php echo $h($answer['summary']); ?></p>
        <p class="bi-meta"><strong>Definition:</strong> <?php echo $h($res['definition']); ?></p>
        <?php if (!empty($res['figures'])): ?>
          <div class="table-responsive">
          <table class="table table-striped bi-table">
            <thead><tr><?php foreach (array_keys($res['figures'][0]) as $col): ?><th scope="col"><?php echo $h(str_replace('_', ' ', $col)); ?></th><?php endforeach; ?></tr></thead>
            <tbody>
              <?php foreach ($res['figures'] as $row): ?>
                <tr><?php foreach ($row as $val): ?><td><?php echo $val === null ? '<span class="bi-meta">n/a</span>' : $h($val); ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
        <?php foreach ($res['notes'] as $n): ?><p class="bi-meta"><i class="fa fa-info-circle"></i> <?php echo $h($n); ?></p><?php endforeach; ?>
        <?php if (!$res['ok'] && !empty($res['error'])): ?><p style="color:#B3302B;"><?php echo $h($res['error']); ?></p><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="bi-note"><strong>Scope:</strong> aggregate counts and totals only (no customer emails or individual bookings). Supplier flags are prompts for human review, not conclusions. Questions outside the approved metrics get no answer rather than a guess.</div>
</div>
<?php include('includes/footer.php'); ?>
</main>
</div>
<script>
document.querySelectorAll('.bi-chip').forEach(function (b) {
  b.addEventListener('click', function () { var i = document.getElementById('biq'); i.value = b.getAttribute('data-q'); i.focus(); });
});
</script>
</body>
</html>

<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email   = $_SESSION['login'];
$perPage = 25;
$page    = max(1, tms_int($_GET['page'] ?? 1, 1));
$offset  = ($page - 1) * $perPage;

$totalRows = 0;
$cq = $dbh->prepare("SELECT COUNT(*) FROM tblloyalty_log WHERE UserEmail=:e");
$cq->execute([':e' => $email]);
$totalRows = (int)$cq->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$hist = $dbh->prepare("SELECT * FROM tblloyalty_log WHERE UserEmail=:e ORDER BY id DESC LIMIT :lim OFFSET :off");
$hist->bindValue(':e', $email);
$hist->bindValue(':lim', $perPage, PDO::PARAM_INT);
$hist->bindValue(':off', $offset, PDO::PARAM_INT);
$hist->execute();
$history = $hist->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Loyalty Points History</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,#f5c518,#e8a000);color:#fff;padding:40px 0;margin-bottom:30px;}
.info-card{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:20px;}
.pager{display:flex;justify-content:center;gap:6px;margin-top:20px;}
.pager a,.pager span{padding:7px 13px;border-radius:6px;font-size:13px;text-decoration:none;color:var(--secondary);border:1px solid #e2e8f0;}
.pager .current{background:var(--secondary);color:#fff;border-color:var(--secondary);}
.pager a:hover{background:#f1f5f9;}
.pts-pos{color:#0B6B2E;}.pts-neg{color:#B3302B;}
html[data-theme="dark"] .pts-pos{color:#5CC79C;}html[data-theme="dark"] .pts-neg{color:#FCA5A5;}
.table-responsive:focus-visible{outline:2px solid var(--secondary);outline-offset:2px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container">
    <h2 style="margin:0;"><i class="fa fa-history"></i> Loyalty Points History</h2>
    <p style="margin:6px 0 0;opacity:.9;"><a href="loyalty.php" style="color:#fff;text-decoration:underline;">← Back to My Loyalty Points</a></p>
  </div>
</div>
<div class="container" style="padding-bottom:50px;">
  <div class="info-card">
    <?php if (empty($history)): ?>
      <p style="color:var(--text-muted);text-align:center;padding:30px;">No points activity yet. Book a tour, write a review, or refer a friend to start earning!</p>
    <?php else: ?>
    <div class="table-responsive" tabindex="0" role="region" aria-label="Points history"><table class="table table-striped">
      <thead><tr><th>Date</th><th>Description</th><th style="text-align:right;">Points</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
      <tr>
        <td><?= date('d M Y, g:i A', strtotime($h->CreatedAt)) ?></td>
        <td><?= htmlspecialchars($h->Description) ?></td>
        <td style="text-align:right;"><span class="<?= $h->Points > 0 ? 'pts-pos' : 'pts-neg' ?>" style="font-weight:700;"><?= ($h->Points > 0 ? '+' : '') . $h->Points ?> pts</span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>"><i class="fa fa-chevron-left"></i> Prev</a><?php endif; ?>
      <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?page=<?= $p ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>">Next <i class="fa fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php include('backend/footer.php'); ?>
</body></html>

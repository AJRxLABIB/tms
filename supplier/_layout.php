<?php
/**
 * Shared bootstrap + layout for the supplier portal. Every page except login/register
 * calls sup_require_login(); the supplier row is reloaded from the DB on every request,
 * never trusted from the session, so suspension takes effect immediately.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}
require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/services/SupplierService.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function sup_current(PDO $dbh): ?array
{
    $id = (int)($_SESSION['supplier_id'] ?? 0);
    if ($id <= 0) return null;
    $s = SupplierService::find($dbh, $id);
    if (!$s || in_array($s['Status'], [SupplierService::STATUS_SUSPENDED, SupplierService::STATUS_REJECTED], true)) {
        unset($_SESSION['supplier_id']);
        return null;
    }
    return $s;
}

function sup_require_login(PDO $dbh): array
{
    $s = sup_current($dbh);
    if (!$s) { header('Location: login.php'); exit; }
    return $s;
}

function sup_money($n): string { return '৳ ' . number_format((float)$n, 0); }

function sup_status_badge(string $status): string
{
    $map = ['pending' => ['warn', 'Pending verification'], 'verified' => ['ok', 'Verified'], 'rejected' => ['bad', 'Rejected'], 'suspended' => ['bad', 'Suspended'],
            'pending_review' => ['warn', 'In review'], 'approved' => ['ok', 'Live'], 'draft' => ['info', 'Draft'], 'issued' => ['info', 'Issued'], 'paid' => ['ok', 'Paid'], 'void' => ['bad', 'Void']];
    [$cls, $label] = $map[$status] ?? ['info', ucfirst($status)];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

function sup_head(string $title, ?array $supplier = null, string $active = ''): void
{
    $nav = ['dashboard.php' => 'Dashboard', 'packages.php' => 'Packages', 'bookings.php' => 'Bookings', 'settlements.php' => 'Payouts'];
    $more = ['blackouts.php' => 'Blackout dates', 'quality.php' => 'Quality'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo h($title); ?> | TourBuddy Supplier Portal</title>
<script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('tms-theme')||'light');}catch(e){}</script>
<link rel="stylesheet" href="../css/tms-theme.css?v=1">
<link rel="stylesheet" href="supplier.css?v=<?php echo (int)@filemtime(__DIR__ . '/supplier.css'); ?>">
</head>
<body>
<a class="sp-skip" href="#main">Skip to content</a>
<header class="sp-top"><div class="sp-top-in">
  <a class="sp-brand" href="<?php echo $supplier ? 'dashboard.php' : 'login.php'; ?>">TourBuddy <span>Supplier Portal</span></a>
  <?php if ($supplier): ?>
  <nav class="sp-nav" aria-label="Supplier navigation">
    <?php foreach ($nav as $file => $label): ?><a href="<?php echo $file; ?>"<?php echo $active === $file ? ' aria-current="page"' : ''; ?>><?php echo h($label); ?></a><?php endforeach; ?>
    <details class="sp-more"><summary<?php echo isset($more[$active]) ? ' aria-current="page"' : ''; ?>>More</summary>
      <div class="sp-more-list"><?php foreach ($more as $file => $label): ?><a href="<?php echo $file; ?>"<?php echo $active === $file ? ' aria-current="page"' : ''; ?>><?php echo h($label); ?></a><?php endforeach; ?></div></details>
  </nav>
  <div class="sp-tools"><span><?php echo h($supplier['BusinessName']); ?></span>
    <button type="button" class="btn secondary sm" id="themeBtn" aria-label="Toggle dark mode">Theme</button>
    <a class="btn secondary sm" href="logout.php">Sign out</a></div>
  <?php else: ?><div class="sp-nav"></div><div class="sp-tools"><button type="button" class="btn secondary sm" id="themeBtn" aria-label="Toggle dark mode">Theme</button></div><?php endif; ?>
</div></header>
<main class="sp-main" id="main">
<?php
}

function sup_foot(): void
{
    ?></main>
<script>
document.getElementById('themeBtn').addEventListener('click',function(){var d=document.documentElement,t=d.getAttribute('data-theme')==='dark'?'light':'dark';d.setAttribute('data-theme',t);try{localStorage.setItem('tms-theme',t);}catch(e){}});
</script>
</body></html>
<?php
}

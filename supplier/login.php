<?php
require __DIR__ . '/_layout.php';
if (sup_current($dbh)) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $r = SupplierService::login($dbh, (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($r['ok']) {
        session_regenerate_id(true);
        $_SESSION['supplier_id'] = (int)$r['supplier']['SupplierId'];
        header('Location: dashboard.php');
        exit;
    }
    $error = $r['error'];
}
sup_head('Sign in');
?>
<div class="sp-auth">
  <h1>Supplier sign in</h1>
  <p class="sp-sub">Manage your packages, availability and bookings with TMS.</p>
  <?php if (!empty($_GET['registered'])): ?><div class="sp-alert ok" role="status">Account created. An administrator will verify your business before you can list packages. You can sign in to follow the status.</div><?php endif; ?>
  <?php if ($error): ?><div class="sp-alert bad" role="alert"><?php echo h($error); ?></div><?php endif; ?>
  <form method="post" class="sp-card" autocomplete="on">
    <?php tms_csrf_field(); ?>
    <div style="margin-bottom:14px"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="username" value="<?php echo h($_POST['email'] ?? ''); ?>"></div>
    <div style="margin-bottom:18px"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn" type="submit">Sign in</button>
  </form>
  <p>New supplier? <a href="register.php">Apply to become a partner</a>.</p>
</div>
<?php sup_foot();

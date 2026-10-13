<?php
require __DIR__ . '/_layout.php';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $r = SupplierService::register($dbh, $_POST);
    if ($r['ok']) { header('Location: login.php?registered=1'); exit; }
    $errors = $r['errors'];
}
$v = fn($k) => h($_POST[$k] ?? '');
$fld = function (string $k, string $label, string $type = 'text', string $extra = '') use ($errors, $v) {
    echo '<div' . ($type === 'full' ? ' class="full"' : '') . '><label for="' . $k . '">' . h($label) . '</label><input id="' . $k . '" name="' . $k . '" type="' . ($type === 'full' ? 'text' : $type) . '" ' . $extra
        . ' value="' . ($type === 'password' ? '' : $v($k)) . '"' . (isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . $k . '-e"' : '') . '>'
        . (isset($errors[$k]) ? '<div class="sp-err" id="' . $k . '-e">' . h($errors[$k]) . '</div>' : '') . '</div>';
};
sup_head('Become a partner');
?>
<div class="sp-auth" style="max-width:760px">
  <h1>Become a TMS partner</h1>
  <p class="sp-sub">Tell us about your business. We verify every supplier before packages go live.</p>
  <?php if (!empty($errors['form'])): ?><div class="sp-alert bad" role="alert"><?php echo h($errors['form']); ?></div><?php endif; ?>
  <form method="post" class="sp-card sp-form" novalidate>
    <?php tms_csrf_field();
    $fld('business_name', 'Business name', 'text', 'required maxlength="160"');
    $fld('contact_name', 'Contact person', 'text', 'required maxlength="120" autocomplete="name"');
    $fld('email', 'Email', 'email', 'required autocomplete="email"');
    $fld('phone', 'Phone', 'tel', 'required autocomplete="tel"');
    $fld('reg_no', 'Trade licence / registration number (optional)', 'text', 'maxlength="60"');
    $fld('address', 'Business address (optional)', 'text', 'maxlength="255" autocomplete="street-address"');
    $fld('password', 'Password (at least 10 characters)', 'password', 'required autocomplete="new-password"');
    $fld('password2', 'Confirm password', 'password', 'required autocomplete="new-password"');
    ?>
    <div class="full"><button class="btn" type="submit">Submit application</button> <a class="btn secondary" href="login.php">I already have an account</a></div>
  </form>
</div>
<?php sup_foot();

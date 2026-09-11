<?php
// signin.php - Sign-in modal (POST is handled by login.php)
?>

<?php if (isset($_SESSION['login_error'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('signinError');
        if (el) el.style.display = 'block';
    });
</script>
<?php endif; ?>

<div class="modal fade" id="myModal4" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content modal-info">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title" style="color:#333;font-size:18px;padding:5px 0;"><?php echo tms_t('modal_sign_in_title','Sign In'); ?></h4>
            </div>
            <div class="modal-body modal-spa">
                <?php if (isset($_SESSION['login_error'])): ?>
                <div id="signinError" class="alert alert-danger" style="font-size:13px;">
                    <?php echo htmlspecialchars($_SESSION['login_error'], ENT_QUOTES, 'UTF-8');
                          unset($_SESSION['login_error']); ?>
                </div>
                <?php endif; ?>
                <div class="login-grids">
                    <div class="login">
                        <div class="login-right">
                            <form method="post" action="login.php">
                                <?php tms_csrf_field(); ?>
                                <input type="hidden" name="redirect"
                                       value="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="email"    name="email"    placeholder="<?php echo htmlspecialchars(tms_t('ph_email_address','Your Email Address'), ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="email">
                                <input type="password" name="password" placeholder="<?php echo htmlspecialchars(tms_t('lbl_password','Password'), ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="current-password">
                                <h4><a href="forgot-password.php"><?php echo tms_t('link_forgot_password','Forgot password?'); ?></a></h4>
                                <input type="submit" name="signin" value="<?php echo htmlspecialchars(tms_t('nav_signin','SIGN IN'), ENT_QUOTES, 'UTF-8'); ?>">
                            </form>
                            <p style="text-align:center;margin-top:12px;font-size:13px;color:#666;">
                                No account yet?
                                <?php /* Round 14: this pointed at #myModal, which exists
                                         nowhere in the codebase (the only file that
                                         would have defined it, includes/signup.php, is
                                         never included). Clicking it closed the sign-in
                                         box and opened nothing. The real signup modal
                                         lives in includes/header.php. */ ?>
                                <a href="#" onclick="if(typeof closeModal==='function'){closeModal('signinModal');openModal('signupModal');}else{window.location='register.php';}return false;"
                                   style="color:#3F84B1;font-weight:600;">Create one free</a>
                            </p>
                        </div>
                        <div class="clearfix"></div>
                    </div>
                    <p style="font-size:12px;color:#aaa;margin-top:10px;">
                        <?php echo tms_t('msg_agree_terms','By logging in you agree to our'); ?>
                        <a href="page.php?type=terms"><?php echo tms_t('link_terms','Terms'); ?></a> <?php echo tms_t('conj_and','and'); ?>
                        <a href="page.php?type=privacy"><?php echo tms_t('link_privacy_policy','Privacy Policy'); ?></a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

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
                <h4 class="modal-title" style="color:#333;font-size:18px;padding:5px 0;">Sign In</h4>
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
                                <input type="email"    name="email"    placeholder="Your Email Address" required autocomplete="email">
                                <input type="password" name="password" placeholder="Password"           required autocomplete="current-password">
                                <h4><a href="forgot-password.php">Forgot password?</a></h4>
                                <input type="submit" name="signin" value="SIGN IN">
                            </form>
                            <p style="text-align:center;margin-top:12px;font-size:13px;color:#666;">
                                No account yet?
                                <a href="#" data-dismiss="modal" data-toggle="modal" data-target="#myModal"
                                   style="color:#3F84B1;font-weight:600;">Create one free</a>
                            </p>
                        </div>
                        <div class="clearfix"></div>
                    </div>
                    <p style="font-size:12px;color:#aaa;margin-top:10px;">
                        By logging in you agree to our
                        <a href="page.php?type=terms">Terms</a> and
                        <a href="page.php?type=privacy">Privacy Policy</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

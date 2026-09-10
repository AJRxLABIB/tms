<?php
// header.php - Modern redesigned header for TMS
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<script>
// Applied before any visible markup renders (this is the first thing output
// in <body>) so the correct theme is set with no flash-of-wrong-theme.
(function(){
    try {
        <?php if (!empty($tms_landing_mode)): ?>
        // The public landing page always renders in light mode — it's a
        // marketing page a guest sees before signing in, and its own theme
        // toggle is intentionally hidden there (see $tms_landing_mode below),
        // so a dark preference saved from a previous logged-in session must
        // not carry over and leave the visitor with no way to switch back.
        document.documentElement.setAttribute('data-theme', 'light');
        <?php else: ?>
        var t = localStorage.getItem('tms-theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
        <?php endif; ?>
    } catch(e) {}
})();
</script>
<!-- Skip-to-content link (Section 12 audit): a keyboard user landing on any
     page had to tab through the entire top bar, nav, and mega-menu before
     reaching actual page content — no way to bypass it. Visually hidden
     until it receives keyboard focus (Tab), matching the standard pattern.
     Targets #tmsMainContent, a zero-height anchor placed right after the
     shared nav closes below — adding a real per-page "main content" id
     would mean editing 70+ individual page files with different layouts;
     this gives every page a working skip target without touching any of
     them. -->
<a href="#tmsMainContent" class="tms-skip-link">Skip to main content</a>
<style>
.tms-skip-link {
    position: absolute; top: -60px; left: 8px; z-index: 100000;
    background: #0f172a; color: #fff; padding: 10px 18px;
    border-radius: 6px; font-size: 14px; font-weight: 600;
    text-decoration: none; transition: top 0.15s ease;
}
.tms-skip-link:focus { top: 8px; color: #fff; text-decoration: none; }
</style>
<!-- ═══════════════════════════════════════════ TOP BAR ═══════════════════════════════════════════ -->
<?php if (empty($tms_landing_mode)): ?>
<div class="tms-topbar">
    <div class="container">
        <div class="tms-topbar-left">
            <?php if (empty($tms_landing_mode)): ?>
            <a href="index.php"><i class="fa fa-home"></i> <?php echo tms_t('nav_home','Home'); ?></a>
            <?php if(isset($_SESSION['login']) && !empty($_SESSION['login'])): ?>
            <span class="tms-topbar-divider"></span>
            <a href="dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a>
            <a href="profile.php"><i class="fa fa-user-circle"></i> <?php echo tms_t('nav_profile','My Profile'); ?></a>
            <a href="tour-history.php"><i class="fa fa-suitcase"></i> <?php echo tms_t('nav_bookings','My Bookings'); ?></a>
            <a href="alerts.php"><i class="fa fa-bell"></i> Price Alerts</a>
            <a href="group-trips.php"><i class="fa fa-users"></i> Group Trips</a>
            <a href="referral.php"><i class="fa fa-gift"></i> Refer Friends</a>
            <?php else: ?>
            <span class="tms-topbar-divider"></span>
            <a href="admin/index.php"><i class="fa fa-shield"></i> Admin</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="tms-topbar-right">
            <?php if (empty($tms_landing_mode)): ?>
            <!-- Global "See Price In" currency selector (Task 5 fix) — every
                 price on the site is rendered by tms_currency(), which reads
                 this choice from the session, so switching it here updates
                 every price on every page after the reload. -->
            <select id="tmsCurrencySelect" class="tms-currency-select" aria-label="See price in" title="See price in">
                <?php foreach (TMS_CURRENCIES as $tmsCurCode => $tmsCurMeta): ?>
                <option value="<?php echo $tmsCurCode; ?>" <?php echo tms_active_currency()===$tmsCurCode?'selected':''; ?>>
                    <?php echo $tmsCurMeta['symbol'] . ' ' . $tmsCurCode; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="tms-topbar-divider"></span>
            <span class="tms-phone"><i class="fa fa-phone"></i> Toll: +880 1759-443004</span>
            <span class="tms-topbar-divider"></span>
            <?php endif; ?>
            <?php if(empty($tms_landing_mode) && isset($_SESSION['login']) && !empty($_SESSION['login'])):
                $tmsNotifUnread = 0;
                $tmsNotifItems  = [];
                try {
                    $ncq = $dbh->prepare("SELECT COUNT(*) FROM tbl_notifications WHERE UserEmail=:e AND IsRead=0");
                    $ncq->execute([':e' => $_SESSION['login']]);
                    $tmsNotifUnread = (int)$ncq->fetchColumn();
                    $nq = $dbh->prepare("SELECT Message, Link, IsRead, CreatedAt FROM tbl_notifications WHERE UserEmail=:e ORDER BY id DESC LIMIT 8");
                    $nq->execute([':e' => $_SESSION['login']]);
                    $tmsNotifItems = $nq->fetchAll(PDO::FETCH_OBJ);
                } catch (Exception $e) { /* tables may not exist yet on first request — fail quiet */ }
            ?>
            <div class="tms-notif-wrap" id="tmsNotifWrap">
                <button type="button" class="tms-notif-bell" id="tmsNotifBell" onclick="tmsToggleNotif(event)" aria-label="Notifications" title="Notifications">
                    <i class="fa fa-bell-o"></i>
                    <?php if ($tmsNotifUnread > 0): ?><span class="tms-notif-badge"><?php echo $tmsNotifUnread > 9 ? '9+' : $tmsNotifUnread; ?></span><?php endif; ?>
                </button>
                <div class="tms-notif-dropdown" id="tmsNotifDropdown">
                    <div class="tms-notif-head">Notifications</div>
                    <?php if (empty($tmsNotifItems)): ?>
                        <div class="tms-notif-empty">You're all caught up — no notifications yet.</div>
                    <?php else: foreach ($tmsNotifItems as $n): ?>
                        <a class="tms-notif-item<?php echo $n->IsRead ? '' : ' unread'; ?>" href="<?php echo htmlspecialchars($n->Link ?: '#', ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="tms-notif-msg"><?php echo htmlspecialchars($n->Message, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="tms-notif-time"><?php echo htmlspecialchars($n->CreatedAt, ENT_QUOTES, 'UTF-8'); ?></div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <span class="tms-topbar-divider"></span>
            <?php endif; ?>
            <?php if(isset($_SESSION['login']) && !empty($_SESSION['login'])): ?>
            <span class="tms-topbar-welcome" style="color:rgba(255,255,255,0.85);font-size:12.5px;"><?php echo tms_t('nav_welcome','Welcome'); ?>, <?php echo htmlspecialchars(explode('@',$_SESSION['login'])[0]); ?>!</span>
            <a href="logout.php" class="btn-topbar-login"><i class="fa fa-sign-out"></i> <?php echo tms_t('nav_logout','Logout'); ?></a>
            <?php else: ?>
            <a href="javascript:void(0)" onclick="openModal('signupModal')" class="btn-topbar-signup"><i class="fa fa-user-plus"></i> <?php echo tms_t('nav_signup','Sign Up'); ?></a>
            <a href="javascript:void(0)" onclick="openModal('signinModal')" class="btn-topbar-login"><i class="fa fa-sign-in"></i> <?php echo tms_t('nav_signin','Sign In'); ?></a>
            <?php endif; ?>
            <?php if (empty($tms_landing_mode)): ?>
            <!-- PWA install button (Task 8): moved here from a bottom-center
                 dismissible banner. Hidden until the browser actually confirms
                 installability via 'beforeinstallprompt' (see footer.php) —
                 never shown as a fake/always-on button. -->
            <button type="button" id="tmsInstallBtn" class="tms-install-btn" style="display:none;" title="Install TMS as an app" aria-label="Install TMS as an app">
                <i class="fa fa-download"></i> <span>Install App</span>
            </button>
            <span class="tms-topbar-divider" id="tmsInstallDivider" style="display:none;"></span>
            <button type="button" id="tmsThemeToggle" class="tms-theme-switch" onclick="tmsToggleTheme()" aria-label="Toggle dark mode" title="Toggle dark / light mode">
                <span class="tms-theme-track">
                    <i class="fa fa-sun-o tms-track-icon tms-track-icon-sun"></i>
                    <i class="fa fa-moon-o tms-track-icon tms-track-icon-moon"></i>
                </span>
                <span class="tms-theme-knob">
                    <i class="fa fa-sun-o" id="tmsThemeIconSun"></i>
                    <i class="fa fa-moon-o" id="tmsThemeIconMoon" style="display:none;"></i>
                </span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════ MAIN NAVBAR ═══════════════════════════════════════════ -->
<nav class="tms-navbar" id="tmsNavbar">
    <div class="tms-navbar-inner">
        <!-- Logo -->
        <a href="index.php" class="tms-logo">
            <div class="tms-logo-icon"><i class="fa fa-globe"></i></div>
            <div class="tms-logo-text">
                <strong>TMS</strong>
                <span>Tourism Management</span>
            </div>
        </a>

        <?php if (!empty($tms_landing_mode)): ?>
        <div style="display:flex;gap:10px;align-items:center;">
            <a href="javascript:void(0)" onclick="openModal('signupModal')" class="btn-topbar-signup" style="background:var(--secondary);color:#fff;padding:9px 20px;border-radius:24px;font-weight:600;font-size:13.5px;"><i class="fa fa-user-plus"></i> Sign Up</a>
            <a href="javascript:void(0)" onclick="openModal('signinModal')" class="btn-topbar-login" style="background:var(--primary);color:#fff;padding:9px 20px;border-radius:24px;font-weight:600;font-size:13.5px;"><i class="fa fa-sign-in"></i> Sign In</a>
        </div>
        <?php else: ?>

        <!-- Hamburger (mobile) -->
        <button class="tms-hamburger" id="tmsHamburger" onclick="toggleMobileNav()" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>

        <!-- Nav Menu -->
        <ul class="tms-nav-menu" id="tmsNavMenu">
            <li <?php echo $currentPage=='index.php'?'class="active"':''; ?>>
                <a href="index.php"><?php echo tms_t('nav_home','Home'); ?></a>
            </li>
            <li <?php echo $currentPage=='page.php'?'class="active"':''; ?>>
                <a href="page.php?type=aboutus"><?php echo tms_t('nav_about','About'); ?></a>
            </li>
            <li <?php echo $currentPage=='package-list.php'?'class="active"':''; ?>>
                <a href="package-list.php"><?php echo tms_t('nav_packages','Tour Packages'); ?></a>
            </li>
            <li <?php echo $currentPage=='destinations.php'?'class="active"':''; ?>>
                <a href="destinations.php"><i class="fa fa-map-marker" style="font-size:12px;"></i> <?php echo tms_t('nav_destinations','Destinations'); ?></a>
            </li>
            <li <?php echo ($currentPage=='explore.php'||$currentPage=='explore-view.php')?'class="active"':''; ?>>
                <a href="explore.php"><i class="fa fa-compass" style="font-size:12px;"></i> <?php echo tms_t('nav_explore','Explore'); ?></a>
            </li>

            <!-- MEGA DROPDOWN -->
            <li class="tms-has-mega" id="featDropdown">
                <a href="javascript:void(0)" onclick="toggleMegaMenu(event)">
                    <?php echo tms_t('nav_features','Features'); ?> <i class="fa fa-chevron-down" style="font-size:10px;margin-left:3px;"></i>
                </a>
                <div class="tms-mega-menu" id="featMegaMenu">
                    <div class="tms-mega-grid">
                        <!-- Col 1 -->
                        <div class="tms-mega-col">
                            <div class="tms-mega-title"><i class="fa fa-magic"></i> AI & Smart Tools</div>
                            <a href="explore.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-map" style="background:linear-gradient(135deg,#0F4C81,#00A8A8);"><i class="fa fa-compass"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Explore Mode</strong>
                                    <span>Travel any destination — no booking needed</span>
                                </div>
                            </a>
                            <a href="ai-recommend.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-ai"><i class="fa fa-magic"></i></div>
                                <div class="tms-mega-label">
                                    <strong>AI Recommender</strong>
                                    <span>Personalized trip suggestions</span>
                                </div>
                            </a>
                            <a href="budget-planner.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-budget"><i class="fa fa-calculator"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Budget Planner</strong>
                                    <span>Smart travel cost calculator</span>
                                </div>
                            </a>
                            <a href="weather.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-weather"><i class="fa fa-cloud-sun" style="font-size:14px;"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Weather Guide</strong>
                                    <span>Live weather forecasts</span>
                                </div>
                            </a>
                            <a href="compare.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-compare"><i class="fa fa-columns"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Compare Packages</strong>
                                    <span>Side-by-side comparison</span>
                                </div>
                            </a>
                        </div>
                        <!-- Col 2 -->
                        <div class="tms-mega-col">
                            <div class="tms-mega-title"><i class="fa fa-user"></i> Trip Planning & User</div>
                            <a href="tour-map.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-map"><i class="fa fa-map-marker"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Interactive Map</strong>
                                    <span>Explore destinations visually</span>
                                </div>
                            </a>
                            <a href="heatmap.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-map"><i class="fa fa-fire"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Travel Heat Map</strong>
                                    <span>See where travelers are booking most</span>
                                </div>
                            </a>
                            <a href="flight-status.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-map"><i class="fa fa-plane"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Flight Tracker</strong>
                                    <span>Live aircraft position (beta)</span>
                                </div>
                            </a>
                            <a href="custom-trip.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-custom"><i class="fa fa-paint-brush"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Custom Trip</strong>
                                    <span>Build your own itinerary</span>
                                </div>
                            </a>
                            <a href="wishlist.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-wish"><i class="fa fa-heart"></i></div>
                                <div class="tms-mega-label">
                                    <strong>My Wishlist</strong>
                                    <span>Save favourite packages</span>
                                </div>
                            </a>
                            <a href="expense-tracker.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-budget"><i class="fa fa-money"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Expense Tracker</strong>
                                    <span>Log spending, get AI insights</span>
                                </div>
                            </a>
                            <a href="wallet.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-budget"><i class="fa fa-briefcase"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Travel Wallet</strong>
                                    <span>Tickets & documents, secured</span>
                                </div>
                            </a>
                            <a href="emergency.php" class="tms-mega-item">
                                <div class="tms-mega-icon icon-safety"><i class="fa fa-shield"></i></div>
                                <div class="tms-mega-label">
                                    <strong>Safety & Emergency</strong>
                                    <span>Emergency contacts & tips</span>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div style="border-top:1px solid #f0f4f8;margin-top:16px;padding-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="reviews.php" class="tms-mega-item" style="flex:1;min-width:140px;">
                            <div class="tms-mega-icon icon-review"><i class="fa fa-star"></i></div>
                            <div class="tms-mega-label"><strong>Tourist Reviews</strong><span>Community ratings</span></div>
                        </a>
                        <a href="loyalty.php" class="tms-mega-item" style="flex:1;min-width:140px;">
                            <div class="tms-mega-icon icon-loyalty"><i class="fa fa-gift"></i></div>
                            <div class="tms-mega-label"><strong>Loyalty Points</strong><span>Earn & redeem rewards</span></div>
                        </a>
                        <a href="language.php" class="tms-mega-item" style="flex:1;min-width:140px;">
                            <div class="tms-mega-icon icon-lang"><i class="fa fa-language"></i></div>
                            <div class="tms-mega-label"><strong>Language</strong><span>Multi-language support</span></div>
                        </a>
                    </div>
                </div>
            </li>

            <li <?php echo $currentPage=='blog.php'?'class="active"':''; ?>>
                <a href="blog.php"><?php echo tms_t('nav_blog','Blog'); ?></a>
            </li>
            <li <?php echo $currentPage=='compare.php'?'class="active"':''; ?>>
                <a href="compare.php"><i class="fa fa-columns" style="font-size:12px;"></i> <?php echo tms_t('nav_compare','Compare'); ?></a>
            </li>
            <li>
                <a href="page.php?type=contact"><?php echo tms_t('nav_contact','Contact'); ?></a>
            </li>
        </ul>
        <?php endif; ?>
    </div>
</nav>
<div id="tmsMainContent" tabindex="-1"></div>

<!-- ═══════════════════════════════════════════ SIGN IN MODAL ═══════════════════════════════════════════ -->
<div id="signinModal" class="tms-modal-overlay" onclick="if(event.target===this)closeModal('signinModal')">
    <div class="tms-modal-box">
        <button class="tms-modal-close" onclick="closeModal('signinModal')" aria-label="Close sign-in dialog">&times;</button>
        <div class="tms-modal-header">
            <div class="tms-modal-icon"><i class="fa fa-sign-in"></i></div>
            <h3>Welcome Back</h3>
            <p>Sign in to your TMS account</p>
        </div>
        <?php if(isset($_SESSION['login_error'])): ?>
        <div class="tms-alert tms-alert-error"><i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['login_error']); unset($_SESSION['login_error']); ?></div>
        <?php endif; ?>
        <form method="post" action="login.php">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SESSION['post_login_redirect'] ?? $_SERVER['PHP_SELF']); ?>">
            <div class="tms-field">
                <label>Email Address</label>
                <div class="tms-input-wrap"><i class="fa fa-envelope"></i><input type="email" name="EmailId" placeholder="your@email.com" required></div>
            </div>
            <div class="tms-field">
                <label>Password</label>
                <div class="tms-input-wrap"><i class="fa fa-lock"></i><input type="password" name="Password" placeholder="Your password" required></div>
            </div>
            <div style="text-align:right;margin-bottom:20px;"><a href="forgot-password.php" style="font-size:13px;color:var(--secondary);">Forgot password?</a></div>
            <button type="submit" name="signin" class="tms-btn-modal">Sign In <i class="fa fa-arrow-right"></i></button>
        </form>
        <p class="tms-modal-switch">No account? <a href="javascript:void(0)" onclick="closeModal('signinModal');openModal('signupModal')">Create one free →</a></p>
    </div>
</div>

<!-- ═══════════════════════════════════════════ SIGN UP MODAL ═══════════════════════════════════════════ -->
<div id="signupModal" class="tms-modal-overlay" onclick="if(event.target===this)closeModal('signupModal')">
    <div class="tms-modal-box">
        <button class="tms-modal-close" onclick="closeModal('signupModal')" aria-label="Close sign-up dialog">&times;</button>
        <div class="tms-modal-header">
            <div class="tms-modal-icon" style="background:linear-gradient(135deg,var(--secondary),var(--primary));"><i class="fa fa-user-plus"></i></div>
            <h3>Join TMS</h3>
            <p>Start planning your dream trip today</p>
        </div>
        <?php if(isset($_SESSION['reg_error'])): ?>
        <div class="tms-alert tms-alert-error"><i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['reg_error']); unset($_SESSION['reg_error']); ?></div>
        <?php endif; ?>
        <form method="post" action="register.php">
            <?php /* id="signupCsrf" matters: checkEmailLive() below reads the token
                     from this element. It used to call tms_csrf_field(), which emits
                     no id, so the live email check always posted an empty token,
                     always got a 403, and always disabled the submit button. */ ?>
            <input type="hidden" name="csrf_token" id="signupCsrf" value="<?php echo htmlspecialchars(tms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ref" value="<?php echo htmlspecialchars($_COOKIE['tms_ref'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="tms-field">
                <label>Full Name *</label>
                <div class="tms-input-wrap"><i class="fa fa-user"></i><input type="text" name="FullName" placeholder="Your full name" required autocomplete="off" minlength="3"></div>
            </div>
            <div class="tms-field">
                <label>Mobile Number *</label>
                <div class="tms-input-wrap"><i class="fa fa-phone"></i><input type="text" name="MobileNumber" placeholder="e.g. 01712345678" maxlength="15" required></div>
            </div>
            <div class="tms-field">
                <label>Email Address *</label>
                <div class="tms-input-wrap"><i class="fa fa-envelope"></i><input type="email" name="EmailId" id="regEmailField" placeholder="your@email.com" required autocomplete="off" onblur="checkEmailLive(this.value)"></div>
                <div id="emailCheckResult" style="font-size:12px;margin-top:4px;"></div>
            </div>
            <div class="tms-field">
                <label>Password *</label>
                <div class="tms-input-wrap"><i class="fa fa-lock"></i><input type="password" name="Password" placeholder="Minimum 6 characters" required minlength="6"></div>
            </div>
            <div class="tms-field">
                <label>Confirm Password *</label>
                <div class="tms-input-wrap"><i class="fa fa-lock"></i><input type="password" name="CPassword" placeholder="Repeat your password" required minlength="6"></div>
            </div>
            <button type="submit" name="submit" id="regSubmitBtn" class="tms-btn-modal" style="background:linear-gradient(135deg,var(--secondary),var(--primary));">Create My Account <i class="fa fa-arrow-right"></i></button>
        </form>
        <p class="tms-modal-switch">Already have an account? <a href="javascript:void(0)" onclick="closeModal('signupModal');openModal('signinModal')">Sign In →</a></p>
    </div>
</div>

<?php if(isset($_SESSION['reg_success'])): ?>
<div id="successBanner" style="background:linear-gradient(135deg,#00b09b,#96c93d);color:#fff;padding:14px 20px;text-align:center;font-size:15px;font-weight:600;">
    <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['reg_success']); unset($_SESSION['reg_success']); ?>
    <button onclick="document.getElementById('successBanner').style.display='none'" style="background:none;border:none;float:right;cursor:pointer;color:#fff;font-size:18px;" aria-label="Dismiss">&times;</button>
</div>
<?php endif; ?>

<style>
/* Modal Styles */
.tms-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(10,22,50,0.75);
    z-index: 999999;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(4px);
    padding: 20px;
}
.tms-modal-overlay.open { display: flex; }
.tms-modal-box {
    background: #fff;
    border-radius: 20px;
    padding: 40px;
    width: 100%;
    max-width: 440px;
    position: relative;
    max-height: 92vh;
    overflow-y: auto;
    animation: modalSlideIn 0.3s ease;
}
@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.tms-modal-close {
    position: absolute;
    top: 14px;
    right: 18px;
    background: #f5f7fa;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 18px;
    cursor: pointer;
    color: #888;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.tms-modal-close:hover { background: #ffe5e5; color: #e53e3e; }
.tms-modal-header { text-align: center; margin-bottom: 28px; }
.tms-modal-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    margin: 0 auto 14px;
}
.tms-modal-header h3 { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 5px; }
.tms-modal-header p { font-size: 14px; color: var(--text-muted); }
.tms-field { margin-bottom: 16px; }
.tms-field label { display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px; }
.tms-input-wrap { position: relative; display: flex; align-items: center; }
.tms-input-wrap i { position: absolute; left: 14px; color: var(--text-light); font-size: 14px; }
.tms-input-wrap input {
    width: 100%;
    padding: 12px 14px 12px 40px;
    border: 2px solid #e8ecf0;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
    color: var(--text);
}
.tms-input-wrap input:focus { border-color: var(--primary); }
.tms-btn-modal {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    color: #fff;
    border: none;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.3s;
}
.tms-btn-modal:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,76,129,0.3); }
.tms-modal-switch { text-align: center; margin-top: 18px; font-size: 14px; color: #888; }
.tms-modal-switch a { color: var(--primary); font-weight: 700; }
.tms-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
.tms-alert-error { background: #fff0f0; color: #c0392b; border: 1px solid #fcd0d0; }

/* ═══════════════ DARK / LIGHT THEME TOGGLE (pill switch) ═══════════════ */
.tms-theme-switch {
    position: relative;
    width: 60px;
    height: 28px;
    padding: 0;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    vertical-align: middle;
    background: none;
}
.tms-theme-track {
    position: absolute;
    inset: 0;
    border-radius: 20px;
    background: #e4e7ec;
    border: 1px solid rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 7px;
    transition: background 0.3s ease;
}
html[data-theme="dark"] .tms-theme-track { background: #2b3444; border-color: rgba(255,255,255,0.12); }
.tms-track-icon { font-size: 12px; color: #b7bfca; transition: color 0.3s ease; }
html[data-theme="dark"] .tms-track-icon-moon { color: #6f8bf5; }
.tms-track-icon-sun { color: #d8ac57; }
html[data-theme="dark"] .tms-track-icon-sun { color: #4a5568; }
.tms-theme-knob {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #f5a623;
    transition: transform 0.3s cubic-bezier(.4,1.5,.5,1), color 0.3s ease;
}
html[data-theme="dark"] .tms-theme-knob { transform: translateX(32px); color: #4568dc; }
.tms-theme-switch:hover .tms-theme-knob { box-shadow: 0 2px 10px rgba(0,0,0,0.35); }

/* ═══════════════ GLOBAL CURRENCY SELECTOR (Task 5 fix) ═══════════════ */
.tms-currency-select {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.25);
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 6px;
    cursor: pointer;
    outline: none;
}
.tms-currency-select:hover { background: rgba(255,255,255,0.22); }
.tms-currency-select option { color: #0f172a; }

/* ═══════════════ PWA INSTALL BUTTON (Task 8) ═══════════════ */
.tms-install-btn {
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: var(--transition);
    white-space: nowrap;
}
.tms-install-btn:hover { background: rgba(255,255,255,0.28); }

/* Redefining the core palette here means every component already built with
   var(--bg)/var(--text)/var(--white) etc automatically re-themes. This is a
   deliberate real dark mode (a toggle + persisted preference), not reliance
   on the browser's own auto-dark-mode inversion, which is what was producing
   unreadable text before (see chat notes). */
html[data-theme="dark"] {
    --bg: #10151c;
    --bg-dark: #171e28;
    --white: #1b222c;
    --text: #eef2f7;
    --text-muted: #b7c1cf;
    --text-light: #8b96a6;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.35);
    --shadow-md: 0 8px 30px rgba(0,0,0,0.45);
    --shadow-lg: 0 20px 60px rgba(0,0,0,0.55);
}
html[data-theme="dark"] body {
    background:
        linear-gradient(rgba(16,21,28,0.93), rgba(16,21,28,0.96)),
        url('images/7.jpg');
    background-size: auto, cover;
    background-position: center, center;
    background-attachment: scroll, fixed;
    color: var(--text);
}
/* .tms-section-title (css/modern-theme.css) is used for section headings
   sitewide — "Why Choose Us", "Feature Highlights", etc. — and hardcodes
   color: var(--primary), which is a fixed dark navy that was never
   redefined for dark mode. Section headings sit directly on the page/
   section background, which DOES go dark, so this was genuinely
   unreadable (dark navy text on a near-black background) everywhere this
   class is used, not just on one page. */
html[data-theme="dark"] .tms-section-title { color: var(--text) !important; }
html[data-theme="dark"] .tms-modal-box { background: var(--white); color: var(--text); }
html[data-theme="dark"] .tms-input-wrap input,
html[data-theme="dark"] input,
html[data-theme="dark"] select,
html[data-theme="dark"] textarea {
    background: #232b37;
    color: var(--text);
    border-color: #333f4d;
}
html[data-theme="dark"] .tms-input-wrap input::placeholder { color: var(--text-light); }
html[data-theme="dark"] .tms-mega-menu { background: var(--white); }
/* These shared components (css/modern-theme.css) hardcode a white
   background and were missed by the earlier per-page dark-mode audit,
   which only touched individual pages' own <style> blocks — not the
   shared stylesheet. Their internal text mostly already uses
   var(--text)/var(--text-muted), so it was going invisible (light text on
   a card that stayed white) once dark mode was on. This is the actual bug
   shown in the report: it affects the main nav bar's links on every page,
   plus feature/package/review/blog cards site-wide. */
html[data-theme="dark"] .tms-navbar,
html[data-theme="dark"] .tms-navbar.scrolled,
html[data-theme="dark"] .tms-feature-card,
html[data-theme="dark"] .tms-pkg-card,
html[data-theme="dark"] .tms-review-card,
html[data-theme="dark"] .tms-blog-card { background: var(--white) !important; }
html[data-theme="dark"] .tms-feature-card h4 { color: var(--text) !important; }
html[data-theme="dark"] .tms-logo-text strong { color: var(--text) !important; }
/* Bootstrap-based admin pages don't use the CSS variables above, so give
   them their own explicit dark rules for the most common components. */
html[data-theme="dark"] .panel,
html[data-theme="dark"] .card,
html[data-theme="dark"] .box,
html[data-theme="dark"] .modal-content {
    background: #1b222c !important;
    color: #eef2f7 !important;
    border-color: #2b3542 !important;
}
html[data-theme="dark"] .panel-heading { background: #232b37 !important; border-color: #2b3542 !important; }
html[data-theme="dark"] .table { color: #eef2f7 !important; }
html[data-theme="dark"] .table > thead > tr > th,
html[data-theme="dark"] .table > tbody > tr > td,
html[data-theme="dark"] .table > tbody > tr > th {
    border-color: #2b3542 !important;
    background: transparent !important;
}
html[data-theme="dark"] .table-striped > tbody > tr:nth-of-type(odd) { background: rgba(255,255,255,0.03) !important; }
html[data-theme="dark"] .form-control {
    background: #232b37 !important;
    color: #eef2f7 !important;
    border-color: #333f4d !important;
}
html[data-theme="dark"] a { color: inherit; }
html[data-theme="dark"] .text-muted { color: var(--text-light) !important; }

/* .pkg-card is used across many pages (About, Contact, etc.) as a plain
   content wrapper with no defined background of its own — give it real
   card styling so it reads correctly in both themes. */
.pkg-card { background: var(--white); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }

/* Many older pages set inline text colors (e.g. style="color:#555") that
   assume a white page background. Those hardcoded shades read fine in
   Light Mode but go unreadable on a dark background — lighten the common
   ones site-wide in Dark Mode without having to touch every file. */
html[data-theme="dark"] [style*="color:#555"], html[data-theme="dark"] [style*="color: #555"],
html[data-theme="dark"] [style*="color:#666"], html[data-theme="dark"] [style*="color: #666"],
html[data-theme="dark"] [style*="color:#777"], html[data-theme="dark"] [style*="color: #777"],
html[data-theme="dark"] [style*="color:#888"], html[data-theme="dark"] [style*="color: #888"],
html[data-theme="dark"] [style*="color:#999"], html[data-theme="dark"] [style*="color: #999"],
html[data-theme="dark"] [style*="color:#aaa"], html[data-theme="dark"] [style*="color: #aaa"],
html[data-theme="dark"] [style*="color:#bbb"], html[data-theme="dark"] [style*="color: #bbb"],
html[data-theme="dark"] [style*="color:#ccc"], html[data-theme="dark"] [style*="color: #ccc"],
html[data-theme="dark"] [style*="color:#333"], html[data-theme="dark"] [style*="color: #333"],
html[data-theme="dark"] [style*="color:#444"], html[data-theme="dark"] [style*="color: #444"],
html[data-theme="dark"] [style*="color:#222"], html[data-theme="dark"] [style*="color: #222"],
html[data-theme="dark"] [style*="color:#1a202c"], html[data-theme="dark"] [style*="color:#0f172a"],
html[data-theme="dark"] [style*="color:#4a5568"], html[data-theme="dark"] [style*="color:#718096"],
html[data-theme="dark"] [style*="color:#64748b"] {
    color: #cdd6e0 !important;
}

/* ROOT-CAUSE FIX for the About/Contact/FAQ/Terms/Agent/Blog "unreadable
   paragraph" bug in Dark Mode: these pages use the *legacy* css/style.css
   stylesheet (pre-dating the CSS-variable theme system), which hardcodes
   color:#999 etc. directly on classes like ".privacy p" rather than on an
   inline style= attribute, so the attribute-selector patch above never
   touched it. That's the plain intro paragraph on page.php?type=aboutus
   with no inline style at all that still went dark-on-dark. */
html[data-theme="dark"] .privacy p,
html[data-theme="dark"] .about p,
html[data-theme="dark"] .terms p,
html[data-theme="dark"] .faq p,
html[data-theme="dark"] .faq li,
html[data-theme="dark"] .agent p,
html[data-theme="dark"] .contact-left li,
html[data-theme="dark"] .details-middle p,
html[data-theme="dark"] .details-middle li,
html[data-theme="dark"] .tracking p,
html[data-theme="dark"] .travel p,
html[data-theme="dark"] .track p,
html[data-theme="dark"] .routes p,
html[data-theme="dark"] .holiday p,
html[data-theme="dark"] .rupes p,
html[data-theme="dark"] .rom-btm p,
html[data-theme="dark"] .rom-btm h5,
html[data-theme="dark"] .hotel-police p,
html[data-theme="dark"] .hotel-amenit li,
html[data-theme="dark"] .app-right p,
html[data-theme="dark"] .banner-text p,
html[data-theme="dark"] .banner-bottom-grid p,
html[data-theme="dark"] .comments-list p,
html[data-theme="dark"] .comments-list ul li,
html[data-theme="dark"] .comments-list ul li a,
html[data-theme="dark"] .popular-right p,
html[data-theme="dark"] .subscribe p,
html[data-theme="dark"] .categories ul li a,
html[data-theme="dark"] .admin-right p,
html[data-theme="dark"] .recent-comments-grid-right p,
html[data-theme="dark"] .ab-rt ul li a,
html[data-theme="dark"] .bus-btm li a,
html[data-theme="dark"] .selectroom_right p,
html[data-theme="dark"] .selectroom_right h2,
html[data-theme="dark"] .selectroom_right h6,
html[data-theme="dark"] p.dow,
html[data-theme="dark"] ul.off-mo li {
    color: var(--text-muted) !important;
}
html[data-theme="dark"] .navbar-nav .dropdown-menu li a {
    color: #cdd6e0 !important;
}

/* Companion fix to the block above: lightening the *text* on these legacy
   sections is only half the job — several of them sit inside containers
   with their own hardcoded LIGHT background (e.g. .errorWrap/.successWrap
   used by the Contact form, Booking, Profile, Change Password and Ticket
   pages; .admin and .recent-comments-grids; .tracking-top/-bottom). Left
   alone those would just swap dark-on-dark for light-on-light. Give them a
   dark card background to match the rest of the theme. */
html[data-theme="dark"] .errorWrap,
html[data-theme="dark"] .successWrap,
html[data-theme="dark"] .succWrap {
    background: var(--white) !important;
    color: var(--text) !important;
    box-shadow: none !important;
}
html[data-theme="dark"] .admin,
html[data-theme="dark"] .recent-comments-grids,
html[data-theme="dark"] .tracking-top,
html[data-theme="dark"] .tracking-bottom {
    background: var(--white) !important;
}
html[data-theme="dark"] .leave-comment input[type="text"],
html[data-theme="dark"] .leave-comment input[type="email"],
html[data-theme="dark"] .leave-comment textarea {
    background: #232b37 !important;
    color: var(--text) !important;
    border-color: #333f4d !important;
}

/* Button ripple (Section 10 audit) — spawned by footer.php's click
   listener on primary/submit buttons. Kept short (600ms) and low-opacity
   so it reads as a subtle click acknowledgment, not a flashy effect. */
.tms-ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.45);
    transform: scale(0);
    animation: tmsRippleAnim 600ms ease-out;
    pointer-events: none;
}
@keyframes tmsRippleAnim {
    to { transform: scale(1); opacity: 0; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() { tmsSyncThemeIcon(); });
</script>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
(function(){
    var curSels = document.querySelectorAll('.tms-currency-select');
    if (!curSels.length) return;
    curSels.forEach(function(curSel){
        curSel.addEventListener('change', function(){
            var chosen = curSel.value;
            fetch('set-currency.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'currency=' + encodeURIComponent(chosen)
            }).then(function(){ location.reload(); })
              .catch(function(){ location.reload(); });
        });
    });
})();
function tmsToggleTheme() {
    var html = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    var next = isDark ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    try { localStorage.setItem('tms-theme', next); } catch(e) {}
    tmsSyncThemeIcon();
    tmsFixInlineColorsForDarkMode();
}

// ROOT-CAUSE FIX (dark-mode contrast): the [style*="color:#555"] etc.
// patches above only catch specific hardcoded HEX codes in an inline
// style= attribute. That misses two real cases found from an actual
// screenshot of page.php?type=aboutus in Dark Mode: (1) any color written
// as rgb(...)/rgba(...)/hsl(...) instead of hex — the real seed content
// for the About Us page (tblpages, type='aboutus') uses
// `color: rgb(0, 0, 0)` and `color: rgb(80, 80, 80)`, which no hex-based
// selector can ever match, so that text stayed black-on-near-black in
// Dark Mode — and (2) any shade an admin later pastes into a CMS page
// (admin/manage-pages.php, manage-blog.php, etc.) from a rich-text editor
// that isn't already on the hardcoded list above. Rather than keep
// enumerating exact strings forever, this reads each inline-styled
// element's actual *rendered* color, computes its perceived brightness,
// and only swaps it for a light, readable color when that color is
// genuinely too dark for the dark background — regardless of hex/rgb/hsl/
// named-color syntax. This covers today's content and any future one,
// without a fragile whack-a-mole list. The original color is remembered
// (data-tms-orig-color) so switching back to Light Mode restores it
// exactly, and already-patched elements are skipped on repeat calls so
// this stays cheap. A MutationObserver re-runs it for content added later
// (AJAX widgets, CMS-loaded sections) without needing every such call site
// to remember to invoke it itself.
function tmsFixInlineColorsForDarkMode() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var nodes = document.querySelectorAll('[style*="color"]');
    for (var i = 0; i < nodes.length; i++) {
        var el = nodes[i];
        if (!el.style || !el.style.color) continue; // also matches background-color; only care about text color
        if (isDark) {
            if (el.dataset.tmsDarkPatched) continue;
            var m = getComputedStyle(el).color.match(/\d+/g);
            if (!m) continue;
            var brightness = (m[0] * 299 + m[1] * 587 + m[2] * 114) / 1000;
            if (brightness < 150) {
                el.dataset.tmsOrigColor = el.style.color;
                el.dataset.tmsDarkPatched = '1';
                el.style.setProperty('color', '#cdd6e0', 'important');
            }
        } else if (el.dataset.tmsDarkPatched) {
            el.style.setProperty('color', el.dataset.tmsOrigColor);
            delete el.dataset.tmsDarkPatched;
            delete el.dataset.tmsOrigColor;
        }
    }
}
tmsFixInlineColorsForDarkMode();
(function() {
    var pending = null;
    new MutationObserver(function() {
        clearTimeout(pending);
        pending = setTimeout(tmsFixInlineColorsForDarkMode, 150);
    }).observe(document.body, { childList: true, subtree: true });
})();
function tmsSyncThemeIcon() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var moon = document.getElementById('tmsThemeIconMoon');
    var sun = document.getElementById('tmsThemeIconSun');
    if (moon && sun) {
        moon.style.display = isDark ? '' : 'none';
        sun.style.display = isDark ? 'none' : '';
    }
    // The footer carries its own dark-mode button (#darkIcon/#darkLabel). It used
    // to run a second, disconnected toggle system with its own storage key; now it
    // just reflects whatever this single toggle decided, so the two never disagree.
    var footerIcon = document.getElementById('darkIcon');
    var footerLabel = document.getElementById('darkLabel');
    if (footerIcon) footerIcon.className = isDark ? 'fa fa-sun-o' : 'fa fa-moon-o';
    if (footerLabel) footerLabel.textContent = isDark ? 'Light Mode' : 'Dark Mode';
}
tmsSyncThemeIcon();
// Sign in modal / mega menu behaviour below ↓
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function toggleMegaMenu(e) {
    e.stopPropagation();
    var menu = document.getElementById('featMegaMenu');
    menu.classList.toggle('open');
}
function tmsToggleNotif(e) {
    e.stopPropagation();
    var dd = document.getElementById('tmsNotifDropdown');
    var wasOpen = dd.classList.contains('open');
    dd.classList.toggle('open');
    if (!wasOpen) {
        // Mark as read the moment the visitor actually opens the dropdown
        var badge = document.querySelector('#tmsNotifBell .tms-notif-badge');
        if (badge) badge.remove();
        document.querySelectorAll('.tms-notif-item.unread').forEach(function(el) { el.classList.remove('unread'); });
        fetch('mark-notifications-read.php', { method: 'POST' }).catch(function() {});
    }
}
function toggleMobileNav() {
    var nav = document.getElementById('tmsNavMenu');
    var btn = document.getElementById('tmsHamburger');
    nav.classList.toggle('mobile-open');
    btn.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    var dd = document.getElementById('featDropdown');
    var menu = document.getElementById('featMegaMenu');
    if (menu && dd && !dd.contains(e.target)) menu.classList.remove('open');

    var notifWrap = document.getElementById('tmsNotifWrap');
    var notifDD = document.getElementById('tmsNotifDropdown');
    if (notifDD && notifWrap && !notifWrap.contains(e.target)) notifDD.classList.remove('open');
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeModal('signinModal'); closeModal('signupModal'); }
});
// Sticky nav shadow on scroll
window.addEventListener('scroll', function() {
    document.getElementById('tmsNavbar').classList.toggle('scrolled', window.scrollY > 20);
});
// FIX (Round 14): this used to decide whether to disable the signup button by
// searching the response text for "already" or "red" — and the CSRF error
// message contains "red" (inside "expired"), so every failure locked the form.
// It also read the token from #signupCsrf, an id that did not exist on the live
// modal, so the request failed CSRF validation every single time. Nobody could
// register from the site-wide Sign Up modal at all. Both are fixed: the endpoint
// returns JSON, and the token element now exists.
function checkEmailLive(email) {
    var result = document.getElementById('emailCheckResult');
    var btn = document.getElementById('regSubmitBtn');
    if (!result) return;
    if (!email || email.length < 5) { result.innerHTML = ''; if (btn) btn.disabled = false; return; }

    result.innerHTML = '<span style="color:#888;font-size:12px;">Checking...</span>';

    var form = new URLSearchParams();
    form.set('emailid', email);
    var csrfEl = document.getElementById('signupCsrf');
    form.set('csrf_token', csrfEl ? csrfEl.value : '');

    fetch('check_availability.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString()
    }).then(function (r) {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
    }).then(function (data) {
        var available = !!data.available;
        result.innerHTML = '<span style="color:' + (available ? '#27ae60' : '#e74c3c') + ';font-size:12px;">' +
            '<i class="fa ' + (available ? 'fa-check-circle' : 'fa-times-circle') + '"></i> ' +
            String(data.message || '').replace(/[<>]/g, '') + '</span>';
        if (btn) btn.disabled = !available;
    }).catch(function () {
        // Never block signup because a convenience check failed — the server
        // validates the email properly on submit anyway.
        result.innerHTML = '';
        if (btn) btn.disabled = false;
    });
}
<?php if(isset($_SESSION['login_error'])): ?>
window.onload = function(){ openModal('signinModal'); };
<?php endif; ?>
<?php if(isset($_SESSION['reg_error'])): ?>
window.onload = function(){ openModal('signupModal'); };
<?php endif; ?>
</script>

<!-- ═══════════════ Toast notifications + skeleton loaders ═══════════════
     Replaces the site's ~18 blocking window.alert() calls (register.php,
     ai-planner.php, ai-packages.php, packing-assistant.php, travel-memory.php,
     virtual-guide.php) with a small, dismissible, non-blocking toast — and adds
     a reusable skeleton-loader class for AJAX-loaded content. Lives here, not
     in a separate CSS/JS file, so every page picks it up automatically the
     same way it already picks up the theme toggle, with no per-page <head>
     edits needed across 60+ files. -->
<style>
#tmsToastStack {
    position: fixed; top: 20px; right: 20px; z-index: 100000;
    display: flex; flex-direction: column; gap: 10px; max-width: 360px;
}
.tms-toast {
    display: flex; align-items: flex-start; gap: 10px;
    background: #fff; color: #1a2535; border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.18); padding: 13px 14px;
    font-family: 'Poppins', sans-serif; font-size: 13.5px; line-height: 1.4;
    border-left: 4px solid var(--primary, #0F4C81);
    animation: tmsToastIn 0.25s ease-out;
}
.tms-toast.success { border-left-color: #22a06b; }
.tms-toast.error   { border-left-color: #e5484d; }
.tms-toast.info    { border-left-color: var(--primary, #0F4C81); }
.tms-toast i.tms-toast-icon { margin-top: 1px; font-size: 15px; }
.tms-toast.success i.tms-toast-icon { color: #22a06b; }
.tms-toast.error   i.tms-toast-icon { color: #e5484d; }
.tms-toast.info    i.tms-toast-icon { color: var(--primary, #0F4C81); }
.tms-toast span { flex: 1; }
.tms-toast .tms-toast-close {
    cursor: pointer; opacity: 0.4; font-size: 12px; margin-top: 2px;
}
.tms-toast .tms-toast-close:hover { opacity: 0.8; }
@keyframes tmsToastIn { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
@keyframes tmsToastOut { from { opacity: 1; } to { opacity: 0; transform: translateX(30px); } }
html[data-theme="dark"] .tms-toast { background: #1a2535; color: #e2e8f0; }

.tms-skeleton {
    background: linear-gradient(90deg, #eceff3 25%, #f6f8fa 37%, #eceff3 63%);
    background-size: 400% 100%;
    animation: tmsSkeletonWave 1.4s ease infinite;
    border-radius: 8px;
}
html[data-theme="dark"] .tms-skeleton {
    background: linear-gradient(90deg, #202b3a 25%, #283449 37%, #202b3a 63%);
    background-size: 400% 100%;
}
@keyframes tmsSkeletonWave { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
@media (max-width: 480px) {
    #tmsToastStack { left: 12px; right: 12px; max-width: none; top: 12px; }
}
</style>
<div id="tmsToastStack" aria-live="polite"></div>
<script>
// tmsToast(message, type) — type is 'info' (default), 'success', or 'error'.
// Auto-picks 'error' for messages that read like a validation/network failure
// and 'success' for ones that read like confirmation, so the ~18 call sites
// that used to just say alert('...') can become tmsToast('...') with no
// per-call type argument required, while still getting the right color/icon.
function tmsToast(message, type) {
    if (!type) {
        var m = String(message).toLowerCase();
        if (m.indexOf('fail') > -1 || m.indexOf('error') > -1 || m.indexOf('please') > -1 || m.indexOf('invalid') > -1 || m.indexOf('do not match') > -1) type = 'error';
        else if (m.indexOf('saved') > -1 || m.indexOf('success') > -1 || m.indexOf('copied') > -1) type = 'success';
        else type = 'info';
    }
    var icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
    var stack = document.getElementById('tmsToastStack');
    if (!stack) return;
    var el = document.createElement('div');
    el.className = 'tms-toast ' + type;
    el.setAttribute('role', 'status');
    el.innerHTML = '<i class="fa ' + icons[type] + ' tms-toast-icon"></i><span></span><span class="tms-toast-close">&times;</span>';
    el.querySelector('span').textContent = message; // textContent, not innerHTML — message may contain user input
    var remove = function() {
        el.style.animation = 'tmsToastOut 0.2s ease-in forwards';
        setTimeout(function() { el.remove(); }, 200);
    };
    el.querySelector('.tms-toast-close').onclick = remove;
    stack.appendChild(el);
    setTimeout(remove, 5000);
}
</script>

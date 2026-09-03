<!-- TMS Modern Footer Partial (used by inner pages) -->
<!-- ════════════════ FOOTER ════════════════ -->
<footer class="tms-footer">
    <div class="tms-container">
        <div class="tms-footer-grid">
            <div class="tms-footer-col tms-footer-brand">
                <a href="index.php" class="tms-logo" style="text-decoration:none;">
                    <div class="tms-logo-icon"><i class="fa fa-globe"></i></div>
                    <div class="tms-logo-text">
                        <strong>TMS</strong>
                        <span>Tourism Management</span>
                    </div>
                </a>
                <p>Your premium travel partner. AI-powered planning, expert guidance, and world-class service.</p>
                <div class="tms-footer-social">
                    <a href="#" class="tms-social-btn"><i class="fa fa-facebook"></i></a>
                    <a href="#" class="tms-social-btn"><i class="fa fa-twitter"></i></a>
                    <a href="#" class="tms-social-btn"><i class="fa fa-instagram"></i></a>
                    <a href="#" class="tms-social-btn"><i class="fa fa-youtube"></i></a>
                </div>
            </div>
            <?php if (empty($tms_landing_mode)): ?>
            <div class="tms-footer-col">
                <h5><?php echo tms_t('footer_quick_links','Quick Links'); ?></h5>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="page.php?type=aboutus"><?php echo tms_t('nav_about','About Us'); ?></a></li>
                    <li><a href="package-list.php"><?php echo tms_t('nav_packages','Tour Packages'); ?></a></li>
                    <li><a href="blog.php"><?php echo tms_t('nav_blog','Travel Blog'); ?></a></li>
                    <li><a href="enquiry.php">Enquiry</a></li>
                    <li><a href="page.php?type=contact"><?php echo tms_t('nav_contact','Contact Us'); ?></a></li>
                </ul>
            </div>
            <div class="tms-footer-col">
                <h5><?php echo tms_t('footer_features','Features'); ?></h5>
                <ul>
                    <li><a href="explore.php">Explore Mode</a></li>
                    <li><a href="ai-recommend.php">AI Recommender</a></li>
                    <li><a href="tour-map.php">Interactive Map</a></li>
                    <li><a href="weather.php">Weather Guide</a></li>
                    <li><a href="budget-planner.php">Budget Planner</a></li>
                    <li><a href="compare.php">Compare Packages</a></li>
                    <li><a href="emergency.php">Safety & Emergency</a></li>
                </ul>
            </div>
            <div class="tms-footer-col">
                <h5><?php echo tms_t('footer_support','Support'); ?></h5>
                <ul>
                    <li><a href="issuetickets.php">Support Tickets</a></li>
                    <li><a href="reviews.php">Reviews</a></li>
                    <li><a href="loyalty.php">Loyalty Points</a></li>
                    <li><a href="custom-trip.php">Custom Trips</a></li>
                    <li><a href="admin/index.php">Admin Login</a></li>
                </ul>
                <div style="margin-top:20px;padding:14px;background:rgba(255,183,3,0.1);border-radius:10px;border:1px solid rgba(255,183,3,0.2);">
                    <div style="color:var(--accent);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">24/7 Support</div>
                    <div style="color:#fff;font-size:15px;font-weight:700;"><i class="fa fa-phone"></i> +880 1759-443004</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="tms-footer-bottom">
            <span>© 2026 TMS — Tourism Management System. All Rights Reserved.</span>
            <?php if (empty($tms_landing_mode)): ?>
            <div style="display:flex;align-items:center;gap:16px;">
                <select class="tms-currency-select" aria-label="See price in" title="See price in" style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.85);">
                    <?php foreach (TMS_CURRENCIES as $tmsFooterCurCode => $tmsFooterCurMeta): ?>
                    <option value="<?php echo $tmsFooterCurCode; ?>" <?php echo tms_active_currency()===$tmsFooterCurCode?'selected':''; ?>>
                        <?php echo $tmsFooterCurMeta['symbol'] . ' ' . $tmsFooterCurCode; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="tmsInstallBtnFooter" style="display:none;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.85);padding:6px 14px;border-radius:20px;font-size:12px;cursor:pointer;font-family:'Poppins',sans-serif;">
                    <i class="fa fa-download"></i> Install App
                </button>
                <button id="darkModeBtn" onclick="tmsToggleTheme()" style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.7);padding:6px 14px;border-radius:20px;font-size:12px;cursor:pointer;font-family:'Poppins',sans-serif;">
                    <i class="fa fa-moon-o" id="darkIcon"></i> <span id="darkLabel">Dark Mode</span>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</footer>

<script>
// Dark mode itself now lives entirely in includes/header.php (tmsToggleTheme,
// the html[data-theme="dark"] rules, and localStorage key 'tms-theme'). This
// button used to run its own separate, more limited toggle (localStorage key
// 'tms_dark', a 4-rule inline <style>) that could disagree with the header's
// toggle. It's removed; tmsSyncThemeIcon() in header.php keeps this button's
// icon/label matched to whichever page state is active.
if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(function(){});

// PWA install button (Task 8) — used to be a dismissible banner fixed to
// the bottom center of the screen. Moved into the top navigation (far
// right, next to the currency selector and theme toggle), with a mirrored
// copy in the footer so it's still reachable on mobile where the topbar is
// hidden. Both stay hidden until the browser actually confirms
// installability via 'beforeinstallprompt' — never shown as a fake button.
(function() {
    var deferredPrompt = null;
    var topBtn = document.getElementById('tmsInstallBtn');
    var topDivider = document.getElementById('tmsInstallDivider');
    var footerBtn = document.getElementById('tmsInstallBtnFooter');

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        if (topBtn) topBtn.style.display = 'inline-flex';
        if (topDivider) topDivider.style.display = '';
        if (footerBtn) footerBtn.style.display = 'inline-block';
    });

    function doInstall() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(function() {
            deferredPrompt = null;
            if (topBtn) topBtn.style.display = 'none';
            if (topDivider) topDivider.style.display = 'none';
            if (footerBtn) footerBtn.style.display = 'none';
        });
    }
    if (topBtn) topBtn.addEventListener('click', doInstall);
    if (footerBtn) footerBtn.addEventListener('click', doInstall);

    window.addEventListener('appinstalled', function() {
        if (topBtn) topBtn.style.display = 'none';
        if (topDivider) topDivider.style.display = 'none';
        if (footerBtn) footerBtn.style.display = 'none';
    });
})();

// ANIMATED COUNTERS (Section 10 audit): stat numbers like the homepage hero
// strip and About page mission stats were static text ("1,900+") with no
// animation at all. Any element with data-counter="N" now counts up from 0
// to N once it scrolls into view, formatted with thousands separators and
// a trailing "+". IntersectionObserver-based so it only ever plays once per
// element and costs nothing on pages that don't use it.
(function() {
    var counters = document.querySelectorAll('[data-counter]');
    if (!counters.length || !('IntersectionObserver' in window)) return;

    function animateCounter(el) {
        var target = parseInt(el.getAttribute('data-counter'), 10);
        if (!target || isNaN(target)) return;
        var duration = 1400; // ms — quick and subtle, not a slow crawl
        var startTime = null;
        function step(ts) {
            if (!startTime) startTime = ts;
            var progress = Math.min((ts - startTime) / duration, 1);
            // ease-out — fast start, gentle settle, matches "subtle and professional"
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = Math.floor(eased * target);
            el.textContent = current.toLocaleString() + (progress >= 1 ? '+' : '');
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    var counterObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                counterObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.4 });

    counters.forEach(function(c) { counterObserver.observe(c); });
})();

// BUTTON RIPPLE (Section 10 audit): primary action buttons had a plain
// color-swap :hover and nothing else — no click feedback at all. Adds a
// short, subtle Material-style ripple centered on the click point. Scoped
// to primary/submit buttons specifically (not every button/link site-wide)
// to keep it a deliberate accent rather than noise on secondary UI.
(function() {
    var RIPPLE_SELECTOR = '.btn-primary, .tms-btn-primary, .btn-hero-primary, button[type="submit"], input[type="submit"]';
    document.addEventListener('click', function(e) {
        var el = e.target.closest ? e.target.closest(RIPPLE_SELECTOR) : null;
        if (!el) return;
        var rect = el.getBoundingClientRect();
        var ripple = document.createElement('span');
        var size = Math.max(rect.width, rect.height) * 1.6;
        ripple.className = 'tms-ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
        var prevPosition = getComputedStyle(el).position;
        if (prevPosition === 'static') el.style.position = 'relative';
        el.style.overflow = 'hidden';
        el.appendChild(ripple);
        ripple.addEventListener('animationend', function() { ripple.remove(); });
    }, { passive: true });
})();
</script>
<?php include('includes/chatbot.php'); ?>
<?php /* Human "Chat with our team" widget removed per user request — Contact page covers this */ ?>

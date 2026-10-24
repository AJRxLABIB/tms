</main>
<?php
require_once __DIR__ . '/brand.php';
// Compact footer: 4 columns. Page links that used to crowd the header live here.
$tmsfE = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$tmsfLanding = !empty($tms_landing_mode);
$tmsfSocials = array_filter([
    'facebook'  => tms_env('TMS_SOCIAL_FACEBOOK', ''),
    'twitter'   => tms_env('TMS_SOCIAL_TWITTER', ''),
    'instagram' => tms_env('TMS_SOCIAL_INSTAGRAM', ''),
    'youtube'   => tms_env('TMS_SOCIAL_YOUTUBE', ''),
]);
$tmsfPhone = trim((string)tms_env('TMS_SUPPORT_PHONE', '+880 1759-443004'));
$tmsfCols = [
    tms_t('footer_discover', 'Discover') => [
        ['package-list.php', tms_t('nav_packages', 'Tour Packages')],
        ['hotels.php', tms_t('nav_hotels', 'Hotels')],
        ['activities.php', tms_t('nav_activities', 'Activities')],
        ['destinations.php', tms_t('nav_destinations', 'Destinations')],
        ['tour-map.php', tms_t('map_title', 'Tourism Map')],
        ['blog.php', tms_t('nav_blog', 'Blog')],
    ],
    tms_t('footer_plan', 'Plan') => [
        ['plan.php', tms_t('nav_plan', 'Plan a trip')],
        ['ai-recommend.php', 'AI Recommender'],
        ['budget-planner.php', 'Budget Planner'],
        ['compare.php', tms_t('nav_compare', 'Compare')],
        ['weather.php', 'Weather'],
    ],
    tms_t('footer_help', 'Help') => [
        ['page.php?type=aboutus', tms_t('nav_about', 'About')],
        ['page.php?type=contact', tms_t('nav_contact', 'Contact')],
        ['issuetickets.php', 'Support Tickets'],
        ['emergency.php', 'Safety & Emergency'],
        ['page.php?type=terms', tms_t('label_terms', 'Terms of Service')],
        ['page.php?type=privacy', tms_t('label_privacy_policy', 'Privacy Policy')],
    ],
];
?>
<footer class="tmsn-foot">
    <div class="tmsn-foot-in">
        <div class="tmsn-foot-grid">
            <div class="tmsn-foot-brand">
                <a href="index.php" class="tmsn-logo"><?php echo tms_brand_mark_html('40'); ?></a>
                <p><?php echo $tmsfE(tms_t('footer_blurb', 'Plan, compare and book trips with AI help, live weather and prices in your currency.')); ?></p>
                <?php if ($tmsfPhone !== ''): ?>
                <a class="tmsn-foot-phone" href="tel:<?php echo $tmsfE(preg_replace('/[^0-9+]/', '', $tmsfPhone)); ?>"><i class="fa fa-phone" aria-hidden="true"></i> <?php echo $tmsfE($tmsfPhone); ?></a>
                <?php endif; ?>
                <?php if ($tmsfSocials): ?>
                <div class="tmsn-foot-social">
                    <?php foreach ($tmsfSocials as $net => $url): ?>
                    <a href="<?php echo $tmsfE($url); ?>" target="_blank" rel="noopener" aria-label="<?php echo $tmsfE(ucfirst($net)); ?>"><i class="fa fa-<?php echo $tmsfE($net); ?>" aria-hidden="true"></i></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!$tmsfLanding): foreach ($tmsfCols as $tmsfTitle => $tmsfItems): ?>
            <div>
                <h5><?php echo $tmsfE($tmsfTitle); ?></h5>
                <ul>
                    <?php foreach ($tmsfItems as $it): ?>
                    <li><a href="<?php echo $tmsfE($it[0]); ?>"><?php echo $tmsfE($it[1]); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="tmsn-foot-bottom">
            <span>© <?php echo date('Y'); ?> TourBuddy. <?php echo tms_t('footer_rights', 'All rights reserved.'); ?></span>
            <?php if (!$tmsfLanding): ?>
            <div class="tmsn-foot-tools">
                <?php /* Round 14: "Write to Us" opens includes/write-us.php (#myModal3), the only form that writes to tblissues. */ ?>
                <a href="#" data-toggle="modal" data-target="#myModal3"><?php echo tms_t('footer_write_us', 'Write to us'); ?></a>
                <button type="button" id="tmsInstallBtnFooter" style="display:none;"><i class="fa fa-download" aria-hidden="true"></i> <?php echo $tmsfE(tms_t('nav_install', 'Install app')); ?></button>
                <button type="button" id="darkModeBtn" onclick="tmsToggleTheme()"><i class="fa fa-moon-o" id="darkIcon" aria-hidden="true"></i> <span id="darkLabel">Dark Mode</span></button>
                <?php if (empty($_SESSION['login'])): ?><a href="admin/index.php">Staff</a><?php endif; ?>
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
// an optional data-suffix (e.g. "+"; none by default so exact counts stay exact). IntersectionObserver-based so it only ever plays once per
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
            el.textContent = current.toLocaleString() + (progress >= 1 ? (el.getAttribute('data-suffix') || '') : '');
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

// ACCESSIBILITY FIX (Round 70, WCAG 2.1.1/2.4.3): the shared sign-in modal
// (#myModal4, backend/signin.php, included on 21+ pages) and contact modal
// (#myModal3, write-us.php, opened from this footer's "Write to Us" link
// site-wide) are classic Bootstrap 3 modals. Bootstrap 3's own
// enforceFocus() only refocuses the modal's outer container whenever focus
// escapes it via a focusin event -- it does NOT cycle Tab back to the
// modal's first focusable element when you tab past its last one (focus
// just leaves the page/document instead, effectively breaking out of the
// modal despite it staying open), and it never returns focus to whichever
// link/button opened the modal once it's closed (a keyboard user is
// dropped back at the top of the page instead of where they were). Both
// gaps are fixed here, generically, for every Bootstrap modal on the site
// -- not hardcoded to these two IDs -- so any future one gets this for
// free, matching the focus-trap/focus-return pattern the custom
// tms-modal-overlay sign-in/sign-up dialogs already have (Round 55) and
// the mega-menu/notification dropdown got in Round 67. Wrapped in
// DOMContentLoaded because jQuery itself loads via a deferred <script> on
// these pages -- a deferred script isn't guaranteed to have run yet by the
// time this inline, non-deferred block is reached during parsing.
document.addEventListener('DOMContentLoaded', function() {
    if (!window.jQuery) return;
    var $ = window.jQuery;
    var tmsModalLastTrigger = null;

    $(document).on('show.bs.modal', '.modal', function(e) {
        // Bootstrap already supplies the opening element as e.relatedTarget
        // when the modal was triggered via a data-toggle="modal" link/button.
        tmsModalLastTrigger = e.relatedTarget || document.activeElement;
    });

    $(document).on('keydown', '.modal.in', function(e) {
        if (e.key !== 'Tab' && e.keyCode !== 9) return;
        var focusable = this.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        focusable = Array.prototype.filter.call(focusable, function(el) {
            return el.offsetParent !== null; // skip hidden elements
        });
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    $(document).on('hidden.bs.modal', '.modal', function() {
        if (tmsModalLastTrigger && typeof tmsModalLastTrigger.focus === 'function') {
            tmsModalLastTrigger.focus();
        }
        tmsModalLastTrigger = null;
    });
});
</script>
<?php
// Round 115 architecture cleanup: this used to say 'includes/chatbot.php'
// -- a leftover from before the Round 49 restructure, same class of bug as
// the includes/header.php/includes/footer.php one already fixed in
// backend/landing.php. Since this footer is include()'d from every
// frontend/*.php page (all reached through their root-shim, so PHP's
// working directory is the project root), the bare path was resolving to
// the stale project-root includes/chatbot.php on every single page site-
// wide instead of this file's own live sibling, backend/chatbot.php.
// The two were still byte-identical at the time of this fix, so nothing
// visibly changed -- but a future edit to backend/chatbot.php would have
// silently never reached a real visitor. Now points at the real sibling.
include('backend/chatbot.php');
?>
<script src="js/tms-a11y.js" defer></script>
<?php /* Human "Chat with our team" widget removed per user request — Contact page covers this */ ?>

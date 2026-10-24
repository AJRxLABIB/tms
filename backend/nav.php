<?php
// backend/nav.php — the single site header (included by backend/header.php, shares its scope:
// $currentPage, $dbh, $tms_landing_mode). 5 primary links + one account menu + mobile drawer.
// Everything that used to live in the utility bar and the 15-item Features mega menu is now on
// plan.php, the account menu, the drawer or the footer.
require_once __DIR__ . '/brand.php';
$tmsnLogged = !empty($_SESSION['login']);
$tmsnLanding = !empty($tms_landing_mode);
$tmsnEmail = $tmsnLogged ? (string)$_SESSION['login'] : '';
$tmsnName = $tmsnLogged ? explode('@', $tmsnEmail)[0] : '';
// Primary links: [href, label, icon, pages that count as "this section"]. Logged-in visitors also get My Trips and Saved Places.
// Explore / Map are protected pages: a signed-out click goes through the login gate (tms_require_user) and returns.
$tmsnPkgPages = ['package-list.php', 'package-details.php', 'hotels.php', 'hotel-details.php', 'activities.php', 'activity-details.php'];
$tmsnLinks = [
    ['index.php', tms_t('nav_home', 'Home'), 'fa-home', ['index.php']],
    ['explore.php', tms_t('nav_explore', 'Explore'), 'fa-compass', ['explore.php', 'explore-view.php', 'destinations.php']],
    ['tour-map.php', tms_t('nav_map', 'Map'), 'fa-map-marker', ['tour-map.php']],
];
if ($tmsnLogged) {
    $tmsnLinks[] = ['my-trips.php', tms_t('nav_my_trips', 'My Trips'), 'fa-suitcase', ['my-trips.php', 'trip-planner.php']];
    $tmsnLinks[] = ['saved-places.php', tms_t('nav_saved_places', 'Saved Places'), 'fa-bookmark-o', ['saved-places.php']];
}
$tmsnPkgLabel = tms_t('nav_packages_short', 'Packages');
// Packages menu: everything that is bookable, plus the planning-tools hub, so Hotels / Activities / tools stay reachable.
$tmsnPkgMenu = [
    ['package-list.php', tms_t('nav_packages', 'Tour Packages'), 'fa-suitcase', ['package-list.php', 'package-details.php']],
    ['hotels.php', tms_t('nav_hotels', 'Hotels'), 'fa-bed', ['hotels.php', 'hotel-details.php']],
    ['activities.php', tms_t('nav_activities', 'Activities'), 'fa-ticket', ['activities.php', 'activity-details.php']],
];
$tmsnPkgActive = in_array($currentPage, $tmsnPkgPages, true);
$tmsnPlanLabel = tms_t('nav_plan', 'Plan a trip');
$tmsnPlanActive = ($currentPage === 'plan.php');
$tmsnAccount = [
    ['dashboard.php', tms_t('nav_dashboard', 'Dashboard'), 'fa-th-large'],
    ['tour-history.php', tms_t('nav_bookings', 'My Bookings'), 'fa-suitcase'],
    ['wishlist.php', tms_t('nav_wishlist', 'Wishlist'), 'fa-heart-o'],
    ['profile.php', tms_t('nav_profile', 'My Profile'), 'fa-user-circle-o'],
];
$tmsnE = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$tmsnNotifUnread = 0; $tmsnNotifItems = [];
if (!$tmsnLanding && $tmsnLogged) {
    try {
        $ncq = $dbh->prepare("SELECT COUNT(*) FROM tbl_notifications WHERE UserEmail=:e AND IsRead=0");
        $ncq->execute([':e' => $tmsnEmail]);
        $tmsnNotifUnread = (int)$ncq->fetchColumn();
        $nq = $dbh->prepare("SELECT Message, Link, IsRead, CreatedAt FROM tbl_notifications WHERE UserEmail=:e ORDER BY id DESC LIMIT 6");
        $nq->execute([':e' => $tmsnEmail]);
        $tmsnNotifItems = $nq->fetchAll(PDO::FETCH_OBJ);
    } catch (Exception $e) { /* table may not exist yet — fail quiet */ }
}
$tmsnThemeSeg = function () use ($tmsnE) {
    $o = '<div class="tmsn-theme" role="group" aria-label="' . $tmsnE(tms_t('nav_theme', 'Appearance')) . '">';
    foreach (['light' => tms_t('theme_light', 'Light'), 'dark' => tms_t('theme_dark', 'Dark'), 'auto' => tms_t('theme_auto', 'Auto')] as $k => $lbl) {
        $o .= '<button type="button" data-tmsn-theme="' . $k . '" aria-pressed="false">' . $tmsnE($lbl) . '</button>';
    }
    return $o . '</div>';
};
$tmsnCurSelect = function ($id) use ($tmsnE) {
    $o = '<select' . ($id ? ' id="' . $id . '"' : '') . ' class="tms-currency-select tmsn-select" aria-label="' . $tmsnE(tms_t('nav_currency', 'Currency')) . '" title="' . $tmsnE(tms_t('nav_currency', 'Currency')) . '">';
    foreach (TMS_CURRENCIES as $code => $meta) {
        $o .= '<option value="' . $tmsnE($code) . '"' . (tms_active_currency() === $code ? ' selected' : '') . '>' . $tmsnE($meta['symbol'] . ' ' . $code) . '</option>';
    }
    return $o . '</select>';
};
?>
<link rel="stylesheet" href="css/tms-nav.css">
<header class="tmsn" id="tmsNavbar"<?php echo $tmsnLanding ? ' data-landing="1"' : ''; ?>>
    <div class="tmsn-bar">
        <a href="index.php" class="tmsn-logo" aria-label="TourBuddy — <?php echo $tmsnE(tms_t('nav_home', 'Home')); ?>">
            <?php echo tms_brand_mark_html('38'); ?>
        </a>

        <?php if ($tmsnLanding): ?>
        <div class="tmsn-right">
            <a href="javascript:void(0)" onclick="openModal('signinModal')" class="tmsn-btn tmsn-btn-ghost"><?php echo tms_t('nav_signin', 'Sign In'); ?></a>
            <a href="javascript:void(0)" onclick="openModal('signupModal')" class="tmsn-btn tmsn-btn-primary"><?php echo tms_t('nav_signup', 'Sign Up'); ?></a>
        </div>
        <?php else: ?>
        <ul class="tmsn-links">
            <?php foreach ($tmsnLinks as $l): ?>
            <li><a href="<?php echo $l[0]; ?>"<?php echo in_array($currentPage, $l[3], true) ? ' aria-current="page"' : ''; ?>><?php echo $tmsnE($l[1]); ?></a></li>
            <?php endforeach; ?>
            <li class="tmsn-dd tmsn-dd-nav">
                <button type="button" class="tmsn-navbtn" aria-haspopup="true" aria-expanded="false" aria-controls="tmsnPkgPanel"<?php echo ($tmsnPkgActive || $tmsnPlanActive) ? ' aria-current="page"' : ''; ?>><?php echo $tmsnE($tmsnPkgLabel); ?> <i class="fa fa-angle-down" aria-hidden="true"></i></button>
                <div class="tmsn-panel tmsn-panel-left" id="tmsnPkgPanel">
                    <?php foreach ($tmsnPkgMenu as $a): ?>
                    <a class="tmsn-item" href="<?php echo $a[0]; ?>"<?php echo in_array($currentPage, $a[3], true) ? ' aria-current="page"' : ''; ?>><i class="fa <?php echo $a[2]; ?>" aria-hidden="true"></i><?php echo $tmsnE($a[1]); ?></a>
                    <?php endforeach; ?>
                    <div class="tmsn-sep"></div>
                    <a class="tmsn-item" href="plan.php"<?php echo $tmsnPlanActive ? ' aria-current="page"' : ''; ?>><i class="fa fa-magic" aria-hidden="true"></i><?php echo $tmsnE($tmsnPlanLabel); ?></a>
                </div>
            </li>
        </ul>

        <div class="tmsn-right">
            <span class="tmsn-hide-sm"><?php echo $tmsnCurSelect('tmsCurrencySelect'); ?></span>

            <button type="button" id="tmsInstallBtn" class="tmsn-iconbtn tmsn-install tmsn-hide-sm" style="display:none;" title="<?php echo $tmsnE(tms_t('nav_install', 'Install app')); ?>" aria-label="<?php echo $tmsnE(tms_t('nav_install', 'Install app')); ?>"><i class="fa fa-download" aria-hidden="true"></i></button>

            <?php if ($tmsnLogged): ?>
            <div class="tmsn-dd" id="tmsNotifWrap">
                <button type="button" class="tmsn-iconbtn" id="tmsNotifBell" data-tmsn-notif="1" aria-haspopup="true" aria-expanded="false" aria-controls="tmsNotifDropdown" aria-label="<?php echo $tmsnE(tms_t('nav_notifications', 'Notifications')); ?>">
                    <i class="fa fa-bell-o" aria-hidden="true"></i>
                    <?php if ($tmsnNotifUnread > 0): ?><span class="tmsn-badge"><?php echo $tmsnNotifUnread > 9 ? '9+' : $tmsnNotifUnread; ?></span><?php endif; ?>
                </button>
                <div class="tmsn-panel tmsn-notif-panel" id="tmsNotifDropdown">
                    <div class="tmsn-notif-head"><?php echo tms_t('nav_notifications', 'Notifications'); ?></div>
                    <?php if (!$tmsnNotifItems): ?>
                    <div class="tmsn-notif-empty"><?php echo tms_t('nav_notif_empty', "You're all caught up."); ?></div>
                    <?php else: ?>
                    <div class="tmsn-notif-list">
                        <?php foreach ($tmsnNotifItems as $n): ?>
                        <a href="<?php echo $tmsnE($n->Link ?: 'notifications.php'); ?>"<?php echo $n->IsRead ? '' : ' class="unread"'; ?>><?php echo $tmsnE($n->Message); ?><small><?php echo $tmsnE($n->CreatedAt); ?></small></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <a class="tmsn-notif-all" href="notifications.php"><?php echo tms_t('nav_view_all', 'View all'); ?></a>
                </div>
            </div>

            <div class="tmsn-dd tmsn-hide-sm">
                <button type="button" class="tmsn-account-btn" aria-haspopup="true" aria-expanded="false" aria-controls="tmsnAccountPanel">
                    <span class="tmsn-avatar" aria-hidden="true"><?php echo $tmsnE(function_exists('mb_substr') ? mb_substr($tmsnName, 0, 1) : substr($tmsnName, 0, 1)); ?></span>
                    <span class="tmsn-uname"><?php echo $tmsnE($tmsnName); ?></span>
                    <i class="fa fa-angle-down" aria-hidden="true"></i>
                </button>
                <div class="tmsn-panel" id="tmsnAccountPanel">
                    <div class="tmsn-who" title="<?php echo $tmsnE($tmsnEmail); ?>"><?php echo $tmsnE($tmsnEmail); ?></div>
                    <?php foreach ($tmsnAccount as $a): ?>
                    <a class="tmsn-item" href="<?php echo $a[0]; ?>"><i class="fa <?php echo $a[2]; ?>" aria-hidden="true"></i><?php echo $tmsnE($a[1]); ?></a>
                    <?php endforeach; ?>
                    <div class="tmsn-sep"></div>
                    <?php echo $tmsnThemeSeg(); ?>
                    <div class="tmsn-sep"></div>
                    <a class="tmsn-item" href="logout.php?t=<?php echo urlencode(tms_csrf_token()); ?>"><i class="fa fa-sign-out" aria-hidden="true"></i><?php echo tms_t('nav_logout', 'Logout'); ?></a>
                </div>
            </div>
            <?php else: ?>
            <button type="button" id="tmsThemeToggle" class="tmsn-iconbtn tmsn-hide-sm" onclick="tmsToggleTheme()" aria-label="<?php echo $tmsnE(tms_t('nav_theme', 'Appearance')); ?>" title="<?php echo $tmsnE(tms_t('nav_theme', 'Appearance')); ?>">
                <i class="fa fa-sun-o" id="tmsThemeIconSun" aria-hidden="true"></i><i class="fa fa-moon-o" id="tmsThemeIconMoon" style="display:none;" aria-hidden="true"></i>
            </button>
            <a href="javascript:void(0)" onclick="openModal('signinModal')" class="tmsn-btn tmsn-btn-ghost"><?php echo tms_t('nav_signin', 'Sign In'); ?></a>
            <a href="javascript:void(0)" onclick="openModal('signupModal')" class="tmsn-btn tmsn-btn-primary tmsn-hide-sm"><?php echo tms_t('nav_signup', 'Sign Up'); ?></a>
            <?php endif; ?>

            <button type="button" class="tmsn-iconbtn tmsn-burger" id="tmsnBurger" aria-label="<?php echo $tmsnE(tms_t('nav_menu', 'Menu')); ?>" aria-expanded="false" aria-controls="tmsnDrawer"><i class="fa fa-bars" aria-hidden="true"></i></button>
        </div>

        <div class="tmsn-scrim" aria-hidden="true"></div>
        <aside class="tmsn-drawer" id="tmsnDrawer" role="dialog" aria-modal="true" aria-label="<?php echo $tmsnE(tms_t('nav_menu', 'Menu')); ?>">
            <div class="tmsn-drawer-head">
                <strong><?php echo tms_t('nav_menu', 'Menu'); ?></strong>
                <button type="button" class="tmsn-iconbtn" id="tmsnDrawerClose" aria-label="<?php echo $tmsnE(tms_t('nav_close', 'Close')); ?>"><i class="fa fa-times" aria-hidden="true"></i></button>
            </div>
            <nav aria-label="<?php echo $tmsnE(tms_t('nav_menu', 'Menu')); ?>">
                <?php foreach ($tmsnLinks as $l): ?>
                <a href="<?php echo $l[0]; ?>"<?php echo in_array($currentPage, $l[3], true) ? ' aria-current="page"' : ''; ?>><i class="fa <?php echo $l[2]; ?>" aria-hidden="true"></i><?php echo $tmsnE($l[1]); ?></a>
                <?php endforeach; ?>
                <?php foreach ($tmsnPkgMenu as $l): ?>
                <a href="<?php echo $l[0]; ?>"<?php echo in_array($currentPage, $l[3], true) ? ' aria-current="page"' : ''; ?>><i class="fa <?php echo $l[2]; ?>" aria-hidden="true"></i><?php echo $tmsnE($l[1]); ?></a>
                <?php endforeach; ?>
                <a href="plan.php"<?php echo $tmsnPlanActive ? ' aria-current="page"' : ''; ?>><i class="fa fa-magic" aria-hidden="true"></i><?php echo $tmsnE($tmsnPlanLabel); ?></a>
                <?php if ($tmsnLogged): ?>
                <div class="tmsn-sep"></div>
                <?php foreach ($tmsnAccount as $a): ?>
                <a href="<?php echo $a[0]; ?>"><i class="fa <?php echo $a[2]; ?>" aria-hidden="true"></i><?php echo $tmsnE($a[1]); ?></a>
                <?php endforeach; ?>
                <?php endif; ?>
            </nav>
            <div class="tmsn-drawer-foot">
                <?php echo $tmsnCurSelect(''); ?>
                <?php echo $tmsnThemeSeg(); ?>
                <?php if ($tmsnLogged): ?>
                <a class="tmsn-btn tmsn-btn-ghost" href="logout.php?t=<?php echo urlencode(tms_csrf_token()); ?>"><?php echo tms_t('nav_logout', 'Logout'); ?></a>
                <?php else: ?>
                <a href="javascript:void(0)" onclick="openModal('signupModal')" class="tmsn-btn tmsn-btn-primary"><?php echo tms_t('nav_signup', 'Sign Up'); ?></a>
                <a href="javascript:void(0)" onclick="openModal('signinModal')" class="tmsn-btn tmsn-btn-ghost"><?php echo tms_t('nav_signin', 'Sign In'); ?></a>
                <?php endif; ?>
            </div>
        </aside>
        <?php endif; ?>
    </div>
</header>
<?php if ($tmsnLogged && !$tmsnLanding): ?>
<?php
// Mobile bottom tab bar (signed-in only; hidden on desktop by css/tms-nav.css). Lives outside <header> because the header
// gets a transform when it hides on scroll, which would break position:fixed for anything inside it.
$tmsnTabs = [
    ['index.php', tms_t('nav_home', 'Home'), 'fa-home', ['index.php']],
    ['explore.php', tms_t('nav_explore', 'Explore'), 'fa-compass', ['explore.php', 'explore-view.php', 'destinations.php']],
    ['tour-map.php', tms_t('nav_map', 'Map'), 'fa-map-marker', ['tour-map.php']],
    ['my-trips.php', tms_t('nav_my_trips', 'My Trips'), 'fa-suitcase', ['my-trips.php', 'trip-planner.php']],
    ['profile.php', tms_t('nav_tab_profile', 'Profile'), 'fa-user-circle-o', ['profile.php', 'dashboard.php']],
];
?>
<nav class="tmsn-tabbar" id="tmsnTabbar" aria-label="<?php echo $tmsnE(tms_t('nav_main', 'Main')); ?>">
    <?php foreach ($tmsnTabs as $tb): ?>
    <a href="<?php echo $tb[0]; ?>"<?php echo in_array($currentPage, $tb[3], true) ? ' aria-current="page"' : ''; ?>><i class="fa <?php echo $tb[2]; ?>" aria-hidden="true"></i><span><?php echo $tmsnE($tb[1]); ?></span></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<script src="js/tms-nav.js" defer></script>

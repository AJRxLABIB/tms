<?php
/**
 * Admin sidebar — grouped into 7 collapsible sections (Overview, Bookings & Payments, Catalogue, Map & Places,
 * People & Suppliers, AI & Safety, System). Every link and every role gate that existed before is preserved:
 * a link is added only if tms_admin_can() allows it, exactly as before. The section holding the current page is
 * expanded; the open/closed state of the others is remembered in localStorage ('tms-admin-nav').
 * A search box filters the links client-side (it only hides/shows menu entries, it grants nothing).
 */
$cur = basename($_SERVER['PHP_SELF']);

// Kept for any older include that still calls it (renders one item immediately).
if (!function_exists('navItem')) {
    function navItem($file, $icon, $label, $cur) {
        $a = ($cur === $file) ? 'active' : '';
        echo "<li class=\"nav-item $a\"><a href=\"$file\"><span class=\"nav-icon\"><i class=\"fa fa-$icon\"></i></span><span class=\"nav-label\">$label</span></a></li>";
    }
}

// Same server-side check tms_admin_require_role() enforces on each page, so the menu cannot drift from real access.
$canSee = function (array $roles) use ($dbh) {
    return isset($dbh) && tms_admin_can($dbh, $roles);
};
$isSuper = isset($dbh) && tms_admin_role($dbh) === 'superadmin';

$groups = [
    'overview' => ['Overview',             'tachometer',   []],
    'bookings' => ['Bookings & Payments',  'calendar-check-o', []],
    'catalogue'=> ['Catalogue',            'briefcase',    []],
    'map'      => ['Map & Places',         'map-marker',   []],
    'people'   => ['People & Suppliers',   'users',        []],
    'ai'       => ['AI & Safety',          'magic',        []],
    'system'   => ['System & Account',     'cogs',         []],
];
$add = function (string $g, string $file, string $icon, string $label) use (&$groups) {
    $groups[$g][2][] = [$file, $icon, $label];
};

// ── Overview
$add('overview', 'dashboard.php', 'tachometer', 'Dashboard');
$add('overview', 'operations-dashboard.php', 'exclamation-circle', 'Operations');
if ($canSee(['finance'])) {
    $add('overview', 'revenue-analytics.php', 'bar-chart', 'Revenue Analytics');
    $add('overview', 'export-report.php', 'download', 'Export Reports');
}
if ($canSee(['operations', 'finance'])) $add('overview', 'user-analytics.php', 'users', 'User Analytics');
if ($canSee(['package_manager'])) $add('overview', 'popular-destinations.php', 'map-marker', 'Popular Destinations');
if ($canSee(['manager'])) $add('overview', 'bi-copilot.php', 'comments-o', 'Business Copilot');

// ── Bookings & Payments
if ($canSee(['booking_manager', 'operations', 'manager'])) {
    $add('bookings', 'manage-bookings.php', 'calendar-check-o', 'Manage Bookings');
    $add('bookings', 'booking-calendar.php', 'calendar', 'Booking Calendar');
    $add('bookings', 'manage-marketplace-bookings.php', 'suitcase', 'Marketplace Bookings');
}
if ($canSee(['operations', 'booking_manager', 'manager'])) $add('bookings', 'manage-custom-trips.php', 'map-o', 'Custom Trips');
if ($canSee(['finance', 'manager'])) $add('bookings', 'supplier-settlements.php', 'money', 'Supplier Settlements');
if ($canSee(['finance', 'package_manager', 'manager'])) $add('bookings', 'manage-coupons.php', 'tag', 'Coupons');

// ── Catalogue
if ($canSee(['package_manager', 'manager'])) {
    $add('catalogue', 'manage-packages.php', 'briefcase', 'All Packages');
    $add('catalogue', 'add-package.php', 'plus-circle', 'Add Package');
}
if ($canSee(['content_manager', 'manager'])) {
    $add('catalogue', 'manage-pages.php', 'file-text', 'Manage Pages');
    $add('catalogue', 'manage-blog.php', 'pencil-square-o', 'Blog Posts');
}
if ($canSee(['content_manager', 'support', 'manager'])) $add('catalogue', 'manage-reviews.php', 'star', 'Reviews');
if ($canSee(['content_manager', 'manager'])) $add('catalogue', 'manage-translations.php', 'language', 'Translations');

// ── Map & Places
if ($canSee(['content_manager', 'manager'])) $add('map', 'map-places.php', 'map-marker', 'Map Places');
if ($canSee(['operations', 'manager'])) {
    $add('map', 'manage-emergency.php', 'phone', 'Emergency Contacts');
    $add('map', 'manage-travel-notices.php', 'info-circle', 'Travel Notices');
}

// ── People & Suppliers
if ($canSee(['support', 'manager'])) {
    $add('people', 'manage-users.php', 'users', 'Manage Users');
    $add('people', 'manage-enquiries.php', 'envelope', 'Enquiries');
    $add('people', 'manage-issues.php', 'exclamation-triangle', 'Support Issues');
    $add('people', 'live-chat.php', 'comments', 'Live Chat');
}
if ($canSee(['manager', 'package_manager'])) {
    $add('people', 'manage-suppliers.php', 'briefcase', 'Suppliers');
    $add('people', 'supplier-quality.php', 'balance-scale', 'Supplier Quality');
}
if ($isSuper) $add('people', 'manage-admins.php', 'user-shield', 'Manage Admins');

// ── AI & Safety
if ($canSee(['ai_manager', 'manager'])) {
    $add('ai', 'ai-dashboard.php', 'magic', 'AI Usage Dashboard');
    $add('ai', 'ai-setup.php', 'plug', 'AI Setup &amp; Test');
    $add('ai', 'ai-safety.php', 'shield', 'AI Safety &amp; Evaluation');
    $add('ai', 'ai-evaluation.php', 'line-chart', 'AI Evaluation &amp; Metrics');
}
if ($canSee(['operations', 'manager'])) $add('ai', 'fraud-cases.php', 'search', 'Fraud Review');

// ── System & Account
if ($canSee(['operations', 'manager'])) {
    $add('system', 'activity-log.php', 'history', 'Activity Log');
    if ($isSuper) $add('system', 'workflow-rules.php', 'cogs', 'Workflow Rules');
    $add('system', 'system-health.php', 'heartbeat', 'System Health');
}
$add('system', 'change-password.php', 'lock', 'Change Password');
$add('system', 'two-factor-setup.php', 'shield', 'Two-Factor Auth');

// Section containing the current page starts open (map editor pages etc. fall back to their list page's section).
$aliases = ['map-place-edit.php' => 'map-places.php', 'map-corrections.php' => 'map-places.php', 'map-duplicates.php' => 'map-places.php',
            'edit-package.php' => 'manage-packages.php', 'add-blog.php' => 'manage-blog.php', 'edit-blog.php' => 'manage-blog.php'];
$curKey = $aliases[$cur] ?? $cur;
$openGroup = '';
foreach ($groups as $gid => [$gl, $gi, $items]) {
    foreach ($items as $it) { if ($it[0] === $curKey) { $openGroup = $gid; break 2; } }
}
?>
<aside class="tms-sidebar" id="tmsSidebar">
  <!-- Logo -->
  <div class="sidebar-logo">
    <div class="logo-icon">✈</div>
    <div class="logo-text">TourBuddy <span>Admin</span></div>
  </div>

  <div class="sidebar-search">
    <label for="tmsNavFilter" class="sr-only">Find a page</label>
    <i class="fa fa-search" aria-hidden="true"></i>
    <input type="search" id="tmsNavFilter" placeholder="Find a page…" autocomplete="off">
  </div>

  <!-- Nav -->
  <nav class="sidebar-nav" aria-label="Admin sections" id="tmsAdminNav" data-open="<?= htmlspecialchars($openGroup, ENT_QUOTES) ?>">
    <?php foreach ($groups as $gid => [$glabel, $gicon, $items]): if (!$items) continue; $isOpen = ($gid === $openGroup); ?>
      <section class="nav-group<?= $isOpen ? ' open' : '' ?>" data-group="<?= $gid ?>">
        <button type="button" class="nav-group-head" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="navg-<?= $gid ?>">
          <span class="nav-icon"><i class="fa fa-<?= $gicon ?>" aria-hidden="true"></i></span>
          <span class="nav-label"><?= $glabel ?></span>
          <i class="fa fa-chevron-down nav-caret" aria-hidden="true"></i>
        </button>
        <ul id="navg-<?= $gid ?>" class="nav-group-list" style="list-style:none;padding:0;">
          <?php foreach ($items as [$file, $icon, $label]) navItem($file, $icon, $label, $curKey); ?>
        </ul>
      </section>
    <?php endforeach; ?>
    <p class="nav-empty" hidden>No matching page.</p>
  </nav>

  <!-- Footer -->
  <div class="sidebar-footer">
    <a href="logout.php">
      <i class="fa fa-sign-out" style="font-size:16px;flex-shrink:0;"></i>
      <span>Sign Out</span>
    </a>
  </div>
</aside>
<script src="js/tms-admin-nav.js" defer></script>

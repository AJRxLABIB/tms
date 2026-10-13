<?php $cur = basename($_SERVER['PHP_SELF']); ?>
<aside class="tms-sidebar" id="tmsSidebar">
  <!-- Logo -->
  <div class="sidebar-logo">
    <div class="logo-icon">✈</div>
    <div class="logo-text">TMS <span>Admin</span></div>
  </div>

  <!-- Nav -->
  <nav class="sidebar-nav" aria-label="Admin sections">
    <?php
    function navItem($file, $icon, $label, $cur) {
        $a = ($cur===$file) ? 'active' : '';
        echo "<li class=\"nav-item $a\">
          <a href=\"$file\">
            <span class=\"nav-icon\"><i class=\"fa fa-$icon\"></i></span>
            <span class=\"nav-label\">$label</span>
          </a>
        </li>";
    }
    ?>
    <?php
    // Round 20 RBAC (item 46): show a link only if the logged-in admin's
    // role actually has server-side access to it (tms_admin_can() is the
    // exact same check tms_admin_require_role() enforces on the page
    // itself, so this can't silently drift out of sync with what's really
    // allowed -- a link the sidebar hides was never a real permission
    // anyway, since the page would 403 it either way).
    $canSee = function (array $roles) use ($dbh) {
        return isset($dbh) && tms_admin_can($dbh, $roles);
    };
    ?>
    <div class="nav-section-label">Main</div>
    <ul style="list-style:none;padding:0;">
      <?php navItem('dashboard.php','tachometer','Dashboard',$cur); ?>
      <?php navItem('operations-dashboard.php','exclamation-circle','Operations',$cur); ?>
    </ul>

    <div class="nav-section-label">Packages</div>
    <ul style="list-style:none;padding:0;">
      <?php if ($canSee(['package_manager', 'manager'])): ?>
        <?php navItem('manage-packages.php','briefcase','All Packages',$cur); ?>
        <?php navItem('add-package.php','plus-circle','Add Package',$cur); ?>
      <?php endif; ?>
    </ul>

    <div class="nav-section-label">Bookings & Users</div>
    <ul style="list-style:none;padding:0;">
      <?php if ($canSee(['booking_manager', 'operations', 'manager'])): ?>
        <?php navItem('manage-bookings.php','calendar-check-o','Manage Bookings',$cur); ?>
        <?php navItem('booking-calendar.php','calendar','Booking Calendar',$cur); ?>
        <?php navItem('manage-marketplace-bookings.php','suitcase','Marketplace Bookings',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['support', 'manager'])): ?>
        <?php navItem('manage-users.php','users','Manage Users',$cur); ?>
      <?php endif; ?>
    </ul>

    <div class="nav-section-label">Communication</div>
    <ul style="list-style:none;padding:0;">
      <?php if ($canSee(['support', 'manager'])): ?>
        <?php navItem('manage-enquiries.php','envelope','Enquiries',$cur); ?>
        <?php navItem('manage-issues.php','exclamation-triangle','Support Issues',$cur); ?>
        <?php navItem('live-chat.php','comments','Live Chat',$cur); ?>
      <?php endif; ?>
    </ul>

    <div class="nav-section-label">Content</div>
    <ul style="list-style:none;padding:0;">
      <?php if ($canSee(['content_manager', 'manager'])): ?>
        <?php navItem('manage-pages.php','file-text','Manage Pages',$cur); ?>
        <?php navItem('manage-blog.php','pencil-square-o','Blog Posts',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['content_manager', 'support', 'manager'])): ?>
        <?php navItem('manage-reviews.php','star','Reviews',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['operations', 'booking_manager', 'manager'])): ?>
        <?php navItem('manage-custom-trips.php','map-o','Custom Trips',$cur); ?>
      <?php endif; ?>
    </ul>

    <div class="nav-section-label">Analytics & Tools</div>
    <ul style="list-style:none;padding:0;">
      <?php if ($canSee(['finance'])): ?>
        <?php navItem('revenue-analytics.php','bar-chart','Revenue Analytics',$cur); ?>
        <?php navItem('export-report.php','download','Export Reports',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['operations', 'finance'])): ?>
        <?php navItem('user-analytics.php','users','User Analytics',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['package_manager'])): ?>
        <?php navItem('popular-destinations.php','map-marker','Popular Destinations',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['ai_manager', 'manager'])): ?>
        <?php navItem('ai-dashboard.php','magic','AI Usage Dashboard',$cur); ?>
        <?php navItem('ai-safety.php','shield','AI Safety &amp; Evaluation',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['manager', 'package_manager'])): ?>
        <?php navItem('manage-suppliers.php','briefcase','Suppliers',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['finance', 'manager'])): ?>
        <?php navItem('supplier-settlements.php','money','Supplier Settlements',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['finance', 'package_manager', 'manager'])): ?>
        <?php navItem('manage-coupons.php','tag','Coupons',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['operations', 'manager'])): ?>
        <?php navItem('manage-emergency.php','phone','Emergency Contacts',$cur); ?>
        <?php navItem('activity-log.php','history','Activity Log',$cur); ?>
        <?php navItem('system-health.php','heartbeat','System Health',$cur); ?>
      <?php endif; ?>
      <?php if ($canSee(['content_manager', 'manager'])): ?>
        <?php navItem('manage-translations.php','language','Translations',$cur); ?>
      <?php endif; ?>
    </ul>

    <div class="nav-section-label">Account</div>
    <ul style="list-style:none;padding:0;">
      <?php navItem('change-password.php','lock','Change Password',$cur); ?>
      <?php navItem('two-factor-setup.php','shield','Two-Factor Auth',$cur); ?>
      <?php if (isset($dbh) && tms_admin_role($dbh) === 'superadmin'): ?>
        <?php navItem('manage-admins.php','user-shield','Manage Admins',$cur); ?>
      <?php endif; ?>
    </ul>
  </nav>

  <!-- Footer -->
  <div class="sidebar-footer">
    <a href="logout.php">
      <i class="fa fa-sign-out" style="font-size:16px;flex-shrink:0;"></i>
      <span>Sign Out</span>
    </a>
  </div>
</aside>

<?php $cur = basename($_SERVER['PHP_SELF']); ?>
<aside class="tms-sidebar" id="tmsSidebar">
  <!-- Logo -->
  <div class="sidebar-logo">
    <div class="logo-icon">✈</div>
    <div class="logo-text">TMS <span>Admin</span></div>
  </div>

  <!-- Nav -->
  <nav class="sidebar-nav">
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
    <div class="nav-section-label">Main</div>
    <ul style="list-style:none;padding:0;">
      <?php navItem('dashboard.php','tachometer','Dashboard',$cur); ?>
    </ul>

    <div class="nav-section-label">Packages</div>
    <ul style="list-style:none;padding:0;">
      <?php navItem('manage-packages.php','briefcase','All Packages',$cur); ?>
      <?php navItem('add-package.php','plus-circle','Add Package',$cur); ?>
    </ul>

    <div class="nav-section-label">Bookings & Users</div>
    <ul style="list-style:none;padding:0;">
      <?php navItem('manage-bookings.php','calendar-check-o','Manage Bookings',$cur); ?>
      <?php navItem('booking-calendar.php','calendar','Booking Calendar',$cur); ?>
      <?php navItem('manage-users.php','users','Manage Users',$cur); ?>
    </ul>

    <div class="nav-section-label">Communication</div>
    <ul style="list-style:none;padding:0;">
      <?php navItem('manage-enquiries.php','envelope','Enquiries',$cur); ?>
      <?php navItem('manage-issues.php','exclamation-triangle','Support Issues',$cur); ?>
      <?php navItem('live-chat.php','comments','Live Chat',$cur); ?>
    </ul>

    <div class="nav-section-label">Content</div>
    <ul style="list-style:none;padding:0;">
      <?php navItem('manage-pages.php','file-text','Manage Pages',$cur); ?>
      <?php navItem('manage-blog.php','pencil-square-o','Blog Posts',$cur); ?>
      <?php navItem('manage-reviews.php','star','Reviews',$cur); ?>
      <?php navItem('manage-custom-trips.php','map-o','Custom Trips',$cur); ?>
    </ul>

    <div class="nav-section-label">Analytics & Tools</div>
    <ul style="list-style:none;padding:0;">
      <?php $__tmsRole = isset($dbh) ? tms_admin_role($dbh) : 'superadmin'; ?>
      <?php if ($__tmsRole === 'superadmin'): ?>
        <?php navItem('revenue-analytics.php','bar-chart','Revenue Analytics',$cur); ?>
        <?php navItem('user-analytics.php','users','User Analytics',$cur); ?>
        <?php navItem('popular-destinations.php','map-marker','Popular Destinations',$cur); ?>
        <?php /* ai-dashboard.php existed already (573 lines — AI usage stats,
                 sentiment breakdown, top AI users) but had no link anywhere
                 in the admin, so it was only reachable by typing the URL. */ ?>
        <?php navItem('ai-dashboard.php','magic','AI Usage Dashboard',$cur); ?>
        <?php navItem('export-report.php','download','Export Reports',$cur); ?>
      <?php endif; ?>
      <?php navItem('manage-coupons.php','tag','Coupons',$cur); ?>
      <?php navItem('manage-emergency.php','phone','Emergency Contacts',$cur); ?>
      <?php navItem('manage-translations.php','language','Translations',$cur); ?>
      <?php navItem('activity-log.php','history','Activity Log',$cur); ?>
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

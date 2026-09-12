<?php if(empty($_SESSION['alogin'])){ header('location:index.php'); exit; } ?>
<script>
(function(){
    try {
        var t = localStorage.getItem('tms-theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    } catch(e) {}
})();
</script>
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$pageLabels = [
  'dashboard.php'=>'Dashboard','manage-packages.php'=>'Manage Packages',
  'add-package.php'=>'Add Package','manage-bookings.php'=>'Manage Bookings',
  'manage-users.php'=>'Manage Users','manage-enquiries.php'=>'Enquiries',
  'manage-issues.php'=>'Support Issues','manage-pages.php'=>'Manage Pages',
  'revenue-analytics.php'=>'Revenue Analytics','export-report.php'=>'Export Reports',
  'manage-coupons.php'=>'Manage Coupons','manage-blog.php'=>'Manage Blog',
  'add-blog.php'=>'Add Blog Post','edit-blog.php'=>'Edit Blog Post',
  'manage-emergency.php'=>'Emergency Contacts','activity-log.php'=>'Activity Log',
  'manage-custom-trips.php'=>'Custom Trips','manage-reviews.php'=>'Reviews',
  'change-password.php'=>'Change Password','do-export.php'=>'Export Data',
  'manage-admins.php'=>'Manage Admins',
  'two-factor-setup.php'=>'Two-Factor Auth',
];
$currentLabel = $pageLabels[$currentPage] ?? ucfirst(str_replace(['-','.php'],['  ',''],$currentPage));
$adminName = htmlentities($_SESSION['alogin']);
$initials = strtoupper(substr($adminName,0,2));
?>
<!-- TOP BAR -->
<header class="tms-topbar">
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="fa fa-bars"></i>
  </button>
  <nav class="topbar-breadcrumb">
    <a href="dashboard.php">Home</a>
    <span class="bc-sep"><i class="fa fa-chevron-right" style="font-size:10px;"></i></span>
    <span class="bc-current"><?= $currentLabel ?></span>
  </nav>
  <div class="topbar-right">
    <button type="button" id="tmsThemeToggle" class="tms-theme-toggle" onclick="tmsToggleTheme()" aria-label="Toggle dark mode" title="Toggle dark / light mode" style="background:rgba(0,0,0,0.06);border:1px solid rgba(0,0,0,0.1);color:inherit;width:30px;height:30px;border-radius:50%;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">
        <i class="fa fa-moon-o" id="tmsThemeIconMoon"></i>
        <i class="fa fa-sun-o" id="tmsThemeIconSun" style="display:none;"></i>
    </button>
    <span class="topbar-date"><?= date('D, d M Y') ?></span>
    <div class="topbar-user">
      <div class="topbar-avatar"><?= $initials ?></div>
      <span><?= $adminName ?></span>
    </div>
    <a href="logout.php" class="btn-v6 ghost sm" title="Logout">
      <i class="fa fa-sign-out"></i> <span class="topbar-logout-label">Logout</span>
    </a>
  </div>
</header>
<script>
// Glass effect on the topbar once the content area scrolls — .tms-content
// is the actual scrollable region in this layout (not the window), so the
// listener attaches there. Same visual idea as the customer navbar's
// scroll-glass behavior from earlier in this pass.
document.addEventListener('DOMContentLoaded', function() {
    var topbar = document.querySelector('.tms-topbar');
    if (!topbar) return;
    function onScroll() {
        topbar.classList.toggle('scrolled', window.scrollY > 10);
    }
    window.addEventListener('scroll', onScroll);
    onScroll();
});
</script>
<script>
function tmsToggleTheme() {
    var html = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    var next = isDark ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    try { localStorage.setItem('tms-theme', next); } catch(e) {}
    tmsSyncThemeIcon();
}
function tmsSyncThemeIcon() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var moon = document.getElementById('tmsThemeIconMoon');
    var sun = document.getElementById('tmsThemeIconSun');
    if (moon && sun) {
        moon.style.display = isDark ? 'none' : '';
        sun.style.display = isDark ? '' : 'none';
    }
}
tmsSyncThemeIcon();
</script>
<style>
html[data-theme="dark"] body { background: #10151c !important; color: #eef2f7 !important; }
html[data-theme="dark"] .tms-topbar,
html[data-theme="dark"] .sidebar,
html[data-theme="dark"] .panel,
html[data-theme="dark"] .card,
html[data-theme="dark"] .stat-card,
html[data-theme="dark"] .modal-content {
    background: #1b222c !important;
    color: #eef2f7 !important;
    border-color: #2b3542 !important;
}
html[data-theme="dark"] .table { color: #eef2f7 !important; }
html[data-theme="dark"] .table > thead > tr > th,
html[data-theme="dark"] .table > tbody > tr > td {
    border-color: #2b3542 !important;
    background: transparent !important;
}
html[data-theme="dark"] .form-control,
html[data-theme="dark"] input,
html[data-theme="dark"] select,
html[data-theme="dark"] textarea {
    background: #232b37 !important;
    color: #eef2f7 !important;
    border-color: #333f4d !important;
}
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
html[data-theme="dark"] [style*="color:#222"], html[data-theme="dark"] [style*="color: #222"] {
    color: #cdd6e0 !important;
}
html[data-theme="dark"] [style*="background:#fff"], html[data-theme="dark"] [style*="background: #fff"],
html[data-theme="dark"] [style*="background:#ffffff"], html[data-theme="dark"] [style*="background: #ffffff"] {
    background: #1b222c !important;
    border-color: #2b3542 !important;
}
html[data-theme="dark"] .page-title-row h2 { color: #eef2f7 !important; }
</style>

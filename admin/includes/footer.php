<footer class="tms-footer">
  <span>&copy; <?= date('Y') ?> Tourism Management System. All rights reserved.</span>
  <span>TourBuddy Admin v6</span>
</footer>
</div><!-- /.tms-content -->
</main><!-- /.tms-main -->

<!-- Sidebar toggle JS -->
<script>
(function(){
  var sidebar = document.getElementById('tmsSidebar');
  var main    = document.getElementById('tmsMain');
  var btn     = document.getElementById('sidebarToggle');
  var key     = 'tms_sidebar_collapsed';

  function apply(collapsed) {
    if(collapsed){ sidebar.classList.add('collapsed'); if(main) main.classList.add('expanded'); }
    else { sidebar.classList.remove('collapsed'); if(main) main.classList.remove('expanded'); }
  }
  apply(localStorage.getItem(key)==='1');
  if(btn) btn.addEventListener('click', function(){
    var c = sidebar.classList.contains('collapsed');
    apply(!c);
    localStorage.setItem(key, c ? '0' : '1');
  });
})();
</script>
<script src="../js/tms-a11y.js" defer></script>

/* tms-nav.js — behaviour for the single header (dropdowns, drawer, theme, hide-on-scroll).
   Pure helpers are exported for node tests (tests/js/nav_test.js); DOM wiring only runs in a browser. */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (typeof window !== 'undefined') { window.TmsNav = api; api.init(); }
})(this, function () {
  'use strict';

  /* ---------- pure helpers ---------- */
  function resolveTheme(pref, prefersDark) {
    if (pref === 'dark' || pref === 'light') return pref;
    return prefersDark ? 'dark' : 'light';          // 'auto' / unknown / empty
  }
  function normalizePref(v) { return (v === 'dark' || v === 'light' || v === 'auto') ? v : 'auto'; }
  /** roving focus: returns the next index for a key, or -1 when the key is not handled */
  function nextIndex(cur, count, key) {
    if (!count) return -1;
    if (key === 'ArrowDown' || key === 'ArrowRight') return cur < 0 ? 0 : (cur + 1) % count;
    if (key === 'ArrowUp' || key === 'ArrowLeft') return cur < 0 ? count - 1 : (cur - 1 + count) % count;
    if (key === 'Home') return 0;
    if (key === 'End') return count - 1;
    return -1;
  }
  /** hide the header when scrolling down past `min`, show on any upward scroll; never while a menu is open */
  function headerState(prevY, y, opts) {
    opts = opts || {};
    var min = opts.min == null ? 120 : opts.min, delta = opts.delta == null ? 6 : opts.delta;
    if (opts.locked) return { hidden: false, scrolled: y > 20 };
    var hidden = !!opts.wasHidden;
    if (y <= min) hidden = false;
    else if (y - prevY > delta) hidden = true;
    else if (prevY - y > delta) hidden = false;
    return { hidden: hidden, scrolled: y > 20 };
  }
  /** wrap Tab inside a container: returns the element to focus, or null to let the browser decide */
  function trapTarget(items, active, shift) {
    if (!items || !items.length) return null;
    var first = items[0], last = items[items.length - 1];
    if (shift && active === first) return last;
    if (!shift && active === last) return first;
    return null;
  }
  /** true when `href` (e.g. "package-list.php?x=1") is the current page file */
  function isCurrent(href, currentFile) {
    var f = String(href || '').split('#')[0].split('?')[0];
    return f !== '' && f === currentFile;
  }

  /* ---------- DOM wiring ---------- */
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  function visible(list) { return Array.prototype.filter.call(list, function (el) { return el.offsetParent !== null || el === document.activeElement; }); }

  function init() {
    if (typeof document === 'undefined') return;
    var header = document.getElementById('tmsNavbar');
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    /* theme: light | dark | auto — one key, resolved before paint by header.php too */
    function readPref() { try { return normalizePref(localStorage.getItem('tms-theme')); } catch (e) { return 'auto'; } }
    function applyTheme(pref) {
      var t = resolveTheme(pref, !!(mq && mq.matches));
      document.documentElement.setAttribute('data-theme', t);
      var btns = document.querySelectorAll('[data-tmsn-theme]');
      Array.prototype.forEach.call(btns, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-tmsn-theme') === pref ? 'true' : 'false'); });
      if (typeof window.tmsSyncThemeIcon === 'function') window.tmsSyncThemeIcon();
      if (typeof window.tmsFixInlineColorsForDarkMode === 'function') window.tmsFixInlineColorsForDarkMode();
    }
    window.tmsSetTheme = function (pref) {
      pref = normalizePref(pref);
      try { localStorage.setItem('tms-theme', pref); } catch (e) {}
      applyTheme(pref);
    };
    window.tmsToggleTheme = function () {            // footer button: simple light <-> dark
      window.tmsSetTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    };
    if (!(header && header.hasAttribute('data-landing'))) applyTheme(readPref());   // landing page stays light
    if (mq && mq.addEventListener) mq.addEventListener('change', function () { if (readPref() === 'auto') applyTheme('auto'); });
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-tmsn-theme]');
      if (b) window.tmsSetTheme(b.getAttribute('data-tmsn-theme'));
    });

    if (!header) return;

    /* dropdowns */
    var dds = Array.prototype.slice.call(header.querySelectorAll('.tmsn-dd'));
    function setOpen(dd, open) {
      dd.classList.toggle('is-open', open);
      var btn = dd.querySelector('[aria-haspopup]');
      if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function closeAll(except) { dds.forEach(function (d) { if (d !== except) setOpen(d, false); }); }
    dds.forEach(function (dd) {
      var btn = dd.querySelector('[aria-haspopup]'), panel = dd.querySelector('.tmsn-panel');
      if (!btn || !panel) return;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = !dd.classList.contains('is-open');
        closeAll(dd); setOpen(dd, open);
        if (open && btn.hasAttribute('data-tmsn-notif')) {
          var badge = btn.querySelector('.tmsn-badge'); if (badge) badge.remove();
          Array.prototype.forEach.call(panel.querySelectorAll('.unread'), function (el) { el.classList.remove('unread'); });
          try { fetch('mark-notifications-read.php', { method: 'POST' }).catch(function () {}); } catch (err) {}
        }
        if (open && e.detail === 0) { var f = visible(panel.querySelectorAll(FOCUSABLE))[0]; if (f) f.focus(); }
      });
      btn.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); closeAll(dd); setOpen(dd, true); var f = visible(panel.querySelectorAll(FOCUSABLE))[0]; if (f) f.focus(); }
      });
      panel.addEventListener('keydown', function (e) {
        var items = visible(panel.querySelectorAll('a[href], button:not([disabled])'));
        var i = items.indexOf(document.activeElement), n = nextIndex(i, items.length, e.key);
        if (n >= 0) { e.preventDefault(); items[n].focus(); }
        else if (e.key === 'Tab') { setOpen(dd, false); }
      });
    });
    document.addEventListener('click', function (e) { dds.forEach(function (d) { if (!d.contains(e.target)) setOpen(d, false); }); });

    /* mobile slide-over with focus trap */
    var burger = document.getElementById('tmsnBurger'), drawer = document.getElementById('tmsnDrawer');
    var scrim = header.querySelector('.tmsn-scrim'), closeBtn = document.getElementById('tmsnDrawerClose');
    var lastFocus = null;
    function drawerOpen() { return header.classList.contains('is-open'); }
    function openDrawer() {
      if (!drawer || drawerOpen()) return;
      lastFocus = document.activeElement; closeAll();
      header.classList.add('is-open'); document.body.style.overflow = 'hidden';
      if (burger) burger.setAttribute('aria-expanded', 'true');
      drawer.removeAttribute('inert');
      var f = visible(drawer.querySelectorAll(FOCUSABLE))[0]; if (f) f.focus();
    }
    function closeDrawer(restore) {
      if (!drawer || !drawerOpen()) return;
      header.classList.remove('is-open'); document.body.style.overflow = '';
      if (burger) burger.setAttribute('aria-expanded', 'false');
      drawer.setAttribute('inert', '');
      if (restore !== false && lastFocus && lastFocus.focus) lastFocus.focus();
      lastFocus = null;
    }
    if (drawer) drawer.setAttribute('inert', '');
    if (burger) burger.addEventListener('click', openDrawer);
    if (closeBtn) closeBtn.addEventListener('click', function () { closeDrawer(); });
    if (scrim) scrim.addEventListener('click', function () { closeDrawer(); });
    if (drawer) drawer.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('a[href]'); if (a) closeDrawer(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (drawerOpen()) { closeDrawer(); return; }
        var open = dds.filter(function (d) { return d.classList.contains('is-open'); })[0];
        if (open) { setOpen(open, false); var b = open.querySelector('[aria-haspopup]'); if (b) b.focus(); }
      } else if (e.key === 'Tab' && drawerOpen()) {
        var t = trapTarget(visible(drawer.querySelectorAll(FOCUSABLE)), document.activeElement, e.shiftKey);
        if (t) { e.preventDefault(); t.focus(); }
      }
    });
    if (window.matchMedia) window.matchMedia('(min-width: 992px)').addEventListener && window.matchMedia('(min-width: 992px)').addEventListener('change', function (m) { if (m.matches) closeDrawer(false); });

    /* sticky header: shadow when scrolled, hide on scroll down, reveal on scroll up */
    var prevY = window.scrollY || 0, ticking = false, wasHidden = false;
    window.addEventListener('scroll', function () {
      if (ticking) return; ticking = true;
      window.requestAnimationFrame(function () {
        var y = window.scrollY || 0;
        var locked = drawerOpen() || dds.some(function (d) { return d.classList.contains('is-open'); }) || header.contains(document.activeElement) && document.activeElement.tagName === 'INPUT';
        var s = headerState(prevY, y, { wasHidden: wasHidden, locked: locked });
        wasHidden = s.hidden; prevY = y;
        header.classList.toggle('is-hidden', s.hidden);
        header.classList.toggle('is-scrolled', s.scrolled);
        ticking = false;
      });
    }, { passive: true });
    header.classList.toggle('is-scrolled', prevY > 20);
    header.addEventListener('focusin', function () { header.classList.remove('is-hidden'); wasHidden = false; });
  }

  return { resolveTheme: resolveTheme, normalizePref: normalizePref, nextIndex: nextIndex, headerState: headerState, trapTarget: trapTarget, isCurrent: isCurrent, init: init };
});

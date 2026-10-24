/* Tropical Journey motion layer. No dependencies. Progressive enhancement: without JS the page is fully visible. */
(function () {
  'use strict';
  var d = document, root = d.documentElement;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  root.classList.add('tms-js');

  // Daypart palette (user may override with ?daypart=night or localStorage tms-daypart)
  function daypart() {
    var o = null; try { o = localStorage.getItem('tms-daypart'); } catch (e) {}
    if (o && /^(dawn|day|dusk|night)$/.test(o)) return o;
    var h = new Date().getHours();
    return h >= 5 && h < 8 ? 'dawn' : h < 17 ? 'day' : h < 20 ? 'dusk' : 'night';
  }
  root.setAttribute('data-tms-daypart', daypart());

  function ready(fn) { d.readyState === 'loading' ? d.addEventListener('DOMContentLoaded', fn) : fn(); }
  ready(function () {
    var hero = d.querySelector('.tms-hero');

    // Scene layers
    if (hero && !hero.querySelector('.tms-scene')) {
      var s = d.createElement('div'); s.className = 'tms-scene'; s.setAttribute('aria-hidden', 'true');
      s.innerHTML = '<i class="s-cloud"></i><i class="s-plane"></i><i class="s-far"></i><i class="s-near"></i><i class="s-palm"></i><i class="s-wave"></i><i class="s-wave w2"></i>';
      hero.appendChild(s);
    }

    // Hero slideshow: <div class="tms-hero-slides" data-images="a.jpg,b.jpg">
    var sl = d.querySelector('.tms-hero-slides');
    if (sl) {
      var imgs = (sl.getAttribute('data-images') || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
      if (imgs.length) {
        var els = imgs.map(function (u, i) { var e = d.createElement('div'); e.className = 'tms-hero-slide' + (i ? '' : ' is-active'); e.style.backgroundImage = 'url("' + u.replace(/"/g, '') + '")'; sl.appendChild(e); return e; });
        var cur = 0, timer = null;
        var tick = function () { els[cur].classList.remove('is-active'); cur = (cur + 1) % els.length; els[cur].classList.add('is-active'); };
        var start = function () { if (!timer && els.length > 1 && !reduce) timer = setInterval(tick, 6500); };
        var stop = function () { clearInterval(timer); timer = null; };
        d.addEventListener('visibilitychange', function () { d.hidden ? stop() : start(); });
        start();
      }
    }

    // Parallax (rAF-throttled, only while hero visible)
    if (hero && !reduce) {
      var ticking = false, vis = true;
      if ('IntersectionObserver' in window) new IntersectionObserver(function (e) { vis = e[0].isIntersecting; }).observe(hero);
      window.addEventListener('scroll', function () {
        if (ticking || !vis) return; ticking = true;
        requestAnimationFrame(function () { root.style.setProperty('--tms-py', Math.min(window.pageYOffset, 800) + 'px'); ticking = false; });
      }, { passive: true });
    }

    // Reveal on scroll
    var rv = [].slice.call(d.querySelectorAll('.tms-reveal'));
    rv.forEach(function (el) { var dl = el.getAttribute('data-delay'); if (dl) el.style.setProperty('--tms-delay', (parseInt(dl, 10) || 0) + 'ms'); });
    if (rv.length) {
      if (!('IntersectionObserver' in window) || reduce) rv.forEach(function (e) { e.classList.add('is-in'); });
      else { var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } }); }, { threshold: .12 }); rv.forEach(function (e) { io.observe(e); }); }
    }

    // Count-up: <span class="tms-count" data-to="1200" data-suffix="+">
    var cn = [].slice.call(d.querySelectorAll('.tms-count'));
    function run(el) {
      var to = parseFloat(el.getAttribute('data-to')) || 0, suf = el.getAttribute('data-suffix') || '';
      if (reduce) { el.textContent = to.toLocaleString() + suf; return; }
      var t0 = null; (function f(t) { t0 = t0 || t; var p = Math.min((t - t0) / 1400, 1); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))).toLocaleString() + suf; if (p < 1) requestAnimationFrame(f); })(performance.now());
    }
    if (cn.length) {
      if ('IntersectionObserver' in window) { var io2 = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { run(e.target); io2.unobserve(e.target); } }); }); cn.forEach(function (e) { io2.observe(e); }); }
      else cn.forEach(run);
    }

    // Card tilt: fine pointers only
    if (!reduce && window.matchMedia && matchMedia('(pointer: fine)').matches) {
      [].forEach.call(d.querySelectorAll('.tms-pkg-card, .tms-tilt'), function (c) {
        c.addEventListener('mousemove', function (e) { var r = c.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5; c.style.transform = 'perspective(800px) rotateY(' + (x * 5).toFixed(2) + 'deg) rotateX(' + (-y * 5).toFixed(2) + 'deg) translateY(-4px)'; });
        c.addEventListener('mouseleave', function () { c.style.transform = ''; });
      });
    }

    // Page-leave fade for same-origin links
    if (!reduce) d.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || a.target || a.hasAttribute('download')) return;
      if (a.origin !== location.origin || a.pathname === location.pathname && a.hash || /^(#|javascript:)/.test(a.getAttribute('href'))) return;
      d.body.classList.add('tms-leaving');
      setTimeout(function () { d.body.classList.remove('tms-leaving'); }, 1500);
    });
    window.addEventListener('pageshow', function () { d.body.classList.remove('tms-leaving'); });
  });
})();

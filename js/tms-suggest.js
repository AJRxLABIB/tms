/*
 * tms-suggest.js — "type a few letters, get matching places" for every destination / location / search box.
 *
 * Automatic: any input listed in AUTO below, or any input with  data-place-suggest="place|hotel|activity|package|all",
 * gets a suggestion list that reads api/suggest.php. Nothing else on the page needs changing.
 *   data-place-suggest-min="2"      minimum letters (default 2)
 *   data-place-suggest-submit="1"   submit the surrounding form after a suggestion is picked
 * A picked suggestion fills the box, fires "input" and "change", and dispatches "tms:place-selected" (detail = the item).
 * Keyboard: Up/Down move, Enter picks, Esc closes. Screen readers get role=combobox / listbox / option.
 * Boxes that already have their own list (Explore, Tour Map, trip planner) are skipped.
 */
(function () {
  'use strict';

  var AUTO = {
    // destination / origin / location boxes (places only)
    'p_dest': 'place', 'p_origin': 'place', 'ctDestination': 'place', 'tcOriginField': 'place', 'tcDestinationField': 'place',
    'tDest': 'place', 'destInput': 'place', 'heroDestInput': 'place', 'destSearch': 'place',
    'pkgLocationField': 'place',
    // search boxes that also look up names in a catalogue
    'hotelsSearch': 'hotel', 'activitiesSearch': 'activity', 'pkgListSearch': 'package'
  };

  var script = document.currentScript;
  var apiUrl = (function () {
    var src = script && script.src ? script.src : '';
    var i = src.indexOf('js/tms-suggest.js');
    return (i > -1 ? src.slice(0, i) : '') + 'api/suggest.php';
  })();
  var lang = (document.documentElement.getAttribute('lang') || '').toLowerCase().indexOf('bn') === 0 ? 'bn' : 'en';

  injectCss();
  var uid = 0;

  function ready(fn) {
    if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn);
  }
  ready(function () {
    Object.keys(AUTO).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) attach(el, AUTO[id]);
    });
    var tagged = document.querySelectorAll('input[data-place-suggest]');
    for (var i = 0; i < tagged.length; i++) attach(tagged[i], tagged[i].getAttribute('data-place-suggest') || 'place');
  });

  function attach(input, kind) {
    if (!input || input.__tmsSuggest) return;
    var t = (input.getAttribute('type') || 'text').toLowerCase();
    if (t !== 'text' && t !== 'search') return;
    input.__tmsSuggest = true;
    var id = 'tms-sg-' + (++uid);
    var list = document.createElement('ul');
    list.id = id; list.className = 'tms-sg-list'; list.setAttribute('role', 'listbox'); list.hidden = true;
    document.body.appendChild(list);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', id);

    var minLen = parseInt(input.getAttribute('data-place-suggest-min') || '2', 10) || 2;
    var items = [], active = -1, timer = null, ctrl = null, seq = 0, cache = {};

    function close() {
      list.hidden = true; active = -1;
      input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant');
    }
    function place() {
      var r = input.getBoundingClientRect();
      list.style.left = (r.left + window.pageXOffset) + 'px';
      list.style.top = (r.bottom + window.pageYOffset + 4) + 'px';
      list.style.width = Math.max(r.width, 240) + 'px';
    }
    function render() {
      list.innerHTML = '';
      if (!items.length) { close(); return; }
      items.forEach(function (it, i) {
        var li = document.createElement('li');
        li.id = id + '-' + i; li.className = 'tms-sg-item'; li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false'); li.setAttribute('data-i', String(i));
        var ic = document.createElement('span'); ic.className = 'tms-sg-ic'; ic.setAttribute('aria-hidden', 'true');
        ic.textContent = it.type === 'hotel' ? '🏨' : it.type === 'activity' ? '🎯' : it.type === 'package' ? '🧳' : '📍';
        var tx = document.createElement('span'); tx.className = 'tms-sg-tx';
        var b = document.createElement('span'); b.className = 'tms-sg-main'; highlight(b, it.label, input.value.trim());
        tx.appendChild(b);
        if (it.sub && it.sub !== it.label) { var s = document.createElement('span'); s.className = 'tms-sg-sub'; s.textContent = it.sub; tx.appendChild(s); }
        li.appendChild(ic); li.appendChild(tx); list.appendChild(li);
      });
      place(); list.hidden = false; input.setAttribute('aria-expanded', 'true');
    }
    function highlight(node, text, q) {
      var i = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1;
      if (i < 0) { node.textContent = text; return; }
      node.appendChild(document.createTextNode(text.slice(0, i)));
      var m = document.createElement('mark'); m.textContent = text.slice(i, i + q.length); node.appendChild(m);
      node.appendChild(document.createTextNode(text.slice(i + q.length)));
    }
    function setActive(n) {
      var kids = list.children;
      if (!kids.length) return;
      if (active > -1 && kids[active]) { kids[active].classList.remove('is-active'); kids[active].setAttribute('aria-selected', 'false'); }
      active = (n + kids.length) % kids.length;
      kids[active].classList.add('is-active'); kids[active].setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', kids[active].id);
      if (kids[active].scrollIntoView) kids[active].scrollIntoView({ block: 'nearest' });
    }
    function pick(i) {
      var it = items[i]; if (!it) return;
      input.value = it.value;
      close();
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      try { input.dispatchEvent(new CustomEvent('tms:place-selected', { bubbles: true, detail: it })); } catch (e) {}
      if (input.getAttribute('data-place-suggest-submit') === '1' && input.form) {
        if (input.form.requestSubmit) input.form.requestSubmit(); else input.form.submit();
      }
    }
    function fetchNow() {
      var q = input.value.trim();
      if (q.length < minLen) { items = []; close(); return; }
      var key = kind + '|' + q.toLowerCase();
      if (cache[key]) { items = cache[key]; render(); return; }
      if (ctrl && ctrl.abort) ctrl.abort();
      ctrl = window.AbortController ? new AbortController() : null;
      var mine = ++seq;
      var url = apiUrl + '?q=' + encodeURIComponent(q) + '&kind=' + encodeURIComponent(kind) + '&lang=' + lang;
      fetch(url, { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined, headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { items: [] }; })
        .then(function (d) {
          if (mine !== seq) return;
          items = (d && d.items) || [];
          cache[key] = items;
          if (document.activeElement === input) render();
        })
        .catch(function () { /* offline or aborted: just show nothing */ });
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(fetchNow, 180); });
    input.addEventListener('focus', function () { if (items.length && input.value.trim().length >= minLen) render(); });
    input.addEventListener('keydown', function (e) {
      if (list.hidden) { if (e.key === 'ArrowDown' && input.value.trim().length >= minLen) { fetchNow(); } return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Enter') { if (active > -1) { e.preventDefault(); pick(active); } else close(); }
      else if (e.key === 'Escape') { close(); }
      else if (e.key === 'Tab') { close(); }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
    list.addEventListener('mousedown', function (e) {
      var li = e.target.closest ? e.target.closest('li[data-i]') : null;
      if (li) { e.preventDefault(); pick(parseInt(li.getAttribute('data-i'), 10)); }
    });
    list.addEventListener('mousemove', function (e) {
      var li = e.target.closest ? e.target.closest('li[data-i]') : null;
      if (li) { var n = parseInt(li.getAttribute('data-i'), 10); if (n !== active) setActive(n); }
    });
    window.addEventListener('resize', function () { if (!list.hidden) place(); });
    window.addEventListener('scroll', function () { if (!list.hidden) place(); }, true);
  }

  function injectCss() {
    if (document.getElementById('tms-sg-css')) return;
    var s = document.createElement('style'); s.id = 'tms-sg-css';
    s.textContent =
      '.tms-sg-list{position:absolute;z-index:100000;margin:0;padding:6px;list-style:none;max-height:320px;overflow:auto;' +
      'background:var(--tms-surface,#fff);color:var(--tms-text,#12303A);border:1px solid var(--tms-border,#E9DCCB);border-radius:12px;' +
      'box-shadow:0 14px 40px rgba(10,60,70,.18);font:15px/1.35 inherit;text-align:left}' +
      '.tms-sg-list[hidden]{display:none}' +
      '.tms-sg-item{display:flex;gap:10px;align-items:center;padding:9px 10px;border-radius:8px;cursor:pointer}' +
      '.tms-sg-item.is-active{background:var(--tms-info-bg,#DDF2F6)}' +
      '.tms-sg-ic{flex:0 0 auto;width:22px;text-align:center;font-size:16px}' +
      '.tms-sg-tx{display:flex;flex-direction:column;min-width:0}' +
      '.tms-sg-main{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.tms-sg-main mark{background:transparent;color:var(--tms-primary,#0E7C86);font-weight:800;padding:0}' +
      '.tms-sg-sub{font-size:12.5px;color:var(--tms-muted,#4F6A73);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      'html:root[data-theme="dark"] .tms-sg-list{background:var(--tms-surface,#16262b);color:var(--tms-text,#E8F3F4)}';
    document.head.appendChild(s);
  }
})();

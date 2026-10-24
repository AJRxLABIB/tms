/* tms-a11y.js — small, dependency-free accessibility helpers (storefront + admin).
   window.tmsA11y.announce(msg, 'polite'|'assertive')   -> screen-reader status message
   window.tmsA11y.setFieldError(input, message)         -> aria-invalid + linked error text (message '' clears)
   Also: wraps bare .table elements in .table-responsive, and marks required fields aria-required. */
(function () {
  'use strict';
  var live;
  function region() {
    if (live && document.body.contains(live)) return live;
    live = document.getElementById('tmsA11yLive');
    if (!live) {
      live = document.createElement('div');
      live.id = 'tmsA11yLive'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite'); live.setAttribute('aria-atomic', 'true');
      document.body.appendChild(live);
    }
    return live;
  }
  function announce(msg, mode) {
    var r = region();
    r.setAttribute('aria-live', mode === 'assertive' ? 'assertive' : 'polite');
    r.setAttribute('role', mode === 'assertive' ? 'alert' : 'status');
    r.textContent = '';
    setTimeout(function () { r.textContent = String(msg == null ? '' : msg); }, 30); // re-set so repeated messages are re-read
  }
  var seq = 0;
  function setFieldError(input, message) {
    if (!input) return;
    var id = input.getAttribute('data-err-id');
    var el = id ? document.getElementById(id) : null;
    if (!message) {
      input.removeAttribute('aria-invalid');
      if (el) el.parentNode.removeChild(el);
      input.removeAttribute('data-err-id');
      var d = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (x) { return x && x !== id; }).join(' ');
      if (d) input.setAttribute('aria-describedby', d); else input.removeAttribute('aria-describedby');
      return;
    }
    if (!el) {
      id = 'tms-err-' + (++seq);
      el = document.createElement('div'); el.id = id; el.className = 'tms-field-error'; el.setAttribute('role', 'alert');
      input.parentNode.insertBefore(el, input.nextSibling);
      input.setAttribute('data-err-id', id);
      var cur = input.getAttribute('aria-describedby');
      input.setAttribute('aria-describedby', cur ? cur + ' ' + id : id);
    }
    el.textContent = message;
    input.setAttribute('aria-invalid', 'true');
  }
  function enhance() {
    var tables = document.querySelectorAll('table.table, table.table-bordered, table.table-striped');
    for (var i = 0; i < tables.length; i++) {
      var t = tables[i], p = t.parentNode;
      if (!p || (p.className && /table-responsive/.test(p.className))) continue;
      var w = document.createElement('div'); w.className = 'table-responsive';
      p.insertBefore(w, t); w.appendChild(t);
    }
    var req = document.querySelectorAll('input[required], select[required], textarea[required]');
    for (var j = 0; j < req.length; j++) if (!req[j].hasAttribute('aria-required')) req[j].setAttribute('aria-required', 'true');
    // Native validation: link the browser's message to the field and announce it.
    document.addEventListener('invalid', function (e) {
      var f = e.target; if (f && f.setAttribute) f.setAttribute('aria-invalid', 'true');
    }, true);
    document.addEventListener('input', function (e) {
      var f = e.target; if (f && f.getAttribute && f.getAttribute('aria-invalid') === 'true' && f.validity && f.validity.valid && !f.getAttribute('data-err-id')) f.removeAttribute('aria-invalid');
    }, true);
  }
  window.tmsA11y = { announce: announce, setFieldError: setFieldError };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance); else enhance();
})();

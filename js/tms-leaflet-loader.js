/*
 * tms-leaflet-loader.js — loads Leaflet only when a page actually needs a map, never in the render path.
 *
 * Before: package-list.php and package-details.php loaded leaflet.css in <head> and leaflet.js as a synchronous
 * <script> from unpkg.com, so a slow/blocked CDN stalled first paint and kept the tab spinner running.
 *
 *   tmsLoadLeaflet(function (err) { if (err) { show message; return; } L.map(...) });
 *
 * Tries unpkg, then jsDelivr, then cdnjs (same pinned version), with a per-source timeout. Callbacks queue while
 * a load is in flight; if Leaflet is already present the callback runs immediately.
 */
(function () {
    'use strict';
    var VERSION = '1.9.4';
    var SOURCES = [
        // Bundled copy first: no third-party CDN on the critical path (a slow or blocked CDN used to leave maps blank).
        { css: 'js/vendor/leaflet/leaflet.css', js: 'js/vendor/leaflet/leaflet.js' },
        { css: 'https://unpkg.com/leaflet@' + VERSION + '/dist/leaflet.css', js: 'https://unpkg.com/leaflet@' + VERSION + '/dist/leaflet.js' },
        { css: 'https://cdn.jsdelivr.net/npm/leaflet@' + VERSION + '/dist/leaflet.css', js: 'https://cdn.jsdelivr.net/npm/leaflet@' + VERSION + '/dist/leaflet.js' },
        { css: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/' + VERSION + '/leaflet.css', js: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/' + VERSION + '/leaflet.js' }
    ];
    var TIMEOUT_MS = 6000;
    var waiting = [];
    var loading = false;

    function flush(err) {
        var cbs = waiting; waiting = []; loading = false;
        cbs.forEach(function (cb) { try { cb(err || null); } catch (e) { if (window.console) console.error(e); } });
    }

    function addCss(href) {
        if (document.querySelector('link[data-tms-leaflet][href="' + href + '"]')) return;
        var l = document.createElement('link');
        l.rel = 'stylesheet'; l.href = href; l.setAttribute('data-tms-leaflet', '1');
        document.head.appendChild(l);
    }

    function tryLoad(i) {
        if (typeof window.L !== 'undefined' && window.L.map) { flush(null); return; }
        if (i >= SOURCES.length) { flush(new Error('Leaflet could not be loaded from any source')); return; }
        var src = SOURCES[i], done = false, s = document.createElement('script');
        var timer = setTimeout(function () { fail(); }, TIMEOUT_MS);
        function fail() {
            if (done) return; done = true; clearTimeout(timer);
            if (s.parentNode) s.parentNode.removeChild(s);
            tryLoad(i + 1);
        }
        s.onload = function () {
            if (done) return; done = true; clearTimeout(timer);
            if (typeof window.L !== 'undefined' && window.L.map) flush(null); else tryLoad(i + 1);
        };
        s.onerror = fail;
        s.async = true; s.src = src.js;
        addCss(src.css);
        document.head.appendChild(s);
    }

    window.tmsLoadLeaflet = function (cb) {
        if (typeof window.L !== 'undefined' && window.L.map) { cb(null); return; }
        waiting.push(cb);
        if (!loading) { loading = true; tryLoad(0); }
    };
})();

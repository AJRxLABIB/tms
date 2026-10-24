/*
 * tms-map.js — shared Leaflet helpers so every map on the site behaves the same way.
 *
 *   TmsMap.addTiles(map, { maxZoom: 18 })       tile layer that falls back OSM -> Carto -> OSM France when tiles fail
 *   TmsMap.fixSize(map)                         redraws the map when its container becomes visible / resized
 *   TmsMap.fetchJson(url, { timeout: 12000 })   fetch with a hard timeout (rejects with Error('timeout'))
 *
 * Why: the maps used one tile server with no error handling (a blocked or slow server left a blank grey box) and were
 * created inside hidden tabs (Leaflet measures 0x0 there and never recovers without invalidateSize()).
 */
(function () {
    'use strict';

    var PROVIDERS = [
        { name: 'OpenStreetMap', url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', subdomains: 'abc',
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' },
        { name: 'Carto', url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', subdomains: 'abcd',
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>' },
        { name: 'OpenStreetMap France', url: 'https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', subdomains: 'abc',
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, tiles by OSM France' }
    ];
    var ERRORS_BEFORE_SWITCH = 4;   // consecutive tile errors with no successful tile in between

    function addTiles(map, opts) {
        opts = opts || {};
        var maxZoom = opts.maxZoom || 18;
        var idx = 0, layer = null, errors = 0, loaded = 0, notice = null;

        function build(i) {
            var p = PROVIDERS[i];
            var l = L.tileLayer(p.url, {
                attribution: p.attribution, maxZoom: maxZoom, subdomains: p.subdomains,
                crossOrigin: false, referrerPolicy: 'strict-origin-when-cross-origin', detectRetina: false
            });
            l.on('tileload', function () { loaded++; errors = 0; hideNotice(); });
            l.on('tileerror', function () {
                errors++;
                if (errors >= ERRORS_BEFORE_SWITCH && loaded === 0) next();
            });
            return l;
        }

        function next() {
            if (idx + 1 >= PROVIDERS.length) { showNotice(); return; }
            idx++; errors = 0;
            if (layer) map.removeLayer(layer);
            layer = build(idx).addTo(map);
            if (window.console) console.warn('[TMS map] tile server failed, switched to ' + PROVIDERS[idx].name);
        }

        function showNotice() {
            if (notice || !map.getContainer) return;
            notice = document.createElement('div');
            notice.className = 'tms-map-notice';
            notice.setAttribute('role', 'status');
            notice.style.cssText = 'position:absolute;left:8px;right:8px;bottom:26px;z-index:1000;background:rgba(15,23,42,.88);color:#fff;' +
                'font:13px/1.4 system-ui,sans-serif;padding:8px 12px;border-radius:8px;text-align:center;pointer-events:none';
            notice.textContent = 'Map background tiles could not be loaded (check your internet connection). Pins and the list below still work.';
            map.getContainer().appendChild(notice);
        }
        function hideNotice() { if (notice && notice.parentNode) { notice.parentNode.removeChild(notice); } notice = null; }

        layer = build(0).addTo(map);
        // If nothing at all has loaded after a while (silent hang rather than an error), move on to the next server.
        setTimeout(function () { if (loaded === 0 && idx === 0) next(); }, 7000);
        return { layer: function () { return layer; }, provider: function () { return PROVIDERS[idx].name; } };
    }

    function fixSize(map) {
        if (!map || !map.getContainer) return;
        var el = map.getContainer();
        var redraw = function () { try { map.invalidateSize({ pan: false }); } catch (e) {} };
        setTimeout(redraw, 0); setTimeout(redraw, 250); setTimeout(redraw, 1000);
        if (typeof ResizeObserver !== 'undefined') {
            var ro = new ResizeObserver(function () { redraw(); });
            ro.observe(el);
        }
        if (typeof IntersectionObserver !== 'undefined') {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) { if (e.isIntersecting) redraw(); });
            }, { threshold: 0.01 });
            io.observe(el);
        }
        window.addEventListener('resize', redraw);
    }

    function fetchJson(url, opts) {
        opts = opts || {};
        var ms = opts.timeout || 12000;
        var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctl) ctl.abort(); }, ms);
        var p = fetch(url, Object.assign({ credentials: 'same-origin' }, opts.init || {}, ctl ? { signal: ctl.signal } : {}))
            .then(function (r) {
                return r.text().then(function (t) {
                    try { return JSON.parse(t); }
                    catch (e) { throw new Error('bad_response'); }
                });
            });
        // Without AbortController support, still stop waiting after the timeout.
        var guard = new Promise(function (_, reject) { setTimeout(function () { reject(new Error('timeout')); }, ms + 500); });
        return Promise.race([p, guard]).then(
            function (v) { clearTimeout(timer); return v; },
            function (e) { clearTimeout(timer); throw (e && e.name === 'AbortError') ? new Error('timeout') : e; }
        );
    }

    window.TmsMap = { addTiles: addTiles, fixSize: fixSize, fetchJson: fetchJson, providers: PROVIDERS };
})();

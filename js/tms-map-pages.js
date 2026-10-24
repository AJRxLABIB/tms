/*
 * tms-map-pages.js — small glue so the secondary map pages (heat map, package details/list, explore, flight status, admin)
 * can use the shared MapLibre module (js/tms-maplibre.js) without repeating boilerplate.
 *
 * PUBLIC API (window.TmsMapPages)
 *   TmsMapPages.mount(el, opts)  -> Promise<controller>    loads MapLibre on demand (CSS + JS), fetches api/map.php?action=config,
 *                                                          then TmsMapGL.createMap(). Rejects with Error('lib'|'config'|...) — callers
 *                                                          show their own friendly message. opts: {base, api, lang, center:[lng,lat], zoom, controls, cooperativeGestures}
 *   TmsMapPages.lazyMount(el, opts, onReady, onError)       same, but only starts when `el` is within 300px of the viewport
 *                                                          (IntersectionObserver; immediate when unsupported).
 *   TmsMapPages.pins(ctl, id, opts) -> {set(items), clear(), fit(), destroy()}
 *                                                          clustered circle pins from [{lat,lng,title,subtitle,url,color,data}] using GeoJSON layers
 *                                                          (survives style switches). opts: {color, cluster:true, onClick(item), popup:true}
 *   TmsMapPages.fetchJson(url, {timeout})                   fetch with a hard timeout (rejects Error('timeout'))
 *   TmsMapPages.nearbyLocal(base, api, lat, lng, exploreCategory, radiusM) -> Promise<items[]>   verified/OSM places from api/map.php?action=nearby
 *   TmsMapPages.utils.*  pure helpers (node-tested in tests/js/heatmap_test.js)
 *       buildHeatPoints(rows, {max, normalize, floor}) -> {points:[{lat,lng,weight,raw,name,id,type}], skipped, max, truncated}
 *       toFeatureCollection(items)   toExploreCategories(exploreCat)   escapeHtml   validCoord
 *
 * Every custom source/layer id starts with "tms-" so the label-language patcher leaves them alone.
 * All API/page text is inserted with textContent (never innerHTML).
 */
(function (root, factory) {
    'use strict';
    var api = factory(root);
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    else { root.TmsMapPages = api; }
}(typeof self !== 'undefined' ? self : this, function (root) {
    'use strict';

    /* ============================ pure helpers ============================ */
    var utils = {};

    utils.escapeHtml = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    /** finite, in range, and not the (0,0) placeholder. Accepts numeric strings (the DB stores MapLat/MapLng as varchar). */
    utils.validCoord = function (lat, lng) {
        if (lat === null || lng === null || lat === undefined || lng === undefined || lat === '' || lng === '') return false;
        if (typeof lat === 'boolean' || typeof lng === 'boolean') return false;
        var a = Number(lat), b = Number(lng);
        if (!isFinite(a) || !isFinite(b)) return false;
        if (a < -90 || a > 90 || b < -180 || b > 180) return false;
        if (a === 0 && b === 0) return false;
        return true;
    };

    /**
     * rows: [{lat,lng,weight,name,id,type}]. weight = real demand (page views + 15 x confirmed bookings, computed server side).
     * Output weights are normalised to [floor..1] so that one runaway destination does not wash out the rest; `raw` keeps the
     * real number for tooltips. Invalid coordinates and negative/NaN weights are dropped (counted in `skipped`); the list is
     * capped at opts.max (default 2000, highest weight first) so a huge catalogue cannot freeze the browser.
     */
    utils.buildHeatPoints = function (rows, opts) {
        opts = opts || {};
        var max = opts.max > 0 ? Math.floor(opts.max) : 2000;
        var floor = typeof opts.floor === 'number' ? Math.max(0, Math.min(0.9, opts.floor)) : 0.1;
        var out = [], skipped = 0;
        (Array.isArray(rows) ? rows : []).forEach(function (r) {
            if (!r || !utils.validCoord(r.lat, r.lng)) { skipped++; return; }
            var w = Number(r.weight);
            if (!isFinite(w) || w < 0) { skipped++; return; }
            out.push({ lat: Number(r.lat), lng: Number(r.lng), raw: w, weight: w, name: r.name == null ? '' : String(r.name),
                id: r.id == null ? null : r.id, type: r.type == null ? '' : String(r.type) });
        });
        out.sort(function (a, b) { return b.raw - a.raw; });
        var truncated = out.length > max;
        if (truncated) out = out.slice(0, max);
        var top = 0;
        out.forEach(function (p) { if (p.raw > top) top = p.raw; });
        out.forEach(function (p) {
            p.weight = opts.normalize === false ? p.raw : (top > 0 ? Math.round((Math.min(1, (p.raw / top) * (1 - floor) + floor)) * 1000) / 1000 : floor);
        });
        return { points: out, skipped: skipped, max: top, truncated: truncated };
    };

    /** GeoJSON FeatureCollection from [{lat,lng,title,subtitle,url,color,id}] — invalid coordinates are skipped. */
    utils.toFeatureCollection = function (items) {
        var feats = [];
        (Array.isArray(items) ? items : []).forEach(function (it, i) {
            if (!it || !utils.validCoord(it.lat, it.lng)) return;
            feats.push({ type: 'Feature', id: i, properties: {
                i: i, title: it.title == null ? '' : String(it.title), subtitle: it.subtitle == null ? '' : String(it.subtitle),
                url: it.url == null ? '' : String(it.url), color: it.color || '', weight: typeof it.weight === 'number' ? it.weight : 1
            }, geometry: { type: 'Point', coordinates: [Number(it.lng), Number(it.lat)] } });
        });
        return { type: 'FeatureCollection', features: feats };
    };

    /** Explore Mode categories -> places-catalogue category ids (empty list = no local equivalent, skip the local lookup). */
    var EXPLORE_TO_LOCAL = {
        hotel: ['hotel'], hotels: ['hotel'], restaurant: ['restaurant'], restaurants: ['restaurant'],
        hospital: ['hospital'], police: ['police'], mall: ['market'], malls: ['market'],
        tourist_info: ['visitor_info'], tourist: ['visitor_info'], attraction: ['attraction', 'historic', 'museum', 'beach', 'mountain', 'waterfall', 'park', 'religious', 'trail'],
        attractions: ['attraction', 'historic', 'museum', 'beach', 'mountain', 'waterfall', 'park', 'religious', 'trail']
    };
    utils.toExploreCategories = function (c) {
        var k = String(c || '').toLowerCase().replace(/[\s-]+/g, '_');
        return EXPLORE_TO_LOCAL[k] ? EXPLORE_TO_LOCAL[k].slice() : [];
    };

    /** Only same-origin relative or http(s) URLs may be used for links built from data. */
    utils.safeUrl = function (u) {
        var s = String(u == null ? '' : u).trim();
        if (!s) return '';
        if (/^(https?:)?\/\//i.test(s) || /^[a-z0-9_\-./?=&%#]+$/i.test(s)) return s.replace(/^javascript:/i, '');
        return '';
    };

    /* ============================ DOM helpers ============================ */
    function doc() { return root.document; }

    function deriveBase(explicit) {
        if (typeof explicit === 'string') return explicit;
        var d = doc(); if (!d) return '';
        var s = d.querySelector('script[src*="tms-map-pages.js"]');
        if (!s) return '';
        var src = s.getAttribute('src') || '';
        var i = src.indexOf('js/tms-map-pages.js');
        return i > 0 ? src.slice(0, i) : '';
    }

    function fetchJson(url, o) {
        var timeout = (o && o.timeout) || 12000;
        var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = null;
        var killer = new Promise(function (_, rej) { timer = setTimeout(function () { if (ctrl) ctrl.abort(); rej(new Error('timeout')); }, timeout); });
        var req = fetch(url, { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });   // error bodies are JSON too: callers show the API's own message
        return Promise.race([req, killer]).then(function (v) { clearTimeout(timer); return v; }, function (e) { clearTimeout(timer); throw e; });
    }

    var libPromise = null;
    function loadScript(src) {
        return new Promise(function (res, rej) {
            var s = doc().createElement('script');
            s.src = src; s.async = true;
            s.onload = function () { res(); };
            s.onerror = function () { rej(new Error('lib')); };
            doc().head.appendChild(s);
        });
    }
    function loadCss(href) {
        var d = doc();
        if (d.querySelector('link[href="' + href + '"]')) return;
        var l = d.createElement('link'); l.rel = 'stylesheet'; l.href = href; d.head.appendChild(l);
    }
    function ensureLibs(base) {
        if (root.maplibregl && root.TmsMapGL) return Promise.resolve();
        if (libPromise) return libPromise;
        loadCss(base + 'js/vendor/maplibre/maplibre-gl.css');
        loadCss(base + 'css/tms-map-ui.css');
        libPromise = Promise.resolve()
            .then(function () { return root.maplibregl ? null : loadScript(base + 'js/vendor/maplibre/maplibre-gl-csp.js'); })
            .then(function () { return root.TmsMapGL ? null : loadScript(base + 'js/tms-maplibre.js'); })
            .then(function () { if (!root.maplibregl || !root.TmsMapGL) throw new Error('lib'); })
            .catch(function (e) { libPromise = null; throw e; });   // allow a later retry (user clicks "retry")
        return libPromise;
    }

    var cfgPromise = null;
    function loadConfig(base, apiUrl) {
        if (cfgPromise) return cfgPromise;
        cfgPromise = fetchJson(base + apiUrl + '?action=config', { timeout: 10000 }).then(function (j) {
            if (!j || j.ok === false) throw new Error('config');
            return j.config || j;
        }).catch(function (e) { cfgPromise = null; throw e; });
        return cfgPromise;
    }

    function mount(el, opts) {
        opts = opts || {};
        if (typeof el === 'string') el = doc().getElementById(el);
        if (!el) return Promise.reject(new Error('container'));
        var base = deriveBase(opts.base);
        var apiUrl = opts.api || 'api/map.php';
        return ensureLibs(base).then(function () {
            return loadConfig(base, apiUrl).catch(function () {
                // The base map must not depend on our own API: fall back to a config-less map (TmsMapGL uses its raster fallback).
                return { styles: [], center: opts.center, zoom: opts.zoom, lang: opts.lang, _noConfig: true };
            });
        }).then(function (cfg) {
            return root.TmsMapGL.createMap(el, {
                config: cfg, base: base, lang: opts.lang || cfg.lang || 'en',
                center: opts.center, zoom: opts.zoom, controls: opts.controls, labels: opts.labels,
                cooperativeGestures: opts.cooperativeGestures
            });
        });
    }

    function lazyMount(el, opts, onReady, onError) {
        if (typeof el === 'string') el = doc().getElementById(el);
        if (!el) { if (onError) onError(new Error('container')); return; }
        var started = false;
        function go() {
            if (started) return; started = true;
            mount(el, opts).then(function (ctl) { if (onReady) onReady(ctl); }, function (e) { if (onError) onError(e); });
        }
        if (typeof IntersectionObserver === 'undefined') { go(); return; }
        var io = new IntersectionObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) if (entries[i].isIntersecting) { io.disconnect(); go(); return; }
        }, { rootMargin: '300px 0px' });
        io.observe(el);
    }

    /** Clustered pins layer. ids: tms-pins-<id>-src / -cluster / -count / -point. */
    function pins(ctl, id, o) {
        o = o || {};
        var src = 'tms-pins-' + id + '-src', LC = 'tms-pins-' + id + '-cluster', LN = 'tms-pins-' + id + '-count', LP = 'tms-pins-' + id + '-point';
        var items = [], popup = null, handlersBound = false, destroyed = false;
        var color = o.color || '#176B50';

        function build(map) {
            if (destroyed || !map.getStyle()) return;
            if (!map.getSource(src)) {
                map.addSource(src, { type: 'geojson', data: utils.toFeatureCollection(items), cluster: o.cluster !== false, clusterRadius: 48, clusterMaxZoom: 13 });
            }
            if (!map.getLayer(LC)) map.addLayer({ id: LC, type: 'circle', source: src, filter: ['has', 'point_count'],
                paint: { 'circle-color': color, 'circle-radius': ['step', ['get', 'point_count'], 16, 10, 20, 50, 26], 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff', 'circle-opacity': 0.92 } });
            // the plain raster fallback style has no glyph server: skip the count labels there (the sized circles still show clusters)
            if (!map.getLayer(LN) && map.getStyle().glyphs) map.addLayer({ id: LN, type: 'symbol', source: src, filter: ['has', 'point_count'],
                layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-font': ['Noto Sans Bold'], 'text-size': 12 }, paint: { 'text-color': '#ffffff' } });
            if (!map.getLayer(LP)) map.addLayer({ id: LP, type: 'circle', source: src, filter: ['!', ['has', 'point_count']],
                paint: { 'circle-color': ['case', ['!=', ['get', 'color'], ''], ['get', 'color'], color], 'circle-radius': 8, 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff' } });
            bind(map);
        }

        function openPopup(map, f) {
            var p = f.properties || {}, idx = Number(p.i), it = items[idx];
            if (o.onClick && it) o.onClick(it, f);
            if (o.popup === false) return;
            if (popup) popup.remove();
            var wrap = doc().createElement('div');
            wrap.className = 'tms-popup';
            var t = doc().createElement('strong'); t.className = 'tms-popup-title'; t.textContent = p.title || ''; wrap.appendChild(t);
            if (p.subtitle) { var s = doc().createElement('div'); s.textContent = p.subtitle; s.className = 'tms-popup-sub'; wrap.appendChild(s); }
            var u = utils.safeUrl(p.url);
            if (u) { var a = doc().createElement('a'); a.href = u; a.textContent = (o.linkLabel || 'View details') + ' →'; a.className = 'tms-popup-link'; wrap.appendChild(a); }
            popup = new root.maplibregl.Popup({ closeButton: true, maxWidth: '260px', offset: 10 })
                .setLngLat(f.geometry.coordinates.slice()).setDOMContent(wrap).addTo(map);
        }

        function bind(map) {
            if (handlersBound) return; handlersBound = true;
            map.on('click', LC, function (e) {
                var f = map.queryRenderedFeatures(e.point, { layers: [LC] })[0]; if (!f) return;
                var cid = f.properties.cluster_id, s = map.getSource(src);
                var done = function (err, z) { if (!err) map.easeTo({ center: f.geometry.coordinates, zoom: z }); };
                var r = s.getClusterExpansionZoom(cid, done);
                if (r && typeof r.then === 'function') r.then(function (z) { done(null, z); }, function () {});
            });
            map.on('click', LP, function (e) { var f = e.features && e.features[0]; if (f) openPopup(map, f); });
            [LC, LP].forEach(function (l) {
                map.on('mouseenter', l, function () { map.getCanvas().style.cursor = 'pointer'; });
                map.on('mouseleave', l, function () { map.getCanvas().style.cursor = ''; });
            });
        }

        // onStyleReady re-runs after every base-style switch: sources/layers are added again with the current items.
        ctl.onStyleReady(function (map) { handlersBound = false; build(map); });

        function fit() {
            if (ctl.fitToItems && items.length) ctl.fitToItems(items.filter(function (i) { return utils.validCoord(i.lat, i.lng); }), { padding: 48, maxZoom: 12 });
        }
        return {
            set: function (list) {
                items = Array.isArray(list) ? list : [];
                var s = ctl.map.getSource(src);
                if (s) s.setData(utils.toFeatureCollection(items)); else build(ctl.map);
            },
            clear: function () { this.set([]); if (popup) { popup.remove(); popup = null; } },
            fit: fit,
            count: function () { return utils.toFeatureCollection(items).features.length; },
            destroy: function () { destroyed = true; if (popup) popup.remove(); }
        };
    }

    function nearbyLocal(base, apiUrl, lat, lng, exploreCategory, radiusM) {
        var cats = utils.toExploreCategories(exploreCategory);
        if (!cats.length || !utils.validCoord(lat, lng)) return Promise.resolve([]);
        var url = base + apiUrl + '?action=nearby&lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng) +
            '&radius_m=' + encodeURIComponent(radiusM || 5000) + '&categories=' + encodeURIComponent(cats.join(',')) + '&limit=12';
        return fetchJson(url, { timeout: 8000 }).then(function (j) { return (j && j.ok !== false && Array.isArray(j.items)) ? j.items : []; })
            .catch(function () { return []; });
    }

    return { mount: mount, lazyMount: lazyMount, pins: pins, fetchJson: fetchJson, nearbyLocal: nearbyLocal, ensureLibs: ensureLibs, utils: utils };
}));

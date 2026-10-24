/*
 * tms-maplibre.js — shared, page-agnostic MapLibre GL helpers for every TourBuddy map page.
 *
 * Requires (load first):  js/vendor/maplibre/maplibre-gl-csp.js  +  js/vendor/maplibre/maplibre-gl.css
 * (the CSP build: its web worker is a real file, so the site's Content-Security-Policy needs no blob: workers).
 *
 * PUBLIC API  (window.TmsMapGL)
 * ---------------------------------------------------------------------------------------------------------------
 *   TmsMapGL.createMap(container, opts) -> controller
 *       container  element or id
 *       opts.config        object from api/map.php?action=config  {styles:[{id,label,url}], default_style, center:[lng,lat], zoom, lang, attribution}
 *       opts.base          site base URL prefix ('' or '/tms/'), used to locate the worker file (default: derived from <script src>)
 *       opts.styleId       style id to start with (default config.default_style, else first style)
 *       opts.fallbackStyle style URL or style object tried when the chosen style fails (default: plain OSM raster style)
 *       opts.maxStyleAttempts  total style load attempts before giving up (default 2). No automatic retry beyond that.
 *       opts.lang          'bn' | 'en'  (label preference; 'bn' prefers name:bn and falls back to the default label)
 *       opts.center/zoom   override config
 *       opts.controls      {nav:true, geolocate:true, fullscreen:true, scale:true, attribution:true}   (false switches one off)
 *       opts.labels        {mapFailed, retry, ...} strings for the failure notice
 *   controller.map                    the maplibregl.Map
 *   controller.onStyleReady(fn)       fn(map) runs now (if a style is loaded) AND after every later style switch. Add your own
 *                                     sources/layers inside it; they are re-added automatically when the user changes the base style.
 *   controller.setStyle(id)           switch base style by id (from config.styles). Resolves false if it could not be loaded.
 *   controller.styleId()              current style id
 *   controller.on('stylefailed'|'stylechanged'|'failed', fn)   lightweight events
 *   controller.fitToItems(items, {padding, maxZoom})   items: [{lat,lng}] ; ignores invalid coordinates ; honours prefers-reduced-motion
 *   controller.flyTo(lng, lat, zoom)  reduced-motion aware
 *   controller.resize()               call after layout changes (also automatic through ResizeObserver)
 *   controller.showNotice(text, {retry:bool}) / controller.hideNotice()
 *   controller.destroy()              removes observers/listeners and the map (idempotent)
 *   TmsMapGL.rasterStyle(tilesUrl, attribution, maxzoom)   builds a minimal raster style object
 *   TmsMapGL.utils.*                  pure helpers (also exported for node tests): haversine, validCoord, bboxKey, boundsToBbox, parseBbox,
 *                                     matchesFilters, feeIsFree, accessibleState, hashEncode/hashDecode, iconSvg, iconKey, patchLabelLang,
 *                                     escapeHtml, formatDistance, formatDuration, externalDirectionsUrls
 *
 * Naming rule: every custom source/layer/image added by pages should start with "tms-" — the label-language patcher
 * only rewrites layers that do NOT start with "tms-", so page layers keep their own text-field.
 *
 * Data/licence: default styles come from OpenFreeMap (https://openfreemap.org, OpenMapTiles schema, data © OpenStreetMap
 * contributors). OpenFreeMap has no uptime guarantee: the controller therefore fails gracefully and never retries in a loop.
 */
(function (root, factory) {
    'use strict';
    var api = factory(root);
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    else { root.TmsMapGL = api; }
}(typeof self !== 'undefined' ? self : this, function (root) {
    'use strict';

    /* =========================================================================================================
     * PURE HELPERS (no DOM, no maplibre) — covered by tests/js/map_ui_test.js
     * ======================================================================================================= */
    var utils = {};

    utils.escapeHtml = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    /** latitude/longitude must be finite numbers inside range and not the (0,0) "null island" placeholder. */
    utils.validCoord = function (lat, lng) {
        if (lat === null || lng === null || lat === '' || lng === '' || lat === undefined || lng === undefined) return false;
        var a = Number(lat), b = Number(lng);
        if (!isFinite(a) || !isFinite(b)) return false;
        if (a < -90 || a > 90 || b < -180 || b > 180) return false;
        if (a === 0 && b === 0) return false;
        return true;
    };

    /** great-circle distance in metres */
    utils.haversine = function (lat1, lng1, lat2, lng2) {
        var R = 6371008.8, rad = Math.PI / 180;
        var dLat = (lat2 - lat1) * rad, dLng = (lng2 - lng1) * rad;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    };

    utils.formatDistance = function (m) {
        m = Number(m);
        if (!isFinite(m) || m < 0) return '';
        if (m < 1000) return Math.round(m / 10) * 10 + ' m';
        return (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km';
    };

    utils.formatDuration = function (s) {
        s = Number(s);
        if (!isFinite(s) || s < 0) return '';
        var mins = Math.max(1, Math.round(s / 60));
        if (mins < 60) return mins + ' min';
        var h = Math.floor(mins / 60), r = mins % 60;
        return h + ' h' + (r ? ' ' + r + ' min' : '');
    };

    /** [w,s,e,n] from {getWest,...} (a maplibre LngLatBounds) or an array. */
    utils.boundsToBbox = function (b) {
        if (!b) return null;
        if (Array.isArray(b)) return b.slice(0, 4).map(Number);
        return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()];
    };

    utils.parseBbox = function (s) {
        if (!s) return null;
        var p = String(s).split(',').map(Number);
        if (p.length !== 4 || p.some(function (n) { return !isFinite(n); })) return null;
        if (p[1] < -90 || p[3] > 90 || p[0] < -180 || p[2] > 180 || p[0] >= p[2] || p[1] >= p[3]) return null;
        return p;
    };

    /**
     * Cache key for a viewport: the bbox snapped outward to a grid whose size depends on the zoom, so small pans
     * reuse the previous response. `sig` is the filter signature (different filters => different key).
     */
    utils.bboxKey = function (bbox, zoom, sig) {
        var z = Math.max(0, Math.min(18, Math.floor(zoom || 0)));
        var cell = 360 / Math.pow(2, Math.max(1, z - 1));          // degrees per cell
        var snap = function (v, up) { return (up ? Math.ceil(v / cell) : Math.floor(v / cell)) * cell; };
        var w = snap(bbox[0], false), s = snap(bbox[1], false), e = snap(bbox[2], true), n = snap(bbox[3], true);
        var f = function (x) { return x.toFixed(4); };
        return [f(w), f(s), f(e), f(n), z, sig || ''].join('|');
    };
    /** the snapped bbox that bboxKey describes (what should actually be requested) */
    utils.snapBbox = function (bbox, zoom) {
        var key = utils.bboxKey(bbox, zoom, '').split('|');
        return [Number(key[0]), Number(key[1]), Number(key[2]), Number(key[3])];
    };

    /* ---- real-data filter predicates: null/unknown is NEVER treated as a negative ---------------------------------- */

    /** true = free, false = paid, null = unknown */
    utils.feeIsFree = function (fee) {
        if (fee === null || fee === undefined || fee === '') return null;
        if (typeof fee === 'boolean') return fee;
        if (typeof fee === 'number') return isFinite(fee) ? fee === 0 : null;
        if (typeof fee === 'string') {
            var t = fee.trim().toLowerCase();
            // The WHOLE text must say it is free ("0.50", "0 for locals, 500 for foreigners" or "free for children" are not free).
            t = t.replace(/[\s.!,;]+$/, '');
            if (/^(free|free entry|free admission|no fee|no charge|0|0\.0+|(৳|bdt|tk|taka)\s*0(\.0+)?|0\s*(৳|bdt|tk|taka)|বিনামূল্যে|ফ্রি|০)$/i.test(t)) return true;
            return null;                                    // free text we cannot interpret => unknown
        }
        if (typeof fee === 'object') {
            if (fee.is_free === true || fee.free === true) return true;
            if (fee.is_free === false || fee.free === false) return false;
            var amt = fee.amount !== undefined ? fee.amount : (fee.adult !== undefined ? fee.adult : undefined);
            if (amt !== undefined && amt !== null && amt !== '' && isFinite(Number(amt))) return Number(amt) === 0;
        }
        return null;
    };

    /** true = accessible info says yes, false = says no, null = unknown */
    utils.accessibleState = function (acc) {
        if (acc === null || acc === undefined || acc === '') return null;
        if (typeof acc === 'boolean') return acc;
        if (typeof acc === 'string') {
            var t = acc.toLowerCase();
            if (/\b(not|no|without)\b[^.]*\b(accessible|wheelchair|step-free)/.test(t) || /inaccessible/.test(t)) return false;
            if (/(wheelchair|step-free|accessible|ramp)/.test(t)) return true;
            return null;
        }
        if (typeof acc === 'object') {
            var vals = [acc.wheelchair, acc.step_free, acc.accessible, acc.ramp];
            for (var i = 0; i < vals.length; i++) {
                var v = vals[i];
                if (v === true || v === 'yes' || v === 'limited') return true;
            }
            for (var j = 0; j < vals.length; j++) {
                if (vals[j] === false || vals[j] === 'no') return false;
            }
        }
        return null;
    };

    /**
     * Returns {pass:boolean, unknown:boolean}. `unknown` is true when a requested filter could not be evaluated
     * because the record has no data for it (the record is then not shown, but is counted as "hidden: no data").
     */
    utils.matchesFilters = function (place, f) {
        f = f || {};
        var unknown = false;
        if (f.categories && f.categories.length && f.categories.indexOf(place.category) === -1) return { pass: false, unknown: false };
        if (f.verified && place.verified !== true) return { pass: false, unknown: false };
        if (f.family) {
            if (place.family_friendly === null || place.family_friendly === undefined) unknown = true;
            else if (place.family_friendly !== true) return { pass: false, unknown: false };
        }
        if (f.accessible) {
            var a = utils.accessibleState(place.accessibility);
            if (a === null) unknown = true; else if (a !== true) return { pass: false, unknown: false };
        }
        if (f.free) {
            var fr = utils.feeIsFree(place.fee);
            if (fr === null) unknown = true; else if (fr !== true) return { pass: false, unknown: false };
        }
        if (unknown) return { pass: false, unknown: true };
        return { pass: true, unknown: false };
    };

    utils.filterSignature = function (f) {
        f = f || {};
        return [(f.categories || []).slice().sort().join('+'), f.verified ? 'v' : '', f.family ? 'f' : '', f.accessible ? 'a' : '', f.free ? 'x' : '', f.q || ''].join('~');
    };

    /* ---- URL hash state ------------------------------------------------------------------------------------------- */
    utils.hashEncode = function (s) {
        var p = [];
        var enc = encodeURIComponent;
        if (s.place) p.push('p=' + enc(s.place));
        if (s.center && isFinite(s.center[0]) && isFinite(s.center[1])) {
            p.push('c=' + Number(s.center[0]).toFixed(5) + ',' + Number(s.center[1]).toFixed(5) + ',' + (isFinite(s.zoom) ? Number(s.zoom).toFixed(2) : ''));
        }
        if (s.categories && s.categories.length) p.push('cat=' + s.categories.map(enc).join(','));
        if (s.verified) p.push('v=1');
        if (s.family) p.push('fr=1');
        if (s.accessible) p.push('a=1');
        if (s.free) p.push('free=1');
        if (s.q) p.push('q=' + enc(s.q));
        if (s.style) p.push('s=' + enc(s.style));
        return p.join('&');
    };

    utils.hashDecode = function (hash) {
        var out = { place: null, center: null, zoom: null, categories: [], verified: false, family: false, accessible: false, free: false, q: '', style: '' };
        if (!hash) return out;
        String(hash).replace(/^#/, '').split('&').forEach(function (kv) {
            if (!kv) return;
            var i = kv.indexOf('=');
            var k = i < 0 ? kv : kv.slice(0, i);
            var v = i < 0 ? '' : kv.slice(i + 1);
            var dec;
            try { dec = decodeURIComponent(v); } catch (e) { return; }
            switch (k) {
                case 'p': out.place = dec.slice(0, 80) || null; break;
                case 'c': {
                    var a = dec.split(',').map(Number);
                    if (utils.validCoordLoose(a[1], a[0])) { out.center = [a[0], a[1]]; if (isFinite(a[2]) && a[2] >= 0 && a[2] <= 22) out.zoom = a[2]; }
                    break;
                }
                case 'cat': out.categories = dec.split(',').map(function (x) { return x.trim(); }).filter(function (x) { return /^[a-z0-9_\-]{1,40}$/i.test(x); }).slice(0, 30); break;
                case 'v': out.verified = dec === '1'; break;
                case 'fr': out.family = dec === '1'; break;
                case 'a': out.accessible = dec === '1'; break;
                case 'free': out.free = dec === '1'; break;
                case 'q': out.q = dec.slice(0, 120); break;
                case 's': out.style = /^[a-z0-9_\-]{1,30}$/i.test(dec) ? dec : ''; break;
            }
        });
        return out;
    };

    /** like validCoord but allows (0,0) — only used to sanity-check hash input */
    utils.validCoordLoose = function (lat, lng) {
        return isFinite(lat) && isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
    };

    /* ---- icons: simple stroke glyphs (24x24 viewBox) authored for this project, no downloads ------------------------ */
    var GLYPHS = {
        pin:      'M12 7a3.5 3.5 0 100 7 3.5 3.5 0 000-7z',
        camera:   'M5 8h3l1.5-2h5L16 8h3v10H5z M12 11.2a2.8 2.8 0 100 5.6 2.8 2.8 0 000-5.6z',
        bed:      'M4 17V8 M4 13h16v4 M20 13v-2a2 2 0 00-2-2h-7v4',
        utensils: 'M8 5v5a2 2 0 002 0V5 M9 10v9 M16 5c-1.6 1.6-2 3.6-2 6h2v8',
        tree:     'M12 4l5 7h-3l4 6H6l4-6H7z M12 17v3',
        leaf:     'M5 19c0-8 5-13 14-14 0 9-5 14-14 14z M5 19l8-8',
        landmark: 'M4 10l8-5 8 5H4z M6 10v7 M10 10v7 M14 10v7 M18 10v7 M4 19h16',
        mountain: 'M3 18l6-10 4 6 3-4 5 8z',
        water:    'M12 4c3 4 5 6.5 5 9a5 5 0 01-10 0c0-2.5 2-5 5-9z',
        bus:      'M6 6h12v10H6z M6 12h12 M8 18v2 M16 18v2',
        train:    'M7 5h10v11H7z M7 11h10 M9 19l-2 2 M15 19l2 2',
        plane:    'M3 13l18-7-6 14-3-6z',
        ship:     'M4 15l2 4h12l2-4z M12 5v10 M7 11h10',
        medical:  'M12 5v14 M5 12h14',
        shop:     'M5 8h14l-1 11H6z M9 8a3 3 0 016 0',
        info:     'M12 8v.5 M12 11v6'
    };
    var ICON_ALIASES = {
        camera: 'camera', photo: 'camera', attraction: 'camera', binoculars: 'camera', eye: 'camera', star: 'camera',
        bed: 'bed', hotel: 'bed', building: 'bed', home: 'bed', accommodation: 'bed', resort: 'bed', guesthouse: 'bed',
        cutlery: 'utensils', utensils: 'utensils', coffee: 'utensils', food: 'utensils', restaurant: 'utensils', cafe: 'utensils', 'glass': 'utensils',
        tree: 'tree', forest: 'tree', park: 'tree', pine: 'tree', wildlife: 'tree', paw: 'tree',
        leaf: 'leaf', nature: 'leaf', garden: 'leaf', 'pagelines': 'leaf',
        university: 'landmark', bank: 'landmark', monument: 'landmark', landmark: 'landmark', museum: 'landmark', heritage: 'landmark', 'institution': 'landmark', place_of_worship: 'landmark', religious: 'landmark', historic: 'landmark', archaeological: 'landmark',
        mountain: 'mountain', hill: 'mountain', viewpoint: 'mountain', 'area-chart': 'mountain', flag: 'mountain', trail: 'mountain', hiking: 'mountain',
        tint: 'water', water: 'water', waterfall: 'water', lake: 'water', river: 'water', beach: 'water', 'umbrella-beach': 'water', umbrella: 'water', anchor: 'ship',
        bus: 'bus', 'bus-alt': 'bus', taxi: 'bus', car: 'bus', transport: 'bus',
        train: 'train', subway: 'train', rail: 'train',
        plane: 'plane', airplane: 'plane', airport: 'plane',
        ship: 'ship', ferry: 'ship', boat: 'ship',
        medkit: 'medical', hospital: 'medical', 'hospital-o': 'medical', 'plus-square': 'medical', ambulance: 'medical', 'h-square': 'medical', medical: 'medical', 'heartbeat': 'medical', shield: 'medical', police: 'medical',
        'shopping-bag': 'shop', 'shopping-cart': 'shop', shop: 'shop', market: 'shop', store: 'shop',
        info: 'info', 'info-circle': 'info', 'information': 'info',
        'map-marker': 'pin', pin: 'pin'
    };

    /** maps a config icon name ("fa-camera", "bed", …) or a category id to one of the glyph keys above */
    utils.iconKey = function (icon, category) {
        var norm = function (s) { return String(s || '').toLowerCase().replace(/^fa[a-z]?[ -]+/, '').replace(/^fa-/, '').replace(/\s+/g, '').replace(/_/g, '-'); };
        var a = norm(icon), c = norm(category);
        if (ICON_ALIASES[a]) return ICON_ALIASES[a];
        if (ICON_ALIASES[c]) return ICON_ALIASES[c];
        // loose containment (e.g. category "historical-sites")
        var hay = a + ' ' + c;
        var keys = Object.keys(ICON_ALIASES);
        for (var i = 0; i < keys.length; i++) { if (keys[i].length > 3 && hay.indexOf(keys[i]) !== -1) return ICON_ALIASES[keys[i]]; }
        return 'pin';
    };

    /** SVG marker (rounded pin disc + white stroke glyph). `size` is the CSS pixel size. Colour is validated. */
    utils.iconSvg = function (color, glyphKey, size) {
        size = size || 32;
        var col = /^#[0-9a-f]{3,8}$/i.test(String(color || '')) ? color : '#1B7F5F';
        var path = GLYPHS[glyphKey] || GLYPHS.pin;
        var fillGlyph = glyphKey === 'pin' ? ' fill="#fff"' : ' fill="none"';
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 32 32">' +
            '<circle cx="16" cy="16" r="14" fill="' + col + '" stroke="#ffffff" stroke-width="2.5"/>' +
            '<g transform="translate(4 4) scale(1)" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"' + fillGlyph + '>' +
            '<path d="' + path + '"/></g></svg>';
    };

    utils.glyphKeys = function () { return Object.keys(GLYPHS); };

    /* ---- label language: prefer name:bn when lang === 'bn' ------------------------------------------------------- */
    function wrapBn(expr) {
        // already patched?
        if (Array.isArray(expr) && expr[0] === 'coalesce' && Array.isArray(expr[1]) && expr[1][0] === 'get' && expr[1][1] === 'name:bn') return expr;
        if (typeof expr === 'string') {
            // legacy "{name}" / "{name_en}" token strings
            if (/\{name(_en|:latin)?\}/.test(expr)) return ['coalesce', ['get', 'name:bn'], ['get', 'name']];
            return expr;
        }
        if (Array.isArray(expr)) return ['coalesce', ['get', 'name:bn'], expr];
        return expr;
    }
    /** returns a deep-ish copy of the style with Bangla preferred in symbol labels (lang 'bn'); other langs return the style untouched */
    utils.patchLabelLang = function (style, lang) {
        if (!style || lang !== 'bn' || !Array.isArray(style.layers)) return style;
        var copy = JSON.parse(JSON.stringify(style));
        copy.layers.forEach(function (l) {
            if (l.type !== 'symbol' || !l.layout || l.layout['text-field'] === undefined) return;
            if (String(l.id).indexOf('tms-') === 0) return;
            l.layout['text-field'] = wrapBn(l.layout['text-field']);
        });
        return copy;
    };
    /** same rewrite for a LIVE map after 'style.load' (only touches text-field of non-tms layers). Returns the patched layer count. */
    utils.applyLabelLang = function (map, lang) {
        if (lang !== 'bn' || !map || !map.getStyle) return 0;
        var style = map.getStyle(), n = 0;
        if (!style || !style.layers) return 0;
        style.layers.forEach(function (l) {
            if (l.type !== 'symbol' || !l.layout || l.layout['text-field'] === undefined) return;
            if (String(l.id).indexOf('tms-') === 0) return;
            var next = wrapBn(l.layout['text-field']);
            if (next !== l.layout['text-field']) {
                try { map.setLayoutProperty(l.id, 'text-field', next); n++; } catch (e) { /* layer vanished mid-switch */ }
            }
        });
        return n;
    };

    /** links that open the same route in an external service (no API key). Points are {lat,lng}. */
    utils.externalDirectionsUrls = function (from, to, mode) {
        var gm = { driving: 'driving', walking: 'walking', cycling: 'bicycling' }[mode] || 'driving';
        var eng = { driving: 'fossgis_osrm_car', walking: 'fossgis_osrm_foot', cycling: 'fossgis_osrm_bike' }[mode] || 'fossgis_osrm_car';
        var ll = function (p) { return Number(p.lat).toFixed(6) + ',' + Number(p.lng).toFixed(6); };
        return {
            osm: 'https://www.openstreetmap.org/directions?engine=' + eng + '&route=' + encodeURIComponent(ll(from) + ';' + ll(to)),
            google: 'https://www.google.com/maps/dir/?api=1&origin=' + encodeURIComponent(ll(from)) + '&destination=' + encodeURIComponent(ll(to)) + '&travelmode=' + gm
        };
    };

    /* =========================================================================================================
     * MAP CONTROLLER (browser only)
     * ======================================================================================================= */
    var FALLBACK_RASTER = {
        tiles: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    };

    function rasterStyle(tilesUrl, attribution, maxzoom) {
        return {
            version: 8,
            sources: { 'tms-raster-base': { type: 'raster', tiles: [tilesUrl], tileSize: 256, maxzoom: maxzoom || 19, attribution: attribution || '' } },
            layers: [{ id: 'tms-raster-base', type: 'raster', source: 'tms-raster-base' }]
        };
    }

    function reducedMotion() {
        try { return !!(root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; }
    }

    function deriveBase() {
        try {
            var scripts = document.getElementsByTagName('script');
            for (var i = 0; i < scripts.length; i++) {
                var src = scripts[i].getAttribute('src') || '';
                var m = src.match(/^(.*?)js\/tms-maplibre\.js/);
                if (m) return m[1];
            }
        } catch (e) { /* ignore */ }
        return '';
    }

    function createMap(container, opts) {
        opts = opts || {};
        var el = typeof container === 'string' ? document.getElementById(container) : container;
        if (!el) throw new Error('TmsMapGL: container not found');
        if (!root.maplibregl) throw new Error('TmsMapGL: maplibre-gl is not loaded');
        var maplibregl = root.maplibregl;
        var cfg = opts.config || {};
        var styles = (cfg.styles || []).filter(function (s) { return s && s.url; });
        var lang = opts.lang || cfg.lang || 'en';
        var base = opts.base !== undefined ? opts.base : deriveBase();
        var maxAttempts = Math.max(1, opts.maxStyleAttempts || 2);
        var labels = opts.labels || {};
        var listeners = { stylefailed: [], stylechanged: [], failed: [] };
        var readyCbs = [];
        var destroyed = false, styleLoaded = false, attempts = 0, loadTimer = null, tileErrors = 0, tileOk = 0, noticeEl = null;
        var currentId = opts.styleId || cfg.default_style || (styles[0] && styles[0].id) || 'fallback';
        var failedIds = {};
        var userSwitchFrom = null;

        try { maplibregl.setWorkerUrl(base + 'js/vendor/maplibre/maplibre-gl-csp-worker.js'); } catch (e) { /* older builds */ }

        function styleById(id) { for (var i = 0; i < styles.length; i++) if (styles[i].id === id) return styles[i]; return null; }
        function fallbackSpec() {
            if (opts.fallbackStyle) return opts.fallbackStyle;
            return rasterStyle(FALLBACK_RASTER.tiles, FALLBACK_RASTER.attribution, 19);
        }
        function initialSpec() {
            var s = styleById(currentId) || styles[0];
            if (s) { currentId = s.id; return s.url; }
            currentId = 'fallback';
            return fallbackSpec();
        }

        var map = new maplibregl.Map({
            container: el,
            style: initialSpec(),
            center: opts.center || cfg.center || [90.4125, 23.8103],
            zoom: opts.zoom != null ? opts.zoom : (cfg.zoom != null ? cfg.zoom : 6),
            attributionControl: false,
            cooperativeGestures: !!opts.cooperativeGestures,
            maxPitch: 60,
            maxZoom: 19,
            renderWorldCopies: true,
            fadeDuration: reducedMotion() ? 0 : 150
        });

        var ctl = opts.controls || {};
        if (ctl.attribution !== false) {
            var custom = [];
            if (cfg.attribution) custom.push(cfg.attribution);
            map.addControl(new maplibregl.AttributionControl({ compact: true, customAttribution: custom }), 'bottom-right');
        }
        if (ctl.nav !== false) map.addControl(new maplibregl.NavigationControl({ visualizePitch: false }), 'top-right');
        if (ctl.fullscreen !== false && maplibregl.FullscreenControl) map.addControl(new maplibregl.FullscreenControl({ container: el.parentNode || el }), 'top-right');
        if (ctl.geolocate !== false && maplibregl.GeolocateControl) {
            // trackUserLocation false + no autotrigger: the browser permission prompt only appears after the user clicks the button
            map.addControl(new maplibregl.GeolocateControl({ positionOptions: { enableHighAccuracy: false, timeout: 10000 }, trackUserLocation: false, showAccuracyCircle: true }), 'top-right');
        }
        if (ctl.scale !== false) map.addControl(new maplibregl.ScaleControl({ maxWidth: 110, unit: 'metric' }), 'bottom-left');

        function emit(name, arg) { (listeners[name] || []).slice().forEach(function (fn) { try { fn(arg); } catch (e) { if (root.console) console.error(e); } }); }

        function runReady() {
            utils.applyLabelLang(map, lang);
            // Flat 2D building footprints (the 3D extrusions hide labels and cost frame time on phones)
            try { if (map.getLayer('building-3d')) map.setLayoutProperty('building-3d', 'visibility', 'none'); } catch (e) { /* style without it */ }
            readyCbs.slice().forEach(function (fn) { try { fn(map); } catch (e) { if (root.console) console.error('[TmsMapGL] onStyleReady callback failed', e); } });
        }

        function armTimer() {
            clearTimeout(loadTimer);
            loadTimer = setTimeout(function () { if (!styleLoaded && !destroyed) styleFailed('timeout'); }, 15000);
        }

        function styleFailed(reason) {
            if (destroyed || styleLoaded) return;
            clearTimeout(loadTimer);
            failedIds[currentId] = true;
            emit('stylefailed', { id: currentId, reason: reason });
            if (userSwitchFrom) {
                // a style the user picked did not load: quietly go back to the one that worked, no retry loop
                var back = styleById(userSwitchFrom);
                userSwitchFrom = null;
                if (back) { currentId = back.id; styleLoaded = false; map.setStyle(back.url); armTimer(); return; }
            }
            attempts++;
            if (attempts < maxAttempts) {
                // one alternative attempt: another configured style that has not failed, else the raster fallback
                var alt = null;
                for (var i = 0; i < styles.length; i++) { if (!failedIds[styles[i].id]) { alt = styles[i]; break; } }
                if (alt) { currentId = alt.id; map.setStyle(alt.url); }
                else { currentId = 'fallback'; map.setStyle(fallbackSpec()); }
                armTimer();
                return;
            }
            showNotice(labels.mapFailed || 'The map background could not be loaded. Markers and lists still work.', { retry: true });
            emit('failed', { id: currentId });
        }

        map.on('style.load', function () {
            if (destroyed) return;
            styleLoaded = true; attempts = 0; tileErrors = 0; tileOk = 0; userSwitchFrom = null;
            clearTimeout(loadTimer);
            hideNotice();
            runReady();
            emit('stylechanged', { id: currentId });
        });
        map.on('data', function (e) { if (e && e.dataType === 'source' && e.tile) tileOk++; });
        map.on('error', function (e) {
            if (destroyed) return;
            var err = e && e.error, status = err && err.status;
            if (!styleLoaded && !e.sourceId) {
                if (status >= 400 || /failed to fetch|networkerror|load failed/i.test((err && err.message) || '')) styleFailed('style-fetch');
                return;
            }
            if (e.sourceId && e.tile) {
                tileErrors++;
                if (tileErrors >= 10 && tileOk === 0 && !noticeEl) {
                    showNotice(labels.tilesFailed || 'Map tiles are not loading right now. Markers and lists still work.', { retry: true });
                }
            }
        });
        armTimer();

        var ro = null;
        if (root.ResizeObserver) {
            ro = new root.ResizeObserver(function () { if (!destroyed) map.resize(); });
            ro.observe(el);
        }

        function showNotice(text, o) {
            hideNotice();
            noticeEl = document.createElement('div');
            noticeEl.className = 'tms-map-notice';
            noticeEl.setAttribute('role', 'status');
            var span = document.createElement('span');
            span.textContent = text;
            noticeEl.appendChild(span);
            if (o && o.retry) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'tms-map-notice-btn';
                b.textContent = labels.retry || 'Try again';
                b.addEventListener('click', function () {
                    // user-initiated retry only: reset the budget, reload the chosen style once
                    failedIds = {}; attempts = 0; tileErrors = 0; tileOk = 0; styleLoaded = false;
                    var s = styleById(currentId === 'fallback' ? (cfg.default_style || (styles[0] && styles[0].id)) : currentId) || styles[0];
                    if (s) { currentId = s.id; map.setStyle(s.url); } else { map.setStyle(fallbackSpec()); }
                    hideNotice(); armTimer();
                });
                noticeEl.appendChild(b);
            }
            el.appendChild(noticeEl);
        }
        function hideNotice() { if (noticeEl && noticeEl.parentNode) noticeEl.parentNode.removeChild(noticeEl); noticeEl = null; }

        var api = {
            map: map,
            onStyleReady: function (fn) {
                readyCbs.push(fn);
                if (styleLoaded && map.isStyleLoaded()) { try { fn(map); } catch (e) { if (root.console) console.error(e); } }
            },
            setStyle: function (id) {
                return new Promise(function (resolve) {
                    var st = styleById(id);
                    if (!st || failedIds[id]) { resolve(false); return; }
                    if (id === currentId && styleLoaded) { resolve(true); return; }
                    var prevId = currentId, done = false;
                    var cleanup = function () { map.off('style.load', okFn); listeners.stylefailed = listeners.stylefailed.filter(function (f) { return f !== badFn; }); };
                    var okFn = function () { if (done) return; done = true; cleanup(); resolve(true); };
                    var badFn = function (info) { if (done || info.id !== id) return; done = true; cleanup(); resolve(false); };
                    map.on('style.load', okFn);
                    listeners.stylefailed.push(badFn);
                    userSwitchFrom = prevId; currentId = id; styleLoaded = false;
                    map.setStyle(st.url);
                    armTimer();
                });
            },
            styleId: function () { return currentId; },
            on: function (name, fn) { if (listeners[name]) listeners[name].push(fn); return api; },
            fitToItems: function (items, o) {
                o = o || {};
                var pts = (items || []).filter(function (it) { return it && utils.validCoord(it.lat, it.lng); });
                if (!pts.length) return false;
                if (pts.length === 1) { api.flyTo(Number(pts[0].lng), Number(pts[0].lat), o.maxZoom || 14); return true; }
                var b = new maplibregl.LngLatBounds();
                pts.forEach(function (p) { b.extend([Number(p.lng), Number(p.lat)]); });
                map.fitBounds(b, { padding: o.padding != null ? o.padding : 60, maxZoom: o.maxZoom || 15, duration: reducedMotion() ? 0 : 600 });
                return true;
            },
            flyTo: function (lng, lat, zoom) {
                var z = zoom != null ? zoom : Math.max(map.getZoom(), 13);
                if (reducedMotion()) map.jumpTo({ center: [lng, lat], zoom: z });
                else map.flyTo({ center: [lng, lat], zoom: z, essential: false, speed: 1.4 });
            },
            resize: function () { if (!destroyed) map.resize(); },
            showNotice: showNotice,
            hideNotice: hideNotice,
            destroy: function () {
                if (destroyed) return;
                destroyed = true;
                clearTimeout(loadTimer);
                if (ro) { try { ro.disconnect(); } catch (e) { /* ignore */ } }
                readyCbs = []; listeners = { stylefailed: [], stylechanged: [], failed: [] };
                hideNotice();
                try { map.remove(); } catch (e) { /* ignore */ }
            }
        };
        return api;
    }

    return { createMap: createMap, rasterStyle: rasterStyle, utils: utils, reducedMotion: reducedMotion };
}));

/*!
 * TmsPoiTiles - live nearby places straight from OpenFreeMap vector tiles (OpenStreetMap data, ODbL).
 * No API key, no server round trip, no Overpass. Browser only.
 *
 *   TmsPoiTiles.loadPlaces({lat, lng, category, radiusM, limit}) -> Promise<{places:[{name,lat,lng,distance_m,category,subtype,address,source}], origin:'live', tiles:n}>
 *   TmsPoiTiles.classify(cls, subclass)   -> TourBuddy category id or ''
 *   TmsPoiTiles.decodeTile(arrayBuffer)   -> {layers:{name:{extent,features:[{type,props,geometry}]}}}
 *   TmsPoiTiles.featuresInTile(buf, z, x, y) -> normalised POIs of one tile
 *   TmsPoiTiles.bindBaseMapClicks(map, onPlace)  makes the base map's own POI labels clickable
 *   TmsPoiTiles.popupNode(place, labels, actions) -> DOM node (textContent only, never innerHTML)
 *
 * Data is never invented: a place appears only when OpenStreetMap has a named feature there.
 * UMD: also usable from node (tests) with an injected fetch via TmsPoiTiles.configure({fetch}).
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    root.TmsPoiTiles = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    var TILEJSON_URL = 'https://tiles.openfreemap.org/planet';
    var TILE_ZOOM = 14;                 // the planet source's maxzoom; the poi layer exists at 11-14
    var cfg = { fetch: null, timeout: 9000, concurrency: 4, cacheSize: 80, now: function () { return Date.now(); } };
    var state = { tileUrl: null, tileUrlAt: 0, tileUrlPromise: null, cache: new Map(), failures: 0, brokenUntil: 0 };

    function configure(o) { for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) cfg[k] = o[k]; return api; }
    function reset() { state.tileUrl = null; state.tileUrlAt = 0; state.tileUrlPromise = null; state.cache = new Map(); state.failures = 0; state.brokenUntil = 0; }
    function doFetch(url, opts) { var f = cfg.fetch || (typeof fetch === 'function' ? fetch.bind(globalThis) : null); if (!f) return Promise.reject(new Error('no fetch')); return f(url, opts); }

    /* ---------- tile maths ---------- */
    function lngToTileX(lng, z) { return Math.floor((lng + 180) / 360 * Math.pow(2, z)); }
    function latToTileY(lat, z) {
        var r = lat * Math.PI / 180, n = Math.pow(2, z);
        return Math.floor((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * n);
    }
    function tileToLng(x, z) { return x / Math.pow(2, z) * 360 - 180; }
    function tileToLat(y, z) { var n = Math.PI - 2 * Math.PI * y / Math.pow(2, z); return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))); }
    /** Tiles (x,y) at zoom z that cover a circle of radiusM around lat/lng, nearest first, capped. */
    function tilesFor(lat, lng, radiusM, z, cap) {
        z = z || TILE_ZOOM;
        var dLat = radiusM / 111320, dLng = radiusM / (111320 * Math.max(0.05, Math.cos(lat * Math.PI / 180)));
        var x0 = lngToTileX(lng - dLng, z), x1 = lngToTileX(lng + dLng, z);
        var y0 = latToTileY(Math.min(85, lat + dLat), z), y1 = latToTileY(Math.max(-85, lat - dLat), z);
        var cx = lngToTileX(lng, z), cy = latToTileY(lat, z), out = [];
        for (var x = x0; x <= x1; x++) for (var y = y0; y <= y1; y++) out.push({ z: z, x: x, y: y, d: Math.abs(x - cx) + Math.abs(y - cy) });
        out.sort(function (a, b) { return a.d - b.d; });
        return out.slice(0, cap || 9);
    }
    function haversine(lat1, lng1, lat2, lng2) {
        var R = 6371000, p = Math.PI / 180, a = Math.sin((lat2 - lat1) * p / 2) * Math.sin((lat2 - lat1) * p / 2) +
            Math.cos(lat1 * p) * Math.cos(lat2 * p) * Math.sin((lng2 - lng1) * p / 2) * Math.sin((lng2 - lng1) * p / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    /* ---------- Mapbox Vector Tile (protobuf) decoder ---------- */
    function Reader(buf) { this.b = buf instanceof Uint8Array ? buf : new Uint8Array(buf); this.p = 0; }
    Reader.prototype.eof = function () { return this.p >= this.b.length; };
    Reader.prototype.varint = function () {            // up to 53 bits, enough for tile data
        var r = 0, mul = 1, byte, n = 0;
        do { byte = this.b[this.p++]; if (byte === undefined) throw new Error('truncated'); r += (byte & 0x7f) * mul; mul *= 128; if (++n > 9) throw new Error('varint'); } while (byte & 0x80);
        return r;
    };
    Reader.prototype.skip = function (wt) {
        if (wt === 0) this.varint(); else if (wt === 1) this.p += 8; else if (wt === 5) this.p += 4;
        else if (wt === 2) { var l = this.varint(); this.p += l; } else throw new Error('wire type ' + wt);
        if (this.p > this.b.length) throw new Error('truncated');
    };
    Reader.prototype.bytes = function () { var l = this.varint(), s = this.p; this.p += l; if (this.p > this.b.length) throw new Error('truncated'); return this.b.subarray(s, s + l); };
    var utf8 = typeof TextDecoder !== 'undefined' ? new TextDecoder('utf-8') : null;
    function str(u8) { if (utf8) return utf8.decode(u8); var s = ''; for (var i = 0; i < u8.length; i++) s += String.fromCharCode(u8[i]); return decodeURIComponent(escape(s)); }
    function f32(u8) { return new DataView(u8.buffer, u8.byteOffset, 4).getFloat32(0, true); }
    function f64(u8) { return new DataView(u8.buffer, u8.byteOffset, 8).getFloat64(0, true); }

    function readValue(u8) {
        var r = new Reader(u8), v = null;
        while (!r.eof()) {
            var tag = r.varint(), f = tag >>> 3, wt = tag & 7;
            if (f === 1 && wt === 2) v = str(r.bytes());
            else if (f === 2 && wt === 5) { v = f32(r.b.subarray(r.p, r.p + 4)); r.p += 4; }
            else if (f === 3 && wt === 1) { v = f64(r.b.subarray(r.p, r.p + 8)); r.p += 8; }
            else if ((f === 4 || f === 5) && wt === 0) v = r.varint();
            else if (f === 6 && wt === 0) { var z = r.varint(); v = (z % 2 === 0) ? z / 2 : -(z + 1) / 2; }
            else if (f === 7 && wt === 0) v = r.varint() !== 0;
            else r.skip(wt);
        }
        return v;
    }
    function zz(n) { return (n % 2 === 0) ? n / 2 : -(n + 1) / 2; }
    function readGeometry(u8s) {                       // -> array of parts, each [[x,y],...]
        var parts = [], cur = null, x = 0, y = 0, i = 0;
        while (i < u8s.length) {
            var ci = u8s[i++], cmd = ci & 7, count = Math.floor(ci / 8);
            if (cmd === 1 || cmd === 2) {
                for (var k = 0; k < count; k++) {
                    x += zz(u8s[i++]); y += zz(u8s[i++]);
                    if (cmd === 1) { cur = []; parts.push(cur); }
                    if (cur) cur.push([x, y]);
                }
            } else if (cmd === 7) { /* close path */ } else throw new Error('geometry command ' + cmd);
        }
        return parts;
    }
    function readFeature(u8) {
        var r = new Reader(u8), f = { id: null, type: 0, tags: [], geom: [] };
        while (!r.eof()) {
            var tag = r.varint(), fn = tag >>> 3, wt = tag & 7;
            if (fn === 1 && wt === 0) f.id = r.varint();
            else if (fn === 2 && wt === 2) { var t = new Reader(r.bytes()); while (!t.eof()) f.tags.push(t.varint()); }
            else if (fn === 3 && wt === 0) f.type = r.varint();
            else if (fn === 4 && wt === 2) { var g = new Reader(r.bytes()), arr = []; while (!g.eof()) arr.push(g.varint()); f.geom = arr; }
            else r.skip(wt);
        }
        return f;
    }
    function readLayer(u8, wanted) {
        var r = new Reader(u8), name = '', keys = [], values = [], feats = [], extent = 4096;
        while (!r.eof()) {
            var tag = r.varint(), fn = tag >>> 3, wt = tag & 7;
            if (fn === 1 && wt === 2) { name = str(r.bytes()); if (wanted && wanted.indexOf(name) < 0) return null; }
            else if (fn === 2 && wt === 2) feats.push(r.bytes());
            else if (fn === 3 && wt === 2) keys.push(str(r.bytes()));
            else if (fn === 4 && wt === 2) values.push(readValue(r.bytes()));
            else if (fn === 5 && wt === 0) extent = r.varint();
            else r.skip(wt);
        }
        if (wanted && wanted.indexOf(name) < 0) return null;
        var out = [];
        for (var i = 0; i < feats.length; i++) {
            var ft = readFeature(feats[i]), props = {};
            for (var t = 0; t + 1 < ft.tags.length; t += 2) props[keys[ft.tags[t]]] = values[ft.tags[t + 1]];
            out.push({ id: ft.id, type: ft.type, props: props, geometry: readGeometry(ft.geom) });
        }
        return { name: name, extent: extent, features: out };
    }
    /** wanted: optional array of layer names to decode (others are skipped cheaply). */
    function decodeTile(buf, wanted) {
        var r = new Reader(buf), layers = {};
        while (!r.eof()) {
            var tag = r.varint(), fn = tag >>> 3, wt = tag & 7;
            if (fn === 3 && wt === 2) { var l = readLayer(r.bytes(), wanted); if (l) layers[l.name] = l; }
            else r.skip(wt);
        }
        return { layers: layers };
    }

    /* ---------- OpenMapTiles POI classes -> TourBuddy categories ---------- */
    // Key: class or "class:subclass". Subclass wins over class. Categories match js/explore.js and api/map.php.
    var CLASS_MAP = {
        'lodging': 'hotel', 'hotel': 'hotel', 'motel': 'hotel', 'hostel': 'hotel', 'guest_house': 'hotel', 'resort': 'hotel', 'apartment': 'hotel', 'chalet': 'hotel', 'camp_site': 'hotel', 'caravan_site': 'hotel',
        'restaurant': 'restaurant', 'fast_food': 'restaurant', 'cafe': 'restaurant', 'food_court': 'restaurant', 'bar': 'restaurant', 'pub': 'restaurant', 'ice_cream': 'restaurant', 'biergarten': 'restaurant', 'bakery': 'restaurant', 'confectionery': 'restaurant',
        'hospital': 'hospital', 'clinic': 'hospital', 'doctors': 'hospital', 'doctor': 'hospital', 'dentist': 'hospital', 'health_post': 'hospital',
        'pharmacy': 'pharmacy', 'chemist': 'pharmacy',
        'police': 'police',
        'atm': 'atm', 'bank': 'atm',
        'fuel': 'gas_station', 'gas_station': 'gas_station', 'charging_station': 'gas_station',
        'mall': 'shopping_mall', 'department_store': 'shopping_mall', 'supermarket': 'shopping_mall', 'marketplace': 'shopping_mall', 'shop': 'shopping_mall', 'clothes': 'shopping_mall', 'convenience': 'shopping_mall', 'grocery': 'shopping_mall',
        'information': 'tourist_info', 'tourist_info': 'tourist_info', 'visitor_centre': 'tourist_info',
        'attraction': 'attraction', 'museum': 'attraction', 'viewpoint': 'attraction', 'zoo': 'attraction', 'aquarium': 'attraction', 'theme_park': 'attraction', 'artwork': 'attraction', 'monument': 'attraction', 'castle': 'attraction', 'ruins': 'attraction',
        'beach': 'attraction', 'park': 'attraction', 'garden': 'attraction', 'nature_reserve': 'attraction', 'place_of_worship': 'attraction', 'mosque': 'attraction', 'temple': 'attraction', 'church': 'attraction', 'gallery': 'attraction',
        'memorial': 'attraction', 'archaeological_site': 'attraction', 'lighthouse': 'attraction', 'peak': 'attraction', 'waterfall': 'attraction', 'cinema': 'attraction', 'theatre': 'attraction', 'stadium': 'attraction', 'golf': 'attraction', 'swimming': 'attraction'
    };
    function classify(cls, sub) {
        cls = String(cls || '').toLowerCase(); sub = String(sub || '').toLowerCase();
        if (sub && CLASS_MAP[cls + ':' + sub]) return CLASS_MAP[cls + ':' + sub];
        if (sub && CLASS_MAP[sub]) return CLASS_MAP[sub];
        if (cls && CLASS_MAP[cls]) return CLASS_MAP[cls];
        return '';
    }

    /* ---------- POIs of one tile ---------- */
    function pickName(p, lang) {
        var order = lang === 'bn' ? ['name:bn', 'name', 'name_en', 'name:latin'] : ['name_en', 'name:latin', 'name', 'name:bn'];
        for (var i = 0; i < order.length; i++) { var v = p[order[i]]; if (typeof v === 'string' && v.trim()) return v.trim(); }
        return '';
    }
    function featuresInTile(buf, z, tx, ty, lang) {
        var tile = decodeTile(buf, ['poi']), layer = tile.layers.poi, out = [];
        if (!layer) return out;
        var ext = layer.extent || 4096, n = Math.pow(2, z);
        layer.features.forEach(function (f) {
            if (f.type !== 1 || !f.geometry.length || !f.geometry[0].length) return;
            var name = pickName(f.props, lang); if (!name) return;      // unnamed POIs are not useful in a list
            var pt = f.geometry[0][0];
            var lng = (tx + pt[0] / ext) / n * 360 - 180;
            var lat = tileToLat(ty + pt[1] / ext, z);
            if (!isFinite(lat) || !isFinite(lng)) return;
            out.push({
                name: name, name_local: typeof f.props.name === 'string' ? f.props.name : '', lat: lat, lng: lng,
                cls: String(f.props['class'] || ''), subclass: String(f.props.subclass || ''), rank: Number(f.props.rank) || 99,
                category: classify(f.props['class'], f.props.subclass), source: 'openfreemap'
            });
        });
        return out;
    }

    /* ---------- network: TileJSON + tiles ---------- */
    function withTimeout(promise, ms, ctrl) {
        return new Promise(function (res, rej) {
            var t = setTimeout(function () { try { ctrl && ctrl.abort(); } catch (e) {} rej(new Error('timeout')); }, ms);
            promise.then(function (v) { clearTimeout(t); res(v); }, function (e) { clearTimeout(t); rej(e); });
        });
    }
    function request(url, kind) {
        var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        return withTimeout(doFetch(url, ctrl ? { signal: ctrl.signal } : undefined), cfg.timeout, ctrl).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return kind === 'json' ? r.json() : r.arrayBuffer();
        });
    }
    /** The planet tile URL carries a weekly version segment, so it is resolved from TileJSON and cached for an hour. */
    function resolveTileUrl() {
        if (state.tileUrl && cfg.now() - state.tileUrlAt < 3600000) return Promise.resolve(state.tileUrl);
        if (state.tileUrlPromise) return state.tileUrlPromise;
        state.tileUrlPromise = request(TILEJSON_URL, 'json').then(function (j) {
            var u = j && j.tiles && j.tiles[0];
            if (typeof u !== 'string' || u.indexOf('{z}') < 0 || !/^https:\/\/tiles\.openfreemap\.org\//.test(u)) throw new Error('bad tilejson');
            state.tileUrl = u; state.tileUrlAt = cfg.now(); state.tileUrlPromise = null; return u;
        }, function (e) { state.tileUrlPromise = null; throw e; });
        return state.tileUrlPromise;
    }
    function lruGet(k) { if (!state.cache.has(k)) return null; var v = state.cache.get(k); state.cache.delete(k); state.cache.set(k, v); return v; }
    function lruSet(k, v) { state.cache.set(k, v); while (state.cache.size > cfg.cacheSize) state.cache.delete(state.cache.keys().next().value); }
    function fetchTile(t, lang) {
        var key = t.z + '/' + t.x + '/' + t.y + '/' + (lang || '');
        var hit = lruGet(key); if (hit) return Promise.resolve(hit);
        return resolveTileUrl().then(function (tpl) {
            return request(tpl.replace('{z}', t.z).replace('{x}', t.x).replace('{y}', t.y), 'buf');
        }).then(function (buf) {
            var pois = featuresInTile(buf, t.z, t.x, t.y, lang); lruSet(key, pois); return pois;
        });
    }
    function pool(items, limit, worker) {
        var i = 0, results = new Array(items.length);
        function next() { if (i >= items.length) return Promise.resolve(); var idx = i++; return worker(items[idx]).then(function (v) { results[idx] = { ok: true, v: v }; }, function (e) { results[idx] = { ok: false, e: e }; }).then(next); }
        var runners = []; for (var k = 0; k < Math.min(limit, items.length); k++) runners.push(next());
        return Promise.all(runners).then(function () { return results; });
    }

    function normName(s) { return String(s).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim(); }
    /** Drops same-name same-category duplicates within ~60 m (a place often has several nodes). */
    function dedupe(list) {
        var kept = [];
        list.forEach(function (p) {
            var n = normName(p.name);
            for (var i = 0; i < kept.length; i++) if (kept[i].category === p.category && normName(kept[i].name) === n && haversine(kept[i].lat, kept[i].lng, p.lat, p.lng) < 60) return;
            kept.push(p);
        });
        return kept;
    }

    /**
     * opts: lat, lng, category ('' = every mapped category), radiusM (default 4000), limit (default 40), lang ('en'|'bn')
     * Resolves {places, origin:'live', tiles, failedTiles}. Rejects only when every tile failed (so callers can fall back).
     */
    function loadPlaces(opts) {
        opts = opts || {};
        var lat = Number(opts.lat), lng = Number(opts.lng);
        if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 85 || Math.abs(lng) > 180) return Promise.reject(new Error('bad coordinates'));
        if (cfg.now() < state.brokenUntil) return Promise.reject(new Error('tiles unavailable (cooling down)'));
        var radius = Math.min(Math.max(Number(opts.radiusM) || 4000, 300), 15000), limit = Math.min(Math.max(opts.limit || 40, 1), 200);
        var tiles = tilesFor(lat, lng, radius, TILE_ZOOM, 9);
        return pool(tiles, cfg.concurrency, function (t) { return fetchTile(t, opts.lang); }).then(function (res) {
            var ok = res.filter(function (r) { return r.ok; });
            if (!ok.length) {
                if (++state.failures >= 3) state.brokenUntil = cfg.now() + 60000;     // circuit breaker: stop hammering for a minute
                throw (res[0] && res[0].e) || new Error('no tiles');
            }
            state.failures = 0;
            var all = [];
            ok.forEach(function (r) { all = all.concat(r.v); });
            var want = opts.category || '';
            var list = all.filter(function (p) { return want ? p.category === want : !!p.category; });
            list.forEach(function (p) { p.distance_m = Math.round(haversine(lat, lng, p.lat, p.lng)); });
            list = list.filter(function (p) { return p.distance_m <= radius; });
            list.sort(function (a, b) { return (a.distance_m - b.distance_m) || (a.rank - b.rank); });
            list = dedupe(list).slice(0, limit);
            return { places: list, origin: 'live', tiles: ok.length, failedTiles: res.length - ok.length, attribution: 'OpenStreetMap contributors, via OpenFreeMap' };
        });
    }

    /* ---------- base-map POI clicks (labels from the Liberty style) ---------- */
    function bindBaseMapClicks(map, onPlace) {
        var layers = [];
        function collect() {
            var st = map.getStyle && map.getStyle(); layers = ((st && st.layers) || []).filter(function (l) { return l['source-layer'] === 'poi' && l.type === 'symbol'; }).map(function (l) { return l.id; });
        }
        function hit(e) {
            if (!layers.length) collect();
            if (!layers.length) return null;
            var present = layers.filter(function (id) { return map.getLayer(id); });
            var fs = present.length ? map.queryRenderedFeatures(e.point, { layers: present }) : [];
            return fs && fs[0] || null;
        }
        map.on('click', function (e) {
            // never steal a click from app layers (pins, clusters): they are queried by their own handlers
            var f = hit(e); if (!f) return;
            var p = f.properties || {}, name = pickName(p, 'en') || p.name; if (!name) return;
            var c = f.geometry && f.geometry.coordinates;
            onPlace({ name: name, name_local: p.name || '', lat: c ? c[1] : e.lngLat.lat, lng: c ? c[0] : e.lngLat.lng, cls: String(p['class'] || ''), subclass: String(p.subclass || ''), category: classify(p['class'], p.subclass), source: 'openfreemap' }, e);
        });
        map.on('mousemove', function (e) { var c = map.getCanvas(); if (c) c.style.cursor = hit(e) ? 'pointer' : ''; });
    }

    /** Popup content built with DOM APIs and textContent only (OSM names are untrusted text). */
    function popupNode(p, labels, actions) {
        labels = labels || {};
        var d = document, root = d.createElement('div'); root.className = 'tms-popup';
        var h = d.createElement('strong'); h.className = 'tms-popup-title'; h.textContent = p.name || ''; root.appendChild(h);
        var sub = [labels[p.category] || '', p.subclass ? String(p.subclass).replace(/_/g, ' ') : ''].filter(Boolean);
        if (sub.length) { var s = d.createElement('div'); s.className = 'tms-popup-sub'; s.textContent = sub.join(' · '); root.appendChild(s); }
        if (typeof p.distance_m === 'number') { var dd = d.createElement('div'); dd.className = 'tms-popup-sub'; dd.textContent = (p.distance_m >= 1000 ? (p.distance_m / 1000).toFixed(1) + ' km' : p.distance_m + ' m') + ' away'; root.appendChild(dd); }
        var row = d.createElement('div'); row.className = 'tms-popup-actions';
        (actions || []).forEach(function (a) {
            var b = d.createElement('button'); b.type = 'button'; b.className = 'tms-popup-btn'; b.textContent = a.label; b.addEventListener('click', function () { a.run(p); }); row.appendChild(b);
        });
        if (row.childNodes.length) root.appendChild(row);
        var src = d.createElement('div'); src.className = 'tms-popup-src'; src.textContent = 'Map data © OpenStreetMap contributors'; root.appendChild(src);
        return root;
    }

    var api = {
        configure: configure, reset: reset, tileZoom: TILE_ZOOM, tilesFor: tilesFor, haversine: haversine, tileToLat: tileToLat, tileToLng: tileToLng,
        lngToTileX: lngToTileX, latToTileY: latToTileY, decodeTile: decodeTile, featuresInTile: featuresInTile, classify: classify, dedupe: dedupe,
        loadPlaces: loadPlaces, bindBaseMapClicks: bindBaseMapClicks, popupNode: popupNode, categories: ['hotel', 'restaurant', 'hospital', 'pharmacy', 'police', 'atm', 'gas_station', 'shopping_mall', 'tourist_info', 'attraction']
    };
    return api;
});

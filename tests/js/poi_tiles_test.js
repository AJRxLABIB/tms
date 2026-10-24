// node tests/js/poi_tiles_test.js  -- MVT decoder, classification, tile maths, loader (fake fetch), all offline.
const T = require('../../js/tms-poi-tiles.js');
let pass = 0, fail = 0;
function t(n, ok, d) { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  ' + (d || ''))); }

// ---- tiny protobuf encoder for the fixture ----
const vi = n => { const o = []; while (n > 127) { o.push((n % 128) | 128); n = Math.floor(n / 128); } o.push(n); return o; };
const tag = (f, w) => vi(f * 8 + w);
const lenD = (f, bytes) => [...tag(f, 2), ...vi(bytes.length), ...bytes];
const sBytes = s => [...Buffer.from(s, 'utf8')];
const zig = n => n >= 0 ? n * 2 : -n * 2 - 1;
function value(v) { if (typeof v === 'string') return lenD(1, sBytes(v)); if (typeof v === 'boolean') return [...tag(7, 0), v ? 1 : 0]; return [...tag(4, 0), ...vi(v)]; }
function layer(name, feats, extent) {
    const keys = [], vals = [];
    const fb = feats.map(f => {
        const tags = [];
        for (const [k, v] of Object.entries(f.props)) {
            let ki = keys.indexOf(k); if (ki < 0) { ki = keys.length; keys.push(k); }
            const vb = JSON.stringify(v); let vj = vals.findIndex(x => x.j === vb); if (vj < 0) { vj = vals.length; vals.push({ j: vb, b: value(v) }); }
            tags.push(ki, vj);
        }
        let geom = [];
        if (f.type === 1) geom = [9, zig(f.x), zig(f.y)];
        else if (f.type === 2) geom = [9, zig(f.pts[0][0]), zig(f.pts[0][1]), (f.pts.length - 1) * 8 + 2];
        if (f.type === 2) { let px = f.pts[0][0], py = f.pts[0][1]; for (let i = 1; i < f.pts.length; i++) { geom.push(zig(f.pts[i][0] - px), zig(f.pts[i][1] - py)); px = f.pts[i][0]; py = f.pts[i][1]; } }
        return lenD(2, [...tag(1, 0), ...vi(f.id || 1), ...lenD(2, tags.flatMap(vi)), ...tag(3, 0), f.type, ...lenD(4, geom.flatMap(vi))]);
    });
    return lenD(3, [...lenD(1, sBytes(name)), ...fb.flat(), ...keys.flatMap(k => lenD(3, sBytes(k))), ...vals.flatMap(v => lenD(4, v.b)), ...tag(5, 0), ...vi(extent || 4096), ...tag(15, 0), 2]);
}
const z = 14, lat0 = 21.4272, lng0 = 92.0058;            // Cox's Bazar
const tx = T.lngToTileX(lng0, z), ty = T.latToTileY(lat0, z);
const px = (lng, ext = 4096) => Math.round(((lng + 180) / 360 * 2 ** z - tx) * ext);
const py = (lat, ext = 4096) => { const r = lat * Math.PI / 180; return Math.round(((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * 2 ** z - ty) * ext); };
const P = (id, lat, lng, props) => ({ id, type: 1, x: px(lng), y: py(lat), props });
const tile = Uint8Array.from([
    ...layer('poi', [
        P(1, 21.4300, 92.0070, { class: 'lodging', subclass: 'hotel', name: 'সমুদ্র হোটেল', name_en: 'Sea Hotel', rank: 5 }),
        P(2, 21.4301, 92.0071, { class: 'lodging', subclass: 'hotel', name: 'Sea Hotel', name_en: 'Sea Hotel', rank: 6 }),   // duplicate within 60 m
        P(3, 21.4280, 92.0060, { class: 'restaurant', subclass: 'fast_food', name: 'Burger Hut', rank: 3 }),
        P(4, 21.4275, 92.0059, { class: 'hospital', subclass: 'hospital', name: 'Town Hospital', 'name:bn': 'টাউন হাসপাতাল', rank: 1 }),
        P(5, 21.4290, 92.0065, { class: 'fuel', name: 'Fuel Stop', rank: 2 }),
        P(6, 21.4271, 92.0057, { class: 'lodging', subclass: 'hotel', rank: 4 }),                 // unnamed: dropped
        P(7, 21.4400, 92.0100, { class: 'attraction', subclass: 'viewpoint', name: 'Far Viewpoint', rank: 7 }),   // outside radius
        P(8, 21.4273, 92.0058, { class: 'weirdclass', name: 'Mystery', rank: 9 }),               // unmapped class
    ]),
    ...layer('transportation', [{ id: 9, type: 2, pts: [[0, 0], [100, 50], [200, 50]], props: { class: 'primary' } }]),
]);

const d = T.decodeTile(tile);
t('decodes both layers', d.layers.poi && d.layers.transportation, Object.keys(d.layers).join());
t('poi feature count', d.layers.poi.features.length === 8, d.layers.poi.features.length);
t('props decoded (unicode string)', d.layers.poi.features[0].props.name === 'সমুদ্র হোটেল');
t('numeric prop decoded', d.layers.poi.features[0].props.rank === 5);
t('line geometry decoded', JSON.stringify(d.layers.transportation.features[0].geometry) === '[[[0,0],[100,50],[200,50]]]', JSON.stringify(d.layers.transportation.features[0].geometry));
t('layer filter skips others', !T.decodeTile(tile, ['poi']).layers.transportation);

const pois = T.featuresInTile(tile, z, tx, ty, 'en');
t('unnamed POI dropped', !pois.some(p => p.name === '' ) && pois.length === 7, pois.length);
const hosp = pois.find(p => p.cls === 'hospital');
t('name kept when name:bn also present (en pref)', hosp && hosp.name === 'Town Hospital', hosp && hosp.name);
t('name:bn used as last resort', T.featuresInTile(Uint8Array.from(layer('poi',[P(1,21.43,92.007,{class:'police','name:bn':'থানা'})])), z, tx, ty, 'en')[0].name === 'থানা');
t('English name preferred for en', pois.find(p => p.cls === 'lodging').name === 'Sea Hotel');
t('bn preferred for bn', T.featuresInTile(tile, z, tx, ty, 'bn').find(p => p.cls === 'lodging').name === 'সমুদ্র হোটেল');
const h = pois.find(p => p.name === 'Sea Hotel');
t('coordinates round-trip within 5 m', Math.abs(h.lat - 21.4300) < 5e-5 && Math.abs(h.lng - 92.0070) < 5e-5, h.lat + ',' + h.lng);

// classification
const C = [['lodging', 'hotel', 'hotel'], ['restaurant', 'fast_food', 'restaurant'], ['hospital', '', 'hospital'], ['pharmacy', '', 'pharmacy'], ['police', '', 'police'], ['atm', '', 'atm'], ['bank', '', 'atm'],
    ['fuel', '', 'gas_station'], ['shop', 'mall', 'shopping_mall'], ['information', '', 'tourist_info'], ['attraction', 'viewpoint', 'attraction'], ['museum', '', 'attraction'], ['weird', '', '']];
C.forEach(([c, s, e]) => t(`classify ${c}:${s} -> ${e || '(none)'}`, T.classify(c, s) === e, T.classify(c, s)));

// tile maths
t('tile roundtrip lat', Math.abs(T.tileToLat(ty, z) - lat0) < 0.02);
const ts = T.tilesFor(lat0, lng0, 4000, 14, 9);
t('tilesFor nearest first and capped', ts.length >= 1 && ts.length <= 9 && ts[0].x === tx && ts[0].y === ty, JSON.stringify(ts[0]));
t('haversine 1 deg lat ~ 111 km', Math.abs(T.haversine(0, 0, 1, 0) - 111195) < 300);

// loader with fake fetch
const buf = tile.buffer.slice(tile.byteOffset, tile.byteOffset + tile.byteLength);
let urls = [], mode = 'ok';
const fakeFetch = async (u) => {
    urls.push(u);
    if (mode === 'down') throw new Error('network');
    if (u === 'https://tiles.openfreemap.org/planet') return { ok: true, json: async () => ({ tiles: ['https://tiles.openfreemap.org/planet/20261018_080001_pt/{z}/{x}/{y}.pbf'] }) };
    const m = /\/14\/(\d+)\/(\d+)\.pbf$/.exec(u); const here = m && +m[1] === tx && +m[2] === ty;
    return { ok: true, arrayBuffer: async () => here ? buf : new Uint8Array(0).buffer };   // other tiles empty
};
(async () => {
    T.configure({ fetch: fakeFetch, timeout: 500 });
    let r = await T.loadPlaces({ lat: lat0, lng: lng0, category: 'hotel', radiusM: 2000 });
    t('loader: origin live', r.origin === 'live');
    t('loader: category filter + dedupe -> 1 hotel', r.places.length === 1 && r.places[0].name === 'Sea Hotel', JSON.stringify(r.places.map(p => p.name)));
    t('loader: distance computed', typeof r.places[0].distance_m === 'number' && r.places[0].distance_m > 100 && r.places[0].distance_m < 600, r.places[0].distance_m);
    t('loader: tile url resolved from TileJSON with version path', urls.some(u => u.includes('/20261018_080001_pt/14/')));
    const n1 = urls.length;
    r = await T.loadPlaces({ lat: lat0, lng: lng0, category: 'restaurant', radiusM: 2000 });
    t('loader: second call served from cache (no new fetches)', urls.length === n1 && r.places[0].name === 'Burger Hut', urls.length + ' vs ' + n1);
    r = await T.loadPlaces({ lat: lat0, lng: lng0, category: '', radiusM: 1000 });
    t('loader: all categories sorted by distance, far + unmapped excluded', r.places.length === 4 && !r.places.some(p => p.name === 'Far Viewpoint' || p.name === 'Mystery') && r.places.every((p, i, a) => !i || a[i - 1].distance_m <= p.distance_m), r.places.map(p => p.name + p.distance_m).join());
    r = await T.loadPlaces({ lat: lat0, lng: lng0, category: 'attraction', radiusM: 15000 });
    t('loader: wide radius includes far viewpoint', r.places.some(p => p.name === 'Far Viewpoint'));
    let rej = false; try { await T.loadPlaces({ lat: 999, lng: 0 }); } catch (e) { rej = true; }
    t('loader: bad coordinates rejected', rej);
    T.reset(); mode = 'down'; let clock = 1000; T.configure({ now: () => clock });
    for (let i = 0; i < 3; i++) { rej = false; try { await T.loadPlaces({ lat: lat0, lng: lng0 }); } catch (e) { rej = true; } t('loader: network down rejects (' + (i + 1) + ')', rej); }
    urls = []; rej = false; try { await T.loadPlaces({ lat: lat0, lng: lng0 }); } catch (e) { rej = /cooling/.test(e.message); }
    t('circuit breaker opens after 3 failures (no new requests)', rej && urls.length === 0, urls.length);
    clock += 61000; mode = 'ok'; r = await T.loadPlaces({ lat: lat0, lng: lng0, category: 'hotel', radiusM: 2000 });
    t('breaker closes after cool-down', r.places.length === 1);
    // hostile tilejson refused
    T.reset(); T.configure({ now: () => Date.now(), fetch: async (u) => u.endsWith('/planet') ? { ok: true, json: async () => ({ tiles: ['https://evil.example/{z}/{x}/{y}.pbf'] }) } : { ok: true, arrayBuffer: async () => buf } });
    rej = false; try { await T.loadPlaces({ lat: lat0, lng: lng0 }); } catch (e) { rej = true; }
    t('TileJSON pointing at a foreign host is refused', rej);
    // garbage tile does not crash
    T.reset(); T.configure({ fetch: async (u) => u.endsWith('/planet') ? { ok: true, json: async () => ({ tiles: ['https://tiles.openfreemap.org/p/{z}/{x}/{y}.pbf'] }) } : { ok: true, arrayBuffer: async () => new Uint8Array([255, 255, 255, 255, 1, 2, 3]).buffer } });
    rej = false; try { await T.loadPlaces({ lat: lat0, lng: lng0 }); } catch (e) { rej = true; }
    t('corrupt tile rejects cleanly (caller falls back)', rej);
    console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();

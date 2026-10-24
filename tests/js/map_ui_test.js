// Pure-helper tests for the tourism map UI (no browser, no network).   node tests/js/map_ui_test.js
// Loads js/tms-maplibre.js exactly as a browser would (inside a vm context with no DOM) and exercises TmsMapGL.utils.
const fs = require('fs'), vm = require('vm'), path = require('path');
let pass = 0, fail = 0;
const t = (n, ok, d) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok || d === undefined ? '' : '   -> ' + d)); };
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const ctx = { self: {}, console };
ctx.self = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../js/tms-maplibre.js'), 'utf8'), ctx, { filename: 'tms-maplibre.js' });
const G = ctx.TmsMapGL;
t('TmsMapGL is exported with createMap and utils', !!G && typeof G.createMap === 'function' && !!G.utils);
const U = G.utils;

// ---- haversine (metres)
const dhk_ctg = U.haversine(23.8103, 90.4125, 22.3569, 91.7832);
t('haversine Dhaka-Chattogram is about 215 km', dhk_ctg > 205000 && dhk_ctg < 225000, dhk_ctg);
t('haversine of identical points is 0', U.haversine(21.4272, 92.0058, 21.4272, 92.0058) === 0);
t('haversine is symmetric', Math.abs(U.haversine(1, 2, 3, 4) - U.haversine(3, 4, 1, 2)) < 1e-6);
t('haversine uses (lat,lng) order: swapping gives a different number', Math.abs(U.haversine(23.8, 90.4, 22.3, 91.7) - U.haversine(90.4, 23.8, 91.7, 22.3)) > 1000);

// ---- coordinates: latitude first, range checked, null island rejected
t('validCoord accepts Cox\'s Bazar (lat 21.4272, lng 92.0058)', U.validCoord(21.4272, 92.0058) === true);
t('validCoord rejects swapped Bangladesh coordinates (lat 92.0058 is impossible)', U.validCoord(92.0058, 21.4272) === false);
t('validCoord rejects null, empty, NaN, strings of text', !U.validCoord(null, 90) && !U.validCoord('', 90) && !U.validCoord(NaN, 90) && !U.validCoord('abc', 90));
t('validCoord rejects 0,0', U.validCoord(0, 0) === false);
t('validCoord accepts numeric strings', U.validCoord('23.8', '90.4') === true);
t('validCoord rejects out-of-range longitude', U.validCoord(10, 181) === false && U.validCoord(-91, 10) === false);

// ---- bbox parse / key
t('parseBbox parses w,s,e,n', eq(U.parseBbox('90,22,92,24'), [90, 22, 92, 24]));
t('parseBbox rejects west>=east, bad lat, wrong arity, NaN', U.parseBbox('92,22,90,24') === null && U.parseBbox('90,22,92,95') === null && U.parseBbox('1,2,3') === null && U.parseBbox('a,b,c,d') === null);
const k1 = U.bboxKey([90.41, 23.80, 90.45, 23.83], 12, 'x'), k2 = U.bboxKey([90.415, 23.805, 90.446, 23.826], 12, 'x');
t('bboxKey: a small pan at the same zoom reuses the key', k1 === k2, k1 + ' vs ' + k2);
t('bboxKey: different filter signature gives a different key', U.bboxKey([90, 22, 91, 23], 10, 'a') !== U.bboxKey([90, 22, 91, 23], 10, 'b'));
t('bboxKey: different zoom gives a different key', U.bboxKey([90, 22, 91, 23], 8, '') !== U.bboxKey([90, 22, 91, 23], 12, ''));
const snapped = U.snapBbox([90.41, 23.80, 90.45, 23.83], 12);
t('snapBbox covers the requested box (snapped outward)', snapped[0] <= 90.41 && snapped[1] <= 23.80 && snapped[2] >= 90.45 && snapped[3] >= 23.83, JSON.stringify(snapped));
t('boundsToBbox accepts arrays and LngLatBounds-like objects', eq(U.boundsToBbox([1, 2, 3, 4, 9]), [1, 2, 3, 4]) && eq(U.boundsToBbox({ getWest: () => 1, getSouth: () => 2, getEast: () => 3, getNorth: () => 4 }), [1, 2, 3, 4]));

// ---- filter predicates: unknown is never a confirmed negative
const unknownPlace = { id: 1, category: 'attraction', verified: false, family_friendly: null, accessibility: null, fee: null };
let r = U.matchesFilters(unknownPlace, { family: true });
t('family filter: unknown family data -> not shown but flagged unknown (not "no")', r.pass === false && r.unknown === true);
r = U.matchesFilters(unknownPlace, { free: true });
t('free filter: unknown fee is NOT treated as free', r.pass === false && r.unknown === true);
r = U.matchesFilters(unknownPlace, { accessible: true });
t('accessible filter: unknown accessibility is not "accessible"', r.pass === false && r.unknown === true);
r = U.matchesFilters({ category: 'hotel', family_friendly: false }, { family: true });
t('family filter: explicit false is a real negative (not unknown)', r.pass === false && r.unknown === false);
r = U.matchesFilters({ category: 'hotel', family_friendly: true, fee: { amount: 0 }, accessibility: { wheelchair: true }, verified: true }, { family: true, free: true, accessible: true, verified: true });
t('all filters satisfied by real data -> pass', r.pass === true && r.unknown === false);
t('category filter excludes other categories', U.matchesFilters({ category: 'hotel' }, { categories: ['beach'] }).pass === false && U.matchesFilters({ category: 'beach' }, { categories: ['beach'] }).pass === true);
t('verified filter requires verified === true (null is not verified)', U.matchesFilters({ category: 'x', verified: null }, { verified: true }).pass === false);
t('no filters -> everything passes, including records with no optional data', U.matchesFilters(unknownPlace, {}).pass === true);
t('feeIsFree: numbers, objects, booleans', U.feeIsFree(0) === true && U.feeIsFree(500) === false && U.feeIsFree({ amount: 0 }) === true && U.feeIsFree({ adult: 200 }) === false && U.feeIsFree({ is_free: true }) === true && U.feeIsFree(true) === true);
t('feeIsFree: free-text "Free" is free, text we cannot read is unknown', U.feeIsFree('Free') === true && U.feeIsFree('Entry fee applies, ask at gate') === null);
t('feeIsFree: "0.50" or "0 for locals, 500 for foreigners" must not be reported as free', U.feeIsFree('0.50 BDT') !== true && U.feeIsFree('0 for locals, 500 for foreigners') !== true && U.feeIsFree('Free for children, 500 for adults') !== true);
t('accessibleState: yes / explicit no / unknown', U.accessibleState({ wheelchair: true }) === true && U.accessibleState('Not wheelchair accessible') === false && U.accessibleState('Hilly terrain') === null && U.accessibleState(null) === null);
t('filterSignature changes with each filter and is stable for category order', U.filterSignature({ categories: ['b', 'a'] }) === U.filterSignature({ categories: ['a', 'b'] }) && U.filterSignature({ free: true }) !== U.filterSignature({}));

// ---- hash state
const state = { place: 'cox-bazar-beach', center: [92.0058, 21.4272], zoom: 11.5, categories: ['beach', 'hotel'], verified: true, family: false, accessible: true, free: false, q: 'কক্সবাজার sea', style: 'positron' };
const enc = U.hashEncode(state), dec = U.hashDecode('#' + enc);
t('hash round trip: place, centre (lng,lat), zoom', dec.place === 'cox-bazar-beach' && Math.abs(dec.center[0] - 92.0058) < 1e-4 && Math.abs(dec.center[1] - 21.4272) < 1e-4 && Math.abs(dec.zoom - 11.5) < 1e-6, enc);
t('hash round trip: categories, flags, Bangla query, style', eq(dec.categories, ['beach', 'hotel']) && dec.verified === true && dec.accessible === true && dec.family === false && dec.free === false && dec.q === 'কক্সবাজার sea' && dec.style === 'positron');
t('hashDecode of garbage returns safe defaults', eq(U.hashDecode('#c=abc&cat=%E0%A4%A&zz'), { place: null, center: null, zoom: null, categories: [], verified: false, family: false, accessible: false, free: false, q: '', style: '' }));
t('hashDecode drops an impossible centre (lat 200) and injected category characters', U.hashDecode('#c=90,200,5').center === null && U.hashDecode('#cat=beach,<script>').categories.length === 1);
t('hashDecode caps overlong place/query values', U.hashDecode('#p=' + 'a'.repeat(500)).place.length <= 80 && U.hashDecode('#q=' + 'a'.repeat(500)).q.length <= 120);
t('hashEncode of an empty state is an empty string', U.hashEncode({}) === '');

// ---- icons
t('iconKey maps config icon names and categories', U.iconKey('fa-bed') === 'bed' && U.iconKey('fa-camera') === 'camera' && U.iconKey('', 'beach') === 'water' && U.iconKey('fa-cutlery') === 'utensils' && U.iconKey('fa-plane') === 'plane' && U.iconKey('???') === 'pin');
const svg = U.iconSvg('#1B7F5F', 'bed', 32);
t('iconSvg builds a self-contained svg with the colour and a path', /^<svg /.test(svg) && svg.includes('fill="#1B7F5F"') && svg.includes('<path') && svg.includes('width="32"'));
t('iconSvg refuses an injected colour (falls back to the brand colour)', !U.iconSvg('red" onload="x', 'bed', 24).includes('onload'));
t('iconSvg falls back to the pin glyph for unknown keys', U.iconSvg('#000000', 'nope', 24).includes('<path'));
t('every glyph key renders', U.glyphKeys().every(k => U.iconSvg('#123456', k, 20).includes('<path')));

// ---- label language patcher on real Liberty text-field expressions
const liberty = {
  version: 8, sources: {}, layers: [
    { id: 'label_city', type: 'symbol', source: 'openmaptiles', layout: { 'text-field': ['case', ['has', 'name:nonlatin'], ['concat', ['get', 'name:latin'], '\n', ['get', 'name:nonlatin']], ['coalesce', ['get', 'name_en'], ['get', 'name']]], 'text-font': ['Noto Sans Regular'] } },
    { id: 'highway-name-major', type: 'symbol', source: 'openmaptiles', layout: { 'text-field': ['coalesce', ['get', 'name_en'], ['get', 'name']] } },
    { id: 'legacy', type: 'symbol', source: 'x', layout: { 'text-field': '{name_en}' } },
    { id: 'tms-places', type: 'symbol', source: 'tms', layout: { 'text-field': ['get', 'label'] } },
    { id: 'road_minor', type: 'line', source: 'openmaptiles', layout: {} },
    { id: 'icon-only', type: 'symbol', source: 'openmaptiles', layout: { 'icon-image': 'x' } }
  ]
};
const bn = U.patchLabelLang(liberty, 'bn');
const byId = id => bn.layers.find(l => l.id === id);
t('patchLabelLang(bn): simple coalesce becomes bn-preferring', eq(byId('highway-name-major').layout['text-field'], ['coalesce', ['get', 'name:bn'], ['coalesce', ['get', 'name_en'], ['get', 'name']]]));
t('patchLabelLang(bn): the exact expression ["coalesce",["get","name_en"],["get","name"]] is preserved inside the fallback', JSON.stringify(byId('label_city').layout['text-field']).includes('["coalesce",["get","name_en"],["get","name"]]') && byId('label_city').layout['text-field'][1][1] === 'name:bn');
t('patchLabelLang(bn): legacy "{name_en}" token string is rewritten', eq(byId('legacy').layout['text-field'], ['coalesce', ['get', 'name:bn'], ['get', 'name']]));
t('patchLabelLang: our own tms-* layers and non-label layers are untouched', eq(byId('tms-places').layout['text-field'], ['get', 'label']) && byId('road_minor').layout && byId('icon-only').layout['text-field'] === undefined);
t('patchLabelLang does not mutate the input style', JSON.stringify(liberty).includes('"text-field":"{name_en}"') && liberty.layers[1].layout['text-field'][1][1] === 'name_en');
t('patchLabelLang is idempotent', eq(U.patchLabelLang(bn, 'bn'), bn));
t('patchLabelLang(en) returns the very same style object', U.patchLabelLang(liberty, 'en') === liberty);

// ---- escaping and formatting
t('escapeHtml escapes the five dangerous characters and tolerates null', U.escapeHtml('<a href="x" onclick=\'y\'>&</a>') === '&lt;a href=&quot;x&quot; onclick=&#39;y&#39;&gt;&amp;&lt;/a&gt;' && U.escapeHtml(null) === '' && U.escapeHtml(5) === '5');
t('formatDistance', U.formatDistance(0) === '0 m' && U.formatDistance(430) === '430 m' && U.formatDistance(1234) === '1.2 km' && U.formatDistance(15600) === '16 km' && U.formatDistance(-1) === '' && U.formatDistance('x') === '');
t('formatDuration', U.formatDuration(20) === '1 min' && U.formatDuration(600) === '10 min' && U.formatDuration(3600) === '1 h' && U.formatDuration(5400) === '1 h 30 min' && U.formatDuration(NaN) === '');
const ex = U.externalDirectionsUrls({ lat: 23.8103, lng: 90.4125 }, { lat: 21.4272, lng: 92.0058 }, 'walking');
t('externalDirectionsUrls: OSM + Google links use lat,lng order and the walking engine/mode', ex.osm.includes('engine=fossgis_osrm_foot') && ex.osm.includes(encodeURIComponent('23.810300,90.412500;21.427200,92.005800')) && ex.google.includes('travelmode=walking') && ex.google.includes('origin=' + encodeURIComponent('23.810300,90.412500')));
t('externalDirectionsUrls: unknown mode falls back to driving', U.externalDirectionsUrls({ lat: 1, lng: 2 }, { lat: 3, lng: 4 }, 'teleport').google.includes('travelmode=driving'));
t('rasterStyle builds a valid minimal raster style', (() => { const s = G.rasterStyle('https://tile.example/{z}/{x}/{y}.png', 'attr', 18); return s.version === 8 && s.layers[0].type === 'raster' && s.sources['tms-raster-base'].tiles[0].includes('{z}'); })());

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);

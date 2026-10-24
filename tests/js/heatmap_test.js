// node tests/js/heatmap_test.js — pure-logic tests for js/tms-map-pages.js (heat points, feature collection, category mapping, URL safety)
'use strict';
const path = require('path');
const P = require(path.join(__dirname, '../../js/tms-map-pages.js'));
const U = P.utils;
let pass = 0, fail = 0;
function t(name, ok, extra) { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  ' + (extra || ''))); }
function eq(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

// validCoord
t('validCoord accepts numeric strings (DB stores varchar)', U.validCoord('23.81', '90.41') === true);
t('validCoord rejects null/empty/undefined', !U.validCoord(null, 90) && !U.validCoord('', '') && !U.validCoord(undefined, 5));
t('validCoord rejects out-of-range lat/lng', !U.validCoord(91, 10) && !U.validCoord(10, 181) && !U.validCoord(-91, 0));
t('validCoord rejects (0,0) placeholder', !U.validCoord(0, 0) && !U.validCoord('0', '0.0'));
t('validCoord rejects NaN, Infinity, booleans, text', !U.validCoord(NaN, 1) && !U.validCoord(Infinity, 1) && !U.validCoord(true, false) && !U.validCoord('abc', '1'));
t('validCoord keeps lat/lng order: lat 90.4 is invalid, lng 90.4 is valid', !U.validCoord(90.4, 23.8) && U.validCoord(23.8, 90.4));

// buildHeatPoints
var rows = [
    { id: 1, name: 'A', lat: 23.8, lng: 90.4, weight: 100, type: 'Nature' },
    { id: 2, name: 'B', lat: '21.4', lng: '92.0', weight: 50 },
    { id: 3, name: 'C', lat: 0, lng: 0, weight: 10 },            // null island
    { id: 4, name: 'D', lat: 24, lng: 91, weight: -5 },          // negative
    { id: 5, name: 'E', lat: 24, lng: 91, weight: 'x' },         // NaN
    null,
    { id: 6, name: 'F', lat: 22, lng: 91, weight: 0 }
];
var r = U.buildHeatPoints(rows);
t('heat: invalid coords / negative / NaN / null rows are skipped and counted', r.skipped === 4 && r.points.length === 3, JSON.stringify(r.skipped));
t('heat: sorted by demand, highest first', eq(r.points.map(p => p.id), [1, 2, 6]));
t('heat: top weight normalises to 1 and keeps raw value', r.points[0].weight === 1 && r.points[0].raw === 100);
t('heat: weights in [floor,1] (default floor .1)', r.points.every(p => p.weight >= 0.1 && p.weight <= 1));
t('heat: half of the top gets floor+(1-floor)*0.5', Math.abs(r.points[1].weight - 0.55) < 1e-9, r.points[1].weight);
t('heat: zero demand sits at the floor, not above', r.points[2].weight === 0.1);
t('heat: numeric strings become numbers', typeof r.points[1].lat === 'number' && r.points[1].lat === 21.4);
t('heat: max reports the real top demand', r.max === 100);
var big = []; for (var i = 1; i <= 50; i++) big.push({ id: i, lat: 20 + i / 100, lng: 90 + i / 100, weight: i });
var capped = U.buildHeatPoints(big, { max: 10 });
t('heat: capped to max points, highest demand kept, flagged truncated', capped.points.length === 10 && capped.truncated === true && capped.points[0].raw === 50 && capped.points[9].raw === 41);
t('heat: all-zero demand does not divide by zero', U.buildHeatPoints([{ id: 1, lat: 1, lng: 1, weight: 0 }]).points[0].weight === 0.1);
t('heat: normalize:false keeps raw weights', U.buildHeatPoints([{ id: 1, lat: 1, lng: 1, weight: 7 }], { normalize: false }).points[0].weight === 7);
t('heat: non-array input is safe', U.buildHeatPoints(null).points.length === 0 && U.buildHeatPoints('x').skipped === 0);
t('heat: floor option is clamped', U.buildHeatPoints([{ id: 1, lat: 1, lng: 1, weight: 0 }], { floor: 5 }).points[0].weight === 0.9);

// toFeatureCollection
var fc = U.toFeatureCollection([{ lat: 23.8, lng: 90.4, title: 'Dhaka', url: 'x.php' }, { lat: 0, lng: 0, title: 'bad' }, { lat: '22.3', lng: '91.8', title: 7 }, null]);
t('features: invalid points dropped, others kept', fc.type === 'FeatureCollection' && fc.features.length === 2);
t('features: GeoJSON order is [lng,lat]', eq(fc.features[0].geometry.coordinates, [90.4, 23.8]));
t('features: titles coerced to strings, numeric strings parsed', fc.features[1].properties.title === '7' && eq(fc.features[1].geometry.coordinates, [91.8, 22.3]));
t('features: index property points back at the source item', fc.features[0].properties.i === 0 && fc.features[1].properties.i === 2);
t('features: empty/invalid input gives an empty collection', U.toFeatureCollection(undefined).features.length === 0);

// explore category mapping
t('categories: hotel -> hotel', eq(U.toExploreCategories('hotel'), ['hotel']));
t('categories: tourist info maps to visitor_info', eq(U.toExploreCategories('tourist_info'), ['visitor_info']));
t('categories: attraction expands to several catalogue categories', U.toExploreCategories('attraction').indexOf('museum') >= 0 && U.toExploreCategories('Attraction').length > 3);
t('categories: ATM / pharmacy / gas stations have no local equivalent', U.toExploreCategories('atm').length === 0 && U.toExploreCategories('pharmacy').length === 0 && U.toExploreCategories('gas_station').length === 0);
t('categories: mapping returns a copy (no shared state)', (function () { var a = U.toExploreCategories('hotel'); a.push('x'); return U.toExploreCategories('hotel').length === 1; })());

// escape + url safety
t('escapeHtml escapes markup and quotes', U.escapeHtml('<img src=x onerror="a">\'&') === '&lt;img src=x onerror=&quot;a&quot;&gt;&#39;&amp;');
t('escapeHtml handles null/undefined', U.escapeHtml(null) === '' && U.escapeHtml(undefined) === '');
t('safeUrl allows relative page links and https', U.safeUrl('package-details.php?pkgid=5') === 'package-details.php?pkgid=5' && U.safeUrl('https://example.org/a') === 'https://example.org/a');
t('safeUrl blocks javascript:, data: and whitespace tricks', U.safeUrl('javascript:alert(1)') === '' && U.safeUrl('data:text/html,x') === '' && U.safeUrl(' javascript:alert(1)') === '');
t('safeUrl empty/null', U.safeUrl('') === '' && U.safeUrl(null) === '');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);

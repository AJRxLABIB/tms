/* Pure tests for js/tms-trip-optimize.js (straight-line order suggestion).   node tests/js/trip_optimize_test.js */
'use strict';
var assert = require('assert');
var O = require('../../js/tms-trip-optimize.js');
var pass = 0, fail = 0;
function t(name, fn) { try { fn(); pass++; console.log('PASS ' + name); } catch (e) { fail++; console.log('FAIL ' + name + ' :: ' + e.message); } }
function P(lat, lng) { return { lat: lat, lng: lng }; }
function rng(seed) { var s = seed >>> 0; return function () { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }
function randomPoints(n, seed) { var r = rng(seed), out = []; for (var i = 0; i < n; i++) out.push(P(21 + r() * 2, 91 + r() * 2)); return out; }
function sortedCopy(a) { return a.slice().sort(function (x, y) { return x - y; }); }
function ident(n) { var a = []; for (var i = 0; i < n; i++) a.push(i); return a; }

t('haversine: zero for the same point, ~111.2 km per degree of latitude', function () {
    assert.strictEqual(O.haversine(P(21, 91), P(21, 91)), 0);
    var d = O.haversine(P(21, 91), P(22, 91)); assert.ok(d > 111000 && d < 111400, String(d));
});
t('haversine is symmetric', function () { var a = P(23.8, 90.4), b = P(21.4, 91.98); assert.ok(Math.abs(O.haversine(a, b) - O.haversine(b, a)) < 1e-6); });

t('a zig-zag order is untangled and the first stop stays first', function () {
    // stops lie on a line (lng 91.0 .. 91.4); the given order jumps back and forth
    var pts = [P(21, 91.0), P(21, 91.3), P(21, 91.1), P(21, 91.4), P(21, 91.2)];
    var r = O.suggestOrder(pts);
    assert.ok(r.ok && r.changed); assert.deepStrictEqual(r.order, [0, 2, 4, 1, 3]);
    assert.ok(r.suggestedDistance_m < r.currentDistance_m); assert.ok(Math.abs(r.savedDistance_m - (r.currentDistance_m - r.suggestedDistance_m)) < 1e-6);
    assert.strictEqual(r.order[0], 0);
});
t('already best order: changed=false and the original order is returned', function () {
    var pts = [P(21, 91.0), P(21, 91.1), P(21, 91.2), P(21, 91.3)];
    var r = O.suggestOrder(pts); assert.ok(r.ok); assert.strictEqual(r.changed, false); assert.deepStrictEqual(r.order, [0, 1, 2, 3]);
    assert.strictEqual(r.suggestedDistance_m, r.currentDistance_m);
});
t('deterministic: same input, same output, many runs', function () {
    var pts = randomPoints(15, 7), first = JSON.stringify(O.suggestOrder(pts));
    for (var i = 0; i < 20; i++) assert.strictEqual(JSON.stringify(O.suggestOrder(pts)), first);
    var f2 = JSON.stringify(O.suggestOrder(pts, { moveFirst: true }));
    for (i = 0; i < 5; i++) assert.strictEqual(JSON.stringify(O.suggestOrder(pts, { moveFirst: true })), f2);
});
t('deterministic with equal distances (ties go to the lower index)', function () {
    var pts = [P(21, 91), P(21.1, 91), P(21, 91.1), P(20.9, 91), P(21, 90.9)];   // four neighbours about equally far from the first stop
    var a = O.suggestOrder(pts), b = O.suggestOrder(pts); assert.deepStrictEqual(a, b);
});
t('never loses or duplicates stops (random inputs, both modes)', function () {
    for (var seed = 1; seed <= 60; seed++) {
        var n = 3 + (seed % 22), pts = randomPoints(n, seed);
        [false, true].forEach(function (mf) {
            var r = O.suggestOrder(pts, { moveFirst: mf });
            assert.ok(r.ok); assert.strictEqual(r.order.length, n); assert.deepStrictEqual(sortedCopy(r.order), ident(n)); assert.ok(O.isPermutation(r.order, n));
        });
    }
});
t('fixed-first: the first stop never moves unless moveFirst is set', function () {
    for (var seed = 100; seed < 140; seed++) { var pts = randomPoints(4 + (seed % 12), seed); assert.strictEqual(O.suggestOrder(pts).order[0], 0); assert.strictEqual(O.suggestOrder(pts, { moveFirst: false }).order[0], 0); }
});
t('moveFirst can start elsewhere and is never longer than fixed-first', function () {
    // first stop sits in the middle of a line: fixed-first must go to one end and come back, moving the first stop lets the path start at an end
    var pts = [P(21, 91.2), P(21, 91.0), P(21, 91.1), P(21, 91.3), P(21, 91.4)];
    var fixed = O.suggestOrder(pts), free = O.suggestOrder(pts, { moveFirst: true });
    assert.ok(fixed.ok && free.ok); assert.ok(free.suggestedDistance_m <= fixed.suggestedDistance_m + 1e-6);
    assert.ok(free.suggestedDistance_m < fixed.suggestedDistance_m); assert.notStrictEqual(free.order[0], 0);
    assert.ok(free.order[0] === 1 || free.order[0] === 4);
});
t('improvement is never worse than the original (random inputs)', function () {
    for (var seed = 200; seed < 280; seed++) {
        var pts = randomPoints(3 + (seed % 20), seed);
        [false, true].forEach(function (mf) {
            var r = O.suggestOrder(pts, { moveFirst: mf });
            assert.ok(r.suggestedDistance_m <= r.currentDistance_m + 1e-6, 'seed ' + seed);
            assert.ok(Math.abs(O.pathLength(pts, r.order) - r.suggestedDistance_m) < 1e-3, 'reported length matches the order');
            assert.ok(Math.abs(O.pathLength(pts, ident(pts.length)) - r.currentDistance_m) < 1e-3);
            assert.strictEqual(r.changed, r.suggestedDistance_m < r.currentDistance_m - 1e-9);
        });
    }
});
t('on average the suggestion is clearly shorter than random orders', function () {
    var saved = 0, total = 0;
    for (var seed = 300; seed < 330; seed++) { var r = O.suggestOrder(randomPoints(12, seed)); saved += r.savedDistance_m; total += r.currentDistance_m; }
    assert.ok(saved / total > 0.3, 'saved ' + (saved / total));
});
t('close to optimal on small inputs (brute force, n=7)', function () {
    function perms(a) { if (a.length <= 1) return [a]; var o = []; a.forEach(function (x, i) { perms(a.slice(0, i).concat(a.slice(i + 1))).forEach(function (p) { o.push([x].concat(p)); }); }); return o; }
    for (var seed = 400; seed < 410; seed++) {
        var pts = randomPoints(7, seed), best = Infinity;
        perms([1, 2, 3, 4, 5, 6]).forEach(function (p) { var l = O.pathLength(pts, [0].concat(p)); if (l < best) best = l; });
        var r = O.suggestOrder(pts); assert.ok(r.suggestedDistance_m >= best - 1e-6, 'cannot beat the optimum');
        assert.ok(r.suggestedDistance_m <= best * 1.35, 'within 35% of optimal, seed ' + seed + ': ' + (r.suggestedDistance_m / best));
    }
});
t('0, 1 and 2 stops are a no-op', function () {
    [[], [P(21, 91)], [P(21, 91), P(22, 92)]].forEach(function (pts) {
        var r = O.suggestOrder(pts); assert.ok(r.ok); assert.strictEqual(r.changed, false); assert.deepStrictEqual(r.order, ident(pts.length)); assert.strictEqual(r.savedDistance_m, 0);
        var r2 = O.suggestOrder(pts, { moveFirst: true }); assert.strictEqual(r2.changed, false); assert.deepStrictEqual(r2.order, ident(pts.length));
    });
    assert.ok(O.suggestOrder([P(21, 91), P(22, 92)]).currentDistance_m > 0);
});
t('bad coordinates are refused (nothing is dropped or guessed) and reported by index', function () {
    var good = P(21, 91);
    [[good, P(NaN, 91), good], [good, P(100, 91), good], [good, P(21, 181), good], [good, P(null, 91), good], [good, { lat: '', lng: '' }, good],
     [good, P(0, 0), good], [good, { lat: 'x', lng: 1 }, good], [good, null, good], [good, { lat: true, lng: true }, good], [good, P(Infinity, 1), good]].forEach(function (pts) {
        var r = O.suggestOrder(pts); assert.strictEqual(r.ok, false); assert.strictEqual(r.error, 'bad_coordinates'); assert.deepStrictEqual(r.badIndexes, [1]); assert.ok(r.message);
    });
    var many = O.suggestOrder([P(NaN, 1), P(21, 91), P(NaN, 1)]); assert.deepStrictEqual(many.badIndexes, [0, 2]);
    assert.strictEqual(O.suggestOrder([P(NaN, 1), P(21, 91)]).ok, false, 'refused even with 2 stops');
});
t('numeric strings are accepted as coordinates', function () { var r = O.suggestOrder([{ lat: '21', lng: '91' }, { lat: '21', lng: '91.3' }, { lat: '21', lng: '91.1' }]); assert.ok(r.ok && r.changed); assert.deepStrictEqual(r.order, [0, 2, 1]); });
t('input that is not a list, or is too long, is refused', function () {
    assert.strictEqual(O.suggestOrder(null).ok, false); assert.strictEqual(O.suggestOrder('x').error, 'invalid_input');
    assert.strictEqual(O.suggestOrder(randomPoints(101, 1)).error, 'too_many_stops');
    assert.ok(O.suggestOrder(randomPoints(100, 1)).ok);
});
t('input is not mutated', function () { var pts = randomPoints(9, 5), copy = JSON.stringify(pts); O.suggestOrder(pts); O.suggestOrder(pts, { moveFirst: true }); assert.strictEqual(JSON.stringify(pts), copy); });
t('duplicate locations are fine (zero distances)', function () { var p = P(21, 91), r = O.suggestOrder([p, p, P(21, 91.1), p, P(21, 91.2)]); assert.ok(r.ok); assert.deepStrictEqual(sortedCopy(r.order), ident(5)); assert.ok(r.suggestedDistance_m <= r.currentDistance_m); });
t('performance: 100 stops finish quickly', function () { var t0 = Date.now(); var r = O.suggestOrder(randomPoints(100, 9), { moveFirst: true }); assert.ok(r.ok); assert.ok(Date.now() - t0 < 8000, 'took ' + (Date.now() - t0) + ' ms'); });
t('applyOrder reorders and refuses anything that is not a permutation', function () {
    assert.deepStrictEqual(O.applyOrder(['a', 'b', 'c'], [2, 0, 1]), ['c', 'a', 'b']);
    assert.strictEqual(O.applyOrder(['a', 'b', 'c'], [0, 0, 1]), null); assert.strictEqual(O.applyOrder(['a', 'b', 'c'], [0, 1]), null);
    assert.strictEqual(O.applyOrder(['a', 'b', 'c'], [0, 1, 3]), null); assert.strictEqual(O.applyOrder(['a', 'b'], [0.5, 1]), null); assert.strictEqual(O.applyOrder('abc', [0, 1, 2]), null);
});
t('isPermutation', function () { assert.ok(O.isPermutation([1, 0], 2)); assert.ok(!O.isPermutation([1, 1], 2)); assert.ok(!O.isPermutation(['0', '1'], 2)); assert.ok(!O.isPermutation(null, 0)); assert.ok(O.isPermutation([], 0)); });

console.log('\n' + pass + ' passed, ' + fail + ' failed'); process.exit(fail ? 1 : 0);

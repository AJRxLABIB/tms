/*
 * tms-trip-optimize.js — suggests a shorter visiting order for the stops of ONE day (no DOM, no network; node-tested in tests/js/trip_optimize_test.js).
 *
 * HONEST LIMITS: distances are STRAIGHT LINES (haversine) between the stop coordinates. They are not road distance, not travel time, and the
 * function knows nothing about opening hours, one-way streets or traffic. The result is a suggestion for the traveller to accept or ignore.
 *
 * Method: nearest neighbour from the first stop, then a bounded 2-opt improvement (reverse a part of the path while that makes it shorter).
 * Deterministic: ties are always broken by the lower original index, so the same input always gives the same answer.
 * The first stop stays first unless opts.moveFirst is true (then every stop is tried as the start and the best path wins).
 * The answer is never longer than the order you passed in: if nothing shorter is found, the original order is returned with changed = false.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; } else { root.TmsTripOptimize = api; }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var R = 6371008.8;                 // mean Earth radius in metres
    var MAX_STOPS = 100;               // same cap as a personal trip
    var MAX_PASSES = 50;               // bound for the 2-opt loop (each pass is O(n^2))
    var EPS = 1e-6;                    // metres: an improvement smaller than this is treated as none

    function rad(d) { return d * Math.PI / 180; }

    /** Great-circle distance in metres between {lat,lng} points. */
    function haversine(a, b) {
        var p1 = rad(a.lat), p2 = rad(b.lat), dp = p2 - p1, dl = rad(b.lng - a.lng);
        var h = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    /** A usable coordinate: real numbers in range. null, '', booleans and the 0,0 placeholder are refused. */
    function validPoint(p) {
        if (!p || typeof p !== 'object') return false;
        var la = p.lat, ln = p.lng;
        if (la === null || ln === null || la === undefined || ln === undefined || la === '' || ln === '' || typeof la === 'boolean' || typeof ln === 'boolean') return false;
        var a = Number(la), b = Number(ln);
        return isFinite(a) && isFinite(b) && a >= -90 && a <= 90 && b >= -180 && b <= 180 && !(a === 0 && b === 0);
    }

    function norm(p) { return { lat: Number(p.lat), lng: Number(p.lng) }; }

    /** Length in metres of visiting the points in the given index order (open path, no return to the start). */
    function pathLength(points, order) {
        var s = 0;
        for (var i = 1; i < order.length; i++) s += haversine(norm(points[order[i - 1]]), norm(points[order[i]]));
        return s;
    }

    function isPermutation(order, n) {
        if (!Array.isArray(order) || order.length !== n) return false;
        var seen = {};
        for (var i = 0; i < n; i++) {
            var v = order[i];
            if (typeof v !== 'number' || v % 1 !== 0 || v < 0 || v >= n || seen[v]) return false;
            seen[v] = true;
        }
        return true;
    }

    function matrix(points) {
        var n = points.length, m = [], i, j;
        var pts = points.map(norm);
        for (i = 0; i < n; i++) m.push(new Array(n));
        for (i = 0; i < n; i++) { m[i][i] = 0; for (j = i + 1; j < n; j++) { var d = haversine(pts[i], pts[j]); m[i][j] = d; m[j][i] = d; } }
        return m;
    }

    function len(m, path) { var s = 0; for (var i = 1; i < path.length; i++) s += m[path[i - 1]][path[i]]; return s; }

    function nearestNeighbour(m, start) {
        var n = m.length, used = new Array(n), path = [start], cur = start, k, j;
        for (j = 0; j < n; j++) used[j] = false;
        used[start] = true;
        for (k = 1; k < n; k++) {
            var best = -1, bd = Infinity;
            for (j = 0; j < n; j++) if (!used[j] && m[cur][j] < bd) { bd = m[cur][j]; best = j; }   // strict "<" keeps the lowest index on ties
            used[best] = true; path.push(best); cur = best;
        }
        return path;
    }

    /** Bounded 2-opt on an open path. lo = first index that may be moved (1 keeps path[0] in place). Returns a new array. */
    function twoOpt(m, path, lo) {
        var n = path.length, p = path.slice(), pass, improved, i, j;
        for (pass = 0; pass < MAX_PASSES; pass++) {
            improved = false;
            for (i = lo; i < n - 1; i++) {
                for (j = i + 1; j < n; j++) {
                    // reversing p[i..j] replaces edge (p[i-1],p[i]) and (p[j],p[j+1]) by (p[i-1],p[j]) and (p[i],p[j+1]); missing edges at the ends cost nothing
                    var before = 0, after = 0;
                    if (i > 0) { before += m[p[i - 1]][p[i]]; after += m[p[i - 1]][p[j]]; }
                    if (j < n - 1) { before += m[p[j]][p[j + 1]]; after += m[p[i]][p[j + 1]]; }
                    if (after < before - EPS) {
                        for (var a = i, b = j; a < b; a++, b--) { var t = p[a]; p[a] = p[b]; p[b] = t; }
                        improved = true;
                    }
                }
            }
            if (!improved) break;
        }
        return p;
    }

    /**
     * @param {Array<{lat:number,lng:number}>} points stops of one day in their current order
     * @param {{moveFirst?:boolean}} [opts]
     * @returns {{ok:true, order:number[], changed:boolean, reason?:string, currentDistance_m:number, suggestedDistance_m:number, savedDistance_m:number}
     *          |{ok:false, error:string, message:string, badIndexes?:number[]}}
     * order[k] is the ORIGINAL index of the stop that should be k-th. It is always a permutation of 0..n-1: no stop is lost or repeated.
     */
    function suggestOrder(points, opts) {
        opts = opts || {};
        if (!Array.isArray(points)) return { ok: false, error: 'invalid_input', message: 'Stops must be a list.' };
        var n = points.length;
        if (n > MAX_STOPS) return { ok: false, error: 'too_many_stops', message: 'At most ' + MAX_STOPS + ' stops can be ordered.' };
        var bad = [];
        points.forEach(function (p, i) { if (!validPoint(p)) bad.push(i); });
        if (bad.length) return { ok: false, error: 'bad_coordinates', badIndexes: bad, message: 'Some places have no usable location, so the order cannot be suggested.' };
        var ident = []; for (var i = 0; i < n; i++) ident.push(i);
        if (n <= 2) {
            var d0 = n === 2 ? pathLength(points, ident) : 0;
            return { ok: true, order: ident, changed: false, reason: 'too_few_stops', currentDistance_m: d0, suggestedDistance_m: d0, savedDistance_m: 0 };
        }
        var m = matrix(points), moveFirst = !!opts.moveFirst;
        var current = len(m, ident), best = ident, bestLen = current;
        function consider(path) { var l = len(m, path); if (l < bestLen - EPS) { best = path; bestLen = l; } }
        if (moveFirst) { for (var s = 0; s < n; s++) consider(twoOpt(m, nearestNeighbour(m, s), 0)); }
        else consider(twoOpt(m, nearestNeighbour(m, 0), 1));
        var changed = best !== ident && bestLen < current - EPS;
        if (!changed) { best = ident; bestLen = current; }
        return { ok: true, order: best.slice(), changed: changed, reason: changed ? undefined : 'already_best_found', currentDistance_m: current, suggestedDistance_m: bestLen, savedDistance_m: current - bestLen };
    }

    /** items reordered by order (a permutation of 0..n-1); returns null when order is not a valid permutation (nothing is ever dropped or duplicated). */
    function applyOrder(items, order) {
        if (!Array.isArray(items) || !isPermutation(order, items.length)) return null;
        return order.map(function (i) { return items[i]; });
    }

    return { suggestOrder: suggestOrder, applyOrder: applyOrder, haversine: haversine, pathLength: pathLength, validPoint: validPoint, isPermutation: isPermutation, MAX_STOPS: MAX_STOPS };
}));

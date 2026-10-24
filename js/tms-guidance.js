/*
 * tms-guidance.js — geometry and state logic for the BETA guidance panel (no DOM, no network, no geolocation; node-tested in tests/js/guidance_test.js).
 *
 * This is NOT turn-by-turn navigation. It only answers, from a position you give it and the route + steps the routing service returned:
 *   how far along the route is the traveller, which step is current, how far to the next manoeuvre, and is the traveller off the route.
 * Nothing is stored: a tracker keeps a few counters and the last result in memory and never records positions. Time comes from an injectable clock.
 *
 * Route geometry is GeoJSON order [lng, lat]. Positions are {lat, lng, accuracy} (accuracy in metres, as navigator.geolocation reports it).
 * Instruction sentences are built from the engine's maneuver type/modifier/name; nothing is looked up or invented.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; } else { root.TmsGuidance = api; }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var R = 6371008.8, M_PER_DEG = Math.PI * R / 180;
    var DEFAULTS = { offRouteM: 60, offRouteFixes: 3, maxAccuracyM: 50, arriveM: 25, minRecalcMs: 15000, backtrackM: 60, ambiguityM: 20, stepEpsilonM: 5 };

    function rad(d) { return d * Math.PI / 180; }
    function haversine(a, b) {
        var p1 = rad(a.lat), p2 = rad(b.lat), dp = p2 - p1, dl = rad(b.lng - a.lng);
        var h = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
    }
    function okNum(v) { return typeof v === 'number' && isFinite(v); }
    function validPos(p) { return !!p && okNum(Number(p.lat)) && okNum(Number(p.lng)) && p.lat !== null && p.lng !== null && p.lat !== '' && p.lng !== '' && typeof p.lat !== 'boolean' && typeof p.lng !== 'boolean' && Math.abs(Number(p.lat)) <= 90 && Math.abs(Number(p.lng)) <= 180; }

    /** [[lng,lat],...] -> {pts, cum, total} (invalid or repeated points dropped), or null when fewer than 2 usable points remain. */
    function prepareLine(coords) {
        if (!Array.isArray(coords)) return null;
        var pts = [];
        coords.forEach(function (c) {
            if (!Array.isArray(c) || c.length < 2) return;
            var lng = Number(c[0]), lat = Number(c[1]);
            if (c[0] === null || c[1] === null || !validPos({ lat: lat, lng: lng })) return;
            var last = pts[pts.length - 1];
            if (last && last.lat === lat && last.lng === lng) return;
            pts.push({ lat: lat, lng: lng });
        });
        if (pts.length < 2) return null;
        var cum = [0];
        for (var i = 1; i < pts.length; i++) cum.push(cum[i - 1] + haversine(pts[i - 1], pts[i]));
        return { pts: pts, cum: cum, total: cum[cum.length - 1] };
    }

    /** Distance in metres from p to segment a-b, and the fraction t (0..1) of the closest point. Local flat-earth maths around p: fine for route-sized segments. */
    function segDist(p, a, b) {
        var k = Math.cos(rad(p.lat)) * M_PER_DEG;
        var ax = (a.lng - p.lng) * k, ay = (a.lat - p.lat) * M_PER_DEG, bx = (b.lng - p.lng) * k, by = (b.lat - p.lat) * M_PER_DEG;
        var dx = bx - ax, dy = by - ay, l2 = dx * dx + dy * dy, t = 0;
        if (l2 > 0) { t = -(ax * dx + ay * dy) / l2; t = t < 0 ? 0 : t > 1 ? 1 : t; }
        var cx = ax + t * dx, cy = ay + t * dy;
        return { d: Math.sqrt(cx * cx + cy * cy), t: t };
    }

    /**
     * Closest point of the route to pos. Returns {distance_m, along_m, seg}.
     * opts.minAlong: when several parts of the route are about equally close (a route that crosses itself or doubles back), prefer the first one
     * that is not behind minAlong, so progress does not jump backwards.
     */
    function project(line, pos, opts) {
        opts = opts || {};
        var n = line.pts.length, best = Infinity, ds = new Array(n - 1), i, tt = new Array(n - 1);
        for (i = 0; i < n - 1; i++) { var r = segDist(pos, line.pts[i], line.pts[i + 1]); ds[i] = r.d; tt[i] = r.t; if (r.d < best) best = r.d; }
        var chosen = -1, tol = opts.ambiguityM != null ? opts.ambiguityM : DEFAULTS.ambiguityM;
        function alongOf(k) { return line.cum[k] + tt[k] * (line.cum[k + 1] - line.cum[k]); }
        if (okNum(opts.minAlong)) {
            for (i = 0; i < n - 1; i++) if (ds[i] <= best + tol && alongOf(i) >= opts.minAlong) { chosen = i; break; }
        }
        if (chosen < 0 && !okNum(opts.minAlong)) { for (i = 0; i < n - 1; i++) if (ds[i] <= best + tol) { chosen = i; break; } }   // no history: earliest of the equally close parts
        if (chosen < 0) { for (i = 0; i < n - 1; i++) if (ds[i] === best) { chosen = i; break; } }
        return { distance_m: ds[chosen], along_m: alongOf(chosen), seg: chosen };
    }

    /**
     * Flatten legs[].steps (as returned by api/map.php?action=route&steps=1) into one list. Each step gets startAlong_m: where along the route its manoeuvre happens.
     * The engine's step distances are summed and scaled to the drawn polyline length, so the two measurements agree. Returns [] when there are no steps.
     */
    function buildSteps(legs, line) {
        var out = [], cum = 0;
        (Array.isArray(legs) ? legs : []).forEach(function (leg, li) {
            (leg && Array.isArray(leg.steps) ? leg.steps : []).forEach(function (s) {
                if (!s || !Array.isArray(s.location) || !okNum(Number(s.location[0])) || !okNum(Number(s.location[1]))) return;
                var d = Number(s.distance_m); if (!okNum(d) || d < 0) d = 0;
                out.push({ i: out.length, legIndex: li, type: String(s.type || 'turn'), modifier: s.modifier ? String(s.modifier) : null, name: s.name ? String(s.name) : '',
                    exit: okNum(s.exit) ? s.exit : null, distance_m: d, location: [Number(s.location[0]), Number(s.location[1])], startAlong_m: cum, endAlong_m: cum + d });
                cum += d;
            });
        });
        var scale = line && cum > 0 ? line.total / cum : 1;
        out.forEach(function (s) { s.startAlong_m *= scale; s.endAlong_m *= scale; });
        return out;
    }

    function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
    /** A readable sentence for one step. ctx.destName names the stop at the end of a leg. Built only from the engine's own type/modifier/name. */
    function instructionText(step, ctx) {
        if (!step) return '';
        ctx = ctx || {};
        var mod = step.modifier ? String(step.modifier).replace(/_/g, ' ') : '', name = step.name ? String(step.name) : '';
        var onto = name ? ' onto ' + name : '', on = name ? ' on ' + name : '';
        var side = /left/.test(mod) ? 'left' : /right/.test(mod) ? 'right' : '';
        switch (step.type) {
            case 'depart': return 'Start' + on;
            case 'arrive': return (ctx.destName ? 'Arrive at ' + ctx.destName : 'Arrive at your destination') + (side ? ', it is on the ' + side : '');
            case 'turn': return mod === 'uturn' ? 'Make a U-turn' + onto : mod === 'straight' || !mod ? 'Continue straight' + on : 'Turn ' + mod + onto;
            case 'new name': return 'Continue' + onto;
            case 'continue': return (mod && mod !== 'straight' ? 'Continue ' + mod : 'Continue straight') + on;
            case 'merge': return 'Merge' + (side ? ' ' + side : '') + onto;
            case 'on ramp': return 'Take the ramp' + (side ? ' on the ' + side : '') + onto;
            case 'off ramp': return 'Take the exit' + (side ? ' on the ' + side : '') + onto;
            case 'fork': return 'Keep ' + (side || 'straight') + ' at the fork' + onto;
            case 'end of road': return 'At the end of the road, turn ' + (mod || 'ahead') + onto;
            case 'roundabout': case 'rotary': case 'roundabout turn': return 'At the roundabout' + (step.exit ? ', take exit ' + step.exit : ', follow the road') + onto;
            case 'exit roundabout': case 'exit rotary': return 'Leave the roundabout' + onto;
            default: return cap(String(step.type || 'Continue').replace(/_/g, ' ')) + (mod ? ' ' + mod : '') + onto;
        }
    }

    /** Where is pos on the route and which step is current/next. minAlong as in project(). */
    function locate(line, steps, pos, minAlong) {
        var pr = project(line, pos, { minAlong: minAlong });
        var along = pr.along_m, cur = -1, nxt = -1, i;
        for (i = 0; i < steps.length; i++) if (steps[i].startAlong_m <= along + DEFAULTS.stepEpsilonM) cur = i;   // within 5 m of a manoeuvre counts as reached
        for (i = 0; i < steps.length; i++) if (steps[i].startAlong_m > along + DEFAULTS.stepEpsilonM) { nxt = i; break; }
        return { distance_m: pr.distance_m, along_m: along, seg: pr.seg, stepIndex: cur, nextStepIndex: nxt,
            remainingToNext_m: nxt >= 0 ? Math.max(0, steps[nxt].startAlong_m - along) : null, remainingTotal_m: Math.max(0, line.total - along) };
    }

    /**
     * Off-route state machine. update(fix) is called for every position reading, in order.
     *  - Readings with accuracy worse than maxAccuracyM (or no accuracy) are NEVER used to decide anything: status 'low_accuracy', counters untouched.
     *  - A usable reading further than offRouteM from the route adds one to a counter; offRouteFixes in a row set offRoute. Any usable reading near the route clears it.
     *  - Arrival: within arriveM of the last point of the route.
     *  - Recalculation is allowed only while offRoute, and at most once per minRecalcMs (injectable clock): see recalcState() / markRecalc().
     * status: ok | checking (1-2 readings off route) | off_route | arrived | low_accuracy | invalid
     */
    function createTracker(opts) {
        opts = opts || {};
        var cfg = {}; Object.keys(DEFAULTS).forEach(function (k) { cfg[k] = opts[k] != null ? opts[k] : DEFAULTS[k]; });
        var now = typeof opts.now === 'function' ? opts.now : function () { return Date.now(); };
        var line = null, steps = [], lastAlong = 0, bad = 0, offRoute = false, arrived = false, last = null, lastRecalcAt = -Infinity;

        function setRoute(l, s) { line = l; steps = s || []; lastAlong = 0; bad = 0; offRoute = false; arrived = false; last = null; }
        function snap(status, extra) {
            var base = last || { distance_m: null, along_m: null, stepIndex: -1, nextStepIndex: -1, remainingToNext_m: null, remainingTotal_m: line ? line.total : null };
            var o = { status: status, offRoute: offRoute, arrived: arrived, offRouteCount: bad, distance_m: base.distance_m, along_m: base.along_m, stepIndex: base.stepIndex,
                nextStepIndex: base.nextStepIndex, remainingToNext_m: base.remainingToNext_m, remainingTotal_m: base.remainingTotal_m, accuracy_m: null, usable: false };
            if (extra) Object.keys(extra).forEach(function (k) { o[k] = extra[k]; });
            return o;
        }
        setRoute(opts.line || null, opts.steps || []);

        function update(fix) {
            if (!line) return snap('invalid');
            if (!validPos(fix)) return snap('invalid');
            var acc = Number(fix.accuracy);
            var usable = fix.accuracy !== null && fix.accuracy !== undefined && fix.accuracy !== '' && isFinite(acc) && acc >= 0 && acc <= cfg.maxAccuracyM;
            if (!usable) return snap('low_accuracy', { accuracy_m: isFinite(acc) && acc >= 0 ? acc : null });
            var pos = { lat: Number(fix.lat), lng: Number(fix.lng) };
            var loc = locate(line, steps, pos, Math.max(0, lastAlong - cfg.backtrackM));
            var end = line.pts[line.pts.length - 1];
            if (haversine(pos, end) <= cfg.arriveM) arrived = true;
            if (loc.distance_m > cfg.offRouteM) {
                bad++; if (bad >= cfg.offRouteFixes) offRoute = true;
                // keep the last on-route progress: a position off the route says nothing reliable about the step
                return snap(arrived ? 'arrived' : offRoute ? 'off_route' : 'checking', { accuracy_m: acc, usable: true, distance_m: loc.distance_m, fromRoute_m: loc.distance_m });
            }
            bad = 0; offRoute = false; lastAlong = loc.along_m; last = loc;
            return snap(arrived ? 'arrived' : 'ok', { accuracy_m: acc, usable: true, fromRoute_m: loc.distance_m });
        }

        function recalcState() {
            if (!offRoute) return { allowed: false, waitMs: 0, reason: 'on_route' };
            var wait = Math.max(0, lastRecalcAt + cfg.minRecalcMs - now());
            return wait > 0 ? { allowed: false, waitMs: wait, reason: 'too_soon' } : { allowed: true, waitMs: 0, reason: 'ok' };
        }
        /** Call when a recalculation request is actually sent (also when it later fails): this is what enforces "at most one per 15 s". */
        function markRecalc() { lastRecalcAt = now(); }

        return { update: update, setRoute: setRoute, recalcState: recalcState, markRecalc: markRecalc, config: cfg,
            get route() { return line; }, get steps() { return steps; } };
    }

    return { DEFAULTS: DEFAULTS, haversine: haversine, validPos: validPos, prepareLine: prepareLine, project: project, segDist: segDist, buildSteps: buildSteps,
        instructionText: instructionText, locate: locate, createTracker: createTracker };
}));

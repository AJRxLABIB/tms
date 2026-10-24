/* Pure tests for js/tms-guidance.js (geometry, step selection, off-route state machine with an injected clock).   node tests/js/guidance_test.js */
'use strict';
var assert = require('assert');
var G = require('../../js/tms-guidance.js');
var pass = 0, fail = 0;
function t(name, fn) { try { fn(); pass++; console.log('PASS ' + name); } catch (e) { fail++; console.log('FAIL ' + name + ' :: ' + e.message); } }
function near(a, b, tol, msg) { assert.ok(Math.abs(a - b) <= tol, (msg || '') + ' expected ' + b + ' +-' + tol + ' got ' + a); }

// L-shaped route: north 0.01 deg (~1112 m) from A to B, then east 0.01 deg (~1038 m) to C. [lng, lat]
var A = [91.0, 21.0], B = [91.0, 21.01], C = [91.01, 21.01];
var coords = [A, [91.0, 21.005], B, [91.005, 21.01], C];
var line = G.prepareLine(coords);
var legsSteps = [{ steps: [
    { distance_m: 1112, duration_s: 100, name: 'Marine Drive', type: 'depart', modifier: null, location: A },
    { distance_m: 1038, duration_s: 90, name: 'Beach Road', type: 'turn', modifier: 'right', location: B },
    { distance_m: 0, duration_s: 0, name: '', type: 'arrive', modifier: null, location: C }] }];
var steps = G.buildSteps(legsSteps, line);
function fix(lng, lat, acc) { return { lat: lat, lng: lng, accuracy: acc === undefined ? 10 : acc }; }

/* ---------- prepareLine / geometry ---------- */
t('prepareLine: total length is the sum of segments (~2150 m)', function () { near(line.total, 1111.95 + 1038.3, 6); assert.strictEqual(line.pts.length, 5); assert.strictEqual(line.cum[0], 0); });
t('prepareLine: invalid, duplicate points dropped; fewer than 2 points -> null', function () {
    assert.strictEqual(G.prepareLine([[1, 2]]), null); assert.strictEqual(G.prepareLine(null), null); assert.strictEqual(G.prepareLine('x'), null);
    assert.strictEqual(G.prepareLine([[91, 21], [91, 21]]), null);
    var l = G.prepareLine([[91, 21], ['x', 1], [91, 21], [200, 5], [91, 21.01], null, [91.01]]); assert.strictEqual(l.pts.length, 2);
});
t('haversine ~111.2 km per degree latitude', function () { near(G.haversine({ lat: 21, lng: 91 }, { lat: 22, lng: 91 }), 111195, 300); });
t('segDist: point beside a segment, before the start, after the end', function () {
    var a = { lat: 21, lng: 91 }, b = { lat: 21, lng: 91.01 };
    near(G.segDist({ lat: 21.0009, lng: 91.005 }, a, b).d, 100, 1);
    near(G.segDist({ lat: 21, lng: 90.99 }, a, b).d, 1038, 5); assert.strictEqual(G.segDist({ lat: 21, lng: 90.99 }, a, b).t, 0);
    assert.strictEqual(G.segDist({ lat: 21, lng: 91.02 }, a, b).t, 1);
    near(G.segDist({ lat: 21, lng: 91.003 }, a, a).d, 311, 3);   // zero-length segment does not divide by zero
});
t('project: point on the route -> distance ~0 and along matches', function () {
    var p = G.project(line, { lat: 21.005, lng: 91.0 }); near(p.distance_m, 0, 0.5); near(p.along_m, 556, 2);
    var q = G.project(line, { lat: 21.01, lng: 91.005 }); near(q.distance_m, 0, 0.5); near(q.along_m, 1112 + 519, 3);
});
t('project: 30 m east of the first leg', function () { var p = G.project(line, { lat: 21.003, lng: 91.0 + 30 / 103750 }); near(p.distance_m, 30, 1.5); near(p.along_m, 334, 3); });
t('project: beyond the end clamps to the route length', function () { var p = G.project(line, { lat: 21.01, lng: 91.02 }); near(p.along_m, line.total, 0.5); near(p.distance_m, 1038, 6); });
t('project: crossing route prefers the part not behind minAlong', function () {
    // a "P" shape: the route goes east, north, west and back south through the same crossing point
    var loop = G.prepareLine([[91.000, 21.000], [91.010, 21.000], [91.010, 21.010], [91.005, 21.010], [91.005, 20.995]]);
    var X = { lat: 21.0, lng: 91.005 };   // crossing of leg 1 (east) and the last leg (south)
    var early = G.project(loop, X, {}), late = G.project(loop, X, { minAlong: 3000 });
    assert.ok(early.along_m < 600, 'first pass ' + early.along_m); assert.ok(late.along_m > 3000, 'second pass ' + late.along_m);
});

/* ---------- steps ---------- */
t('buildSteps: start positions follow cumulative step distances, scaled to the polyline', function () {
    assert.strictEqual(steps.length, 3); near(steps[0].startAlong_m, 0, 0.001);
    near(steps[1].startAlong_m, 1112 * line.total / 2150, 0.01); near(steps[2].startAlong_m, line.total, 0.01); assert.strictEqual(steps[2].legIndex, 0);
});
t('buildSteps: legs are concatenated and indexed; bad steps skipped; no steps -> []', function () {
    var two = G.buildSteps([{ steps: [{ distance_m: 100, location: [91, 21], type: 'depart' }, { distance_m: 0, location: [91, 21.001], type: 'arrive' }] },
        { steps: [{ distance_m: 50, location: [91, 21.001], type: 'depart' }, { distance_m: 0, location: ['x', 1], type: 'turn' }, { distance_m: 0, location: [91, 21.002], type: 'arrive' }] }], null);
    assert.deepStrictEqual(two.map(function (s) { return [s.legIndex, s.type, s.startAlong_m]; }), [[0, 'depart', 0], [0, 'arrive', 100], [1, 'depart', 100], [1, 'arrive', 150]]);
    assert.deepStrictEqual(G.buildSteps([], line), []); assert.deepStrictEqual(G.buildSteps(null, line), []); assert.deepStrictEqual(G.buildSteps([{}, { steps: 'x' }], line), []);
});
t('instructionText: common maneuvers', function () {
    var T = G.instructionText;
    assert.strictEqual(T({ type: 'depart', name: 'Marine Drive' }), 'Start on Marine Drive');
    assert.strictEqual(T({ type: 'turn', modifier: 'right', name: 'Beach Road' }), 'Turn right onto Beach Road');
    assert.strictEqual(T({ type: 'turn', modifier: 'sharp left', name: '' }), 'Turn sharp left');
    assert.strictEqual(T({ type: 'turn', modifier: 'uturn', name: '' }), 'Make a U-turn');
    assert.strictEqual(T({ type: 'turn', modifier: 'straight', name: 'X' }), 'Continue straight on X');
    assert.strictEqual(T({ type: 'new name', name: 'Y' }), 'Continue onto Y');
    assert.strictEqual(T({ type: 'roundabout', exit: 2, name: 'Z' }), 'At the roundabout, take exit 2 onto Z');
    assert.strictEqual(T({ type: 'roundabout' }), 'At the roundabout, follow the road');
    assert.strictEqual(T({ type: 'fork', modifier: 'slight left', name: '' }), 'Keep left at the fork');
    assert.strictEqual(T({ type: 'merge', modifier: 'slight right', name: '' }), 'Merge right');
    assert.strictEqual(T({ type: 'arrive', modifier: 'left' }, { destName: 'Laboni Beach' }), 'Arrive at Laboni Beach, it is on the left');
    assert.strictEqual(T({ type: 'arrive' }), 'Arrive at your destination');
    assert.strictEqual(T({ type: 'something_new', modifier: 'left', name: '' }), 'Something new left');
    assert.strictEqual(T(null), '');
});
t('locate: first leg, middle', function () {
    var l = G.locate(line, steps, { lat: 21.005, lng: 91.0 }); assert.strictEqual(l.stepIndex, 0); assert.strictEqual(l.nextStepIndex, 1);
    near(l.remainingToNext_m, 556, 4); near(l.remainingTotal_m, line.total - 556, 4);
});
t('locate: after the corner the current step is the turn and the next is the arrival', function () {
    var l = G.locate(line, steps, { lat: 21.01, lng: 91.005 }); assert.strictEqual(l.stepIndex, 1); assert.strictEqual(l.nextStepIndex, 2); near(l.remainingToNext_m, 519, 5);
});
t('locate: at the very start the current step is depart, next is the turn', function () { var l = G.locate(line, steps, { lat: 21.0, lng: 91.0 }); assert.strictEqual(l.stepIndex, 0); assert.strictEqual(l.nextStepIndex, 1); });
t('locate: at the end there is no next manoeuvre', function () { var l = G.locate(line, steps, { lat: 21.01, lng: 91.01 }); assert.strictEqual(l.nextStepIndex, -1); assert.strictEqual(l.remainingToNext_m, null); near(l.remainingTotal_m, 0, 1); });
t('locate: within 5 m of a manoeuvre it counts as reached (consistent current/next), 12 m before it does not', function () {
    var l = G.locate(line, steps, { lat: 21.01 - 3 / 111195, lng: 91.0 }); assert.strictEqual(l.stepIndex, 1); assert.strictEqual(l.nextStepIndex, 2);
    l = G.locate(line, steps, { lat: 21.01 - 12 / 111195, lng: 91.0 }); assert.strictEqual(l.stepIndex, 0); assert.strictEqual(l.nextStepIndex, 1); near(l.remainingToNext_m, 12, 2);
});

/* ---------- tracker ---------- */
function mk(extra) { var clock = { t: 1e6 }; var o = { line: line, steps: steps, now: function () { return clock.t; } }; Object.keys(extra || {}).forEach(function (k) { o[k] = extra[k]; }); var tr = G.createTracker(o); return { tr: tr, clock: clock }; }
var OFF = { lat: 21.003, lng: 91.0 + 150 / 103750 };   // ~150 m east of the first leg

t('tracker: on-route fix is ok with step info and accuracy', function () {
    var s = mk().tr.update(fix(91.0, 21.004, 12)); assert.strictEqual(s.status, 'ok'); assert.strictEqual(s.usable, true); assert.strictEqual(s.accuracy_m, 12); assert.strictEqual(s.stepIndex, 0); assert.strictEqual(s.nextStepIndex, 1);
    near(s.remainingToNext_m, 667, 5); assert.strictEqual(s.offRoute, false);
});
t('tracker: off route needs 3 usable fixes in a row (1 and 2 are only "checking")', function () {
    var m = mk(); m.tr.update(fix(91.0, 21.002));
    var a = m.tr.update(fix(OFF.lng, OFF.lat)), b = m.tr.update(fix(OFF.lng, OFF.lat));
    assert.strictEqual(a.status, 'checking'); assert.strictEqual(b.status, 'checking'); assert.strictEqual(b.offRoute, false); assert.strictEqual(b.offRouteCount, 2);
    var c = m.tr.update(fix(OFF.lng, OFF.lat)); assert.strictEqual(c.status, 'off_route'); assert.strictEqual(c.offRoute, true); near(c.fromRoute_m, 150, 3);
});
t('tracker: a fix back on the route resets the counter and the off-route flag', function () {
    var m = mk(); for (var i = 0; i < 3; i++) m.tr.update(fix(OFF.lng, OFF.lat));
    assert.strictEqual(m.tr.update(fix(OFF.lng, OFF.lat)).offRoute, true);
    var s = m.tr.update(fix(91.0, 21.004)); assert.strictEqual(s.status, 'ok'); assert.strictEqual(s.offRoute, false); assert.strictEqual(s.offRouteCount, 0);
    m.tr.update(fix(OFF.lng, OFF.lat)); m.tr.update(fix(OFF.lng, OFF.lat)); assert.strictEqual(m.tr.update(fix(91.0, 21.004)).offRouteCount, 0);
});
t('tracker: 59 m off is on the route, 61 m off counts', function () {
    var m = mk(); assert.strictEqual(m.tr.update(fix(91.0 + 59 / 103750, 21.004)).offRouteCount, 0);
    assert.strictEqual(m.tr.update(fix(91.0 + 62 / 103750, 21.004)).offRouteCount, 1);
});
t('tracker: low-accuracy fixes never count towards off-route and never clear it', function () {
    var m = mk(); m.tr.update(fix(OFF.lng, OFF.lat)); m.tr.update(fix(OFF.lng, OFF.lat));
    var lo = m.tr.update(fix(91.0, 21.004, 120)); assert.strictEqual(lo.status, 'low_accuracy'); assert.strictEqual(lo.usable, false); assert.strictEqual(lo.accuracy_m, 120); assert.strictEqual(lo.offRouteCount, 2);
    for (var i = 0; i < 5; i++) assert.strictEqual(m.tr.update(fix(OFF.lng, OFF.lat, 80)).offRouteCount, 2);
    assert.strictEqual(m.tr.update(fix(OFF.lng, OFF.lat, 10)).status, 'off_route');
    var cleared = mk(); for (i = 0; i < 3; i++) cleared.tr.update(fix(OFF.lng, OFF.lat));
    assert.strictEqual(cleared.tr.update(fix(91.0, 21.004, 200)).offRoute, true, 'imprecise on-route reading must not clear off-route');
});
t('tracker: accuracy exactly 50 m is usable, 50.1 is not; missing/NaN accuracy is not usable', function () {
    var m = mk(); assert.strictEqual(m.tr.update(fix(91.0, 21.004, 50)).usable, true); assert.strictEqual(m.tr.update(fix(91.0, 21.004, 50.1)).status, 'low_accuracy');
    assert.strictEqual(m.tr.update({ lat: 21.004, lng: 91.0 }).status, 'low_accuracy'); assert.strictEqual(m.tr.update({ lat: 21.004, lng: 91.0, accuracy: NaN }).status, 'low_accuracy');
    assert.strictEqual(m.tr.update({ lat: 21.004, lng: 91.0, accuracy: -1 }).status, 'low_accuracy'); assert.strictEqual(m.tr.update({ lat: 21.004, lng: 91.0, accuracy: null }).status, 'low_accuracy');
});
t('tracker: low-accuracy reading keeps the last good step info (no jump)', function () {
    var m = mk(); var ok = m.tr.update(fix(91.0, 21.004)); var lo = m.tr.update(fix(91.01, 21.01, 300));
    assert.strictEqual(lo.stepIndex, ok.stepIndex); assert.strictEqual(lo.remainingToNext_m, ok.remainingToNext_m);
});
t('tracker: invalid positions are ignored without changing state', function () {
    var m = mk(); m.tr.update(fix(OFF.lng, OFF.lat));
    [null, {}, { lat: NaN, lng: 91, accuracy: 5 }, { lat: 91, lng: 91, accuracy: 5 }, { lat: 21, lng: 181, accuracy: 5 }, { lat: null, lng: null, accuracy: 5 }, { lat: true, lng: true, accuracy: 5 }].forEach(function (f) { assert.strictEqual(m.tr.update(f).status, 'invalid'); });
    assert.strictEqual(m.tr.update(fix(OFF.lng, OFF.lat)).offRouteCount, 2);
    assert.strictEqual(G.createTracker({}).update(fix(91, 21)).status, 'invalid', 'no route yet');
});
t('tracker: progress through the steps (corner passed -> next is arrival)', function () {
    var m = mk(); assert.strictEqual(m.tr.update(fix(91.0, 21.002)).nextStepIndex, 1);
    var s = m.tr.update(fix(91.002, 21.01)); assert.strictEqual(s.stepIndex, 1); assert.strictEqual(s.nextStepIndex, 2);
});
t('tracker: arrival within 25 m of the end is sticky', function () {
    var m = mk(); var a = m.tr.update(fix(91.0095, 21.01)); assert.strictEqual(a.arrived, false);
    var b = m.tr.update(fix(91.01 - 10 / 103750, 21.01)); assert.strictEqual(b.status, 'arrived'); assert.strictEqual(b.arrived, true);
    assert.strictEqual(m.tr.update(fix(91.005, 21.01)).arrived, true);
});
t('tracker: recalculation is refused while on the route', function () { var m = mk(); m.tr.update(fix(91.0, 21.004)); var r = m.tr.recalcState(); assert.strictEqual(r.allowed, false); assert.strictEqual(r.reason, 'on_route'); });
t('tracker: recalculation allowed once off route; then at most once per 15 s (injected clock)', function () {
    var m = mk(); for (var i = 0; i < 3; i++) m.tr.update(fix(OFF.lng, OFF.lat));
    assert.deepStrictEqual(m.tr.recalcState(), { allowed: true, waitMs: 0, reason: 'ok' });
    m.tr.markRecalc();
    var r = m.tr.recalcState(); assert.strictEqual(r.allowed, false); assert.strictEqual(r.reason, 'too_soon'); assert.strictEqual(r.waitMs, 15000);
    m.clock.t += 14999; r = m.tr.recalcState(); assert.strictEqual(r.allowed, false); assert.strictEqual(r.waitMs, 1);
    m.clock.t += 1; assert.strictEqual(m.tr.recalcState().allowed, true);
});
t('tracker: the 15 s limit survives a route replacement (no spamming by re-routing)', function () {
    var m = mk(); for (var i = 0; i < 3; i++) m.tr.update(fix(OFF.lng, OFF.lat));
    m.tr.markRecalc(); m.tr.setRoute(line, steps); for (i = 0; i < 3; i++) m.tr.update(fix(OFF.lng, OFF.lat));
    assert.strictEqual(m.tr.recalcState().allowed, false); m.clock.t += 15000; assert.strictEqual(m.tr.recalcState().allowed, true);
});
t('tracker: setRoute resets progress, off-route and arrival', function () {
    var m = mk(); for (var i = 0; i < 3; i++) m.tr.update(fix(OFF.lng, OFF.lat)); m.tr.update(fix(91.01, 21.01));
    m.tr.setRoute(line, steps); var s = m.tr.update(fix(91.0, 21.002)); assert.strictEqual(s.offRoute, false); assert.strictEqual(s.arrived, false); assert.strictEqual(s.status, 'ok');
});
t('tracker: custom thresholds are honoured', function () {
    var m = mk({ offRouteM: 20, offRouteFixes: 2, maxAccuracyM: 15 });
    assert.strictEqual(m.tr.update(fix(91.0 + 30 / 103750, 21.004, 20)).status, 'low_accuracy');
    m.tr.update(fix(91.0 + 30 / 103750, 21.004, 10)); assert.strictEqual(m.tr.update(fix(91.0 + 30 / 103750, 21.004, 10)).offRoute, true);
});
t('tracker: default thresholds are the documented ones', function () { var c = mk().tr.config; assert.strictEqual(c.offRouteM, 60); assert.strictEqual(c.offRouteFixes, 3); assert.strictEqual(c.maxAccuracyM, 50); assert.strictEqual(c.minRecalcMs, 15000); });
t('tracker: no position history is kept (state is counters plus the last result only)', function () {
    var m = mk(); for (var i = 0; i < 200; i++) m.tr.update(fix(91.0, 21.0 + i * 0.00004));
    assert.deepStrictEqual(Object.keys(m.tr).sort(), ['config', 'markRecalc', 'recalcState', 'route', 'setRoute', 'steps', 'update']);
});
t('tracker: GPS jitter along the route never moves progress backwards past the tolerance', function () {
    var m = mk(); m.tr.update(fix(91.0, 21.008)); var s = m.tr.update(fix(91.0, 21.0079)); assert.ok(s.along_m > 870, 'along ' + s.along_m);
});
t('tracker: loop route does not jump back to the first pass', function () {
    var loop = G.prepareLine([[91.000, 21.000], [91.010, 21.000], [91.010, 21.010], [91.005, 21.010], [91.005, 20.995]]);
    var st = G.buildSteps([{ steps: [{ distance_m: 1038, location: [91.0, 21.0], type: 'depart' }, { distance_m: 1112, location: [91.01, 21.0], type: 'turn', modifier: 'left' },
        { distance_m: 519, location: [91.01, 21.01], type: 'turn', modifier: 'left' }, { distance_m: 1668, location: [91.005, 21.01], type: 'turn', modifier: 'left' }, { distance_m: 0, location: [91.005, 20.995], type: 'arrive' }] }], loop);
    var tr = G.createTracker({ line: loop, steps: st }); tr.update(fix(91.008, 21.0)); tr.update(fix(91.01, 21.005)); tr.update(fix(91.007, 21.01)); tr.update(fix(91.005, 21.006));
    var s = tr.update(fix(91.005, 21.0));   // crossing point, second pass
    assert.ok(s.along_m > 3000, 'second pass along ' + s.along_m);
});

console.log('\n' + pass + ' passed, ' + fail + ' failed'); process.exit(fail ? 1 : 0);

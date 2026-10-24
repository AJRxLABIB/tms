/* Pure tests for js/tms-trip-logic.js and the key/payload helpers in js/tms-trips.js.   node tests/js/trip_logic_test.js */
'use strict';
var assert = require('assert');
var L = require('../../js/tms-trip-logic.js');
var T = require('../../js/tms-trips.js');
var pass = 0, fail = 0;
function t(name, fn) { try { fn(); pass++; console.log('PASS ' + name); } catch (e) { fail++; console.log('FAIL ' + name + ' :: ' + e.message); } }

t('toMin parses valid and rejects invalid', function () { assert.strictEqual(L.toMin('09:30'), 570); assert.strictEqual(L.toMin('24:00'), null); assert.strictEqual(L.toMin(''), null); assert.strictEqual(L.toMin(null), null); });
t('unknown values are labelled unknown', function () { assert.strictEqual(L.fmtMin(null), 'unknown'); assert.strictEqual(L.fmtDist(null), 'unknown'); assert.strictEqual(L.fmtMoney(null), 'unknown'); });
t('fmtMin / fmtDist', function () { assert.strictEqual(L.fmtMin(90), '1 h 30 min'); assert.strictEqual(L.fmtMin(45), '45 min'); assert.strictEqual(L.fmtDist(450), '450 m'); assert.strictEqual(L.fmtDist(4322), '4.3 km'); });
t('dayDate adds days without timezone drift', function () { assert.strictEqual(L.dayDate('2026-03-30', 1), '2026-03-30'); assert.strictEqual(L.dayDate('2026-03-30', 3), '2026-04-01'); assert.strictEqual(L.dayDate(null, 2), null); });
t('dayOf defaults to 1', function () { assert.strictEqual(L.dayOf({}), 1); assert.strictEqual(L.dayOf({ day_index: 3 }), 3); assert.strictEqual(L.dayOf({ day_index: 0 }), 1); });

var S = function (n, a, d, c) { return { name: n, arrive_time: a || null, depart_time: d || null, est_cost: c === undefined ? null : c, lat: 21.4, lng: 91.9 }; };

t('totals: costs sum only known, unknown counted', function () {
    var x = L.dayTotals([S('A', null, null, 10), S('B', null, null, null), S('C', null, null, 0)], null);
    assert.strictEqual(x.cost, 10); assert.strictEqual(x.costUnknownStops, 1); assert.strictEqual(x.costKnownStops, 2);
});
t('totals: no cost at all is null, not 0', function () { assert.strictEqual(L.dayTotals([S('A')], null).cost, null); });
t('totals: planned hours need both times, window needs two times', function () {
    var x = L.dayTotals([S('A', '09:00', '10:30'), S('B', '12:00', null)], null);
    assert.strictEqual(x.plannedMin, 90); assert.strictEqual(x.windowMin, 180);
    var y = L.dayTotals([S('A', '09:00', null)], null); assert.strictEqual(y.plannedMin, null); assert.strictEqual(y.windowMin, null);
});
t('totals: route absent means unknown distance and duration', function () {
    var x = L.dayTotals([S('A'), S('B')], null); assert.strictEqual(x.distance_m, null); assert.strictEqual(x.duration_s, null);
    var y = L.dayTotals([S('A'), S('B')], { distance_m: 4300, duration_s: 600 }); assert.strictEqual(y.distance_m, 4300);
});
t('warnings: overlapping times', function () {
    var w = L.dayWarnings([S('A', '09:00', '11:00'), S('B', '10:00', '11:00')], null);
    assert.ok(w.some(function (x) { return x.type === 'overlap'; }));
});
t('warnings: time earlier than the stop before it', function () {
    var w = L.dayWarnings([S('A', '10:00', null), S('B', '09:00', null)], null); assert.ok(w.some(function (x) { return x.type === 'order'; }));
});
t('warnings: departure before arrival', function () {
    var w = L.dayWarnings([S('A', '10:00', '09:00')], null); assert.ok(w.some(function (x) { return x.type === 'order'; }));
});
t('warnings: travel gap shorter than the real route', function () {
    var w = L.dayWarnings([S('A', '09:00', '10:00'), S('B', '10:10', '11:00')], [{ duration_s: 1800 }]);
    assert.ok(w.some(function (x) { return x.type === 'travel'; }));
});
t('warnings: no travel warning when the gap is enough', function () {
    var w = L.dayWarnings([S('A', '09:00', '10:00'), S('B', '11:00', '12:00')], [{ duration_s: 1800 }]);
    assert.ok(!w.some(function (x) { return x.type === 'travel'; }));
});
t('warnings: NO travel warning without a real route (nothing invented)', function () {
    assert.deepStrictEqual(L.dayWarnings([S('A', '09:00', '10:00'), S('B', '10:05', '11:00')], null), []);
    assert.deepStrictEqual(L.dayWarnings([S('A', '09:00', '10:00'), S('B', '10:05', '11:00')], []), []);
});
t('warnings: no warning when times are missing', function () { assert.deepStrictEqual(L.dayWarnings([S('A'), S('B')], [{ duration_s: 9999 }]), []); });
t('warnings: route travel longer than the planned window', function () {
    var w = L.dayWarnings([S('A', '09:00', null), S('B', '10:00', null)], [{ duration_s: 7200 }]);
    assert.ok(w.some(function (x) { return x.type === 'window'; }));
});
t('coordsParam is lng,lat and skips invalid points', function () {
    assert.strictEqual(L.coordsParam([{ lat: 21.5, lng: 91.9 }, { lat: 'x', lng: 1 }, { lat: 22, lng: 92 }]), '91.900000,21.500000;92.000000,22.000000');
});

// ---- tms-trips.js helpers ----
t('placeKey: internal place id', function () { assert.strictEqual(T.placeKey({ id: 12, lat: 21.2, lng: 92, name: 'X' }), 'p:12'); });
t('placeKey: package/local/tile places never use a numeric id', function () {
    assert.ok(/^geo:/.test(T.placeKey({ id: 5, source: 'package', lat: 21.2, lng: 92, name: 'Pkg' })));
    assert.ok(/^geo:/.test(T.placeKey({ source: 'openstreetmap', lat: 21.2, lng: 92, name: 'Cafe' })));
});
t('placeKey: geo key is stable and valid', function () {
    var k = T.placeKey({ lat: 21.2345678, lng: -92.1, name: 'Café Ünï 1' });
    assert.strictEqual(k, 'geo:21.23457_-92.10000_caf-n-1'); assert.ok(/^[a-z]{1,12}:[A-Za-z0-9._:\/\-]{1,100}$/.test(k));
    assert.strictEqual(k, T.placeKey({ lat: 21.2345678, lng: -92.1, name: 'Café Ünï 1' }));
});
t('placeKey: no coordinates, no key', function () { assert.strictEqual(T.placeKey({ name: 'x' }), ''); });
t('toPayload: whitelisted category and context', function () {
    var p = T.toPayload({ id: 3, lat: 1, lng: 2, name: ' A  B ', category: 'beach', locality: 'Teknaf' }, { destination: 'Cox', country: 'Bangladesh' });
    assert.strictEqual(p.place_key, 'p:3'); assert.strictEqual(p.name, 'A B'); assert.strictEqual(p.category, 'beach'); assert.strictEqual(p.destination_name, 'Cox'); assert.strictEqual(p.country, 'Bangladesh');
    assert.strictEqual(T.toPayload({ lat: 1, lng: 2, name: 'x', category: 'casino' }, {}).category, 'other');
});
t('toPayload never sends an owner', function () { assert.ok(!('user_email' in T.toPayload({ lat: 1, lng: 2, name: 'x', user_email: 'a@b.c' }, {}))); });
t('validCoord rejects 0,0 and out of range', function () { assert.ok(!T.utils.validCoord(0, 0)); assert.ok(!T.utils.validCoord(91, 0)); assert.ok(T.utils.validCoord('21.4', '91.9')); });


/* ---- budget ---- */
var B = function (d, c) { return { name: 'x', day_index: d, est_cost: c, lat: 21.4, lng: 91.9 }; };
t('parseBudgetInput: empty = no budget, numbers parsed, commas allowed', function () {
    assert.deepStrictEqual(L.parseBudgetInput(''), { ok: true, value: null }); assert.deepStrictEqual(L.parseBudgetInput(null), { ok: true, value: null }); assert.deepStrictEqual(L.parseBudgetInput('  '), { ok: true, value: null });
    assert.strictEqual(L.parseBudgetInput('1,500.505').value, 1500.51); assert.strictEqual(L.parseBudgetInput('0').value, 0); assert.strictEqual(L.parseBudgetInput('25000').value, 25000);
});
t('parseBudgetInput: refuses negatives, text, exponent, absurd values', function () {
    ['-5', 'abc', '1e3', '12 000', '--1', '1.2.3', '99999999999', '.5x', 'NaN', 'Infinity'].forEach(function (v) { assert.strictEqual(L.parseBudgetInput(v).ok, false, v); });
    assert.strictEqual(L.parseBudgetInput('9999999999.99').ok, true);
});
t('budgetSummary: no budget -> status none, costs still summed, nothing invented', function () {
    var x = L.budgetSummary([B(1, 10), B(1, null), B(2, 5.5)], null);
    assert.strictEqual(x.status, 'none'); assert.strictEqual(x.spent, 15.5); assert.strictEqual(x.unknown, 1); assert.strictEqual(x.known, 2); assert.strictEqual(x.remaining, null); assert.strictEqual(x.maybeHigher, true);
});
t('budgetSummary: per-day and whole-trip sums, days sorted', function () {
    var x = L.budgetSummary([B(2, 5), B(1, 10), B(1, 20), B(3, null)], 100);
    assert.deepStrictEqual(x.perDay.map(function (d) { return [d.day, d.cost, d.known, d.unknown, d.places]; }), [[1, 30, 2, 0, 2], [2, 5, 1, 0, 1], [3, 0, 0, 1, 1]]);
    assert.strictEqual(x.spent, 35); assert.strictEqual(x.remaining, 65); assert.strictEqual(x.status, 'under'); assert.strictEqual(x.over, 0);
});
t('budgetSummary: over budget, exact, zero budget', function () {
    var o = L.budgetSummary([B(1, 80), B(1, 30)], 100); assert.strictEqual(o.status, 'over'); assert.strictEqual(o.over, 10); assert.strictEqual(o.remaining, 0);
    var e = L.budgetSummary([B(1, 60), B(1, 40)], 100); assert.strictEqual(e.status, 'exact'); assert.strictEqual(e.remaining, 0);
    var z = L.budgetSummary([B(1, 1)], 0); assert.strictEqual(z.status, 'over'); assert.strictEqual(L.budgetSummary([], 0).status, 'exact');
});
t('budgetSummary: floating point drift cannot flip the verdict (0.1 + 0.2 vs 0.3)', function () {
    var x = L.budgetSummary([B(1, 0.1), B(1, 0.2)], 0.3); assert.strictEqual(x.status, 'exact'); assert.strictEqual(x.spent, 0.3);
    var y = L.budgetSummary([B(1, 10.07), B(1, 20.01), B(1, 0.01)], 30.09); assert.strictEqual(y.status, 'exact');
});
t('budgetSummary: unknown, negative or non-numeric costs count as "no cost entered", never as 0 spent silently', function () {
    var x = L.budgetSummary([B(1, null), B(1, ''), B(1, 'x'), B(1, -3), B(1, undefined), B(1, 0)], 10);
    assert.strictEqual(x.unknown, 5); assert.strictEqual(x.known, 1); assert.strictEqual(x.spent, 0);
});
t('budgetSummary: stops without a day count as day 1; empty trip', function () {
    var x = L.budgetSummary([{ est_cost: 4 }, B(1, 6)], 20); assert.strictEqual(x.perDay.length, 1); assert.strictEqual(x.perDay[0].cost, 10);
    var e = L.budgetSummary([], 50); assert.strictEqual(e.spent, 0); assert.strictEqual(e.status, 'under'); assert.strictEqual(e.remaining, 50); assert.deepStrictEqual(e.perDay, []);
    assert.strictEqual(L.budgetSummary(null, null).status, 'none');
});
t('budgetText: wording for none / under / exact / over, with the "may be higher" line', function () {
    var cur = 'BDT';
    var n = L.budgetText(L.budgetSummary([], null), cur); assert.strictEqual(n.headline, 'No budget set'); assert.strictEqual(n.icon, 'info'); assert.strictEqual(n.unknown, ''); assert.ok(/No costs entered yet/.test(n.detail));
    var u = L.budgetText(L.budgetSummary([B(1, 100), B(1, null), B(1, null)], 1000), cur);
    assert.strictEqual(u.icon, 'ok'); assert.ok(/Within budget: 900 BDT left so far/.test(u.headline)); assert.strictEqual(u.unknown, '2 places have no cost entered, so the total may be higher.');
    var one = L.budgetText(L.budgetSummary([B(1, 100), B(1, null)], 1000), cur); assert.strictEqual(one.unknown, '1 place has no cost entered, so the total may be higher.');
    var full = L.budgetText(L.budgetSummary([B(1, 100)], 1000), cur); assert.strictEqual(full.headline, 'Within budget: 900 BDT left'); assert.strictEqual(full.unknown, '');
    var ex = L.budgetText(L.budgetSummary([B(1, 100)], 100), cur); assert.strictEqual(ex.headline, 'Exactly on budget');
    var ov = L.budgetText(L.budgetSummary([B(1, 1200.5), B(1, null)], 1000), cur); assert.strictEqual(ov.icon, 'over'); assert.strictEqual(ov.headline, 'Over budget by 200.5 BDT'); assert.ok(/total may be higher/.test(ov.unknown));
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);

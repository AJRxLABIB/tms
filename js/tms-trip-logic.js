/*
 * tms-trip-logic.js — pure helpers for the personal trip planner (no DOM, no network; node-tested in tests/js/trip_logic_test.js).
 *
 * Everything here is computed ONLY from what the user typed (times, costs) and from real route results passed in by the caller.
 * Unknown stays unknown: a missing time, cost or route yields null / "unknown", never an estimate.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; } else { root.TmsTripLogic = api; }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** 'HH:MM' -> minutes since midnight, or null */
    function toMin(t) {
        var m = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(String(t == null ? '' : t));
        return m ? Number(m[1]) * 60 + Number(m[2]) : null;
    }
    function fmtMin(n) {
        if (n == null || !isFinite(n)) return 'unknown';
        n = Math.round(n); var h = Math.floor(n / 60), m = n % 60;
        return h > 0 ? (h + ' h' + (m ? ' ' + m + ' min' : '')) : (m + ' min');
    }
    function fmtDist(m) {
        if (m == null || !isFinite(m)) return 'unknown';
        return m < 1000 ? Math.round(m) + ' m' : (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km';
    }
    function fmtMoney(n) { return n == null ? 'unknown' : (Math.round(n * 100) / 100).toLocaleString('en', { maximumFractionDigits: 2 }); }

    /** 1-based day of a stop (missing = day 1) */
    function dayOf(s) { var d = Number(s && s.day_index); return d >= 1 ? Math.floor(d) : 1; }
    function stopsOfDay(stops, day) { return (stops || []).filter(function (s) { return dayOf(s) === day; }); }

    /** Date label for day n of a trip that starts on 'YYYY-MM-DD' (UTC maths, so no timezone drift) or null when undated. */
    function dayDate(start, n) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(start || ''));
        if (!m) return null;
        var d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + (n - 1)));
        return isNaN(d.getTime()) ? null : d.toISOString().slice(0, 10);
    }

    /**
     * Per-day totals. cost: sum of the costs the user entered (costUnknown = stops without one). plannedMin: sum of (departure - arrival)
     * for stops that have both. windowMin: first known time to last known time. route: {distance_m, duration_s} or null when no real route.
     */
    function dayTotals(dayStops, route) {
        var cost = 0, known = 0, unknown = 0, planned = 0, hasBoth = false, times = [];
        dayStops.forEach(function (s) {
            if (s.est_cost == null || s.est_cost === '' || !isFinite(Number(s.est_cost))) unknown++; else { cost += Number(s.est_cost); known++; }
            var a = toMin(s.arrive_time), d = toMin(s.depart_time);
            if (a != null) times.push(a); if (d != null) times.push(d);
            if (a != null && d != null && d >= a) { planned += d - a; hasBoth = true; }
        });
        return {
            places: dayStops.length, cost: known ? Math.round(cost * 100) / 100 : null, costKnownStops: known, costUnknownStops: unknown,
            plannedMin: hasBoth ? planned : null,
            windowMin: times.length >= 2 ? Math.max.apply(null, times) - Math.min.apply(null, times) : null,
            distance_m: route && route.distance_m != null ? route.distance_m : null,
            duration_s: route && route.duration_s != null ? route.duration_s : null
        };
    }

    /**
     * Warnings for one day, in list order. legs: [{duration_s}] aligned with consecutive stop pairs (legs[i] = stop i -> stop i+1) or null/[] when
     * no real route exists (then no travel warning is produced: nothing is guessed).
     * Types: order (a time earlier than the stop before it), overlap (a stop starts before the previous one has finished),
     *        travel (the gap between two stops is shorter than the route needs), window (route travel alone exceeds the planned day window).
     */
    function dayWarnings(dayStops, legs) {
        var out = [];
        function nm(s) { return s.name || 'a stop'; }
        dayStops.forEach(function (s, i) {
            var a = toMin(s.arrive_time), d = toMin(s.depart_time);
            if (a != null && d != null && d < a) out.push({ type: 'order', index: i, text: nm(s) + ': departure is before arrival.' });
            if (i === 0) return;
            var p = dayStops[i - 1], pa = toMin(p.arrive_time), pd = toMin(p.depart_time);
            var prevEnd = pd != null ? pd : pa;
            if (a != null && pa != null && a < pa) out.push({ type: 'order', index: i, text: nm(s) + ' is planned to start before ' + nm(p) + ', which comes earlier in the list.' });
            else if (a != null && pd != null && a < pd) out.push({ type: 'overlap', index: i, text: nm(s) + ' starts at ' + s.arrive_time + ' but ' + nm(p) + ' only ends at ' + p.depart_time + '.' });
            var leg = legs && legs[i - 1];
            if (leg && leg.duration_s != null && a != null && prevEnd != null) {
                var gap = a - prevEnd, need = Math.ceil(leg.duration_s / 60);
                if (gap >= 0 && gap < need) out.push({ type: 'travel', index: i, text: 'Not enough time to get from ' + nm(p) + ' to ' + nm(s) + ': you planned ' + fmtMin(gap) + ', the route alone needs about ' + fmtMin(need) + '.' });
            }
        });
        var t = dayTotals(dayStops, null);
        if (legs && legs.length && t.windowMin != null) {
            var sum = 0; legs.forEach(function (l) { if (l && l.duration_s != null) sum += l.duration_s; });
            if (Math.ceil(sum / 60) > t.windowMin) out.push({ type: 'window', index: -1, text: 'Travel between stops (about ' + fmtMin(Math.ceil(sum / 60)) + ') is longer than the time window you planned for this day (' + fmtMin(t.windowMin) + ').' });
        }
        return out;
    }

    /** lng,lat;lng,lat for api/map.php?action=route (longitude first), only valid points */
    function coordsParam(stops) {
        return stops.filter(function (s) { return isFinite(Number(s.lat)) && isFinite(Number(s.lng)); })
            .map(function (s) { return Number(s.lng).toFixed(6) + ',' + Number(s.lat).toFixed(6); }).join(';');
    }

    /* ------------------------------------------------------------------ budget (user-entered numbers only, nothing is estimated) */
    var MAX_BUDGET = 9999999999.99;   // DECIMAL(12,2)
    function cents(n) { return Math.round(Number(n) * 100); }

    /** Text from the budget box -> {ok:true, value:number|null} (null = no budget) or {ok:false}. Accepts "1,500.50"; refuses negatives, text, huge values. */
    function parseBudgetInput(v) {
        if (v == null) return { ok: true, value: null };
        var t = String(v).trim().replace(/,/g, '');
        if (t === '') return { ok: true, value: null };
        if (!/^\d+(\.\d{1,})?$/.test(t)) return { ok: false };
        var n = Number(t);
        if (!isFinite(n) || n > MAX_BUDGET) return { ok: false };
        return { ok: true, value: Math.round(n * 100) / 100 };
    }

    /**
     * Spent = sum of the costs the user typed on the stops. Stops without a cost are COUNTED, never priced, so the total is a floor ("may be higher").
     * budget null/undefined = none set. Money is compared in whole cents so 0.1 + 0.2 style errors cannot flip the result.
     * status: none (no budget), under, exact, over.
     */
    function budgetSummary(stops, budget) {
        var days = {}, total = { places: 0, cost: 0, known: 0, unknown: 0 }, cents_ = 0, order = [];
        (stops || []).forEach(function (s) {
            var d = dayOf(s), x = days[d];
            if (!x) { x = days[d] = { day: d, places: 0, cost: 0, known: 0, unknown: 0, _c: 0 }; order.push(d); }
            x.places++; total.places++;
            if (s.est_cost == null || s.est_cost === '' || !isFinite(Number(s.est_cost)) || Number(s.est_cost) < 0) { x.unknown++; total.unknown++; }
            else { x._c += cents(s.est_cost); cents_ += cents(s.est_cost); x.known++; total.known++; }
        });
        total.cost = cents_ / 100;
        var perDay = order.sort(function (a, b) { return a - b; }).map(function (d) { var x = days[d]; x.cost = x._c / 100; delete x._c; return x; });
        var hasBudget = budget != null && budget !== '' && isFinite(Number(budget)) && Number(budget) >= 0;
        var out = { budget: hasBudget ? Number(budget) : null, spent: total.cost, known: total.known, unknown: total.unknown, places: total.places, perDay: perDay,
            maybeHigher: total.unknown > 0, status: 'none', remaining: null, over: null };
        if (hasBudget) {
            var diff = cents(budget) - cents_;
            out.status = diff > 0 ? 'under' : diff === 0 ? 'exact' : 'over';
            out.remaining = diff > 0 ? diff / 100 : 0;
            out.over = diff < 0 ? -diff / 100 : 0;
        }
        return out;
    }

    /** Plain-language lines for the budget card. icon: ok | over | info (the page pairs it with a symbol, so colour is never the only signal). */
    function budgetText(sum, currency) {
        var cur = currency ? ' ' + currency : '';
        var m = function (n) { return fmtMoney(n) + cur; };
        var unknown = sum.unknown > 0 ? sum.unknown + (sum.unknown === 1 ? ' place has' : ' places have') + ' no cost entered, so the total may be higher.' : '';
        var spent = sum.known ? 'Costs you entered: ' + m(sum.spent) + '.' : 'No costs entered yet.';
        if (sum.status === 'none') return { icon: 'info', headline: 'No budget set', detail: spent, unknown: unknown };
        if (sum.status === 'over') return { icon: 'over', headline: 'Over budget by ' + m(sum.over), detail: spent + ' Budget: ' + m(sum.budget) + '.', unknown: unknown };
        if (sum.status === 'exact') return { icon: 'ok', headline: 'Exactly on budget', detail: spent + ' Budget: ' + m(sum.budget) + '.', unknown: unknown };
        return { icon: 'ok', headline: 'Within budget: ' + m(sum.remaining) + ' left' + (sum.unknown ? ' so far' : ''), detail: spent + ' Budget: ' + m(sum.budget) + '.', unknown: unknown };
    }

    return { toMin: toMin, fmtMin: fmtMin, fmtDist: fmtDist, fmtMoney: fmtMoney, dayOf: dayOf, stopsOfDay: stopsOfDay, dayDate: dayDate,
        dayTotals: dayTotals, dayWarnings: dayWarnings, coordsParam: coordsParam,
        parseBudgetInput: parseBudgetInput, budgetSummary: budgetSummary, budgetText: budgetText, MAX_BUDGET: MAX_BUDGET };
}));

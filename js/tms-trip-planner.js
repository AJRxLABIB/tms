/*
 * tms-trip-planner.js — day-by-day personal trip planner (frontend/trip-planner.php).
 *
 * Data model: the trip object from api/map.php (itinerary_get). stops are kept sorted by day (stable), exactly like the server stores them.
 * Writes: itinerary_save (whole trip, CSRF) for edits, itinerary_move_stop / itinerary_duplicate_day for ordering. Ownership is enforced
 * on the server for every call. Warnings and totals use ONLY the times/costs the user typed and real route results (api/map.php route).
 * When there is no route, distance/duration show "unknown" and no line is drawn. Every string from data is inserted with textContent.
 */
(function () {
    'use strict';
    var host = document.getElementById('planner');
    if (!host || !window.TmsTrips || !window.TmsTripLogic) return;
    var TT = window.TmsTrips, L = window.TmsTripLogic;
    var OPT = window.TmsTripOptimize, GD = window.TmsGuidance;   // optional modules: the planner still works without them
    var trip;
    try { trip = JSON.parse(document.getElementById('tripData').textContent); } catch (e) { return; }
    var MAX_STOPS = 100, MAX_DAYS = 60;
    var state = { day: 1, routes: {}, dirty: false, saving: false, timer: null, cid: 0, focus: null, routeSeq: 0, order: null, orderSeq: 0 };
    var ui = {};   // live nodes that are refreshed without a full re-render

    function h(tag, attrs, kids) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === undefined || v === null || v === false) return;
            if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v;
            else if (k === 'on') Object.keys(v).forEach(function (ev) { n.addEventListener(ev, v[ev]); });
            else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'hidden' || k === 'type' || k === 'min' || k === 'max' || k === 'step' || k === 'maxLength') n[k] = v;
            else n.setAttribute(k, v === true ? '' : v);
        });
        [].concat(kids || []).forEach(function (c) { if (c == null) return; n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return n;
    }
    function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
    function $(id) { return document.getElementById(id); }
    function say(msg, kind) { var s = $('plannerStatus'); s.textContent = msg || ''; s.className = 'tms-tr-status' + (kind === 'err' ? ' is-err' : kind === 'ok' ? ' is-ok' : ''); }

    /* ------------------------------------------------------------------ model */
    function norm() {   // ids for the DOM, day defaults, stable sort by day
        trip.stops.forEach(function (s, i) { if (!s._cid) s._cid = ++state.cid; s.day_index = L.dayOf(s); s._i = i; });
        trip.stops.sort(function (a, b) { return a.day_index - b.day_index || a._i - b._i; });
        trip.stops.forEach(function (s, i) { s.position = i + 1; });
    }
    function dayStops(d) { return L.stopsOfDay(trip.stops, d || state.day); }
    function dayLabel(n) {
        var iso = L.dayDate(trip.start_date, n);
        if (!iso) return null;
        try { return new Date(iso + 'T00:00:00Z').toLocaleDateString('en', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }); } catch (e) { return iso; }
    }
    function payload() {
        var b = { id: trip.id, title: trip.title, mode: trip.mode, planner: true, destination_name: trip.destination_name || '', notes: trip.notes || '', status: trip.status || 'planned',
            start_date: trip.start_date || null, end_date: trip.end_date || null,
            stops: trip.stops.map(function (s) { return TT.utils.tripStopFrom(s); }) };
        if (!trip.start_date) b.day_count = trip.days;
        if (trip.budget_ready) { b.budget_total = trip.budget_total == null ? null : trip.budget_total; b.budget_currency = trip.budget_currency || 'BDT'; }   // only once migration 046 is applied
        return b;
    }

    /* ------------------------------------------------------------------ saving */
    function scheduleSave() { state.dirty = true; say('Unsaved changes...', ''); clearTimeout(state.timer); state.timer = setTimeout(flush, 700); }
    function flush() {
        clearTimeout(state.timer);
        if (state.saving) return state.savingP.then(flush);
        if (!state.dirty) return Promise.resolve(true);
        state.dirty = false; state.saving = true; say('Saving...', '');
        state.savingP = TT.call('itinerary_save', { body: payload() }).then(function () {
            state.saving = false; if (!state.dirty) say('All changes saved.', 'ok'); return true;
        }).catch(function (e) { state.saving = false; state.dirty = true; say('Not saved: ' + e.message, 'err'); return false; });
        return state.savingP;
    }
    window.addEventListener('beforeunload', function (e) { if (state.dirty || state.saving) { e.preventDefault(); e.returnValue = ''; } });

    /* ------------------------------------------------------------------ details form */
    function renderDetails() {
        var f = $('detailsForm'); clear(f);
        function field(id, label, input, wide) { return h('div', { class: 'tms-tr-field' + (wide ? ' is-wide' : '') }, [h('label', { for: id, text: label }), input]); }
        var title = h('input', { class: 'tms-tr-input', id: 'dTitle', type: 'text', maxLength: 120, required: true, value: trip.title });
        var dest = h('input', { class: 'tms-tr-input', id: 'dDest', type: 'text', maxLength: 160, value: trip.destination_name || '' });
        var start = h('input', { class: 'tms-tr-input', id: 'dStart', type: 'date', value: trip.start_date || '' });
        var end = h('input', { class: 'tms-tr-input', id: 'dEnd', type: 'date', value: trip.end_date || '' });
        var days = h('input', { class: 'tms-tr-input', id: 'dDays', type: 'number', min: 1, max: MAX_DAYS, value: trip.start_date ? '' : String(trip.days), disabled: !!trip.start_date });
        var mode = h('select', { class: 'tms-tr-input', id: 'dMode' }, [['driving', 'Driving'], ['walking', 'Walking'], ['cycling', 'Cycling']].map(function (m) { return h('option', { value: m[0], text: m[1] }); }));
        mode.value = trip.mode;
        var notes = h('textarea', { class: 'tms-tr-input', id: 'dNotes', maxLength: 2000 }); notes.value = trip.notes || '';
        var done = h('input', { type: 'checkbox', id: 'dDone', checked: trip.status === 'completed' });
        function syncDays() { days.disabled = !!(start.value || end.value); }
        start.addEventListener('input', syncDays); end.addEventListener('input', syncDays);
        var btn = h('button', { class: 'tms-tr-btn tms-tr-btn-primary', type: 'submit', text: 'Save trip details' });
        [field('dTitle', 'Trip name', title), field('dDest', 'Destination', dest), field('dStart', 'Start date', start), field('dEnd', 'End date (up to 60 days)', end),
         field('dDays', 'Number of days (when no dates are set)', days), field('dMode', 'Travel mode for routes', mode),
         field('dNotes', 'Trip notes', notes, true),
         h('div', { class: 'tms-tr-field is-wide' }, [h('label', null, [done, ' Mark this trip as completed'])]),
         h('div', { class: 'tms-tr-field is-wide' }, [btn])].forEach(function (n) { f.appendChild(n); });
        f.onsubmit = function (e) {
            e.preventDefault(); btn.disabled = true;
            var prev = { title: trip.title, destination_name: trip.destination_name, start_date: trip.start_date, end_date: trip.end_date, days: trip.days, mode: trip.mode, notes: trip.notes, status: trip.status };
            trip.title = title.value.trim(); trip.destination_name = dest.value.trim(); trip.start_date = start.value || null; trip.end_date = end.value || null;
            trip.mode = mode.value; trip.notes = notes.value; trip.status = done.checked ? 'completed' : 'planned';
            if (trip.start_date && trip.end_date) { var span = Math.round((Date.parse(trip.end_date) - Date.parse(trip.start_date)) / 864e5) + 1; trip.days = span > 0 ? Math.min(span, MAX_DAYS) : trip.days; }
            else if (!trip.start_date && !trip.end_date) trip.days = Math.max(1, Math.min(MAX_DAYS, parseInt(days.value, 10) || trip.days));
            clearTimeout(state.timer); state.dirty = true;
            TT.call('itinerary_save', { body: payload() }).then(function () {
                state.dirty = false; say('Trip details saved.', 'ok'); state.routes = {}; state.day = Math.min(state.day, trip.days);
                renderAll();
            }).catch(function (err) { Object.keys(prev).forEach(function (k) { trip[k] = prev[k]; }); state.dirty = false; btn.disabled = false; say('Not saved: ' + err.message, 'err'); });
        };
        $('detailsSummary').textContent = '(' + (trip.destination_name ? trip.destination_name + ', ' : '') + trip.days + ' day' + (trip.days === 1 ? '' : 's') + ')';
        $('tripTitleH').textContent = trip.title;
    }

    /* ------------------------------------------------------------------ day tabs */
    function renderTabs() {
        var tabs = $('dayTabs'); clear(tabs);
        for (var n = 1; n <= trip.days; n++) (function (d) {
            var lab = dayLabel(d), count = dayStops(d).length;
            var b = h('button', { type: 'button', class: 'tms-tr-day', role: 'tab', id: 'dayTab' + d, 'aria-selected': d === state.day ? 'true' : 'false', 'aria-controls': 'dayPanel', tabindex: d === state.day ? '0' : '-1' },
                ['Day ' + d, h('small', { text: (lab ? lab + ' · ' : '') + count + (count === 1 ? ' place' : ' places') })]);
            b.addEventListener('click', function () { selectDay(d); });
            b.addEventListener('keydown', function (e) {
                var to = e.key === 'ArrowRight' ? d + 1 : e.key === 'ArrowLeft' ? d - 1 : e.key === 'Home' ? 1 : e.key === 'End' ? trip.days : 0;
                if (to >= 1 && to <= trip.days) { e.preventDefault(); selectDay(to); var nb = $('dayTab' + to); if (nb) nb.focus(); }
            });
            tabs.appendChild(b);
        })(n);
        $('dayPanel').setAttribute('aria-labelledby', 'dayTab' + state.day);
    }
    function selectDay(d) { state.day = d; state.order = null; renderTabs(); renderPanel(); refreshRoute(); clear($('addList')); $('addStatus').textContent = ''; }

    /* ------------------------------------------------------------------ day panel */
    function renderPanel() {
        var p = $('dayPanel'); clear(p);
        var list = dayStops();
        var lab = dayLabel(state.day);
        p.appendChild(h('h2', { class: 'tms-tr-title', style: 'font-size:20px;', text: 'Day ' + state.day + (lab ? ' · ' + lab : '') }));
        ui.totals = h('div', { class: 'tms-tr-totals' }); p.appendChild(ui.totals);
        var tools = h('div', { class: 'tms-tr-row', style: 'margin-bottom:10px;' });
        if (!trip.start_date && trip.days < MAX_DAYS) tools.appendChild(h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', text: 'Add a day', on: { click: addDay } }));
        if (list.length && trip.days > 1) {
            var sel = h('select', { class: 'tms-tr-input', id: 'dupTo', style: 'width:auto;min-height:36px;', 'aria-label': 'Copy this day to which day' });
            for (var d = 1; d <= trip.days; d++) if (d !== state.day) sel.appendChild(h('option', { value: String(d), text: 'Day ' + d }));
            tools.appendChild(sel);
            tools.appendChild(h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', text: 'Copy this day to the selected day', on: { click: function () { dupDay(Number(sel.value)); } } }));
        }
        if (OPT && list.length >= 3) tools.appendChild(h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', id: 'orderBtn', 'aria-expanded': state.order && state.order.day === state.day ? 'true' : 'false', 'aria-controls': 'orderHost', text: 'Suggest a better order', on: { click: openOrder } }));
        p.appendChild(tools);
        ui.orderHost = h('div', { id: 'orderHost' }); p.appendChild(ui.orderHost);
        if (state.order && state.order.day === state.day) renderOrderBox();
        if (!list.length) {
            p.appendChild(h('div', { class: 'tms-tr-card tms-tr-empty' }, [h('span', { class: 'fa fa-map-signs', 'aria-hidden': 'true' }), h('p', { text: 'No places on this day yet. Search below, or add places from your saved places.' })]));
        } else {
            ui.legs = [];
            var ol = h('ol', { class: 'tms-tr-timeline', 'aria-label': 'Stops on day ' + state.day });
            list.forEach(function (s, i) {
                ol.appendChild(stopCard(s, i, list.length));
                if (i < list.length - 1) { var lg = h('li', { class: 'tms-tr-leg', 'aria-hidden': 'false' }); ui.legs.push(lg); ol.appendChild(lg); }
            });
            p.appendChild(ol);
        }
        refreshDerived();
        if (state.focus) { var sel = '[data-pos="' + state.focus.pos + '"] '; var t = p.querySelector(sel + '.' + state.focus.cls); if (t && !t.disabled) t.focus(); else { var any = p.querySelector(sel + 'button'); if (any) any.focus(); } state.focus = null; }
    }
    function stopCard(s, i, n) {
        var cid = s._cid, nm = s.name;
        var up = h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm btn-up', 'aria-label': 'Move ' + nm + ' earlier in day ' + state.day, disabled: i === 0, on: { click: function () { moveStop(s, 'up'); } } }, [h('span', { class: 'fa fa-arrow-up', 'aria-hidden': 'true' }), ' Up']);
        var dn = h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm btn-down', 'aria-label': 'Move ' + nm + ' later in day ' + state.day, disabled: i === n - 1, on: { click: function () { moveStop(s, 'down'); } } }, [h('span', { class: 'fa fa-arrow-down', 'aria-hidden': 'true' }), ' Down']);
        var rm = h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm tms-tr-btn-danger btn-remove', 'aria-label': 'Remove ' + nm + ' from the trip', on: { click: function () { removeStop(s); } } }, [h('span', { class: 'fa fa-trash', 'aria-hidden': 'true' }), ' Remove']);
        var arrive = h('input', { class: 'tms-tr-input', type: 'time', id: 'a' + cid, value: s.arrive_time || '' });
        var depart = h('input', { class: 'tms-tr-input', type: 'time', id: 'd' + cid, value: s.depart_time || '' });
        var cost = h('input', { class: 'tms-tr-input', type: 'number', id: 'c' + cid, min: 0, step: '0.01', inputmode: 'decimal', placeholder: 'unknown', value: s.est_cost == null ? '' : String(s.est_cost) });
        var note = h('input', { class: 'tms-tr-input', type: 'text', id: 'n' + cid, maxLength: 300, value: s.note || '' });
        function bind(inp, fn) { inp.addEventListener('input', function () { fn(); scheduleSave(); refreshDerived(); }); }
        bind(arrive, function () { s.arrive_time = arrive.value || null; });
        bind(depart, function () { s.depart_time = depart.value || null; });
        bind(cost, function () { var v = cost.value.trim(); s.est_cost = v === '' || !isFinite(Number(v)) || Number(v) < 0 ? null : Number(v); });
        bind(note, function () { s.note = note.value.trim() || null; });
        var done = h('input', { type: 'checkbox', checked: !!s.is_done, on: { change: function () { s.is_done = this.checked; li.classList.toggle('is-done', s.is_done); scheduleSave(); } } });
        var mv = h('select', { class: 'tms-tr-input', style: 'width:auto;min-height:36px;', id: 'm' + cid });
        mv.appendChild(h('option', { value: '', text: 'Move to day...' }));
        for (var d = 1; d <= trip.days; d++) if (d !== state.day) mv.appendChild(h('option', { value: String(d), text: 'Day ' + d }));
        mv.addEventListener('change', function () { if (mv.value) moveStop(s, null, Number(mv.value)); });
        var li = h('li', { class: 'tms-tr-stop' + (s.is_done ? ' is-done' : ''), 'data-n': String(i + 1), 'data-pos': String(s.position) }, [
            h('div', { class: 'tms-tr-stop-head' }, [h('h3', { class: 'tms-tr-stop-name', text: nm }), h('div', { class: 'tms-tr-row' }, [up, dn, rm])]),
            h('div', { class: 'tms-tr-stop-grid' }, [
                h('div', { class: 'tms-tr-field' }, [h('label', { for: 'a' + cid, text: 'Arrive' }), arrive]),
                h('div', { class: 'tms-tr-field' }, [h('label', { for: 'd' + cid, text: 'Leave' }), depart]),
                h('div', { class: 'tms-tr-field' }, [h('label', { for: 'c' + cid, text: 'Estimated cost' }), cost]),
                h('div', { class: 'tms-tr-field' }, [h('label', { for: 'm' + cid, text: 'Day' }), mv]),
                h('div', { class: 'tms-tr-field is-wide' }, [h('label', { for: 'n' + cid, text: 'Note' }), note])]),
            h('label', { class: 'tms-tr-muted', style: 'display:block;margin-top:8px;' }, [done, ' Done'])
        ]);
        return li;
    }

    /* ------------------------------------------------------------------ totals, warnings, legs (recomputed from user data + real routes) */
    function routeInfo() { var r = state.routes[state.day]; return r && r.status === 'ok' ? r.data : null; }
    function refreshDerived() {
        var list = dayStops(), r = routeInfo(), t = L.dayTotals(list, r);
        if (ui.totals) {
            clear(ui.totals);
            [['Places', String(t.places)],
             ['Estimated cost', t.cost == null ? 'unknown' : L.fmtMoney(t.cost) + (t.costUnknownStops ? ' (+' + t.costUnknownStops + ' unknown)' : '')],
             ['Planned time at stops', t.plannedMin == null ? 'unknown' : L.fmtMin(t.plannedMin)],
             ['Distance by route', list.length < 2 ? 'n/a' : (r ? L.fmtDist(t.distance_m) : 'unknown')],
             ['Travel time by route', list.length < 2 ? 'n/a' : (r ? L.fmtMin(t.duration_s / 60) : 'unknown')]
            ].forEach(function (x) { ui.totals.appendChild(h('div', null, [x[0], h('strong', { text: x[1] })])); });
        }
        if (ui.legs) ui.legs.forEach(function (lg, i) {
            var leg = r && r.legs && r.legs[i];
            lg.textContent = leg ? 'Next: ' + L.fmtDist(leg.distance_m) + ', about ' + L.fmtMin(leg.duration_s / 60) + ' by ' + trip.mode : 'Next: distance and travel time unknown';
        });
        updateBudget();
        var w = $('warnings'); clear(w);
        var warns = L.dayWarnings(list, r && r.legs && r.legs.length ? r.legs : null);
        var rs = state.routes[state.day];
        if (warns.length) {
            w.appendChild(h('h2', { class: 'tms-tr-title', style: 'font-size:16px;', text: 'Check day ' + state.day + ' (' + warns.length + ')' }));
            w.appendChild(h('ul', { class: 'tms-tr-warns' }, warns.map(function (x) { return h('li', { text: x.text }); })));
        }
        if (rs && rs.status === 'error' && list.length >= 2) w.appendChild(h('p', { class: 'tms-tr-note', text: 'Travel-time checks are switched off for this day because the route could not be calculated.' }));
    }

    /* ------------------------------------------------------------------ route + map */
    var map = { ctl: null, pins: null, ready: false };
    var ROUTE_SRC = 'tms-trip-route-src';
    function drawRouteLayers(m) {
        if (!m.getStyle()) return;
        if (!m.getSource(ROUTE_SRC)) m.addSource(ROUTE_SRC, { type: 'geojson', data: routeGeo() });
        if (!m.getLayer('tms-trip-route-casing')) m.addLayer({ id: 'tms-trip-route-casing', type: 'line', source: ROUTE_SRC, layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 9, 'line-opacity': 0.9 } });
        if (!m.getLayer('tms-trip-route-line')) m.addLayer({ id: 'tms-trip-route-line', type: 'line', source: ROUTE_SRC, layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#0b7f86', 'line-width': 5, 'line-opacity': 0.95 } });
    }
    function routeGeo() { var r = routeInfo(); return { type: 'FeatureCollection', features: r && r.geometry ? [{ type: 'Feature', properties: {}, geometry: r.geometry }] : [] }; }
    function updateMap(fit) {
        if (!map.ready) return;
        var list = dayStops();
        map.pins.set(list.map(function (s, i) { return { lat: s.lat, lng: s.lng, title: (i + 1) + '. ' + s.name, subtitle: 'Day ' + state.day }; }));
        var src = map.ctl.map.getSource(ROUTE_SRC); if (src) src.setData(routeGeo());
        if (fit && list.length) map.pins.fit();
    }
    function initMap() {
        var note = $('mapNote');
        if (!window.TmsMapPages) { note.textContent = 'The map could not be loaded. The numbered list shows all stops and travel details.'; return; }
        window.TmsMapPages.lazyMount('tripMap', { base: '', lang: document.documentElement.lang || 'en', zoom: 7 }, function (ctl) {
            map.ctl = ctl; map.pins = window.TmsMapPages.pins(ctl, 'trip', { popup: true });
            ctl.onStyleReady(function (m) { drawRouteLayers(m); map.ready = true; updateMap(true); });
            note.textContent = 'Pins are numbered like the list. A line is drawn only when a real route was calculated.';
        }, function () { note.textContent = 'The map could not be loaded. The numbered list shows all stops and travel details.'; note.className = 'tms-tr-status is-err'; });
    }
    function refreshRoute() {
        if (guide.active) stopGuidance('The day or its places changed, so guidance stopped.');
        var list = dayStops(), d = state.day, rt = $('routeText'); clear(rt);
        if (list.length < 2) { delete state.routes[d]; refreshDerived(); updateMap(true); rt.appendChild(h('p', { class: 'tms-tr-muted', text: list.length ? 'Add a second place to see a route.' : 'No places on this day yet.' })); return; }
        if (list.length > 25) { state.routes[d] = { status: 'error', msg: 'A route can have at most 25 stops.' }; refreshDerived(); showRouteText(); return; }
        var coords = L.coordsParam(list), sig = trip.mode + '|' + coords, cur = state.routes[d];
        if (cur && cur.sig === sig && cur.status !== 'loading') { showRouteText(); updateMap(true); return; }
        var seq = ++state.routeSeq; state.routes[d] = { sig: sig, status: 'loading' }; showRouteText();
        TT.call('route', { params: { mode: trip.mode, coords: coords }, timeout: 20000 }).then(function (j) {
            if (seq !== state.routeSeq) return;
            state.routes[d] = { sig: sig, status: 'ok', data: { distance_m: j.distance_m, duration_s: j.duration_s, geometry: j.geometry, legs: j.legs || [], source: j.source } };
        }).catch(function (e) {
            if (seq !== state.routeSeq) return;
            state.routes[d] = { sig: sig, status: 'error', msg: e.message };
        }).then(function () { if (seq !== state.routeSeq) return; refreshDerived(); showRouteText(); updateMap(true); });
    }
    function showRouteText() {
        var rt = $('routeText'); clear(rt); var r = state.routes[state.day]; if (!r) return;
        if (r.status === 'loading') rt.appendChild(h('p', { class: 'tms-tr-muted', text: 'Calculating the route...' }));
        else if (r.status === 'error') rt.appendChild(h('p', { class: 'tms-tr-status is-err', text: 'Route unavailable: ' + r.msg + ' No line is drawn and distances stay unknown.' }));
        else {
            var list = dayStops();
            rt.appendChild(h('p', { class: 'tms-tr-muted', text: 'Route (' + trip.mode + '): ' + L.fmtDist(r.data.distance_m) + ', about ' + L.fmtMin(r.data.duration_s / 60) + '. Source: ' + (r.data.source || 'routing service') + '.' }));
            var ol = h('ol', { class: 'tms-tr-list', 'aria-label': 'Route legs as text' });
            list.slice(0, -1).forEach(function (s, i) { var lg = r.data.legs[i]; ol.appendChild(h('li', { class: 'tms-tr-muted', text: s.name + ' to ' + list[i + 1].name + ': ' + (lg ? L.fmtDist(lg.distance_m) + ', ' + L.fmtMin(lg.duration_s / 60) : 'unknown') })); });
            rt.appendChild(ol);
        }
    }

    /* ------------------------------------------------------------------ structural actions */
    function renderAll() { state.order = null; norm(); renderDetails(); renderTabs(); renderPanel(); refreshRoute(); }
    function applyServer(it, focus) { state.order = null; trip.stops = it.stops; trip.days = it.days || trip.days; state.focus = focus || null; state.routes = {}; norm(); renderTabs(); renderPanel(); refreshRoute(); }
    function moveStop(s, dir, toDay) {
        flush().then(function (ok) {
            if (!ok) return;
            say('Moving...', '');
            var body = { id: trip.id, position: s.position }; if (toDay) body.day = toDay; else body.dir = dir;
            return TT.call('itinerary_move_stop', { body: body }).then(function (j) {
                applyServer(j.itinerary, toDay ? null : { pos: s.position + (dir === 'up' ? -1 : 1), cls: dir === 'up' ? 'btn-up' : 'btn-down' });
                say(toDay ? s.name + ' moved to day ' + toDay + '.' : s.name + ' moved.', 'ok'); TT.announce(toDay ? s.name + ' moved to day ' + toDay : s.name + ' moved ' + (dir === 'up' ? 'earlier' : 'later'));
            }).catch(function (e) { say('Not moved: ' + e.message, 'err'); });
        });
    }
    function removeStop(s) {
        var i = trip.stops.indexOf(s); if (i < 0) return;
        state.order = null;
        trip.stops.splice(i, 1); state.dirty = true; renderTabs(); renderPanel(); refreshRoute(); flush().then(function () { TT.announce(s.name + ' removed'); });
        var nx = $('dayPanel').querySelector('.btn-remove, .tms-tr-card button'); if (nx) nx.focus();
    }
    function dupDay(to) {
        flush().then(function (ok) {
            if (!ok) return;
            TT.call('itinerary_duplicate_day', { body: { id: trip.id, from_day: state.day, to_day: to } }).then(function (j) {
                applyServer(j.itinerary); state.day = to; renderTabs(); renderPanel(); refreshRoute(); say('Day copied.', 'ok'); TT.announce('Day copied to day ' + to);
            }).catch(function (e) { say('Not copied: ' + e.message, 'err'); });
        });
    }
    function addDay() {
        if (trip.start_date || trip.days >= MAX_DAYS) return;
        trip.days++; state.dirty = true; renderDetails(); renderTabs(); selectDay(trip.days); flush();
    }
    function addPlace(p) {
        if (trip.stops.length >= MAX_STOPS) { $('addStatus').textContent = 'A trip can have up to ' + MAX_STOPS + ' places.'; return; }
        if (!TT.utils.validCoord(p.lat, p.lng) || !(p.name || p.name_bn)) { $('addStatus').textContent = 'This place has no usable location, so it cannot be added.'; return; }
        var key = TT.placeKey(p);
        if (key && dayStops().some(function (s) { return s.place_key === key; })) { $('addStatus').textContent = (p.name || p.name_bn) + ' is already on day ' + state.day + '.'; return; }
        var pid = /^p:(\d+)$/.exec(key);
        trip.stops.push({ place_id: pid ? Number(pid[1]) : null, name: String(p.name || p.name_bn).slice(0, 160), lat: Number(p.lat), lng: Number(p.lng), note: null, day_index: state.day,
            arrive_time: null, depart_time: null, est_cost: null, is_done: false, place_key: key || null, _i: 1e6 });
        state.order = null; norm(); state.dirty = true; renderTabs(); renderPanel(); refreshRoute();
        $('addStatus').textContent = (p.name || p.name_bn) + ' added to day ' + state.day + '.'; TT.announce((p.name || p.name_bn) + ' added to day ' + state.day);
        flush();
    }

    /* ------------------------------------------------------------------ finding places */
    function showResults(items, emptyMsg) {
        var ul = $('addList'); clear(ul);
        items = (items || []).filter(function (p) { return p && TT.utils.validCoord(p.lat, p.lng); });
        if (!items.length) { $('addStatus').textContent = emptyMsg; return; }
        $('addStatus').textContent = items.length + ' place' + (items.length === 1 ? '' : 's') + ' found.';
        items.forEach(function (p) {
            var nm = p.name || p.name_bn;
            ul.appendChild(h('li', { class: 'tms-tr-result' }, [h('span', null, [nm, p.category_label || p.category ? h('span', { class: 'tms-tr-muted', text: ' · ' + (p.category_label || p.category) }) : null]),
                h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', 'aria-label': 'Add ' + nm + ' to day ' + state.day, text: 'Add', on: { click: function () { addPlace(p); } } })]));
        });
    }
    var searchTimer = null, searchSeq = 0;
    $('placeSearch').addEventListener('input', function () {
        var q = this.value.trim(); clearTimeout(searchTimer);
        if (q.length < 2) { clear($('searchResults')); return; }
        searchTimer = setTimeout(function () {
            var seq = ++searchSeq; $('addStatus').textContent = 'Searching...';
            TT.call('search', { params: { q: q, limit: 8 } }).then(function (j) {
                if (seq !== searchSeq) return;
                var ul = $('searchResults'); clear(ul); $('addStatus').textContent = '';
                var items = (j.items || []).filter(function (p) { return TT.utils.validCoord(p.lat, p.lng); });
                if (!items.length) { $('addStatus').textContent = 'No place matched "' + q + '" in our map database.'; return; }
                items.forEach(function (p) {
                    ul.appendChild(h('li', { class: 'tms-tr-result' }, [h('span', null, [p.name || p.name_bn, p.category_label ? h('span', { class: 'tms-tr-muted', text: ' · ' + p.category_label }) : null]),
                        h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', 'aria-label': 'Add ' + (p.name || p.name_bn) + ' to day ' + state.day, text: 'Add', on: { click: function () { addPlace(p); } } })]));
                });
                $('addStatus').textContent = items.length + ' result' + (items.length === 1 ? '' : 's') + '.';
            }).catch(function (e) { if (seq === searchSeq) $('addStatus').textContent = e.message; });
        }, 300);
    });
    $('nearbyBtn').addEventListener('click', function () {
        var list = dayStops(), last = list.length ? list[list.length - 1] : (trip.stops.length ? trip.stops[trip.stops.length - 1] : null);
        if (!last) { $('addStatus').textContent = 'Add a first place, then you can look for places near it.'; return; }
        $('addStatus').textContent = 'Looking near ' + last.name + '...';
        TT.call('nearby', { params: { lat: last.lat, lng: last.lng, radius_m: 5000, limit: 12 } }).then(function (j) { showResults(j.items, 'No places from our database within 5 km of ' + last.name + '.'); })
            .catch(function (e) { $('addStatus').textContent = e.message; });
    });
    $('savedBtn').addEventListener('click', function () {
        $('addStatus').textContent = 'Loading your saved places...';
        TT.call('saved_list', { params: { limit: 200 } }).then(function (j) { showResults(j.items, 'You have no saved places yet. Tap the heart on a place in Explore or the map.'); })
            .catch(function (e) { $('addStatus').textContent = e.message; });
    });

    /* ================================================================== budget (numbers the user typed; nothing is estimated) */
    var CURRENCIES = ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'JPY', 'SGD', 'AED', 'MYR', 'THB'];
    function initBudget() {
        var box = $('budgetBox'); if (!box) return;
        clear(box);
        var card = h('section', { class: 'tms-tr-card tms-bd', 'aria-labelledby': 'bdH' });
        card.appendChild(h('h2', { id: 'bdH', class: 'tms-tr-title', style: 'font-size:18px;', text: 'Trip budget' }));
        if (trip.budget_ready) {
            var cur = trip.budget_currency || 'BDT';
            var opts = CURRENCIES.indexOf(cur) < 0 ? [cur].concat(CURRENCIES) : CURRENCIES;
            var inp = h('input', { class: 'tms-tr-input', id: 'bTotal', type: 'text', inputmode: 'decimal', autocomplete: 'off', maxLength: 16, placeholder: 'No budget set', 'aria-describedby': 'bErr', value: trip.budget_total == null ? '' : String(trip.budget_total) });
            var sel = h('select', { class: 'tms-tr-input', id: 'bCur' }, opts.map(function (c) { return h('option', { value: c, text: c }); }));
            sel.value = cur;
            var err = h('p', { id: 'bErr', class: 'tms-tr-muted', style: 'min-height:1.2em;margin:4px 0 0;' });
            inp.addEventListener('input', function () {
                var r = L.parseBudgetInput(inp.value);
                if (!r.ok) { inp.setAttribute('aria-invalid', 'true'); err.textContent = 'Enter a plain number such as 25000 (zero or more), or leave it empty for no budget.'; return; }
                inp.removeAttribute('aria-invalid'); err.textContent = '';
                trip.budget_total = r.value; scheduleSave(); updateBudget();
            });
            sel.addEventListener('change', function () { trip.budget_currency = sel.value; scheduleSave(); updateBudget(); });
            card.appendChild(h('div', { class: 'tms-tr-row' }, [
                h('div', { class: 'tms-tr-field' }, [h('label', { for: 'bTotal', text: 'Budget for the whole trip' }), inp]),
                h('div', { class: 'tms-tr-field' }, [h('label', { for: 'bCur', text: 'Currency' }), sel])]));
            card.appendChild(err);
        } else {
            card.appendChild(h('p', { class: 'tms-tr-note', text: 'Setting a budget needs a database update that has not been applied yet (migration 046). The totals of the costs you entered still work.' }));
        }
        ui.budgetOut = h('div', { id: 'bOut' }); card.appendChild(ui.budgetOut);
        ui.budgetLive = h('div', { class: 'tms-sr-only', role: 'status', 'aria-live': 'polite' }); card.appendChild(ui.budgetLive);
        card.appendChild(h('p', { class: 'tms-tr-muted', style: 'margin:8px 0 0;font-size:13px;', text: 'Only the costs you typed on places are added up. Nothing is looked up or estimated. Costs on places are treated as being in the trip currency.' }));
        box.appendChild(card);
        updateBudget();
    }
    function updateBudget() {
        if (!ui.budgetOut) return;
        var cur = trip.budget_ready ? (trip.budget_currency || 'BDT') : '';
        var sum = L.budgetSummary(trip.stops, trip.budget_ready ? trip.budget_total : null), tx = L.budgetText(sum, cur);
        var icon = tx.icon === 'over' ? 'fa-exclamation-triangle' : tx.icon === 'ok' ? 'fa-check-circle' : 'fa-info-circle';
        var out = ui.budgetOut; clear(out);
        out.appendChild(h('p', { class: 'tms-bd-head is-' + tx.icon }, [h('span', { class: 'fa ' + icon, 'aria-hidden': 'true' }), ' ', h('strong', { text: tx.headline })]));
        out.appendChild(h('p', { class: 'tms-tr-muted', style: 'margin:0 0 6px;', text: tx.detail }));
        if (sum.budget != null && sum.budget > 0) {
            var pct = Math.round(sum.spent / sum.budget * 100);
            out.appendChild(h('progress', { class: 'tms-bd-bar', max: '100', value: String(Math.min(100, pct)), 'aria-label': 'Share of the budget used by the costs you entered' }));
            out.appendChild(h('span', { class: 'tms-tr-muted', style: 'margin-left:8px;font-size:13px;', text: pct + '% of budget' + (pct > 100 ? ' (over)' : '') }));
        }
        if (tx.unknown) out.appendChild(h('p', { class: 'tms-tr-note', style: 'margin:8px 0 0;', text: tx.unknown }));
        if (sum.perDay.length) {
            var ul = h('ul', { class: 'tms-tr-list', 'aria-label': 'Costs you entered, by day', style: 'margin:8px 0 0;' });
            sum.perDay.forEach(function (d) {
                ul.appendChild(h('li', { class: 'tms-tr-muted', text: 'Day ' + d.day + ': ' + (d.known ? L.fmtMoney(d.cost) + (cur ? ' ' + cur : '') : 'no costs entered') + (d.known && d.unknown ? ' (+' + d.unknown + ' without a cost)' : d.unknown ? ' (' + d.unknown + (d.unknown === 1 ? ' place' : ' places') + ')' : '') }));
            });
            out.appendChild(ul);
        }
        if (ui.budgetLive && state.lastBudgetStatus !== sum.status) { if (state.lastBudgetStatus !== undefined) ui.budgetLive.textContent = tx.headline; state.lastBudgetStatus = sum.status; }
    }

    /* ================================================================== itinerary order suggestion (straight-line distance only) */
    function stopPts(list) { return list.map(function (s) { return { lat: s.lat, lng: s.lng }; }); }
    function openOrder() {
        if (!OPT) return;
        if (state.order && state.order.day === state.day) { state.order = null; renderOrderBox(); var b = $('orderBtn'); if (b) b.setAttribute('aria-expanded', 'false'); return; }
        state.order = { day: state.day, mode: 'suggest', moveFirst: false };
        computeOrder();
        var bt = $('orderBtn'); if (bt) bt.setAttribute('aria-expanded', 'true');
    }
    function computeOrder() {
        var o = state.order, list = dayStops();
        o.list = list.slice(); o.res = OPT.suggestOrder(stopPts(list), { moveFirst: o.moveFirst }); o.road = null;
        renderOrderBox();
        if (o.res.ok && o.res.changed) roadCompare(o);
    }
    /** Optional: real road numbers for the current and the suggested order, from the existing route action. Failure is shown as failure. */
    function roadCompare(o) {
        var list = o.list;
        if (list.length > 25) { o.road = { status: 'skip', msg: 'Road distances are skipped for more than 25 places.' }; return renderOrderRoad(); }
        var seq = ++state.orderSeq;
        o.road = { status: 'loading' }; renderOrderRoad();
        var newList = OPT.applyOrder(list, o.res.order);
        function fetchRoute(arr) { return TT.call('route', { params: { mode: trip.mode, coords: L.coordsParam(arr) }, timeout: 20000 }); }
        var cur = routeInfo();   // reuse the route already shown for this day when it is the same order
        var curP = cur && state.routes[state.day] && state.routes[state.day].sig === trip.mode + '|' + L.coordsParam(list) ? Promise.resolve(cur) : fetchRoute(list);
        Promise.all([curP, fetchRoute(newList)]).then(function (r) {
            if (seq !== state.orderSeq || state.order !== o) return;
            o.road = { status: 'ok', cur: { d: r[0].distance_m, t: r[0].duration_s }, neu: { d: r[1].distance_m, t: r[1].duration_s } };
            renderOrderRoad();
        }).catch(function (e) {
            if (seq !== state.orderSeq || state.order !== o) return;
            o.road = { status: 'error', msg: e && e.message ? e.message : 'unknown error' }; renderOrderRoad();
        });
    }
    function renderOrderRoad() {
        var el = $('orderRoad'), o = state.order; if (!el || !o || !o.road) return;
        var r = o.road; clear(el);
        if (r.status === 'loading') el.textContent = 'Checking road distance with the route service...';
        else if (r.status === 'skip') el.textContent = r.msg;
        else if (r.status === 'error') el.textContent = 'Road distance could not be checked (' + r.msg + '). The straight-line numbers above are still correct. Nothing is guessed.';
        else {
            var worse = r.neu.d > r.cur.d;
            el.appendChild(h('span', { text: 'By road (' + trip.mode + '), from the route service: your current order ' + L.fmtDist(r.cur.d) + ', about ' + L.fmtMin(r.cur.t / 60) + '. Suggested order ' + L.fmtDist(r.neu.d) + ', about ' + L.fmtMin(r.neu.t / 60) + '.' + (worse ? ' By road the suggestion is longer, so you may prefer to keep your current order.' : '') }));
        }
    }
    function renderOrderBox() {
        var host = ui.orderHost; if (!host) return; clear(host);
        var o = state.order; if (!o || o.day !== state.day) return;
        var card = h('section', { class: 'tms-tr-card tms-od', 'aria-labelledby': 'odH' });
        card.appendChild(h('h3', { id: 'odH', class: 'tms-tr-title', style: 'font-size:16px;', text: 'Order suggestion for day ' + o.day }));
        function closeBtn() { return h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', text: 'Close', on: { click: function () { state.order = null; renderOrderBox(); var b = $('orderBtn'); if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); } } } }); }
        if (o.mode === 'applied') {
            card.appendChild(h('p', { role: 'status', text: o.saveMsg || 'The new order is applied.' }));
            card.appendChild(h('div', { class: 'tms-tr-row' }, [h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', id: 'orderUndo', text: 'Undo (restore the previous order)', on: { click: undoOrder } }), closeBtn()]));
            host.appendChild(card); return;
        }
        var res = o.res;
        var chk = h('input', { type: 'checkbox', id: 'orderFirst', checked: !!o.moveFirst });
        chk.addEventListener('change', function () { o.moveFirst = chk.checked; computeOrder(); var c = $('orderFirst'); if (c) c.focus(); });
        card.appendChild(h('label', { class: 'tms-tr-muted', style: 'display:block;margin-bottom:8px;', for: 'orderFirst' }, [chk, ' Let the first stop move too']));
        if (!res.ok) { card.appendChild(h('p', { class: 'tms-tr-status is-err', text: res.message })); card.appendChild(closeBtn()); host.appendChild(card); return; }
        if (!res.changed) {
            card.appendChild(h('p', { role: 'status', text: res.reason === 'too_few_stops' ? 'There are too few places on this day to reorder.' : 'No shorter order was found. Your current order is about ' + L.fmtDist(res.currentDistance_m) + ' in straight lines, and that is already as short as this method can get it.' }));
        } else {
            card.appendChild(h('p', { role: 'status', text: 'Straight-line distance between places: now ' + L.fmtDist(res.currentDistance_m) + ', suggested ' + L.fmtDist(res.suggestedDistance_m) + ' (' + L.fmtDist(res.savedDistance_m) + ' shorter).' }));
            var ol = h('ol', { class: 'tms-tr-list', style: 'list-style:decimal;padding-left:24px;gap:4px;', 'aria-label': 'Suggested order of the places' });
            res.order.forEach(function (idx, k) { ol.appendChild(h('li', { text: o.list[idx].name + (idx === k ? '' : ' (was ' + (idx + 1) + ')') + (k === 0 && !o.moveFirst ? ' - stays first' : '') })); });
            card.appendChild(ol);
            card.appendChild(h('p', { class: 'tms-tr-muted', id: 'orderRoad', 'aria-live': 'polite', style: 'font-size:13px;' }));
        }
        card.appendChild(h('p', { class: 'tms-tr-note', text: 'This is only a suggestion. It uses straight-line distance between places, not road time, traffic, one-way streets or opening hours. The arrive and leave times you entered stay with each place, so check them after applying.' }));
        var row = h('div', { class: 'tms-tr-row' });
        if (res.changed) row.appendChild(h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-primary', id: 'orderApply', text: 'Apply this order', on: { click: applyOrderNow } }));
        row.appendChild(closeBtn()); card.appendChild(row);
        host.appendChild(card);
        if (o.road) renderOrderRoad();
    }
    /** Put one day's stops (already a permutation of that day's stops) back into trip.stops in the same slots, then keep the model normalised. */
    function replaceDay(day, newList) {
        var k = 0;
        trip.stops = trip.stops.map(function (s) { return L.dayOf(s) === day ? newList[k++] : s; });
        norm();
    }
    function applyOrderNow() {
        var o = state.order; if (!o || !o.res || !o.res.ok || !o.res.changed) return;
        var list = dayStops();
        var same = list.length === o.list.length && list.every(function (s, i) { return s === o.list[i]; });
        if (!same) { say('The places on this day changed, so the suggestion is out of date. Ask for a new one.', 'err'); state.order = null; renderOrderBox(); return; }
        var neu = OPT.applyOrder(list, o.res.order); if (!neu) { say('The suggested order was not valid, so nothing was changed.', 'err'); return; }
        var prev = list.map(function (s) { return s._cid; }), applied = neu.map(function (s) { return s._cid; });
        replaceDay(o.day, neu);
        state.order = { day: o.day, mode: 'applied', prev: prev, applied: applied, saveMsg: 'New order applied. Saving...' };
        state.routes = {}; state.dirty = true;
        renderPanel(); refreshRoute();
        persistOrder('New order applied and saved.', 'Order applied here, but it is not saved yet');
    }
    function persistOrder(okMsg, failMsg) {
        flush().then(function (ok) {
            if (state.order && state.order.mode === 'applied') { state.order.saveMsg = ok ? okMsg : failMsg + '. Check your connection: it is retried with your next change.'; renderOrderBox(); }
            if (ok) TT.announce(okMsg);
            var u = $('orderUndo'); if (u) u.focus();
        });
    }
    function undoOrder() {
        var o = state.order; if (!o || o.mode !== 'applied') return;
        var list = dayStops(), cids = list.map(function (s) { return s._cid; });
        var unchanged = cids.length === o.applied.length && cids.every(function (c, i) { return c === o.applied[i]; });
        if (!unchanged) { say('The places on this day changed since, so the order cannot be restored automatically.', 'err'); state.order = null; renderOrderBox(); return; }
        var by = {}; list.forEach(function (s) { by[s._cid] = s; });
        replaceDay(o.day, o.prev.map(function (c) { return by[c]; }));
        state.order = null; state.routes = {}; state.dirty = true;
        renderPanel(); refreshRoute();
        flush().then(function (ok) { say(ok ? 'Previous order restored and saved.' : 'Previous order restored here, but it is not saved yet.', ok ? 'ok' : 'err'); TT.announce('Previous order restored'); var b = $('orderBtn'); if (b) b.focus(); });
    }

    /* ================================================================== guidance mode (beta, foreground only) */
    var guide = { active: false, watchId: null, tracker: null, steps: [], line: null, stops: [], legTargets: [], startStop: null, lastFix: null, busy: false, rcTimer: null, band: null, lastStep: -2, lastStatus: '', token: 0, mode: 'driving', progressAlong: null };
    var GD_TEXT = 'Guidance (beta). Works while this page stays open. It is not a replacement for a navigation app.';
    function gd(id) { return document.getElementById(id); }
    function reduceMotion() { try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; } }
    function initGuidance() {
        var host = gd('guidance'); if (!host) return;
        if (!GD) { host.appendChild(h('p', { class: 'tms-tr-muted', text: 'Guidance is not available because its script did not load.' })); return; }
        var card = h('section', { class: 'tms-tr-card tms-gd', 'aria-labelledby': 'gdH' }, [
            h('h2', { id: 'gdH', class: 'tms-tr-title', style: 'font-size:18px;' }, ['Guidance ', h('span', { class: 'tms-tr-badge', text: 'beta' })]),
            h('p', { class: 'tms-tr-note', text: GD_TEXT }),
            h('div', { id: 'gdIntro' }, [
                h('p', { class: 'tms-tr-muted', text: 'Shows the next instruction and the distance to it for the route of the selected day, using your device location.' }),
                h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-primary', id: 'gdStart', text: 'Start guidance', on: { click: gdAsk } })]),
            h('div', { id: 'gdConsent', hidden: true }, [
                h('p', { text: 'Before you start: guidance reads your device location while this page is open. Your browser will ask for permission when you press the button below. Your positions stay in this page\'s memory and are not saved. The only thing ever sent is one pair of coordinates, your current position, if you press "Recalculate from here" so the route service can plan a route. If you press Stop, or leave this page, location tracking ends.' }),
                h('div', { class: 'tms-tr-row' }, [
                    h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-primary', id: 'gdGo', text: 'Allow location and start', on: { click: gdBegin } }),
                    h('button', { type: 'button', class: 'tms-tr-btn', id: 'gdCancel', text: 'Cancel', on: { click: function () { gdShow('intro'); gd('gdStart').focus(); } } })])]),
            h('div', { id: 'gdLiveBox', hidden: true }, [
                h('p', { id: 'gdStatus', class: 'tms-tr-status', role: 'status' }),
                h('div', { class: 'tms-gd-next' }, [h('strong', { id: 'gdNext', class: 'tms-gd-instr' }), h('span', { id: 'gdDist', class: 'tms-gd-dist' })]),
                h('p', { id: 'gdMeta', class: 'tms-tr-muted', style: 'margin:6px 0;' }),
                h('div', { id: 'gdOff', hidden: true, class: 'tms-tr-note' }, [
                    h('span', { id: 'gdOffText' }), ' ',
                    h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-sm', id: 'gdRecalc', text: 'Recalculate from here', on: { click: gdRecalc } })]),
                h('ol', { id: 'gdSteps', class: 'tms-gd-steps', 'aria-label': 'Route steps' }),
                h('div', { class: 'tms-tr-row', style: 'margin-top:10px;' }, [h('button', { type: 'button', class: 'tms-tr-btn', id: 'gdStop', text: 'Stop guidance', on: { click: function () { stopGuidance('Guidance stopped. Your location is no longer being used.'); var b = gd('gdStart'); if (b) b.focus(); } } })])]),
            h('p', { id: 'gdMsg', class: 'tms-tr-muted', role: 'status', 'aria-live': 'polite' }),
            h('div', { id: 'gdSay', class: 'tms-sr-only', 'aria-live': 'polite', 'aria-atomic': 'true' })
        ]);
        host.appendChild(card);
        window.addEventListener('pagehide', function () { stopGuidance(null); });
    }
    function gdShow(which) { gd('gdIntro').hidden = which !== 'intro'; gd('gdConsent').hidden = which !== 'consent'; gd('gdLiveBox').hidden = which !== 'live'; }
    function gdMsg(t) { var m = gd('gdMsg'); if (m) m.textContent = t || ''; }
    function gdSay(t) { var e = gd('gdSay'); if (!e) return; e.textContent = ''; setTimeout(function () { e.textContent = t; }, 60); }
    function gdAsk() {
        gdMsg('');
        var list = dayStops();
        if (list.length < 2) { gdMsg('Add at least two places to this day first. Guidance follows the route between them.'); return; }
        if (list.length > 25) { gdMsg('Guidance can follow a route of at most 25 places.'); return; }
        if (!navigator.geolocation) { gdMsg('This browser cannot provide a location, so guidance is not available here.'); return; }
        gdShow('consent'); gd('gdGo').focus();
    }
    function gdBegin() {
        var list = dayStops(); gdMsg('');
        if (!navigator.geolocation || list.length < 2) { gdShow('intro'); return; }
        stopGuidance(null);
        var token = ++guide.token;
        guide.active = true; guide.stops = list.slice(); guide.mode = trip.mode; guide.startStop = 0; guide.lastStep = -2; guide.band = null; guide.lastStatus = ''; guide.tracker = null; guide.lastFix = null; guide.progressAlong = null;
        guide.legTargets = list.slice(1).map(function (s, i) { return i + 1; });
        gdShow('live'); gd('gdStatus').textContent = 'Asking your device for its location and getting the route...'; gd('gdStatus').className = 'tms-tr-status';
        gd('gdNext').textContent = 'Waiting for your position...'; gd('gdDist').textContent = ''; gd('gdMeta').textContent = ''; gd('gdOff').hidden = true; clear(gd('gdSteps'));
        // The permission prompt appears here, from the button press. Only the newest reading is kept in memory.
        try {
            guide.watchId = navigator.geolocation.watchPosition(function (pos) { if (token === guide.token) gdFix(pos); }, function (err) { if (token === guide.token) gdErr(err); },
                { enableHighAccuracy: true, maximumAge: 2000, timeout: 20000 });
        } catch (e) { stopGuidance('Could not start location tracking in this browser.'); return; }
        TT.call('route', { params: { mode: guide.mode, coords: L.coordsParam(list), steps: '1' }, timeout: 20000 }).then(function (j) {
            if (token !== guide.token || !guide.active) return;
            var line = GD.prepareLine(j.geometry && j.geometry.coordinates), steps = line ? GD.buildSteps(j.legs, line) : [];
            if (!line || !steps.length) { stopGuidance('The route service did not return turn-by-turn steps for this route, so guidance cannot start. The route and distances above still work.'); return; }
            guide.line = line; guide.steps = steps;
            guide.tracker = GD.createTracker({ line: line, steps: steps });
            gdRenderSteps(); gd('gdStatus').textContent = guide.lastFix ? 'Route ready.' : 'Route ready. Waiting for your position...';
            if (guide.lastFix) gdRender(guide.tracker.update(guide.lastFix));
        }).catch(function (e) {
            if (token === guide.token && guide.active) stopGuidance('Guidance could not get a route: ' + (e && e.message ? e.message : 'unknown error') + '. Try again in a moment.');
        });
    }
    function gdErr(err) {
        var c = err && err.code;
        if (c === 1) { stopGuidance('Location permission was denied, so guidance stopped. You can allow location for this site in your browser settings and try again.'); return; }
        var st = gd('gdStatus'); if (!st) return; st.className = 'tms-tr-status is-err';
        st.textContent = c === 3 ? 'No position yet (the request timed out). Still trying. Indoors or with a weak signal this can take a while.'
            : c === 2 ? 'Your device cannot work out a position right now. Still trying.' : 'The location service reported a problem. Still trying.';
    }
    function gdFix(pos) {
        var c = pos && pos.coords; if (!c) return;
        guide.lastFix = { lat: c.latitude, lng: c.longitude, accuracy: c.accuracy };   // only the newest reading, never a list
        if (!guide.tracker) { gd('gdMeta').textContent = 'Position found (accuracy about ' + Math.round(c.accuracy) + ' m). Getting the route...'; return; }
        gdRender(guide.tracker.update(guide.lastFix));
    }
    function gdStopName(step) { var si = step ? guide.legTargets[step.legIndex] : null; return si != null && guide.stops[si] ? guide.stops[si].name : null; }
    function gdRenderSteps() {
        var ol = gd('gdSteps'); if (!ol) return; clear(ol);
        guide.steps.forEach(function (s, i) {
            ol.appendChild(h('li', { id: 'gdStep' + i, class: 'tms-gd-step' }, [GD.instructionText(s, { destName: gdStopName(s) }), s.distance_m > 0 ? h('small', { class: 'tms-tr-muted', text: ' (' + L.fmtDist(s.distance_m) + ')' }) : null]));
        });
    }
    function gdRender(sn) {
        var st = gd('gdStatus'), nx = gd('gdNext'), ds = gd('gdDist'), meta = gd('gdMeta'); if (!st) return;
        if (sn.usable && !sn.offRoute && sn.along_m != null) guide.progressAlong = sn.along_m;
        var acc = sn.accuracy_m != null ? 'Position accuracy: about ' + Math.round(sn.accuracy_m) + ' m.' : '';
        var msg, cls = 'tms-tr-status';
        if (sn.status === 'low_accuracy') { msg = 'Position is too imprecise' + (sn.accuracy_m != null ? ' (about ' + Math.round(sn.accuracy_m) + ' m, 50 m or better is needed)' : ' (no accuracy reported)') + '. Waiting for a better reading. Off-route checks are paused.'; cls += ' is-err'; }
        else if (sn.status === 'checking') msg = 'Checking whether you left the route...';
        else if (sn.status === 'off_route') { msg = 'You seem to be off the route (about ' + L.fmtDist(sn.distance_m) + ' from it).'; cls += ' is-err'; }
        else if (sn.status === 'arrived') { var lastS = guide.steps[guide.steps.length - 1]; msg = 'You have arrived' + (gdStopName(lastS) ? ' at ' + gdStopName(lastS) : '') + ' (within 25 m of the end of this route).' + (guide.legTargets[guide.legTargets.length - 1] < guide.stops.length - 1 ? ' The rest of the day is not guided: use the numbered list for the next place.' : ''); }
        else if (sn.status === 'invalid') msg = 'Waiting for a valid position...';
        else msg = 'Following the route.';
        st.className = cls; st.textContent = msg;
        meta.textContent = [acc, sn.remainingTotal_m != null && sn.along_m != null ? 'Left on this route: ' + L.fmtDist(sn.remainingTotal_m) + ' (measured along the route line).' : ''].filter(Boolean).join(' ');
        var next = sn.nextStepIndex >= 0 ? guide.steps[sn.nextStepIndex] : null, txt = '';
        if (sn.along_m == null) { nx.textContent = 'Waiting for a position on the route...'; ds.textContent = ''; }
        else if (next) { txt = GD.instructionText(next, { destName: gdStopName(next) }); nx.textContent = txt; ds.textContent = 'in ' + L.fmtDist(sn.remainingToNext_m); }
        else { nx.textContent = 'You are at the end of the route.'; ds.textContent = ''; }
        if (sn.stepIndex !== guide.lastStep) {   // highlight the step you are on (text label as well as styling)
            var prev = gd('gdStep' + guide.lastStep);
            if (prev) { prev.classList.remove('is-current'); prev.removeAttribute('aria-current'); var pm = prev.querySelector('.tms-gd-here'); if (pm) prev.removeChild(pm); }
            var cur = gd('gdStep' + sn.stepIndex);
            if (cur && sn.stepIndex >= 0) {
                cur.classList.add('is-current'); cur.setAttribute('aria-current', 'step'); cur.appendChild(h('strong', { class: 'tms-gd-here', text: ' You are on this step' }));
                try { cur.scrollIntoView({ block: 'nearest', behavior: reduceMotion() ? 'auto' : 'smooth' }); } catch (e) { /* older browsers */ }
            }
            guide.lastStep = sn.stepIndex;
        }
        if (txt) {   // spoken: when the next instruction changes, then once each at 500, 200 and 50 m
            var rem = sn.remainingToNext_m, band = rem <= 50 ? 50 : rem <= 200 ? 200 : rem <= 500 ? 500 : 9999;
            if (!guide.band || guide.band.key !== sn.nextStepIndex) { guide.band = { key: sn.nextStepIndex, band: band }; gdSay('Next: ' + txt + ', in ' + L.fmtDist(rem)); }
            else if (band < guide.band.band) { guide.band.band = band; gdSay('In ' + L.fmtDist(rem) + ': ' + txt); }
        }
        if (sn.status !== guide.lastStatus) {
            if (sn.status === 'off_route') gdSay('You seem to be off the route.'); else if (sn.status === 'arrived') gdSay('You have arrived.'); else if (sn.status === 'low_accuracy') gdSay('Position is too imprecise. Waiting for a better reading.');
            guide.lastStatus = sn.status;
        }
        gdRecalcUi(sn);
    }
    function gdRecalcUi(sn) {
        var box = gd('gdOff'), btn = gd('gdRecalc'); if (!box) return;
        clearTimeout(guide.rcTimer);
        var show = !!sn.offRoute && !sn.arrived && !!guide.tracker; box.hidden = !show; if (!show) return;
        var rs = guide.tracker.recalcState();
        gd('gdOffText').textContent = 'You are more than 60 m from the route. You can plan a new route from where you are to the next place.';
        btn.disabled = guide.busy || !rs.allowed;
        btn.textContent = guide.busy ? 'Recalculating...' : rs.allowed ? 'Recalculate from here' : 'Recalculate from here (wait ' + Math.ceil(rs.waitMs / 1000) + ' s)';
        if (!rs.allowed && rs.waitMs > 0 && !guide.busy) guide.rcTimer = setTimeout(function () { if (guide.active && guide.tracker) gdRecalcUi({ offRoute: true, arrived: false }); }, Math.min(rs.waitMs, 1000) + 20);
    }
    /** The next place not reached yet. Before you have been on the route at all, that is the first place of the day. */
    function gdTarget() {
        if (guide.startStop != null && (guide.progressAlong == null || guide.progressAlong < 50)) return guide.startStop;
        var s = guide.lastStep >= 0 ? guide.steps[guide.lastStep] : null, leg = s ? s.legIndex : 0;
        return guide.legTargets[Math.min(leg, guide.legTargets.length - 1)];
    }
    function gdRecalc() {
        if (!guide.active || !guide.tracker || guide.busy) return;
        var rs = guide.tracker.recalcState(); if (!rs.allowed) { gdRecalcUi({ offRoute: true, arrived: false }); return; }
        var fix = guide.lastFix; if (!fix || !(fix.accuracy <= guide.tracker.config.maxAccuracyM)) { gdMsg('Waiting for a more precise position before recalculating.'); return; }
        var T = gdTarget(), target = guide.stops[T]; if (!target) return;
        guide.tracker.markRecalc(); guide.busy = true; gdRecalcUi({ offRoute: true, arrived: false });
        var token = guide.token;
        // The single coordinate pair that leaves this page: your current position, plus the place you are heading to.
        var coords = Number(fix.lng).toFixed(6) + ',' + Number(fix.lat).toFixed(6) + ';' + Number(target.lng).toFixed(6) + ',' + Number(target.lat).toFixed(6);
        TT.call('route', { params: { mode: guide.mode, coords: coords, steps: '1' }, timeout: 20000 }).then(function (j) {
            if (token !== guide.token || !guide.active) return;
            guide.busy = false;
            var line = GD.prepareLine(j.geometry && j.geometry.coordinates), steps = line ? GD.buildSteps(j.legs, line) : [];
            if (!line || !steps.length) { gdMsg('The route service did not return steps for the new route. Try again in a moment.'); gdRecalcUi({ offRoute: true, arrived: false }); return; }
            guide.line = line; guide.steps = steps; guide.legTargets = [T]; guide.startStop = null; guide.progressAlong = null; guide.lastStep = -2; guide.band = null;
            guide.tracker.setRoute(line, steps); gdRenderSteps();
            gdMsg('New route planned from your position to ' + target.name + '.'); gdSay('New route planned to ' + target.name + '.');
            gd('gdOff').hidden = true;
            if (guide.lastFix) gdRender(guide.tracker.update(guide.lastFix));
        }).catch(function (e) {
            if (token !== guide.token || !guide.active) return;
            guide.busy = false; gdMsg('Could not recalculate: ' + (e && e.message ? e.message : 'unknown error') + '. You can try again after a short wait.'); gdRecalcUi({ offRoute: true, arrived: false });
        });
    }
    function stopGuidance(message) {
        var was = guide.active;
        guide.token++; guide.active = false; guide.busy = false; clearTimeout(guide.rcTimer);
        if (guide.watchId != null && navigator.geolocation) { try { navigator.geolocation.clearWatch(guide.watchId); } catch (e) { /* nothing to clear */ } }
        guide.watchId = null; guide.tracker = null; guide.lastFix = null; guide.steps = []; guide.line = null; guide.band = null;
        if (!gd('gdIntro')) return;
        gdShow('intro');
        if (message) { gdMsg(message); if (was) gdSay(message); }
    }

    /* ================================================================== offline copy (explicit user action only; reuses the TripKit in sw.js) */
    function swMsg(msg, ms) {
        return new Promise(function (resolve, reject) {
            if (!('serviceWorker' in navigator)) { reject(new Error('This browser cannot save pages for offline use.')); return; }
            var timer = setTimeout(function () { reject(new Error('The offline service did not answer. Reload the page and try again.')); }, ms || 20000);
            navigator.serviceWorker.ready.then(function (reg) {
                if (!reg.active) { clearTimeout(timer); reject(new Error('The offline service is not active yet. Reload the page and try again.')); return; }
                var ch = new MessageChannel();
                ch.port1.onmessage = function (e) { clearTimeout(timer); resolve(e.data); };
                reg.active.postMessage(msg, [ch.port2]);
            }).catch(function (e) { clearTimeout(timer); reject(e); });
        });
    }
    function pathOf(u) { var a = new URL(u, location.href); return a.origin === location.origin ? a.pathname + a.search : null; }
    function offlineUrls() {
        var trip_ = pathOf('trip-planner.php?id=' + encodeURIComponent(trip.id)), emerg = pathOf('emergency.php');
        var assets = [];   // the page's own styles and scripts, so the saved page still looks and works. The map engine is left out: map tiles need a connection.
        [].slice.call(document.querySelectorAll('link[rel="stylesheet"][href], script[src]')).forEach(function (el) {
            var p = pathOf(el.getAttribute('href') || el.getAttribute('src')); if (p && p.indexOf('/js/vendor/maplibre/') < 0 && assets.indexOf(p) < 0) assets.push(p);
        });
        return { page: trip_, emergency: emerg, assets: assets };
    }
    function fmtWhen(iso) { var d = new Date(iso); if (isNaN(d.getTime())) return null; try { return d.toLocaleString('en', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); } catch (e) { return d.toString(); } }
    function initOffline() {
        var box = $('offlineBox'); if (!box) return;
        var urls = offlineUrls();
        var banner = h('div', { id: 'offBanner', class: 'tms-tr-note', role: 'status', hidden: true });
        var status = h('p', { id: 'offStatus', class: 'tms-tr-status', role: 'status', 'aria-live': 'polite' });
        var saveBtn = h('button', { type: 'button', class: 'tms-tr-btn', id: 'offSave', text: 'Save this trip for offline' });
        var rmBtn = h('button', { type: 'button', class: 'tms-tr-btn tms-tr-btn-danger', id: 'offRemove', text: 'Remove offline copy', hidden: true });
        var card = h('section', { class: 'tms-tr-card', 'aria-labelledby': 'offH', style: 'margin-bottom:14px;' }, [
            h('h2', { id: 'offH', class: 'tms-tr-title', style: 'font-size:18px;', text: 'Offline copy' }), banner,
            h('p', { class: 'tms-tr-muted', text: 'Saves this plan (your places, times, notes, costs and budget as they are right now) and the emergency contacts page on this device only, so you can read them without a connection. Map tiles need a connection, so the map stays blank offline. Route times and distances are not refreshed offline, and changes cannot be saved until you are back online. Signing out removes offline copies from this device.' }),
            status, h('div', { class: 'tms-tr-row' }, [saveBtn, rmBtn])]);
        box.appendChild(card);
        var savedAt = null;
        function paint() {
            clear(status); status.className = 'tms-tr-status';
            var when = savedAt ? fmtWhen(savedAt) : null;
            status.textContent = when ? 'Saved on ' + when + '. It does not update by itself: save again after you change the trip.' : 'No offline copy of this trip on this device.';
            rmBtn.hidden = !savedAt; saveBtn.textContent = savedAt ? 'Update offline copy' : 'Save this trip for offline';
            var off = typeof navigator.onLine === 'boolean' && !navigator.onLine;
            banner.hidden = !off;
            banner.textContent = off ? 'You are offline. What you see was loaded earlier' + (when ? ' and the offline copy was saved on ' + when : '') + ', so it may be out of date. Route lines, maps and saving changes need a connection.' : '';
        }
        function refresh() {
            if (!('serviceWorker' in navigator)) { saveBtn.disabled = true; status.textContent = 'This browser cannot save pages for offline use.'; return Promise.resolve(); }
            return swMsg({ type: 'TMS_LIST_TRIPKIT' }, 6000).then(function (r) { savedAt = r && r.savedAt && urls.page && r.savedAt[urls.page] ? r.savedAt[urls.page] : null; paint(); })
                .catch(function () { paint(); });
        }
        saveBtn.addEventListener('click', function () {
            if (typeof navigator.onLine === 'boolean' && !navigator.onLine) { status.className = 'tms-tr-status is-err'; status.textContent = 'You are offline, so a new copy cannot be saved now.'; return; }
            saveBtn.disabled = true; status.className = 'tms-tr-status'; status.textContent = 'Saving your latest changes, then the offline copy...';
            flush().then(function (ok) {
                if (!ok) throw new Error('Your latest changes are not saved yet, so the offline copy would be out of date. Fix that first.');
                return swMsg({ type: 'TMS_CACHE_TRIP', urls: [urls.page, urls.emergency].concat(urls.assets) });
            }).then(function (res) {
                var map = {}; ((res && res.results) || []).forEach(function (r) { map[r.url] = r.ok; });
                if (!map[urls.page]) throw new Error('The trip page could not be saved (check your connection and try again).');
                return refresh().then(function () {
                    if (!map[urls.emergency]) { status.className = 'tms-tr-status is-err'; status.textContent += ' The emergency contacts page could not be saved this time.'; }
                    else { status.className = 'tms-tr-status is-ok'; }
                    TT.announce('Trip saved for offline use');
                });
            }).catch(function (e) { status.className = 'tms-tr-status is-err'; status.textContent = 'Not saved for offline: ' + (e && e.message ? e.message : 'unknown error'); })
              .then(function () { saveBtn.disabled = false; });
        });
        rmBtn.addEventListener('click', function () {
            rmBtn.disabled = true;
            swMsg({ type: 'TMS_REMOVE_TRIP', urls: [urls.page] }).then(function () { return swMsg({ type: 'TMS_LIST_TRIPKIT' }, 6000); }).then(function (r) {
                // the emergency page is shared by every saved trip: remove it only when no saved trip page is left
                var left = ((r && r.urls) || []).some(function (u) { return /\/(my-trip|trip-planner|ticket)\.php/i.test(u); });
                return left ? null : swMsg({ type: 'TMS_REMOVE_TRIP', urls: [urls.emergency] });
            }).then(function () { savedAt = null; return refresh(); }).then(function () { status.textContent = 'Offline copy removed from this device.'; status.className = 'tms-tr-status is-ok'; TT.announce('Offline copy removed'); })
              .catch(function (e) { status.className = 'tms-tr-status is-err'; status.textContent = 'Could not remove the offline copy: ' + (e && e.message ? e.message : 'unknown error'); })
              .then(function () { rmBtn.disabled = false; });
        });
        window.addEventListener('online', paint); window.addEventListener('offline', paint);
        paint(); refresh();
    }

    renderAll();
    initMap();
    initBudget();
    initGuidance();
    initOffline();
})();

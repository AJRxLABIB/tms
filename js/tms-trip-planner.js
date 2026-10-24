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
    var trip;
    try { trip = JSON.parse(document.getElementById('tripData').textContent); } catch (e) { return; }
    var MAX_STOPS = 100, MAX_DAYS = 60;
    var state = { day: 1, routes: {}, dirty: false, saving: false, timer: null, cid: 0, focus: null, routeSeq: 0 };
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
    function selectDay(d) { state.day = d; renderTabs(); renderPanel(); refreshRoute(); clear($('addList')); $('addStatus').textContent = ''; }

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
        p.appendChild(tools);
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
    function renderAll() { norm(); renderDetails(); renderTabs(); renderPanel(); refreshRoute(); }
    function applyServer(it, focus) { trip.stops = it.stops; trip.days = it.days || trip.days; state.focus = focus || null; state.routes = {}; norm(); renderTabs(); renderPanel(); refreshRoute(); }
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
        norm(); state.dirty = true; renderTabs(); renderPanel(); refreshRoute();
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

    renderAll();
    initMap();
})();

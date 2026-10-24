/*
 * tms-trips.js — shared client for Saved places and personal trips (api/map.php).
 *
 * PUBLIC API (window.TmsTrips)
 *   TmsTrips.call(action, {params, body, timeout}) -> Promise<json>   GET, or POST (JSON + X-CSRF-Token) when body is given.
 *                                                    Rejects with Error whose .code / .message come from the API.
 *   TmsTrips.placeKey(place)  TmsTrips.toPayload(place, ctx)   stable key + the exact payload api/map.php validates
 *   TmsTrips.actions(place, ctx) -> HTMLElement      [Save heart] + [Add to trip v] for one place
 *   TmsTrips.mountActions(container, resolver, ctx)  fills every [data-tms-actions] element inside container;
 *                                                    resolver(el) returns the place object for that element
 *   TmsTrips.announce(msg)                           polite aria-live message
 *   TmsTrips.utils                                   pure helpers (node-tested)
 *
 * All text from the API or from places is inserted with textContent. Nothing here invents data: a place without
 * valid coordinates simply gets no buttons.
 */
(function (root, factory) {
    'use strict';
    var api = factory(root);
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    else { root.TmsTrips = api; }
}(typeof self !== 'undefined' ? self : this, function (root) {
    'use strict';

    var CATS = ['attraction', 'historic', 'museum', 'religious', 'beach', 'mountain', 'waterfall', 'park', 'trail', 'hotel', 'restaurant',
        'market', 'transport', 'visitor_info', 'hospital', 'police', 'other', 'pharmacy', 'atm', 'gas_station', 'shopping_mall', 'tourist_info', 'destination'];

    /* ============================ pure helpers ============================ */
    var utils = {};
    utils.slug = function (s) { return String(s == null ? '' : s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40); };
    utils.validCoord = function (lat, lng) {
        if (lat === null || lng === null || lat === undefined || lng === undefined || lat === '' || lng === '' || typeof lat === 'boolean' || typeof lng === 'boolean') return false;
        var a = Number(lat), b = Number(lng);
        return isFinite(a) && isFinite(b) && a >= -90 && a <= 90 && b >= -180 && b <= 180 && !(a === 0 && b === 0);
    };
    /** Internal map place -> p:<id>; anything else (tiles, packages, geocoder) -> geo:<lat>_<lng>[_name]. */
    utils.placeKey = function (p) {
        if (!p) return '';
        if (p.place_key && /^[a-z]{1,12}:[A-Za-z0-9._:\/\-]{1,100}$/.test(p.place_key)) return p.place_key;
        if (p.id != null && /^\d{1,10}$/.test(String(p.id)) && !p._pseudo && p.source !== 'package' && p.source !== 'local' && p.source !== 'openstreetmap') return 'p:' + p.id;
        if (!utils.validCoord(p.lat, p.lng)) return '';
        var k = 'geo:' + Number(p.lat).toFixed(5) + '_' + Number(p.lng).toFixed(5);
        var s = utils.slug(p.name);
        return s ? k + '_' + s : k;
    };
    utils.category = function (p) {
        var c = String((p && (p.category || p.local_kind)) || '').toLowerCase();
        return CATS.indexOf(c) >= 0 ? c : 'other';
    };
    utils.toPayload = function (p, ctx) {
        ctx = ctx || {};
        var name = String((p && (p.name || p.name_bn)) || '').replace(/\s+/g, ' ').trim().slice(0, 160);
        var out = { place_key: utils.placeKey(p), name: name, lat: Number(p.lat), lng: Number(p.lng), category: ctx.category || utils.category(p) };
        if (ctx.kind) out.kind = ctx.kind;
        var loc = p.locality || p.district || ''; if (loc) out.locality = String(loc).slice(0, 150);
        var country = ctx.country || p.country || ''; if (country) out.country = String(country).slice(0, 100);
        if (ctx.destination) out.destination_name = String(ctx.destination).slice(0, 160);
        return out;
    };
    utils.tripStopFrom = function (s) {
        return { place_id: s.place_id || null, name: s.name, lat: s.lat, lng: s.lng, note: s.note || null, day_index: s.day_index || null,
            arrive_time: s.arrive_time || null, depart_time: s.depart_time || null, est_cost: s.est_cost == null ? null : s.est_cost,
            is_done: !!s.is_done, place_key: s.place_key || null };
    };

    /* ============================ network ============================ */
    function deriveBase() {
        var d = root.document; if (!d) return '';
        var s = d.querySelector('script[src*="tms-trips.js"]');
        var src = s ? (s.getAttribute('src') || '') : '';
        var i = src.indexOf('js/tms-trips.js');
        return i > 0 ? src.slice(0, i) : '';
    }
    var csrfP = null;
    function getCsrf() {
        if (csrfP) return csrfP;
        csrfP = fetch(deriveBase() + 'api/map.php?action=config', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { var t = (j && ((j.config && j.config.csrf) || j.csrf)) || ''; if (!t) throw new Error('csrf'); return t; })
            .catch(function (e) { csrfP = null; throw e; });
        return csrfP;
    }
    function call(action, opt) {
        opt = opt || {};
        var url = deriveBase() + 'api/map.php?action=' + encodeURIComponent(action);
        Object.keys(opt.params || {}).forEach(function (k) {
            var v = opt.params[k]; if (v !== undefined && v !== null && v !== '') url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
        });
        var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctl) ctl.abort(); }, opt.timeout || 15000);
        var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctl ? ctl.signal : undefined };
        var go = opt.body !== undefined
            ? getCsrf().then(function (tok) {
                init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.headers['X-CSRF-Token'] = tok; init.body = JSON.stringify(opt.body);
                return fetch(url, init);
            })
            : fetch(url, init);
        return go.then(function (r) {
            return r.text().then(function (t) {
                var j = null;
                try { j = JSON.parse(t); } catch (e) { throw mkErr('bad_response', 'The server sent an unexpected answer. Please try again.'); }
                if (!r.ok || !j || j.ok === false) {
                    var er = j && j.error ? j.error : {};
                    var msg = r.status === 401 ? 'Please sign in again to continue.' : (er.message || 'Something went wrong. Please try again.');
                    throw mkErr(er.code || ('http_' + r.status), msg);
                }
                return j;
            });
        }).catch(function (e) {
            if (e && e.name === 'AbortError') throw mkErr('timeout', 'The request took too long. Please try again.');
            if (e instanceof TypeError) throw mkErr('network', 'You seem to be offline. Check your connection and try again.');
            throw e;
        }).then(function (v) { clearTimeout(timer); return v; }, function (e) { clearTimeout(timer); throw e; });
    }
    function mkErr(code, msg) { var e = new Error(msg); e.code = code; return e; }

    /* ============================ DOM helpers ============================ */
    function doc() { return root.document; }
    function el(tag, cls, text) { var n = doc().createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function icon(cls) { var i = el('span', 'fa ' + cls); i.setAttribute('aria-hidden', 'true'); return i; }
    var live = null;
    function announce(msg) {
        try { if (root.tmsA11y && root.tmsA11y.announce) { root.tmsA11y.announce(msg, 'polite'); return; } } catch (e) { /* fall through */ }
        if (!live) { live = el('div', 'tms-sr-only'); live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite'); doc().body.appendChild(live); }
        live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30);
    }

    /* ============================ saved places state ============================ */
    var savedP = null, savedSet = null;
    function loadSaved() {
        if (savedP) return savedP;
        savedP = call('saved_list', { params: { limit: 500 } }).then(function (j) {
            savedSet = {}; (j.items || []).forEach(function (i) { savedSet[i.place_key] = true; }); return savedSet;
        }).catch(function (e) { savedP = null; throw e; });
        return savedP;
    }

    function heartButton(place, ctx) {
        var key = utils.placeKey(place);
        var b = el('button', 'tms-save-btn'); b.type = 'button';
        var ic = icon('fa-heart-o'), tx = el('span', null, 'Save');
        b.appendChild(ic); b.appendChild(doc().createTextNode(' ')); b.appendChild(tx);
        b.setAttribute('aria-pressed', 'false');
        var name = place.name || place.name_bn || 'place';
        b.setAttribute('aria-label', 'Save ' + name + ' to your saved places');
        function paint(on) {
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            ic.className = 'fa ' + (on ? 'fa-heart' : 'fa-heart-o'); tx.textContent = on ? 'Saved' : 'Save';
            b.setAttribute('aria-label', (on ? 'Remove ' : 'Save ') + name + (on ? ' from' : ' to') + ' your saved places');
        }
        loadSaved().then(function (set) { paint(!!set[key]); }, function () { /* stays "Save"; a click shows the real error */ });
        b.addEventListener('click', function () {
            var want = b.getAttribute('aria-pressed') !== 'true';
            b.disabled = true;
            var req = want ? call('saved_save', { body: utils.toPayload(place, ctx) }) : call('saved_remove', { body: { place_key: key } });
            req.then(function () {
                if (savedSet) { if (want) savedSet[key] = true; else delete savedSet[key]; }
                paint(want); announce(want ? name + ' saved' : name + ' removed from saved places');
            }).catch(function (e) { announce(e.message); showInlineError(b, e.message); })
              .then(function () { b.disabled = false; });
        });
        return b;
    }

    function showInlineError(anchor, msg) {
        var host = anchor.parentNode; if (!host) return;
        var old = host.querySelector('.tms-trip-err'); if (old) old.remove();
        var n = el('span', 'tms-trip-err', msg); n.setAttribute('role', 'alert'); host.appendChild(n);
        setTimeout(function () { if (n.parentNode) n.remove(); }, 6000);
    }

    /* ============================ add to trip ============================ */
    function addToTrip(tripId, place, ctx) {
        var payload = utils.toPayload(place, ctx);
        return call('itinerary_get', { params: { id: tripId } }).then(function (j) {
            var it = j.itinerary || j;
            var stops = (it.stops || []).map(utils.tripStopFrom);
            if (stops.some(function (s) { return s.place_key && s.place_key === payload.place_key; })) { var e = mkErr('duplicate', 'This place is already in that trip.'); e.trip = it; throw e; }
            var pid = /^p:(\d+)$/.exec(payload.place_key);
            stops.push({ place_id: pid ? Number(pid[1]) : null, name: payload.name, lat: payload.lat, lng: payload.lng, note: null, day_index: 1,
                arrive_time: null, depart_time: null, est_cost: null, is_done: false, place_key: payload.place_key });
            return call('itinerary_save', { body: { id: it.id, title: it.title, mode: it.mode, planner: true, stops: stops } }).then(function () { return it; });
        });
    }

    function tripMenu(place, ctx) {
        var wrap = el('span', 'tms-trip-menu-wrap');
        var btn = el('button', 'tms-trip-btn'); btn.type = 'button';
        btn.setAttribute('aria-haspopup', 'true'); btn.setAttribute('aria-expanded', 'false');
        btn.appendChild(icon('fa-calendar-plus-o')); btn.appendChild(doc().createTextNode(' Add to trip'));
        var panel = el('div', 'tms-trip-menu'); panel.hidden = true; panel.setAttribute('role', 'group'); panel.setAttribute('aria-label', 'Choose a trip');
        var status = el('div', 'tms-trip-menu-status'); status.setAttribute('role', 'status');
        wrap.appendChild(btn); wrap.appendChild(panel);

        function close(focus) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); if (focus) btn.focus(); }
        function done(msg, tripId) {
            panel.textContent = '';
            panel.appendChild(el('p', 'tms-trip-menu-msg', msg));
            if (tripId) { var a = el('a', 'tms-trip-menu-link', 'Open the planner'); a.href = deriveBase() + 'trip-planner.php?id=' + encodeURIComponent(tripId); panel.appendChild(a); }
            announce(msg);
        }
        function pick(trip) {
            panel.textContent = ''; panel.appendChild(el('p', 'tms-trip-menu-msg', 'Adding...'));
            addToTrip(trip.id, place, ctx).then(function (it) { done((place.name || 'Place') + ' added to ' + it.title + '.', it.id); })
                .catch(function (e) { if (e.code === 'duplicate') done(e.message, trip.id); else done(e.message); });
        }
        function newTripForm() {
            var f = el('form', 'tms-trip-menu-new');
            var lab = el('label', null, 'Name of the new trip'); var inp = el('input'); inp.type = 'text'; inp.maxLength = 120; inp.required = true;
            inp.value = ctx && ctx.destination ? ('Trip to ' + ctx.destination).slice(0, 120) : '';
            var id = 'tmsNewTrip' + Math.floor(Math.random() * 1e6); inp.id = id; lab.setAttribute('for', id);
            var go = el('button', 'tms-trip-menu-go', 'Create and add'); go.type = 'submit';
            f.appendChild(lab); f.appendChild(inp); f.appendChild(go);
            f.addEventListener('submit', function (ev) {
                ev.preventDefault(); go.disabled = true;
                var body = { title: inp.value }; if (ctx && ctx.destination) body.destination_name = ctx.destination;
                call('itinerary_create', { body: body }).then(function (j) { pick({ id: j.id }); })
                    .catch(function (e) { go.disabled = false; status.textContent = e.message; });
            });
            return f;
        }
        function render(trips) {
            panel.textContent = '';
            panel.appendChild(el('p', 'tms-trip-menu-head', 'Personal plan, not a booking'));
            if (trips.length) {
                var ul = el('ul', 'tms-trip-menu-list');
                trips.forEach(function (t) {
                    var li = el('li'); var b = el('button', 'tms-trip-menu-item'); b.type = 'button';
                    b.appendChild(el('span', 'tms-trip-menu-title', t.title));
                    b.appendChild(el('span', 'tms-trip-menu-meta', (t.stop_count || 0) + (t.stop_count === 1 ? ' place' : ' places')));
                    b.addEventListener('click', function () { pick(t); });
                    li.appendChild(b); ul.appendChild(li);
                });
                panel.appendChild(ul);
            } else { panel.appendChild(el('p', 'tms-trip-menu-msg', 'You have no trips yet.')); }
            panel.appendChild(newTripForm()); panel.appendChild(status);
            var first = panel.querySelector('button, input'); if (first) first.focus();
        }
        btn.addEventListener('click', function () {
            if (!panel.hidden) { close(false); return; }
            panel.hidden = false; btn.setAttribute('aria-expanded', 'true');
            panel.textContent = ''; panel.appendChild(el('p', 'tms-trip-menu-msg', 'Loading your trips...'));
            call('itinerary_list').then(function (j) { render(j.items || []); })
                .catch(function (e) { panel.textContent = ''; panel.appendChild(el('p', 'tms-trip-err', e.message)); });
        });
        wrap.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { e.stopPropagation(); close(true); } });
        doc().addEventListener('click', function (e) { if (!panel.hidden && !wrap.contains(e.target)) close(false); });
        return wrap;
    }

    /* ============================ public ============================ */
    function actions(place, ctx) {
        var box = el('div', 'tms-trip-actions');
        if (!place || !utils.validCoord(place.lat, place.lng) || !utils.placeKey(place) || !(place.name || place.name_bn)) return box;   // nothing to save without a real location
        box.appendChild(heartButton(place, ctx)); box.appendChild(tripMenu(place, ctx));
        return box;
    }
    function mountActions(container, resolver, ctx) {
        if (!container) return;
        Array.prototype.forEach.call(container.querySelectorAll('[data-tms-actions]'), function (host) {
            if (host.getAttribute('data-tms-mounted')) return;
            host.setAttribute('data-tms-mounted', '1');
            var p = null; try { p = resolver(host); } catch (e) { p = null; }
            if (p) host.appendChild(actions(p, ctx));
        });
    }

    return { call: call, placeKey: utils.placeKey, toPayload: utils.toPayload, actions: actions, mountActions: mountActions, announce: announce,
        loadSaved: loadSaved, addToTrip: addToTrip, utils: utils, _el: el, _icon: icon };
}));

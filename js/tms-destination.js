/* tms-destination.js — destination page (explore-view.php) extras that sit on top of js/explore.js:
   tab keyboard support, Attractions + Travel info panels, weather-aware tips, nearby radius/sort/indoor controls,
   map <-> list sync and the "My itinerary" panel. explore.js calls TmsDest.onTab/onInfo/onWeather/showOnMap/focusPlaceCard.
   All dynamic text goes through escapeHtml; nothing here invents data. */
(function () {
    'use strict';
    var V = null;   // window.TmsExploreView (set by explore.js)
    function view() { return V || (V = window.TmsExploreView || null); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function $(id) { return document.getElementById(id); }
    function box(cls, icon, msg, alert) {
        return '<div class="' + cls + '"' + (alert ? ' role="alert"' : '') + '><i class="fa ' + icon + '" aria-hidden="true"></i> <span>' + esc(msg) + '</span></div>';
    }

    /* ---------- tabs: arrow keys / Home / End (automatic activation) ---------- */
    (function tabKeys() {
        var list = $('exploreTabList'); if (!list) return;
        list.addEventListener('keydown', function (e) {
            var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
            var i = tabs.indexOf(document.activeElement); if (i < 0) return;
            var n = -1;
            if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
            else if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
            else if (e.key === 'Home') n = 0;
            else if (e.key === 'End') n = tabs.length - 1;
            if (n < 0) return;
            e.preventDefault(); tabs[n].focus(); window.exploreSwitchTab(tabs[n].dataset.tab);
        });
    })();

    /* ---------- Attractions + Travel info (from the destination info already loaded for Overview) ---------- */
    function renderAttractions(info, err) {
        var el = $('attractionsContent'); if (!el) return;
        if (!info) { el.innerHTML = box('explore-error', 'fa-exclamation-triangle', err || 'Attractions are unavailable right now.', true); return; }
        var list = info.attractions || [];
        var items = list.map(function (a) {
            return '<div class="attraction-item"><div class="attraction-icon" aria-hidden="true"><i class="fa fa-map-pin"></i></div><div><strong>' + esc(a.name) + '</strong><span>' + esc(a.description) + '</span></div></div>';
        }).join('');
        el.innerHTML = '<div class="explore-card"><h4><i class="fa fa-landmark" aria-hidden="true"></i> Popular attractions</h4>' +
            (items || '<p class="text-muted">No attraction data available for this destination.</p>') +
            '<p class="tmsd-note">Descriptions are a quick orientation, not an official source.</p>' +
            '<button type="button" class="tmsd-btn" id="attrNearbyBtn"><i class="fa fa-location-arrow" aria-hidden="true"></i> Find attractions near the centre</button></div>';
        var b = $('attrNearbyBtn');
        if (b) b.addEventListener('click', function () { window.exploreSwitchTab('places'); window.loadPlaces('attraction'); });
    }
    function renderTravel(info, err) {
        var el = $('travelContent'); if (!el) return;
        if (!info) { el.innerHTML = box('explore-error', 'fa-exclamation-triangle', err || 'Travel information is unavailable right now.', true); return; }
        function card(icon, title, inner) { return '<div class="explore-card"><h4><i class="fa fa-' + icon + '" aria-hidden="true"></i> ' + title + '</h4>' + inner + '</div>'; }
        var out = '';
        if (info.source === 'catalogue') out += '<div class="tms-sample-banner" style="margin-bottom:12px;"><i class="fa fa-info-circle" aria-hidden="true"></i> ' + esc(info.ai_note || 'AI summary unavailable: showing verified catalogue information.') + '</div>';
        if (info.best_time_to_visit) out += card('calendar-check', 'Best time to visit', '<p>' + esc(info.best_time_to_visit) + '</p>');
        if (info.how_to_get_there) out += card('route', 'Getting there', '<p>' + esc(info.how_to_get_there) + '</p>');
        var c = info.estimated_costs;
        if (c) out += card('wallet', 'Estimated travel costs (per day, US dollars)',
            '<div class="cost-grid"><div class="cost-box"><div class="lbl">Budget</div><div class="val">$' + esc(c.budget_per_day_usd) + '</div></div>' +
            '<div class="cost-box"><div class="lbl">Mid-range</div><div class="val">$' + esc(c.midrange_per_day_usd) + '</div></div>' +
            '<div class="cost-box"><div class="lbl">Luxury</div><div class="val">$' + esc(c.luxury_per_day_usd) + '</div></div></div>' +
            '<p class="tmsd-note">' + esc(c.currency_note || '') + ' Figures are approximate.</p>');
        var tips = (info.safety_tips || []).map(function (t) { return '<div class="safety-tip-row"><i class="fa fa-shield-alt" aria-hidden="true"></i><span>' + esc(t) + '</span></div>'; }).join('');
        if (tips) out += card('shield-alt', 'Safety tips', tips);
        var e = info.emergency_contacts;
        if (e || info.emergency_note) {
            var rows = '';
            if (e) [['police', 'Police'], ['ambulance', 'Ambulance'], ['fire', 'Fire'], ['helpline', 'Tourist helpline'], ['embassy', 'Embassy']].forEach(function (k) {
                if (e[k[0]]) rows += '<div class="emergency-row"><span>' + k[1] + '</span><strong>' + esc(e[k[0]]) + '</strong></div>';
            });
            out += card('phone-alt', 'Emergency contacts', (info.emergency_note ? '<p class="tmsd-note" style="margin-top:0">' + esc(info.emergency_note) + '</p>' : '') + (rows ? '<div class="emergency-box">' + rows + '</div>' : ''));
        }
        el.innerHTML = out || '<div class="explore-card"><p class="text-muted">No travel information is available for this destination yet.</p></div>';
    }

    /* ---------- weather tips ---------- */
    function renderTips(w) {
        var el = $('weatherTips'); if (!el || !window.TmsWeatherTips) return;
        var tips = window.TmsWeatherTips(w);
        el.innerHTML = '<div class="explore-card"><h4><i class="fa fa-lightbulb-o" aria-hidden="true"></i> Weather-aware suggestions</h4>' + tips.map(function (t, i) {
            return '<div class="tmsd-tip tmsd-' + esc(t.level) + '"><i class="fa ' + (t.level === 'warn' ? 'fa-exclamation-triangle' : 'fa-info-circle') + '" aria-hidden="true"></i><span>' + esc(t.text) + '</span>' +
                (t.action ? ' <button type="button" class="tmsd-btn" data-tip="' + i + '">' + esc(t.action.label) + '</button>' : '') + '</div>';
        }).join('') + '</div>';
        el.onclick = function (e) {
            var b = e.target.closest('[data-tip]'); if (!b) return;
            var a = tips[parseInt(b.getAttribute('data-tip'), 10)].action;
            if (a && a.type === 'filter-nearby') setIndoor(true, a.categories);
        };
    }

    /* ---------- nearby: radius, sort, indoor-friendly filter ---------- */
    var indoorCats = null;
    function setIndoor(on, cats) {
        var v = view(); if (!v) return;
        v.state.indoorOnly = on; if (cats) indoorCats = cats;
        var chip = $('indoorChip'); if (chip) { chip.hidden = !(on || indoorCats); chip.setAttribute('aria-pressed', on ? 'true' : 'false'); chip.textContent = on ? 'Indoor-friendly only (on)' : 'Indoor-friendly only'; }
        var first = null;
        Array.prototype.forEach.call(document.querySelectorAll('#placeCatTabs .place-cat-btn'), function (b) {
            var hide = on && indoorCats && indoorCats.indexOf(b.dataset.cat) < 0;
            b.hidden = hide; if (!hide && !first) first = b;
        });
        window.exploreSwitchTab('places');
        if (on && indoorCats && indoorCats.indexOf(v.state.currentCategory) < 0 && first) v.loadPlaces(first.dataset.cat);
        else if (v.state.placesLoaded) v.loadPlaces(v.state.currentCategory);
        else v.loadPlaces(on && first ? first.dataset.cat : 'hotel');
    }
    (function controls() {
        var r = $('nearbyRadius'), s = $('nearbySort'), chip = $('indoorChip');
        function reload() {
            var v = view(); if (!v || !v.state.placesLoaded) return;
            v.loadPlaces(v.state.currentCategory);
            if (v.state.mapReady && v.state.mapCategory !== v.state.currentCategory) window.exploreLoadMapCategory(v.state.mapCategory);
        }
        if (r) r.addEventListener('change', function () { var v = view(); if (v) { v.state.radius = parseInt(r.value, 10) || 5000; reload(); } });
        if (s) s.addEventListener('change', function () { var v = view(); if (v) { v.state.sortBy = s.value; reload(); } });
        if (chip) chip.addEventListener('click', function () { var v = view(); if (v) setIndoor(!v.state.indoorOnly, indoorCats); });
    })();

    /* ---------- map <-> list sync ---------- */
    function showOnMap(p, cat) {
        var v = view(); if (!v) return;
        window.exploreSwitchTab('map');
        window.exploreLoadMapCategory(cat);
        setTimeout(function () { v.focusMapPlace(p, cat); }, 300);
    }
    function focusPlaceCard(p) {
        var v = view(); if (!v) return;
        window.exploreSwitchTab('places');
        var idx = -1;
        v.state.currentPlaces.forEach(function (q, i) { if (idx < 0 && q.name === p.name && String(q.lat) === String(p.lat) && String(q.lng) === String(p.lng)) idx = i; });
        var card = idx >= 0 ? document.querySelector('#placesGrid .place-card[data-idx="' + idx + '"]') : null;
        if (!card) return;
        Array.prototype.forEach.call(document.querySelectorAll('.place-card.is-focus'), function (c) { c.classList.remove('is-focus'); });
        card.classList.add('is-focus');
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        card.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
        var a = card.querySelector('a'); if (a) a.focus({ preventScroll: true });
    }

    /* ---------- My itinerary (api/map.php: itinerary_list, itinerary_save) ---------- */
    var MAP_API = 'api/map.php', itLoaded = false;
    function mapFetch(action, opt) {
        opt = opt || {};
        var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (opt.body) { init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.headers['X-CSRF-Token'] = opt.csrf || ''; init.body = JSON.stringify(opt.body); }
        return fetch(MAP_API + '?action=' + encodeURIComponent(action), init).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (j) {
                if (!r.ok || !j || j.ok === false) { var m = j && j.error && j.error.message ? j.error.message : (r.status === 401 ? 'Please sign in to use itineraries.' : 'Request failed.'); var e = new Error(m); throw e; }
                return j;
            });
        });
    }
    function loadItineraries() {
        var list = $('itineraryList'), start = $('itineraryStart'), v = view(); if (!list || !v) return;
        var D = v.dest;
        start.innerHTML = '<form id="itStartForm" class="tmsd-start"><label for="itTitle">Trip name</label>' +
            '<input type="text" id="itTitle" maxlength="120" value="' + esc('Trip to ' + D.name) + '">' +
            '<button type="submit" class="tmsd-btn tmsd-btn-primary"><i class="fa fa-plus" aria-hidden="true"></i> Start a trip for ' + esc(D.name) + '</button>' +
            '<div id="itStartMsg" role="status" aria-live="polite" class="tmsd-note"></div></form>';
        $('itStartForm').addEventListener('submit', function (e) {
            e.preventDefault();
            var title = $('itTitle').value.trim(), msg = $('itStartMsg'), btn = e.target.querySelector('button');
            if (!title) { msg.textContent = 'Give the trip a name first.'; return; }
            btn.disabled = true; msg.textContent = 'Creating your trip...';
            mapFetch('config').then(function (cfg) {
                return mapFetch('itinerary_save', { csrf: cfg.csrf, body: { title: title, mode: 'driving', stops: [{ name: D.name, lat: D.lat, lng: D.lng }] } });
            }).then(function (res) {
                msg.innerHTML = 'Trip created. <a href="trip-planner.php?id=' + encodeURIComponent(res.id) + '">Open planner</a>';
                itLoaded = false; loadItineraries();
            }).catch(function (err) { msg.textContent = err.message || 'Could not create the trip.'; btn.disabled = false; });
        });
        list.innerHTML = '<div class="explore-loading"><i class="fa fa-spinner fa-spin spin" aria-hidden="true"></i>Loading your trips...</div>';
        mapFetch('itinerary_list').then(function (j) {
            itLoaded = true;
            var items = j.items || [];
            if (!items.length) { list.innerHTML = '<p class="text-muted">You have no saved trips yet. Start one above, then add stops in the planner.</p>'; return; }
            list.innerHTML = '<ul class="tmsd-trips">' + items.map(function (t) {
                var stops = t.stop_count != null ? t.stop_count + ' stop' + (t.stop_count === 1 ? '' : 's') : '';
                return '<li><div><strong>' + esc(t.title) + '</strong><span>' + esc(stops) + (t.updated_at ? ' · updated ' + esc(String(t.updated_at).slice(0, 10)) : '') + '</span></div>' +
                    '<a class="tmsd-btn" href="trip-planner.php?id=' + encodeURIComponent(t.id) + '">Open planner</a></li>';
            }).join('') + '</ul><p class="tmsd-note"><a href="my-trips.php">See all my trips</a></p>';
        }).catch(function (err) {
            list.innerHTML = box('explore-error', 'fa-exclamation-triangle', err.message || 'Could not load your trips.', true) +
                '<button type="button" class="explore-retry-btn" id="itRetry">Try again</button>';
            var rb = $('itRetry'); if (rb) rb.addEventListener('click', loadItineraries);
        });
    }

    window.TmsDest = {
        onTab: function (tab) {
            var b = document.querySelector('.explore-tab-btn[data-tab="' + tab + '"]');
            if (b && b.scrollIntoView) b.scrollIntoView({ block: 'nearest', inline: 'center' });
            if (tab === 'itinerary' && !itLoaded) loadItineraries();
        },
        onInfo: function (info, err) { renderAttractions(info, err); renderTravel(info, err); },
        onWeather: renderTips,
        showOnMap: showOnMap,
        focusPlaceCard: focusPlaceCard
    };
})();

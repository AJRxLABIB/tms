/**
 * explore.js — Client-side logic for TourBuddy Explore Mode
 * Covers: destination search/autocomplete (explore.php) and the destination
 * hub page — overview, map & navigation, nearby places, weather, AI chat
 * (explore-view.php). Uses feature detection so one file can serve both pages.
 */

var EXPLORE_API = 'api/explore.php';

/* ══════════════════════════════════════════════════════════════
   Utility
   ══════════════════════════════════════════════════════════════ */
function exploreDebounce(fn, delay) {
    var t;
    return function () {
        var args = arguments, ctx = this;
        clearTimeout(t);
        t = setTimeout(function () { fn.apply(ctx, args); }, delay);
    };
}

function exploreGet(action, params, timeoutMs) {
    var qs = new URLSearchParams(Object.assign({ action: action }, params || {})).toString();
    var url = EXPLORE_API + '?' + qs;
    // Hard timeout: a hung request used to leave "Finding hotels nearby..." spinning forever.
    if (window.TmsMapPages && window.TmsMapPages.fetchJson) return window.TmsMapPages.fetchJson(url, { timeout: timeoutMs || 14000 });
    return fetch(url).then(function (r) { return r.json(); });
}

// Live places: OpenFreeMap vector tiles first (no key, browser-side, fast), then the server path (cache / Overpass / local
// catalogue) only if the tiles are unreachable or empty. Same response shape as exploreGet('places').
function exploreLivePlaces(params) {
    var server = function () { return exploreGet('places', params); };
    if (!window.TmsPoiTiles) return server();
    var radius = Number(params.radius) || 4000;
    return window.TmsPoiTiles.loadPlaces({ lat: Number(params.lat), lng: Number(params.lng), category: params.category || '', radiusM: radius, limit: 40 }).then(function (r) {
        if (!r.places.length) return server().then(function (res) {
            if (res && res.success && res.data && !(res.data.places || res.data.items || []).length) res.data.noneLive = true;
            return res;
        });
        var places = r.places.map(function (p) {
            return { name: p.name, lat: p.lat, lng: p.lng, distance_m: p.distance_m, category: p.category, type: String(p.subclass || p.cls || '').replace(/_/g, ' '), address: '', source: 'openstreetmap' };
        });
        return { success: true, origin: 'live', data: { places: places, origin: 'live', provider: 'openfreemap-tiles' } };
    }, function () { return server(); });
}

// Shared text for failed/slow requests (timeout vs. server vs. offline).
function exploreErrText(err, what) {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return 'You appear to be offline. Check your connection and try again.';
    if (err && err.message === 'timeout') return 'Loading ' + what + ' took too long. The map data servers may be busy.';
    return 'Could not load ' + what + '. Please try again.';
}
function exploreErrorBox(msg, retryJs) {
    return '<div class="explore-error" role="alert"><i class="fa fa-exclamation-triangle"></i> <span>' + escapeHtml(msg) + '</span>' +
        (retryJs ? '<button type="button" class="explore-retry-btn" onclick="' + retryJs + '"><i class="fa fa-redo"></i> Try again</button>' : '') + '</div>';
}
// One line telling the visitor where the list came from: live, saved earlier, or our own catalogue.
function exploreSourceNote(res) {
    var origin = res.origin || (res.data && res.data.origin) || 'live';
    if (origin === 'live' && res.data && res.data.provider === 'openfreemap-tiles') return '<p class="explore-source" role="status"><i class="fa fa-map-pin"></i> Live from OpenStreetMap (via OpenFreeMap).</p>';
    var notice = res.notice || (res.data && res.data.notice) || '';
    if (origin === 'live') return '';
    var txt = origin === 'cached' ? 'Saved results (refreshed periodically).'
            : origin === 'stale'  ? (notice || 'Live data is unavailable right now. Showing results saved earlier; they may be out of date.')
            : origin === 'local'  ? (notice || 'Live map data is unavailable right now. Showing places from our own catalogue.')
            : '';
    return txt ? '<p class="explore-source' + (origin === 'cached' ? '' : ' is-warn') + '" role="status"><i class="fa fa-info-circle"></i> ' + escapeHtml(txt) + '</p>' : '';
}
function exploreSkeleton(n) {
    var h = ''; for (var i = 0; i < (n || 6); i++) h += '<div class="sk"></div>';
    return '<div class="explore-skeleton" aria-hidden="true">' + h + '</div>';
}

function explorePost(action, formData) {
    formData.append('action', action);
    return fetch(EXPLORE_API, { method: 'POST', body: formData }).then(function (r) { return r.json(); });
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function goToDestination(name, lat, lng, country) {
    var url = 'explore-view.php?name=' + encodeURIComponent(name)
        + '&lat=' + encodeURIComponent(lat)
        + '&lng=' + encodeURIComponent(lng)
        + '&country=' + encodeURIComponent(country || '');
    window.location.href = url;
}

/* ══════════════════════════════════════════════════════════════
   SEARCH PAGE (explore.php)
   ══════════════════════════════════════════════════════════════ */
(function initExploreSearchPage() {
    var input = document.getElementById('exploreSearchInput');
    if (!input) return;

    var box  = document.getElementById('exploreSuggestions');
    var spin = document.getElementById('exploreSearchSpinner');

    var doSearch = exploreDebounce(function (val) {
        if (val.trim().length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
        spin.style.display = 'inline-block';
        exploreGet('search', { q: val }).then(function (res) {
            spin.style.display = 'none';
            // FIX (Round 14): this was `if (!res.success) return;` — the spinner
            // stopped and absolutely nothing appeared, so typing a city and
            // getting no reaction at all was the failure mode for the entry
            // point of the whole feature (Nominatim rate-limits aggressively).
            if (!res.success) {
                box.innerHTML = '<div class="explore-suggestion-item" style="cursor:default;opacity:.8;">' +
                    '<i class="fa fa-exclamation-triangle"></i> ' +
                    escapeHtml(res.error || 'Destination search is unavailable right now — please try again in a moment.') +
                    '</div>';
                box.style.display = 'block';
                return;
            }
            var results = res.data.results || [];
            if (!results.length) {
                box.innerHTML = '<div class="explore-suggestion-item" style="cursor:default;opacity:.8;">' +
                    '<i class="fa fa-search"></i> No matching destination found. Try a city or country name.</div>';
                box.style.display = 'block';
                return;
            }
            renderSuggestions(results);
        }).catch(function () {
            spin.style.display = 'none';
            box.innerHTML = '<div class="explore-suggestion-item" style="cursor:default;opacity:.8;">' +
                '<i class="fa fa-plug"></i> Could not reach the destination search service. Check your connection and try again.</div>';
            box.style.display = 'block';
        });
    }, 350);

    input.addEventListener('input', function () { doSearch(this.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            var first = box.querySelector('.explore-suggestion-item');
            if (first) first.click();
        }
    });
    document.addEventListener('click', function (e) {
        if (!box.contains(e.target) && e.target !== input) box.style.display = 'none';
    });

    function renderSuggestions(results) {
        if (!results.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
        box.innerHTML = results.map(function (r, i) {
            return '<div class="explore-suggestion-item" data-idx="' + i + '">' +
                '<i class="fa fa-map-marker"></i>' +
                '<div><div class="explore-suggestion-main">' + escapeHtml(r.short_name) + '</div>' +
                '<div class="explore-suggestion-sub">' + escapeHtml(r.display_name) + '</div></div>' +
                '</div>';
        }).join('');
        box.style.display = 'block';
        // Event delegation with the raw data (no HTML/JS string-escaping needed —
        // this is what previously broke on destinations with an apostrophe, e.g.
        // "Cox's Bazar": building 'goToDestination(\'' + name + ...)' as an HTML
        // string meant a literal apostrophe in `name` closed the JS string early,
        // producing "missing ) after argument list" / "Could not parse..." errors.
        box.__results = results;
    }

    box.addEventListener('click', function (e) {
        var item = e.target.closest('.explore-suggestion-item');
        if (!item || !box.__results) return;
        var r = box.__results[parseInt(item.dataset.idx, 10)];
        if (r) goToDestination(r.short_name, r.lat, r.lng, r.country || '');
    });

    window.searchExploreDestination = function () { doSearch(input.value); };
    window.exploreChip = function (name) { input.value = name; doSearch(name); input.focus(); };
})();

/* ══════════════════════════════════════════════════════════════
   DESTINATION HUB PAGE (explore-view.php)
   ══════════════════════════════════════════════════════════════ */
(function initExploreViewPage() {
    var root = document.getElementById('exploreViewRoot');
    if (!root) return;

    var DEST = {
        name:    root.dataset.name,
        lat:     parseFloat(root.dataset.lat),
        lng:     parseFloat(root.dataset.lng),
        country: root.dataset.country || ''
    };

    var state = { info: null, weather: null, userLoc: null, map: null, markers: [], routeLayer: null, navTarget: null, navMarker: null, currentPlaces: [], currentCategory: 'hotel', mapPlaces: [], mapCategory: 'attraction' };

    // ── Tabs ──────────────────────────────────────────────────
    window.exploreSwitchTab = function (tab) {
        document.querySelectorAll('.explore-tab-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab); });
        document.querySelectorAll('.explore-panel').forEach(function (p) { p.classList.toggle('active', p.id === 'panel-' + tab); });
        if (tab === 'map' && !state.mapStarting) initMap();
        if (tab === 'map' && state.ctl) setTimeout(function () { state.ctl.resize(); }, 100);
        if (tab === 'places' && !state.placesLoaded) loadPlaces('hotel');
        if (tab === 'weather' && !state.weather) loadWeather();
    };

    // BUG FIX: the AI chat's system prompt (see api/explore.php's
    // handleChat()) is designed to fold in "a short summary of what the
    // visitor is currently seeing" via body.dataset.exploreContext, so
    // follow-up questions like "is it a good time to go?" or "what's the
    // weather like there?" can be answered directly instead of vaguely.
    // Nothing ever wrote that attribute, so the chatbot was always
    // context-blind. Populated here from whatever of the Overview/Weather
    // tabs has loaded so far — safe to call multiple times, and safe if
    // neither has loaded yet (chat just gets an empty context, same as
    // the previous behavior).
    function updateExploreContext() {
        var parts = [];
        if (state.info) {
            if (state.info.overview) parts.push('Overview: ' + String(state.info.overview).slice(0, 220));
            if (state.info.best_time_to_visit) parts.push('Best time to visit: ' + state.info.best_time_to_visit);
        }
        if (state.weather && state.weather.current) {
            var c = state.weather.current;
            parts.push('Current weather: ' + (c.description || '') + ', ' + Math.round(c.temp) + '°C (feels like ' + Math.round(c.feels_like) + '°C)');
        }
        document.body.dataset.exploreContext = parts.join(' | ').slice(0, 1200);
    }

    // ── Overview tab ──────────────────────────────────────────
    function loadOverview() {
        var elOverview = document.getElementById('overviewContent');
        exploreGet('info', { name: DEST.name, lat: DEST.lat, lng: DEST.lng, country: DEST.country }).then(function (res) {
            // NEW (Round 79, master prompt item 6 -- Destination System,
            // "Travel" tier): local_inventory (real packages/hotels/
            // activities this system sells for DEST.country) is unrelated to
            // whether the AI overview call succeeded, and api/explore.php now
            // returns it either way (nested under res.data on success, at the
            // top level alongside the error on failure -- see apiError()'s
            // new $extra parameter). Render it regardless, so a visitor still
            // sees real bookable options even when the AI text fails (the
            // documented common case with no AI key configured).
            var inventory = res.success ? (res.data && res.data.local_inventory) : res.local_inventory;
            if (!res.success) {
                elOverview.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(res.error) + '</div>';
                renderLocalInventory(inventory);
                return;
            }
            state.info = res.data.info;
            renderOverview(state.info);
            renderLocalInventory(inventory);
            updateExploreContext();
        }).catch(function () {
            elOverview.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> Could not load destination information. Please check your connection and try again.</div>';
        });
    }

    // ── Local inventory: real packages/hotels/activities TourBuddy actually
    //    sells for this destination's country (master prompt item 6) ──
    function renderLocalInventory(inventory) {
        var el = document.getElementById('localInventoryContent');
        if (!el) return;
        inventory = inventory || {};
        var packages   = inventory.packages   || [];
        var hotels     = inventory.hotels     || [];
        var activities = inventory.activities || [];

        if (!packages.length && !hotels.length && !activities.length) {
            el.innerHTML = '<div class="explore-card"><h4><i class="fa fa-suitcase-rolling"></i> Book This Destination</h4>' +
                '<p class="text-muted">No packages, hotels, or activities are currently listed for ' + escapeHtml(DEST.country || 'this destination') + ' in our catalog.</p></div>';
            return;
        }

        function row(href, img, title, meta, price, sample) {
            return '<a class="inventory-item" href="' + href + '">' +
                '<img loading="lazy" src="' + (img || 'images/no-image-placeholder.jpg') + '" alt="">' +
                '<div class="inventory-item-body"><strong>' + escapeHtml(title) + '</strong>' +
                (sample ? ' <span class="tms-sample-badge"><i class="fa fa-flask"></i> Sample</span>' : '') +
                '<span>' + escapeHtml(meta) + '</span></div>' +
                (price ? '<div class="inventory-item-price">' + escapeHtml(price) + '</div>' : '') +
                '</a>';
        }

        var pkgHtml = packages.map(function (p) {
            return row('package-details.php?pkgid=' + encodeURIComponent(p.PackageId), p.PackageImage, p.PackageName,
                p.PackageLocation || '', p.PackagePrice ? ('৳ ' + Number(p.PackagePrice).toLocaleString()) : '', false);
        }).join('') || '<p class="text-muted">No packages currently listed for ' + escapeHtml(DEST.country || 'here') + '.</p>';

        var hotelHtml = hotels.map(function (h) {
            var meta = (h.City || '') + (h.StarRating ? ' · ' + h.StarRating + '★' : '');
            return row('hotel-details.php?id=' + encodeURIComponent(h.HotelId), h.HotelImage, h.HotelName,
                meta, h.PricePerNight ? ('৳ ' + Number(h.PricePerNight).toLocaleString() + '/night') : '', !!Number(h.IsSampleData));
        }).join('') || '<p class="text-muted">No hotels currently listed for ' + escapeHtml(DEST.country || 'here') + '.</p>';

        var activityHtml = activities.map(function (a) {
            return row('activity-details.php?id=' + encodeURIComponent(a.ActivityId), a.ActivityImage, a.ActivityName,
                a.Category || (a.City || ''), a.PricePerPerson ? ('৳ ' + Number(a.PricePerPerson).toLocaleString() + '/person') : '', !!Number(a.IsSampleData));
        }).join('') || '<p class="text-muted">No activities currently listed for ' + escapeHtml(DEST.country || 'here') + '.</p>';

        el.innerHTML =
            '<div class="explore-card"><h4><i class="fa fa-box-open"></i> Packages in ' + escapeHtml(DEST.country || 'this destination') + '</h4>' + pkgHtml + '</div>' +
            '<div class="explore-card"><h4><i class="fa fa-bed"></i> Hotels</h4>' + hotelHtml + '</div>' +
            '<div class="explore-card"><h4><i class="fa fa-hiking"></i> Activities</h4>' + activityHtml + '</div>';
    }

    function renderOverview(info) {
        var el = document.getElementById('overviewContent');
        // Catalogue-only overview (no AI provider answered): hide cards that have no verified content instead of showing
        // empty or "undefined" boxes, and say plainly where the information comes from.
        var offline = info && info.source === 'catalogue';
        var noteHtml = offline ? '<div class="tms-sample-banner" style="margin-bottom:12px;"><i class="fa fa-info-circle"></i> ' +
            escapeHtml(info.ai_note || 'AI summary unavailable — showing verified catalogue information.') + '</div>' : '';
        function card(icon, title, inner, skip) {
            return skip ? '' : '<div class="explore-card"><h4><i class="fa fa-' + icon + '"></i> ' + title + '</h4>' + inner + '</div>';
        }
        var attractions = (info.attractions || []).map(function (a) {
            return '<div class="attraction-item"><div class="attraction-icon"><i class="fa fa-map-pin"></i></div>' +
                '<div><strong>' + escapeHtml(a.name) + '</strong><span>' + escapeHtml(a.description) + '</span></div></div>';
        }).join('') || '<p class="text-muted">No attraction data available.</p>';

        var foods = (info.famous_foods || []).map(function (f) {
            return '<div class="food-item"><div class="food-icon"><i class="fa fa-utensils"></i></div>' +
                '<div><strong>' + escapeHtml(f.name) + '</strong><span>' + escapeHtml(f.description) + '</span></div></div>';
        }).join('') || '<p class="text-muted">No food data available.</p>';

        var tips = (info.safety_tips || []).map(function (t) {
            return '<div class="safety-tip-row"><i class="fa fa-shield-alt"></i><span>' + escapeHtml(t) + '</span></div>';
        }).join('');

        var costs = info.estimated_costs || {};
        var emg = info.emergency_contacts;
        var emgHtml = '<p class="text-muted" style="font-size:13px;">' + escapeHtml(info.emergency_note || '') + '</p>';
        if (emg) {
            emgHtml += '<div class="emergency-box">' +
                (emg.police    ? '<div class="emergency-row"><span>Police</span><strong>' + escapeHtml(emg.police) + '</strong></div>' : '') +
                (emg.ambulance ? '<div class="emergency-row"><span>Ambulance</span><strong>' + escapeHtml(emg.ambulance) + '</strong></div>' : '') +
                (emg.fire      ? '<div class="emergency-row"><span>Fire</span><strong>' + escapeHtml(emg.fire) + '</strong></div>' : '') +
                (emg.helpline  ? '<div class="emergency-row"><span>Tourist Helpline</span><strong>' + escapeHtml(emg.helpline) + '</strong></div>' : '') +
                (emg.embassy   ? '<div class="emergency-row"><span>Embassy</span><strong>' + escapeHtml(emg.embassy) + '</strong></div>' : '') +
                '</div>';
        }

        el.innerHTML = noteHtml +
            '<div class="explore-card"><h4><i class="fa fa-info-circle"></i> Overview</h4><p>' + escapeHtml(info.overview) + '</p></div>' +
            card('landmark', 'Popular Attractions', attractions, offline && !(info.attractions || []).length) +
            card('scroll', 'Historical Background', '<p>' + escapeHtml(info.history) + '</p>', !info.history) +
            card('calendar-check', 'Best Time to Visit', '<p>' + escapeHtml(info.best_time_to_visit) + '</p>', !info.best_time_to_visit) +
            card('route', 'Getting There', '<p>' + escapeHtml(info.how_to_get_there) + '</p>', !info.how_to_get_there) +
            card('users', 'Local Culture', '<p>' + escapeHtml(info.local_culture) + '</p>', !info.local_culture) +
            card('utensils', 'Famous Foods', foods, offline && !(info.famous_foods || []).length) +
            (info.estimated_costs ? (
            '<div class="explore-card"><h4><i class="fa fa-wallet"></i> Estimated Travel Costs (per day)</h4>' +
                '<div class="cost-grid">' +
                    // DATA-ACCURACY FIX: these three fields are named
                    // *_per_day_usd (see ExploreService::getDestinationInfo's
                    // AI prompt) — they were being rendered with the site's
                    // Taka symbol, misstating an actual USD estimate as if it
                    // were ~80x smaller in BDT. Label them as USD instead of
                    // silently relabeling the currency.
                    '<div class="cost-box"><div class="lbl">Budget</div><div class="val">$' + escapeHtml(costs.budget_per_day_usd) + '<span class="cost-unit">/day USD</span></div></div>' +
                    '<div class="cost-box"><div class="lbl">Mid-range</div><div class="val">$' + escapeHtml(costs.midrange_per_day_usd) + '<span class="cost-unit">/day USD</span></div></div>' +
                    '<div class="cost-box"><div class="lbl">Luxury</div><div class="val">$' + escapeHtml(costs.luxury_per_day_usd) + '<span class="cost-unit">/day USD</span></div></div>' +
                '</div><p style="font-size:12px;color:var(--text-muted);margin-top:10px;">' + escapeHtml(costs.currency_note || '') + ' Figures are approximate, in US dollars.</p></div>'
            ) : '') +
            card('shield-alt', 'Safety Tips', tips, offline && !tips) +
            card('phone-alt', 'Emergency Contacts', emgHtml, offline && !emg);
    }

    // ── Map & Navigation tab (MapLibre via js/tms-map-pages.js) ─────────────────
    // The map library is loaded when the Map tab is first opened. Everything that needs the map goes through withMap(), which
    // starts the map if necessary and runs the callback once it is ready (or never, if the map failed — the rest of the page works).
    function withMap(fn) {
        if (state.mapReady) { fn(); return; }
        (state.mapQueue = state.mapQueue || []).push(fn);
        if (!state.mapStarting) initMap();
    }

    function mapFailed(box, msg) {
        box.innerHTML = '';
        var d = document.createElement('div');
        d.setAttribute('role', 'alert');
        d.style.cssText = 'padding:28px;text-align:center;color:#94a3b8;font-size:13px;';
        d.textContent = msg;
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'explore-retry-btn'; b.textContent = 'Try again'; b.style.marginLeft = '8px';
        b.addEventListener('click', function () { state.mapStarting = false; initMap(); });
        d.appendChild(document.createTextNode(' ')); d.appendChild(b);
        box.appendChild(d);
    }

    function initMap() {
        if (state.mapStarting) return;
        state.mapStarting = true;
        var box = document.getElementById('exploreMap');
        if (!window.TmsMapPages) { mapFailed(box, 'The map library could not be loaded.'); return; }
        box.innerHTML = '';
        TmsMapPages.mount(box, { base: '', lang: document.documentElement.lang || 'en', center: [DEST.lng, DEST.lat], zoom: 12 }).then(function (ctl) {
            state.ctl = ctl; state.map = ctl.map;
            state.destPins = TmsMapPages.pins(ctl, 'dest', { color: '#0F4C81', cluster: false, popup: true });
            state.destPins.set([{ lat: DEST.lat, lng: DEST.lng, title: DEST.name, subtitle: 'Your destination' }]);
            state.navPins = TmsMapPages.pins(ctl, 'nav', { color: '#00A8A8', cluster: false, popup: true });
            state.userPins = TmsMapPages.pins(ctl, 'user', { color: '#e74c3c', cluster: false, popup: true });
            state.catPins = TmsMapPages.pins(ctl, 'cat', { color: '#00A8A8', cluster: true, popup: false,
                onClick: function (item) { if (item && item.data) openPinPopup(item.data, item.idx, item.cat); } });
            ctl.onStyleReady(function (map) { drawRouteLayers(map); });
            // The base map already draws every mapped shop, hotel, clinic and so on: make those labels clickable.
            if (window.TmsPoiTiles && !state.baseClicks) {
                state.baseClicks = true;
                TmsPoiTiles.bindBaseMapClicks(state.map, function (poi) {
                    poi.distance_m = Math.round(TmsPoiTiles.haversine(DEST.lat, DEST.lng, poi.lat, poi.lng));
                    poi.type = String(poi.subclass || poi.cls || '').replace(/_/g, ' ');
                    openPinPopup(poi, -1, poi.category || 'attraction');
                });
            }
            state.mapReady = true;
            var q = state.mapQueue || []; state.mapQueue = [];
            q.forEach(function (fn) { try { fn(); } catch (e) { if (window.console) console.error(e); } });
            // Default view: nearby attractions, same as before.
            exploreLoadMapCategory('attraction');
        }, function () { mapFailed(box, 'The map could not be loaded right now. Check your connection.'); });
    }

    // Popup for a category pin, built with DOM nodes (no HTML strings: names/addresses come from live map data).
    function openPinPopup(p, idx, category) {
        if (!state.map || !window.maplibregl) return;
        if (state.pinPopup) state.pinPopup.remove();
        var w = document.createElement('div');
        w.className = 'tms-popup';
        var t = document.createElement('strong'); t.className = 'tms-popup-title'; t.textContent = p.name || ''; w.appendChild(t);
        var kind = p.type || p.subtype || ''; if (kind) { var kd = document.createElement('div'); kd.className = 'tms-popup-sub'; kd.textContent = kind; w.appendChild(kd); }
        if (p.address) { var ad = document.createElement('div'); ad.className = 'tms-popup-sub'; ad.textContent = p.address; w.appendChild(ad); }
        if (typeof p.distance_m === 'number') { var dm = document.createElement('div'); dm.className = 'tms-popup-sub'; dm.textContent = (p.distance_m >= 1000 ? (p.distance_m / 1000).toFixed(1) + ' km' : p.distance_m + ' m') + ' away'; w.appendChild(dm); }
        var row = document.createElement('div'); row.className = 'tms-popup-actions';
        var d = document.createElement('a'); d.href = '#'; d.textContent = 'View Details';
        d.className = 'tms-popup-link';
        d.addEventListener('click', function (e) { e.preventDefault(); openPlaceDetails(p, category); });
        var g = document.createElement('a'); g.href = '#'; g.textContent = 'Get Directions';
        g.className = 'tms-popup-link';
        g.addEventListener('click', function (e) { e.preventDefault(); exploreNavigateToPlace(p.lat, p.lng, p.name || ''); });
        row.appendChild(d); row.appendChild(g); w.appendChild(row);
        state.pinPopup = new maplibregl.Popup({ maxWidth: '260px', offset: 12 }).setLngLat([p.lng, p.lat]).setDOMContent(w).addTo(state.map);
    }

    // Route line (solid) or straight-line fallback (dashed). Re-added after a base-style switch by onStyleReady.
    var ROUTE_SRC = 'tms-route-src';
    function drawRouteLayers(map) {
        if (!map.getStyle()) return;
        if (!map.getSource(ROUTE_SRC)) map.addSource(ROUTE_SRC, { type: 'geojson', data: state.routeData || { type: 'FeatureCollection', features: [] } });
        if (!map.getLayer('tms-route-casing')) map.addLayer({ id: 'tms-route-casing', type: 'line', source: ROUTE_SRC, filter: ['==', ['get', 'dashed'], 0],
            layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 9, 'line-opacity': 0.9 } });
        if (!map.getLayer('tms-route-line')) map.addLayer({ id: 'tms-route-line', type: 'line', source: ROUTE_SRC, filter: ['==', ['get', 'dashed'], 0],
            layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#0b7f86', 'line-width': 5, 'line-opacity': 0.95 } });
        if (!map.getLayer('tms-route-dash')) map.addLayer({ id: 'tms-route-dash', type: 'line', source: ROUTE_SRC, filter: ['==', ['get', 'dashed'], 1],
            paint: { 'line-color': '#64748b', 'line-width': 4, 'line-dasharray': [2, 2] } });
    }
    function setRoute(latLngPairs, dashed) {
        var coords = [];
        (latLngPairs || []).forEach(function (pt) { if (pt && TmsMapPages.utils.validCoord(pt[0], pt[1])) coords.push([Number(pt[1]), Number(pt[0])]); });
        state.routeData = coords.length < 2 ? { type: 'FeatureCollection', features: [] }
            : { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { dashed: dashed ? 1 : 0 }, geometry: { type: 'LineString', coordinates: coords } }] };
        drawRouteLayers(state.map);
        var src = state.map.getSource(ROUTE_SRC); if (src) src.setData(state.routeData);
        if (coords.length >= 2) state.ctl.fitToItems(coords.map(function (c) { return { lng: c[0], lat: c[1] }; }), { padding: 48, maxZoom: 16 });
    }
    function clearRoute() { state.routeData = null; if (state.map && state.map.getSource(ROUTE_SRC)) state.map.getSource(ROUTE_SRC).setData({ type: 'FeatureCollection', features: [] }); }

    // The map used to ALWAYS show attraction pins only; any category's real nearby places can now be plotted (same data as the Nearby Places tab).
    var MAP_CATEGORY_ICONS = {
        hotel: ['#0F4C81', 'fa-bed'], restaurant: ['#e67e22', 'fa-utensils'],
        hospital: ['#c0392b', 'fa-hospital'], pharmacy: ['#8e44ad', 'fa-prescription-bottle-alt'],
        police: ['#2c3e50', 'fa-shield-alt'], atm: ['#27ae60', 'fa-money-bill-wave'],
        gas_station: ['#d35400', 'fa-gas-pump'], shopping_mall: ['#16a085', 'fa-shopping-bag'],
        tourist_info: ['#2980b9', 'fa-info-circle'], attraction: ['#00A8A8', 'fa-camera']
    };
    window.exploreLoadMapCategory = function (category) {
        if (!state.mapReady) { withMap(function () { exploreLoadMapCategory(category); }); return; }
        state.mapCategory = category;
        document.querySelectorAll('.map-cat-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.cat === category); b.setAttribute('aria-pressed', b.dataset.cat === category ? 'true' : 'false'); });

        // Clear the previous category's pins only -- destination/user/route layers are separate.
        state.catPins.clear();
        if (state.pinPopup) { state.pinPopup.remove(); state.pinPopup = null; }

        // A pin's popup offers both "View Details" (same modal as the Nearby Places cards) and "Get Directions".
        // state.mapPlaces holds the plotted list; each pin item carries its index and the place object.
        function plot(places) {
            state.mapPlaces = places || [];
            var conf = MAP_CATEGORY_ICONS[category] || MAP_CATEGORY_ICONS.attraction;
            var items = [];
            state.mapPlaces.slice(0, 20).forEach(function (p, idx) {
                if (!TmsMapPages.utils.validCoord(p.lat, p.lng)) return;
                items.push({ lat: Number(p.lat), lng: Number(p.lng), title: p.name || '', color: conf[0], data: p, idx: idx, cat: category });
            });
            state.catPins.set(items);
        }

        // Reuse the Nearby Places tab's already-fetched list when it's the
        // same category -- avoids firing the same live query twice.
        if (state.currentCategory === category && state.currentPlaces && state.currentPlaces.length) {
            plot(state.currentPlaces);
            return;
        }
        var status = document.getElementById('mapStatus');
        var token = state.mapToken = (state.mapToken || 0) + 1;
        if (status) { status.className = 'explore-source'; status.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Loading ' + escapeHtml((PLACE_LABELS[category] || [category])[0].toLowerCase()) + ' on the map...'; }
        exploreLivePlaces({ lat: DEST.lat, lng: DEST.lng, category: category, radius: 5000 }).then(function (res) {
            if (token !== state.mapToken) return;   // the visitor already picked another category
            if (!res.success) {
                if (status) { status.className = 'explore-source is-warn'; status.innerHTML = '<i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(res.error || 'Places are unavailable right now.') +
                    ' <button type="button" class="explore-retry-btn" onclick="exploreLoadMapCategory(\'' + category + '\')">Try again</button>'; }
                return;
            }
            var list = (res.data && (res.data.places || res.data.items)) || [];
            plot(list);
            var plotted = list.filter(function (p) { return p.lat && p.lng; }).length;
            if (status) {
                var note = exploreSourceNote(res);
                if (!list.length) { status.className = 'explore-source'; status.innerHTML = '<i class="fa fa-info-circle"></i> No ' + escapeHtml((PLACE_LABELS[category] || [category])[0].toLowerCase()) + ' found within 5 km.'; }
                else { status.className = 'explore-source' + (note && res.data.origin !== 'cached' ? ' is-warn' : ''); status.innerHTML = '<i class="fa fa-map-pin"></i> ' + plotted + ' of ' + list.length + ' shown on the map.' + (note ? ' ' + note.replace(/<[^>]+>/g, '') : ''); }
            }
        }).catch(function (err) {
            if (token !== state.mapToken) return;
            if (status) { status.className = 'explore-source is-warn'; status.innerHTML = '<i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(exploreErrText(err, 'places')) +
                ' <button type="button" class="explore-retry-btn" onclick="exploreLoadMapCategory(\'' + category + '\')">Try again</button>'; }
        });
    };


    // Prefer the site-wide toast (defined in includes/header.php) over alert(),
    // which blocks the page and looks broken on mobile.
    function exploreNotify(msg) {
        if (typeof tmsToast === 'function') { tmsToast(msg, 'info'); }
        else { alert(msg); }
    }

    window.exploreLocateMe = function () {
        var btn = document.getElementById('locateBtn');
        if (!navigator.geolocation) { exploreNotify('Your browser does not support location sharing. You can still browse the map manually.'); return; }
        if (!state.mapReady) { withMap(function () { exploreLocateMe(); }); return; }
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Locating...';
        // The browser permission prompt appears only because the visitor pressed "Locate Me".
        navigator.geolocation.getCurrentPosition(function (pos) {
            state.userLoc = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            btn.innerHTML = '<i class="fa fa-location-arrow"></i> Locate Me';
            state.userPins.set([{ lat: state.userLoc.lat, lng: state.userLoc.lng, title: 'You are here' }]);
            document.getElementById('routeModeBar').style.display = 'flex';
            exploreDrawRoute('driving');
        }, function () {
            btn.innerHTML = '<i class="fa fa-location-arrow"></i> Locate Me';
            exploreNotify('Could not get your location. Allow location access in your browser, then tap "Locate Me" again.');
        }, { enableHighAccuracy: true, timeout: 10000 });
    };

    // Clearing navTarget puts routing back on the destination itself.
    window.exploreRouteToDestination = function () {
        state.navTarget = null;
        if (state.navPins) state.navPins.clear();
        if (state.ctl) state.ctl.flyTo(DEST.lng, DEST.lat, 12);
        var activeBtn = document.querySelector('.map-mode-btn.active');
        exploreDrawRoute(activeBtn ? activeBtn.dataset.mode : 'driving');
    };

    window.exploreDrawRoute = function (mode) {
        if (!state.userLoc) { exploreLocateMe(); return; }
        if (!state.mapReady) { withMap(function () { exploreDrawRoute(mode); }); return; }
        var target = state.navTarget || DEST;
        document.querySelectorAll('.map-mode-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.mode === mode); });
        var summary = document.getElementById('routeSummary');
        summary.innerHTML = '<span class="item"><i class="fa fa-spinner fa-spin"></i> Calculating route...</span>';

        // api/explore.php proxies the routing engine server-side (cached, rate-limited): the browser never calls a routing server itself.
        exploreGet('route', {
            from_lat: state.userLoc.lat, from_lng: state.userLoc.lng,
            to_lat: target.lat, to_lng: target.lng, mode: mode
        }, 16000).then(function (res) {
            if (!res.success) {
                routeFallbackLine(target, summary, res.error);
                return;
            }
            var route = res.data.route;
            summary.innerHTML =
                '<span class="item"><i class="fa fa-map-marker"></i> To ' + escapeHtml(target.name || DEST.name) + '</span>' +
                '<span class="item"><i class="fa fa-road"></i> ' + escapeHtml(route.distance_text) + '</span>' +
                '<span class="item"><i class="fa fa-clock"></i> ' + escapeHtml(route.duration_text) + '</span>' +
                '<span class="item"><i class="fa fa-signal"></i> via ' + (route.source === 'osrm' ? 'OSRM' : 'Google Directions') + '</span>' +
                (state.navTarget
                    ? '<span class="item"><a href="#" onclick="exploreRouteToDestination();return false;">' +
                      '<i class="fa fa-undo"></i> Route back to ' + escapeHtml(DEST.name) + '</a></span>'
                    : '');
            if (route.geometry && route.geometry.length) setRoute(route.geometry, false);   // geometry arrives as [lat,lng] pairs
            else clearRoute();
        }).catch(function (err) {
            routeFallbackLine(target, summary, exploreErrText(err, 'the route'));
        });
    };

    // Routing server down or slow: still show a dashed straight line and the straight-line distance, clearly labelled.
    function routeFallbackLine(target, summary, msg) {
        var from = state.userLoc;
        var km = TmsMapPages.utils.validCoord(from.lat, from.lng) ? haversineKm(from.lat, from.lng, target.lat, target.lng) : 0;
        setRoute([[from.lat, from.lng], [target.lat, target.lng]], true);
        summary.innerHTML = '<span class="item" style="color:#b45309;"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(msg || 'Routing is unavailable right now.') + '</span>' +
            '<span class="item"><i class="fa fa-ruler"></i> About ' + km.toFixed(1) + ' km in a straight line</span>' +
            '<span class="item"><a href="#" onclick="exploreDrawRoute(document.querySelector(\'.map-mode-btn.active\') ? document.querySelector(\'.map-mode-btn.active\').dataset.mode : \'driving\');return false;"><i class="fa fa-redo"></i> Try again</a></span>';
    }
    function haversineKm(lat1, lng1, lat2, lng2) {
        var r = Math.PI / 180, a = lat1 * r, b = lat2 * r, dLat = (lat2 - lat1) * r, dLng = (lng2 - lng1) * r;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a) * Math.cos(b) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * 6371.0088 * Math.asin(Math.sqrt(h));
    }

    // "Get Directions" on a nearby-place card keeps visitors on the page: switch to the Map & Navigation tab, drop a pin on the
    // chosen place and draw the route from the visitor's location (asked only on their click).
    window.exploreNavigateToPlace = function (lat, lng, name) {
        lat = parseFloat(lat); lng = parseFloat(lng);
        if (!TmsMapPages.utils.validCoord(lat, lng)) {
            exploreNotify('We don\'t have exact coordinates for this place, so directions aren\'t available for it.');
            return;
        }
        state.navTarget = { lat: lat, lng: lng, name: name || 'this place' };
        exploreSwitchTab('map');
        withMap(function () {
            state.navPins.set([{ lat: lat, lng: lng, title: state.navTarget.name }]);
            state.ctl.flyTo(lng, lat, 15);
            document.getElementById('routeModeBar').style.display = 'flex';
            if (state.userLoc) {
                var activeBtn = document.querySelector('.map-mode-btn.active');
                exploreDrawRoute(activeBtn ? activeBtn.dataset.mode : 'driving');
            } else {
                exploreLocateMe();
            }
        });
    };

    // ── Nearby places tab ─────────────────────────────────────
    var PLACE_LABELS = {
        hotel: ['Hotels', 'fa-bed'], restaurant: ['Restaurants', 'fa-utensils'],
        hospital: ['Hospitals', 'fa-hospital'], pharmacy: ['Pharmacies', 'fa-prescription-bottle-alt'],
        police: ['Police Stations', 'fa-shield-alt'], atm: ['ATMs & Banks', 'fa-money-bill-wave'],
        gas_station: ['Gas Stations', 'fa-gas-pump'], shopping_mall: ['Shopping Malls', 'fa-shopping-bag'],
        tourist_info: ['Tourist Info Centers', 'fa-info-circle'], attraction: ['Attractions', 'fa-landmark']
    };

    window.loadPlaces = function (category) {
        state.placesLoaded = true;
        state.currentCategory = category;
        document.querySelectorAll('.place-cat-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.cat === category); b.setAttribute('aria-pressed', b.dataset.cat === category ? 'true' : 'false'); });
        var grid = document.getElementById('placesGrid');
        var label = PLACE_LABELS[category] ? PLACE_LABELS[category][0].toLowerCase() : 'places';
        var token = state.placesToken = (state.placesToken || 0) + 1;
        grid.setAttribute('aria-busy', 'true');
        grid.innerHTML = '<p class="explore-loading" style="padding:8px 0 14px;" role="status"><i class="fa fa-spinner fa-spin"></i> Finding ' + label + ' nearby...</p>' + exploreSkeleton(6);
        var retry = "loadPlaces('" + category + "')";

        exploreLivePlaces({ lat: DEST.lat, lng: DEST.lng, category: category }).then(function (res) {
            if (token !== state.placesToken) return;   // a newer category was chosen while this one was loading
            grid.removeAttribute('aria-busy');
            if (!res.success) {
                grid.innerHTML = exploreErrorBox(res.error || 'Nearby places are unavailable right now.', retry);
                return;
            }
            var places = (res.data && (res.data.places || res.data.items)) || [];
            state.currentPlaces = places;
            if (!places.length) {
                grid.innerHTML = exploreSourceNote(res) + '<div class="explore-empty"><i class="fa fa-search"></i> No ' + escapeHtml(label) + ' found within 4 km. Try another category or <a href="#" onclick="' + retry + ';return false;">search again</a>.</div>';
                return;
            }
            grid.innerHTML = exploreSourceNote(res) + '<div class="place-grid">' + places.map(renderPlaceCard).join('') + '</div>';
        }).catch(function (err) {
            if (token !== state.placesToken) return;
            grid.removeAttribute('aria-busy');
            grid.innerHTML = exploreErrorBox(exploreErrText(err, label), retry);
        });
    };

    document.getElementById('placesGrid').addEventListener('click', function (e) {
        var opener = e.target.closest('.place-card-open, .place-card-details-link');
        if (opener) {
            e.preventDefault();
            var p2 = state.currentPlaces[parseInt(opener.dataset.idx, 10)];
            if (p2) openPlaceDetails(p2, state.currentCategory);
            return;
        }
        var link = e.target.closest('.nav-link');
        if (link) {
            e.preventDefault();
            var p1 = state.currentPlaces[parseInt(link.dataset.idx, 10)];
            if (p1) exploreNavigateToPlace(p1.lat, p1.lng, p1.name);
        }
    });

    function renderPlaceCard(p, idx) {
        var distKm = (typeof p.distance_m === 'number') ? (p.distance_m / 1000).toFixed(1) + ' km away' : '';
        return '<div class="place-card">' +
            '<a href="#" class="place-card-open" data-idx="' + idx + '">' +
            (p.photo ? '<img class="photo" src="' + p.photo + '" alt="' + escapeHtml(p.name) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '') +
            // PROVENANCE (Round 73, master prompt item 65): Google's Places API
            // terms require showing each photo's attribution whenever the photo
            // itself is shown. This is Google's own html_attributions string
            // (server-fetched, never user-supplied), so it's rendered as-is
            // rather than escaped -- only ever present alongside a real photo.
            (p.photo && p.photo_attribution ? '<div class="photo-credit">Photo: ' + p.photo_attribution + '</div>' : '') +
            '<h6>' + escapeHtml(p.name) + '</h6>' +
            '</a>' +
            (p.rating ? '<div class="rating"><i class="fa fa-star"></i> ' + p.rating + ' / 5</div>' : '') +
            // FIX (Round 114, package/places/map upgrade -- finding #12):
            // PlaceDetailsService has always computed a real is_open_now for
            // the detail modal (from OSM opening_hours, or Google's live
            // open_now flag -- see ExploreService::parseOverpassPlaces()/
            // parseGooglePlaces(), which now attach it to the LIST results
            // too), but it was only ever shown after opening a place's full
            // detail modal. A visitor scanning a grid of a dozen hotels had no
            // way to tell which were open right now without clicking into
            // each one individually, even though the platform already knew.
            (p.is_open_now === true ? '<div class="pd-badge pd-open" style="margin-bottom:6px;display:inline-flex;"><i class="fa fa-clock"></i> Open now</div>' :
                p.is_open_now === false ? '<div class="pd-badge pd-closed" style="margin-bottom:6px;display:inline-flex;"><i class="fa fa-clock"></i> Closed now</div>' : '') +
            (p.address ? '<div class="meta"><i class="fa fa-map-marker"></i> ' + escapeHtml(p.address) + '</div>' : '') +
            (p.phone ? '<div class="meta"><i class="fa fa-phone"></i> ' + escapeHtml(p.phone) + '</div>' : '') +
            (p.opening_hours ? '<div class="meta"><i class="fa fa-clock"></i> ' + escapeHtml(p.opening_hours) + '</div>' : '') +
            (distKm ? '<div class="meta"><i class="fa fa-route"></i> ' + distKm + '</div>' : '') +
            (p.source === 'local' ? '<div class="meta"><i class="fa fa-database"></i> From our catalogue' + (p.is_sample ? ' (sample listing)' : '') + '</div>' : '') +
            '<div class="place-card-actions">' +
            (p.source === 'local' && p.local_url
                ? '<a class="nav-link" href="' + escapeHtml(p.local_url) + '"><i class="fa fa-arrow-right"></i> View on TourBuddy</a>'
                : '<a class="nav-link place-card-details-link" href="#" data-idx="' + idx + '"><i class="fa fa-circle-info"></i> View Details</a>') +
            (p.lat && p.lng ? '<a class="nav-link" href="#" data-idx="' + idx + '"><i class="fa fa-directions"></i> Get Directions</a>' : '') +
            '</div>' +
            '</div>';
    }

    // ── Place details modal ────────────────────────────────────
    // FIX (Round 114, package/places/map upgrade -- finding #13, dead code):
    // this used to list icons for 'rooms', 'menu', 'doctors', 'reviews' and
    // 'gallery' too. Those were section types from the pre-Round-14
    // PlaceDetailsService that fabricated room rates, a restaurant menu,
    // named doctors and reviews from a hash of the place's name --
    // PlaceDetailsService::sectionsFor() has only ever emitted 'info_grid',
    // 'list' and 'text' since that rewrite (see backend/PlaceDetailsService.php),
    // so those keys (and the matching branches in renderSection()/the
    // gallery+hero_image handling in renderPlaceDetail() below) were
    // permanently unreachable -- removed rather than left as a trap for a
    // future reader to assume still works.
    var SECTION_ICONS = { info_grid: 'fa-info-circle', list: 'fa-list', text: 'fa-align-left' };

    function openPlaceDetails(p, category) {
        var modal = document.getElementById('placeDetailModal');
        var body  = document.getElementById('placeDetailBody');
        if (!modal || !body) return;

        body.innerHTML = '<div class="explore-loading"><i class="fa fa-spinner fa-spin spin"></i>Loading details...</div>';
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';

        exploreGet('place_details', {
            category: category || 'hotel',
            name: p.name || '',
            lat: p.lat || '',
            lng: p.lng || '',
            address: p.address || '',
            phone: p.phone || '',
            opening_hours: p.opening_hours || '',
            website: p.website || '',
            rating: p.rating || '',
            distance_m: p.distance_m || '',
            // FIX (Round 114): pass through the real is_open_now the list
            // already computed (Overpass hours or Google's live open_now
            // flag) instead of letting PlaceDetailsService re-derive it from
            // opening_hours alone -- for a Google-sourced place, opening_hours
            // here is already the display string "Open now"/"Closed now" (see
            // ExploreService::parseGooglePlaces()), and re-parsing that as if
            // it were OSM hours syntax never actually resolves to true/false.
            is_open_now: (p.is_open_now === true || p.is_open_now === false) ? p.is_open_now : ''
        }).then(function (res) {
            if (!res.success) {
                body.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(res.error) + '</div>';
                return;
            }
            body.innerHTML = renderPlaceDetail(res.data.place);
        }).catch(function () {
            body.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> Could not load details. Please try again.</div>';
        });
    }
    window.openPlaceDetails = openPlaceDetails;

    window.closePlaceDetails = function () {
        var modal = document.getElementById('placeDetailModal');
        if (!modal) return;
        modal.classList.remove('open');
        document.body.style.overflow = '';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.closePlaceDetails();
    });

    function renderPlaceDetail(pl) {
        // ROOT-CAUSE FIX (Round 14): this renderer assumed every field was always
        // present, because the backend used to invent any it didn't have — an
        // email, a website, Facebook and Instagram profiles, a star rating with a
        // review count, a definitive Open/Closed badge, and five stock photos,
        // all derived from a hash of the place's name and shown as fact. The
        // backend now returns null for anything the map data doesn't actually
        // contain, so this renders "not listed" instead of a plausible lie, and
        // omits links entirely rather than pointing at a domain the business
        // does not own.
        var badges = '<div class="pd-badges">' +
            '<span class="pd-badge"><i class="fa fa-tag"></i> ' + escapeHtml(pl.category_label) + '</span>' +
            (pl.rating ? '<span class="pd-badge pd-rating"><i class="fa fa-star"></i> ' + pl.rating +
                (pl.review_count ? ' (' + pl.review_count + ')' : '') + '</span>' : '') +
            // is_open_now is now true / false / null, where null means the map
            // data didn't say — in which case no badge at all, rather than
            // asserting "Closed Now" on a place we know nothing about.
            (pl.is_open_now === true
                ? '<span class="pd-badge pd-open"><i class="fa fa-clock"></i> Open now</span>'
                : pl.is_open_now === false
                    ? '<span class="pd-badge pd-closed"><i class="fa fa-clock"></i> Closed now</span>'
                    : '') +
            (pl.distance_km != null ? '<span class="pd-badge"><i class="fa fa-route"></i> ' + pl.distance_km + ' km away</span>' : '') +
            '</div>';

        function row(icon, value, missingText) {
            return value
                ? '<div class="pd-contact-item"><i class="fa ' + icon + '"></i> ' + escapeHtml(value) + '</div>'
                : '<div class="pd-contact-item pd-missing"><i class="fa ' + icon + '"></i> ' + escapeHtml(missingText) + '</div>';
        }

        var phoneRow = pl.phone
            ? '<div class="pd-contact-item"><i class="fa fa-phone"></i> <a href="tel:' + escapeHtml(pl.phone.replace(/[^+\d]/g, '')) + '">' + escapeHtml(pl.phone) + '</a></div>'
            : (pl.category === 'hospital' || pl.category === 'police')
                ? '<div class="pd-contact-item pd-missing"><i class="fa fa-phone"></i> No number in the map data — in an emergency call your local emergency number</div>'
                : '<div class="pd-contact-item pd-missing"><i class="fa fa-phone"></i> Phone not listed</div>';

        var contact = '<div class="pd-contact-grid">' +
            row('fa-map-marker', pl.address, 'Address not listed — see the map below') +
            phoneRow +
            row('fa-clock', pl.opening_hours, 'Opening hours not listed') +
            (pl.website
                ? '<div class="pd-contact-item"><a href="' + escapeHtml(pl.website) + '" target="_blank" rel="noopener nofollow"><i class="fa fa-globe"></i> Official website</a></div>'
                : '<div class="pd-contact-item pd-missing"><i class="fa fa-globe"></i> No website listed</div>') +
            '<div class="pd-contact-item"><a href="' + escapeHtml(pl.map_url) + '" target="_blank" rel="noopener"><i class="fa fa-map"></i> Open in Google Maps</a></div>' +
            (pl.osm_url
                ? '<div class="pd-contact-item"><a href="' + escapeHtml(pl.osm_url) + '" target="_blank" rel="noopener"><i class="fa fa-map-o"></i> View / correct on OpenStreetMap</a></div>'
                : '') +
            '</div>';

        var map = '<div class="pd-map"><iframe src="' + escapeHtml(pl.map_embed_url) + '" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';

        var sections = (pl.sections || []).map(renderSection).join('');

        // No hero image, no gallery: PlaceDetailsService::build() always sets
        // hero_image => null and images => [] by explicit design (no stock
        // photography presented as photos of a specific place -- see its own
        // docblock), so there is nothing here to ever render for either.
        return '<div class="pd-header"><h2>' + escapeHtml(pl.name) + '</h2>' + badges + '</div>' +
            '<div class="explore-card"><h4><i class="fa fa-address-card"></i> Contact &amp; Location</h4>' + contact + map + '</div>' +
            sections;
    }

    function renderSection(s) {
        var icon = s.icon || SECTION_ICONS[s.type] || 'fa-circle';
        var head = '<h4><i class="fa ' + icon + '"></i> ' + escapeHtml(s.title) + '</h4>';

        if (s.type === 'text') {
            return '<div class="explore-card">' + head + '<p class="pd-text">' + escapeHtml(s.content) + '</p></div>';
        }
        if (s.type === 'list') {
            return '<div class="explore-card">' + head + '<div class="pd-list">' + s.items.map(function (i) {
                return '<div class="pd-list-item"><i class="fa fa-check"></i> ' + escapeHtml(i) + '</div>';
            }).join('') + '</div></div>';
        }
        if (s.type === 'info_grid') {
            return '<div class="explore-card">' + head + '<div class="pd-info-grid">' + s.items.map(function (i) {
                return '<div class="pd-info-item"><i class="fa ' + (i.icon || 'fa-circle') + '"></i><div><div class="lbl">' + escapeHtml(i.label) + '</div><div class="val">' + escapeHtml(i.value) + '</div></div></div>';
            }).join('') + '</div></div>';
        }
        return '';
    }

    // ── Weather tab ────────────────────────────────────────────
    function loadWeather() {
        var panel = document.getElementById('weatherContent');
        exploreGet('weather', { lat: DEST.lat, lng: DEST.lng }).then(function (res) {
            if (!res.success) {
                panel.innerHTML = exploreErrorBox(res.error || 'Weather is unavailable right now.', 'exploreRetryWeather()');
                return;
            }
            state.weather = res.data.weather;
            renderWeather(state.weather);
            updateExploreContext();
        }).catch(function (err) {
            panel.innerHTML = exploreErrorBox(exploreErrText(err, 'the weather'), 'exploreRetryWeather()');
        });
    }
    window.exploreRetryWeather = function () {
        var panel = document.getElementById('weatherContent');
        panel.innerHTML = '<div class="explore-loading"><i class="fa fa-spinner fa-spin spin"></i>Fetching live weather...</div>';
        loadWeather();
    };

    function renderWeather(w) {
        var c = w.current;
        var alerts = (w.alerts || []).map(function (a) {
            return '<div class="weather-alert ' + a.severity + '"><i class="fa fa-' + (a.severity === 'warning' ? 'exclamation-triangle' : 'info-circle') + '"></i>' + escapeHtml(a.message) + '</div>';
        }).join('');

        var hourly = (w.hourly || []).map(function (h) {
            var t = new Date(h.time);
            return '<div class="hour-chip"><div class="t">' + t.getHours() + ':00</div><i class="fa ' + weatherIcon(h.code) + '"></i><div class="temp">' + Math.round(h.temp) + '°</div></div>';
        }).join('');

        var daily = (w.daily || []).map(function (d) {
            // FIX (Round 14): `new Date("2026-09-04")` is parsed as UTC midnight
            // but formatted in local time, so every row was labelled with the
            // previous weekday for any viewer at a negative UTC offset.
            var parts = String(d.date).split('-');
            var dt = new Date(+parts[0], (+parts[1] || 1) - 1, +parts[2] || 1);
            var dayName = dt.toLocaleDateString(undefined, { weekday: 'short' });
            return '<div class="daily-row"><span class="day">' + dayName + '</span><i class="fa ' + weatherIcon(d.code) + '"></i>' +
                '<span>' + Math.round(d.tmax) + '° / ' + Math.round(d.tmin) + '°</span>' +
                '<span class="range">' + (d.pop != null ? d.pop + '% rain' : '') + '</span></div>';
        }).join('');

        // FIX (Round 14): the backend's aqiLabel(0) returns "Good", so a
        // location where the air-quality endpoint returned nothing was shown as
        // "— · Good" — a positive health claim next to a missing value.
        var aq = w.air_quality;
        var aqHtml = (aq && aq.us_aqi != null)
            ? '<div class="weather-stat"><div class="lbl">Air Quality</div><div class="val">' + aq.us_aqi +
              '</div><div class="lbl">' + escapeHtml(aq.label || '') + '</div></div>'
            : '';

        document.getElementById('weatherContent').innerHTML =
            '<div class="weather-hero-card">' +
                '<i class="fa ' + weatherIcon(c.code) + '" style="font-size:2em;"></i>' +
                '<div class="temp">' + (c.temp != null ? Math.round(c.temp) + '°C' : '—') + '</div>' +
                '<div class="desc">' + escapeHtml(c.description || '') +
                    (c.feels_like != null ? ' · Feels like ' + Math.round(c.feels_like) + '°C' : '') + '</div>' +
                '<div class="weather-stat-grid">' +
                    '<div class="weather-stat"><div class="lbl">Humidity</div><div class="val">' + (c.humidity != null ? c.humidity + '%' : '—') + '</div></div>' +
                    '<div class="weather-stat"><div class="lbl">Wind</div><div class="val">' + (c.wind_speed != null ? Math.round(c.wind_speed) + ' km/h' : '—') + '</div></div>' +
                    aqHtml +
                '</div>' +
            '</div>' +
            (alerts ? '<div class="explore-card"><h4><i class="fa fa-bell"></i> Weather Alerts</h4>' + alerts + '</div>' : '') +
            '<div class="explore-card"><h4><i class="fa fa-clock"></i> Hourly Forecast</h4><div class="hourly-scroll">' + hourly + '</div></div>' +
            '<div class="explore-card"><h4><i class="fa fa-calendar-week"></i> 7-Day Forecast</h4>' + daily + '</div>';
    }

    function weatherIcon(code) {
        var map = { 0:'fa-sun',1:'fa-sun',2:'fa-cloud-sun',3:'fa-cloud',45:'fa-smog',48:'fa-smog',
            51:'fa-cloud-rain',53:'fa-cloud-rain',55:'fa-cloud-rain',61:'fa-cloud-showers-heavy',
            63:'fa-cloud-showers-heavy',65:'fa-cloud-showers-heavy',71:'fa-snowflake',73:'fa-snowflake',
            75:'fa-snowflake',80:'fa-cloud-showers-heavy',81:'fa-cloud-showers-heavy',82:'fa-cloud-showers-heavy',
            95:'fa-bolt',96:'fa-bolt',99:'fa-bolt' };
        return map[code] || 'fa-cloud';
    }

    window.exploreRefreshPlaceCat = function (btn, cat) { loadPlaces(cat); };

    // ── Init ──────────────────────────────────────────────────
    loadOverview();
})();

/* The Explore chat now lives in the single site-wide assistant (backend/chatbot.php; "Ask about this place"). */

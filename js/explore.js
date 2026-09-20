/**
 * explore.js — Client-side logic for TMS Explore Mode
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

function exploreGet(action, params) {
    var qs = new URLSearchParams(Object.assign({ action: action }, params || {})).toString();
    return fetch(EXPLORE_API + '?' + qs).then(function (r) { return r.json(); });
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
        if (tab === 'map' && !state.map) initMap();
        if (tab === 'map' && state.map) setTimeout(function () { state.map.invalidateSize(); }, 100);
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

    // ── Local inventory: real packages/hotels/activities TMS actually
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

        el.innerHTML =
            '<div class="explore-card"><h4><i class="fa fa-info-circle"></i> Overview</h4><p>' + escapeHtml(info.overview) + '</p></div>' +
            '<div class="explore-card"><h4><i class="fa fa-landmark"></i> Popular Attractions</h4>' + attractions + '</div>' +
            '<div class="explore-card"><h4><i class="fa fa-scroll"></i> Historical Background</h4><p>' + escapeHtml(info.history) + '</p></div>' +
            '<div class="explore-card"><h4><i class="fa fa-calendar-check"></i> Best Time to Visit</h4><p>' + escapeHtml(info.best_time_to_visit) + '</p></div>' +
            '<div class="explore-card"><h4><i class="fa fa-users"></i> Local Culture</h4><p>' + escapeHtml(info.local_culture) + '</p></div>' +
            '<div class="explore-card"><h4><i class="fa fa-utensils"></i> Famous Foods</h4>' + foods + '</div>' +
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
                '</div><p style="font-size:12px;color:var(--text-muted);margin-top:10px;">' + escapeHtml(costs.currency_note || '') + ' Figures are approximate, in US dollars.</p></div>' +
            '<div class="explore-card"><h4><i class="fa fa-shield-alt"></i> Safety Tips</h4>' + tips + '</div>' +
            '<div class="explore-card"><h4><i class="fa fa-phone-alt"></i> Emergency Contacts</h4>' + emgHtml + '</div>';
    }

    // ── Map & Navigation tab ─────────────────────────────────
    function initMap() {
        state.map = L.map('exploreMap').setView([DEST.lat, DEST.lng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
        }).addTo(state.map);

        L.marker([DEST.lat, DEST.lng], { icon: destIcon() }).addTo(state.map)
            .bindPopup('<strong>' + escapeHtml(DEST.name) + '</strong><br>Your destination').openPopup();

        // Delegated from the map container, same pattern tour-map.php uses for
        // its own popup buttons -- Leaflet creates/destroys popup DOM nodes on
        // demand, so a listener bound once here (registered exactly once, since
        // initMap() itself only ever runs once) catches every pin's "View
        // Details" click regardless of when that pin's popup was built.
        document.getElementById('exploreMap').addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('.map-pin-details-link') : null;
            if (!link) return;
            e.preventDefault();
            var p = state.mapPlaces[parseInt(link.getAttribute('data-idx'), 10)];
            if (p) openPlaceDetails(p, state.mapCategory);
        });

        // Default view: nearby attractions, same as before this round.
        exploreLoadMapCategory('attraction');
    }

    // NEW (AI-map round): the map used to ALWAYS show attraction pins only —
    // switching to "Hotels" or "Restaurants" in the separate Nearby Places tab
    // never put a single pin on the map, even though the same real, already-
    // fetched place data (with real lat/lng) was sitting right there. This
    // lets the map tab plot any category's real nearby places at once,
    // reusing loadPlaces()'s cached result when it's the same category that's
    // already loaded (state.currentPlaces) instead of fetching it twice.
    var MAP_CATEGORY_ICONS = {
        hotel: ['#0F4C81', 'fa-bed'], restaurant: ['#e67e22', 'fa-utensils'],
        hospital: ['#c0392b', 'fa-hospital'], pharmacy: ['#8e44ad', 'fa-prescription-bottle-alt'],
        police: ['#2c3e50', 'fa-shield-alt'], atm: ['#27ae60', 'fa-money-bill-wave'],
        gas_station: ['#d35400', 'fa-gas-pump'], shopping_mall: ['#16a085', 'fa-shopping-bag'],
        tourist_info: ['#2980b9', 'fa-info-circle'], attraction: ['#00A8A8', 'fa-camera']
    };
    function mapCategoryIcon(category) {
        var conf = MAP_CATEGORY_ICONS[category] || MAP_CATEGORY_ICONS.attraction;
        return L.divIcon({ className: '', html: '<div style="background:' + conf[0] + ';width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.3);"><i class="fa ' + conf[1] + '" style="color:#fff;font-size:10px;"></i></div>', iconSize:[24,24], iconAnchor:[12,12] });
    }

    window.exploreLoadMapCategory = function (category) {
        if (!state.map) return;
        state.mapCategory = category;
        document.querySelectorAll('.map-cat-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.cat === category); });

        // Clear the previous category's pins only -- destination/user/route
        // markers are never pushed into state.markers, so this can't remove them.
        state.markers.forEach(function (m) { state.map.removeLayer(m); });
        state.markers = [];

        // FIX (Round 114, package/places/map upgrade -- finding #11): a pin's
        // popup only ever offered "Get Directions" -- clicking a place's card
        // on the Nearby Places tab shows a full details view (renderPlaceCard
        // gives "View Details" + "Get Directions"), but clicking the exact
        // same place's pin on the Map tab could never open that same modal.
        // state.mapPlaces holds the currently-plotted list so the delegated
        // click handler below (registered once, in initMap()) can look a pin
        // back up by index -- the same data-idx delegation pattern
        // loadPlaces()/renderPlaceCard() already use for cards, chosen here
        // over embedding the place name/data directly in an onclick attribute
        // (which breaks on quotes in a name, same class of bug tour-map.php's
        // own tmEsc() helper exists to avoid).
        function plot(places) {
            state.mapPlaces = places || [];
            state.mapPlaces.slice(0, 20).forEach(function (p, idx) {
                if (!p.lat || !p.lng) return;
                var m = L.marker([p.lat, p.lng], { icon: mapCategoryIcon(category) }).addTo(state.map)
                    .bindPopup('<strong>' + escapeHtml(p.name) + '</strong>' + (p.address ? '<br>' + escapeHtml(p.address) : '') +
                        '<br><a href="#" class="map-pin-details-link" data-idx="' + idx + '"><i class="fa fa-circle-info"></i> View Details</a>' +
                        ' &middot; <a href="#" onclick="exploreNavigateToPlace(' + p.lat + ',' + p.lng + ',\'' + escapeHtml(p.name).replace(/'/g, "\\'") + '\');return false;"><i class="fa fa-directions"></i> Get Directions</a>');
                state.markers.push(m);
            });
        }

        // Reuse the Nearby Places tab's already-fetched list when it's the
        // same category -- avoids firing the same live query twice.
        if (state.currentCategory === category && state.currentPlaces && state.currentPlaces.length) {
            plot(state.currentPlaces);
            return;
        }
        exploreGet('places', { lat: DEST.lat, lng: DEST.lng, category: category, radius: 5000 }).then(function (res) {
            if (res.success) plot(res.data.places);
        }).catch(function () {});
    };

    function destIcon() {
        return L.divIcon({ className: '', html: '<div style="background:var(--primary,#0F4C81);width:34px;height:34px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:flex;align-items:center;justify-content:center;box-shadow:0 3px 8px rgba(0,0,0,.3);"><i class="fa fa-flag" style="transform:rotate(45deg);color:#fff;font-size:14px;"></i></div>', iconSize:[34,34], iconAnchor:[17,34] });
    }
    function attractionIcon() {
        return L.divIcon({ className: '', html: '<div style="background:#00A8A8;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.3);"><i class="fa fa-camera" style="color:#fff;font-size:10px;"></i></div>', iconSize:[24,24], iconAnchor:[12,12] });
    }
    function userIcon() {
        return L.divIcon({ className: '', html: '<div style="background:#e74c3c;width:20px;height:20px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>', iconSize:[20,20], iconAnchor:[10,10] });
    }

    // Prefer the site-wide toast (defined in includes/header.php) over alert(),
    // which blocks the page and looks broken on mobile.
    function exploreNotify(msg) {
        if (typeof tmsToast === 'function') { tmsToast(msg, 'info'); }
        else { alert(msg); }
    }

    window.exploreLocateMe = function () {
        var btn = document.getElementById('locateBtn');
        if (!navigator.geolocation) { exploreNotify('Your browser does not support location sharing. You can still browse the map manually.'); return; }
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Locating...';
        navigator.geolocation.getCurrentPosition(function (pos) {
            state.userLoc = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            btn.innerHTML = '<i class="fa fa-location-arrow"></i> Locate Me';
            if (state.userMarker) state.map.removeLayer(state.userMarker);
            state.userMarker = L.marker([state.userLoc.lat, state.userLoc.lng], { icon: userIcon() })
                .addTo(state.map).bindPopup('You are here');
            document.getElementById('routeModeBar').style.display = 'flex';
            exploreDrawRoute('driving');
        }, function () {
            btn.innerHTML = '<i class="fa fa-location-arrow"></i> Locate Me';
            exploreNotify('Could not get your location. Allow location access in your browser, then tap "Locate Me" again.');
        }, { enableHighAccuracy: true, timeout: 10000 });
    };

    // Clearing navTarget puts routing back on the destination itself. Without
    // this, viewing directions to one nearby cafe meant every subsequent
    // "Locate Me" or travel-mode switch kept routing to that cafe forever.
    window.exploreRouteToDestination = function () {
        state.navTarget = null;
        if (state.navMarker) { state.map.removeLayer(state.navMarker); state.navMarker = null; }
        if (state.map) state.map.setView([DEST.lat, DEST.lng], 12);
        var activeBtn = document.querySelector('.map-mode-btn.active');
        exploreDrawRoute(activeBtn ? activeBtn.dataset.mode : 'driving');
    };

    window.exploreDrawRoute = function (mode) {
        if (!state.userLoc) { exploreLocateMe(); return; }
        var target = state.navTarget || DEST;
        document.querySelectorAll('.map-mode-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.mode === mode); });
        var summary = document.getElementById('routeSummary');
        summary.innerHTML = '<span class="item"><i class="fa fa-spinner fa-spin"></i> Calculating route...</span>';

        exploreGet('route', {
            from_lat: state.userLoc.lat, from_lng: state.userLoc.lng,
            to_lat: target.lat, to_lng: target.lng, mode: mode
        }).then(function (res) {
            if (!res.success) {
                summary.innerHTML = '<span class="item" style="color:#c0392b;"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(res.error) + '</span>';
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

            if (state.routeLayer) state.map.removeLayer(state.routeLayer);
            if (route.geometry && route.geometry.length) {
                state.routeLayer = L.polyline(route.geometry, { color: '#00A8A8', weight: 5, opacity: .85 }).addTo(state.map);
                state.map.fitBounds(state.routeLayer.getBounds(), { padding: [40, 40] });
            }
        }).catch(function () {
            summary.innerHTML = '<span class="item" style="color:#c0392b;"><i class="fa fa-exclamation-triangle"></i> Routing service unavailable right now.</span>';
        });
    };

    // ROOT-CAUSE FIX: "Get Directions" on a nearby-place card used to open
    // https://www.openstreetmap.org/directions in a new tab, taking visitors
    // off the site entirely. This keeps them on the page: it switches to the
    // existing (already-built) embedded Map & Navigation tab, drops a pin on
    // the chosen place, and draws the route with the same OSRM-backed
    // in-app routing used for the main destination — auto-detecting the
    // visitor's GPS location via navigator.geolocation exactly as before,
    // just now reusable for any nearby place instead of only the destination.
    window.exploreNavigateToPlace = function (lat, lng, name) {
        lat = parseFloat(lat); lng = parseFloat(lng);
        if (!lat || !lng) {
            exploreNotify('We don\'t have exact coordinates for this place, so directions aren\'t available for it.');
            return;
        }
        state.navTarget = { lat: lat, lng: lng, name: name || 'this place' };

        exploreSwitchTab('map');
        if (!state.map) initMap();

        if (state.navMarker) state.map.removeLayer(state.navMarker);
        state.navMarker = L.marker([lat, lng], { icon: attractionIcon() }).addTo(state.map)
            .bindPopup('<strong>' + escapeHtml(state.navTarget.name) + '</strong>').openPopup();
        state.map.setView([lat, lng], 15);

        document.getElementById('routeModeBar').style.display = 'flex';
        if (state.userLoc) {
            var activeBtn = document.querySelector('.map-mode-btn.active');
            exploreDrawRoute(activeBtn ? activeBtn.dataset.mode : 'driving');
        } else {
            exploreLocateMe();
        }
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
        document.querySelectorAll('.place-cat-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.cat === category); });
        var grid = document.getElementById('placesGrid');
        grid.innerHTML = '<div class="explore-loading"><i class="fa fa-spinner fa-spin spin"></i>Finding ' + (PLACE_LABELS[category] ? PLACE_LABELS[category][0].toLowerCase() : 'places') + ' nearby...</div>';

        exploreGet('places', { lat: DEST.lat, lng: DEST.lng, category: category }).then(function (res) {
            if (!res.success) {
                grid.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(res.error) + '</div>';
                return;
            }
            var places = res.data.places || [];
            state.currentPlaces = places;
            if (!places.length) {
                grid.innerHTML = '<p class="text-muted">No results found nearby. Try a different category or check back later.</p>';
                return;
            }
            grid.innerHTML = '<div class="place-grid">' + places.map(renderPlaceCard).join('') + '</div>';
        }).catch(function () {
            grid.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> Could not load nearby places. Please try again.</div>';
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
            (p.photo ? '<img class="photo" src="' + p.photo + '" alt="' + escapeHtml(p.name) + '">' : '') +
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
            '<div class="place-card-actions">' +
            '<a class="nav-link place-card-details-link" href="#" data-idx="' + idx + '"><i class="fa fa-circle-info"></i> View Details</a>' +
            '<a class="nav-link" href="#" data-idx="' + idx + '"><i class="fa fa-directions"></i> Get Directions</a>' +
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
                panel.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(res.error) + '</div>';
                return;
            }
            state.weather = res.data.weather;
            renderWeather(state.weather);
            updateExploreContext();
        }).catch(function () {
            panel.innerHTML = '<div class="explore-error"><i class="fa fa-exclamation-triangle"></i> Weather service is currently unavailable.</div>';
        });
    }

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

/* ══════════════════════════════════════════════════════════════
   FLOATING AI EXPLORE CHATBOT (explore-chatbot.php)
   ══════════════════════════════════════════════════════════════ */
(function initExploreChatbot() {
    var toggleBtn = document.getElementById('exploreChatToggle');
    if (!toggleBtn) return;

    var open = false;
    var sessionId = 'exp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

    window.toggleExploreChat = function () {
        open = !open;
        document.getElementById('exploreChatPanel').classList.toggle('open', open);
        if (open) {
            var msgs = document.getElementById('exploreChatMessages');
            if (!msgs.children.length) {
                var destName = document.body.dataset.exploreDest || 'your destination';
                addExploreMsg('bot', "Hi! I'm your AI travel assistant for " + destName + ". Ask me about attractions, food, transport, safety, weather-based activities, or anything else about your trip!");
            }
            document.getElementById('exploreChatInput').focus();
        }
    };

    function addExploreMsg(type, text) {
        var msgs = document.getElementById('exploreChatMessages');
        var div = document.createElement('div');
        div.className = 'exp-msg exp-msg-' + type;
        var formatted = escapeHtml(text).replace(/\n/g, '<br>');
        div.innerHTML = '<div class="exp-msg-bubble">' + formatted + '</div>';
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function showExploreTyping() {
        var msgs = document.getElementById('exploreChatMessages');
        var div = document.createElement('div');
        div.className = 'exp-msg exp-msg-bot exp-typing';
        div.id = 'expTyping';
        div.innerHTML = '<div class="exp-msg-bubble"><span class="exp-typing-dot"></span><span class="exp-typing-dot"></span><span class="exp-typing-dot"></span></div>';
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }
    function hideExploreTyping() { var el = document.getElementById('expTyping'); if (el) el.remove(); }

    window.sendExploreChat = function () {
        var input = document.getElementById('exploreChatInput');
        var text = input.value.trim();
        if (!text) return;
        input.value = '';
        addExploreMsg('user', text);
        showExploreTyping();

        var fd = new FormData();
        fd.append('message', text);
        fd.append('destination', document.body.dataset.exploreDest || '');
        fd.append('session_id', sessionId);
        fd.append('context', document.body.dataset.exploreContext || '');

        explorePost('chat', fd).then(function (res) {
            hideExploreTyping();
            addExploreMsg('bot', res.success ? res.data.reply : ('Sorry, I ran into an issue: ' + (res.error || 'please try again.')));
        }).catch(function () {
            hideExploreTyping();
            addExploreMsg('bot', "Sorry, I couldn't reach the AI assistant. Please check your connection and try again.");
        });
    };
})();

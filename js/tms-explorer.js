/*
 * tms-explorer.js — the tourism map explorer (frontend/tour-map.php).
 *
 * Talks ONLY to api/map.php (see the contract in js/tms-maplibre.js header / ENGINEERING notes):
 *   config · places · place · search · nearby · geocode · route · itinerary_* · suggest_correction
 * Renders with MapLibre GL through the shared TmsMapGL module.
 *
 * Rules this file follows:
 *  - Every string that comes from the API is written with textContent / setAttribute, never innerHTML
 *    (the only innerHTML is the project's own static SVG icons built from a validated colour).
 *  - Unknown (null) data is never shown and never treated as a negative.
 *  - Autocomplete queries our own database only (action=search). The public geocoder is called only after an
 *    explicit click / Enter with no local match.
 *  - Every request is cancellable, time-limited and guarded against stale responses.
 */
(function () {
    'use strict';

    var G = window.TmsMapGL;
    var root = document.getElementById('tmsExplorer');
    if (!root || !G) return;
    var U = G.utils;

    /* ------------------------------------------------------------------------------------------------ labels */
    var labels = {};
    try { labels = JSON.parse((document.getElementById('tmsExplorerLabels') || {}).textContent || '{}') || {}; } catch (e) { labels = {}; }
    function T(k, f) { return labels[k] !== undefined && labels[k] !== '' ? labels[k] : f; }
    function fmt(s) { var a = Array.prototype.slice.call(arguments, 1); return String(s).replace(/%(\d)/g, function (m, i) { return a[i - 1] !== undefined ? a[i - 1] : ''; }); }

    var API = root.getAttribute('data-api') || 'api/map.php';
    var BASE = root.getAttribute('data-base') || '';
    var LANG = root.getAttribute('data-lang') || 'en';
    var SIGNED_IN = root.getAttribute('data-signed-in') === '1';
    var LOGIN_URL = root.getAttribute('data-login-url') || 'login.php';
    var focusInfo = null;
    try { focusInfo = JSON.parse((document.getElementById('tmsExFocus') || {}).textContent || 'null'); } catch (e) { focusInfo = null; }

    /* ------------------------------------------------------------------------------------------------ helpers */
    function h(tag, props, kids) {
        var el = document.createElement(tag);
        props = props || {};
        Object.keys(props).forEach(function (k) {
            var v = props[k];
            if (v === undefined || v === null || v === false) return;
            if (k === 'class') el.className = v;
            else if (k === 'text') el.textContent = v;
            else if (k === 'on') Object.keys(v).forEach(function (ev) { el.addEventListener(ev, v[ev]); });
            else if (k === 'dataset') Object.keys(v).forEach(function (d) { el.dataset[d] = v[d]; });
            else el.setAttribute(k, v === true ? '' : v);
        });
        [].concat(kids || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
        });
        return el;
    }
    function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
    function $(sel, ctx) { return (ctx || root).querySelector(sel); }
    function announce(msg) { try { if (window.tmsA11y) window.tmsA11y.announce(msg, 'polite'); } catch (e) { /* ignore */ } }
    function debounce(fn, ms) { var t; var d = function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; d.cancel = function () { clearTimeout(t); }; return d; }
    function svgNode(svgString) {   // OUR static svg only (colour validated in U.iconSvg)
        var s = h('span', { class: 'tms-ic', 'aria-hidden': 'true' });
        s.innerHTML = svgString;
        return s;
    }
    function safeUrl(u) {
        if (!u || typeof u !== 'string') return null;
        var t = u.trim();
        if (/^(https?:)?\/\//i.test(t)) { return /^https?:\/\//i.test(t) || /^\/\//.test(t) ? t : null; }
        if (/^[a-z][a-z0-9+.\-]*:/i.test(t)) return null;          // javascript:, data:, mailto: …
        return t;
    }
    function localName(p) { return (LANG === 'bn' && p.name_bn) ? p.name_bn : (p.name || p.name_bn || ''); }
    function altName(p) { var a = (LANG === 'bn') ? p.name : p.name_bn; return a && a !== localName(p) ? a : ''; }
    function pid(p) { return String(p.id); }

    /* ------------------------------------------------------------------------------------------------ state */
    var state = {
        cfg: null, csrf: '', catById: {}, ctl: null, map: null,
        items: new Map(),              // id -> place (everything fetched this session)
        pinned: new Map(),             // places that must stay visible (selected, trip stops, search picks)
        shown: [],                     // places currently matching the filters (all fetched)
        hiddenUnknown: 0, total: null,
        selectedId: null, selected: null,
        filters: { categories: [], verified: false, family: false, accessible: false, free: false, q: '' },
        scope: 'view',                 // 'view' = viewport driven, 'query' = a text search result set
        cache: new Map(), viewCtl: null, viewSeq: 0, rendered: 60,
        from: null, to: null, pick: null, mode: 'driving', route: null, routeLoading: false,
        trip: { stops: [], mode: 'driving', route: null, stale: false, title: '', loading: false },
        detailCache: {}, geo: null, geoItems: [], iconCache: {}, layersReady: false, tab: 'explore'
    };
    var cleanups = [];
    function on(target, ev, fn, opt) { target.addEventListener(ev, fn, opt); cleanups.push(function () { target.removeEventListener(ev, fn, opt); }); }

    /* ------------------------------------------------------------------------------------------------ API */
    function mkErr(code, extra) { var e = new Error(code); e.code = code; e.data = extra || null; return e; }
    function apiCall(action, params, opt) {
        opt = opt || {};
        var url = API + (API.indexOf('?') >= 0 ? '&' : '?') + 'action=' + encodeURIComponent(action);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v !== undefined && v !== null && v !== '') url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
        });
        var ctl = new AbortController(), timedOut = false;
        var timer = setTimeout(function () { timedOut = true; ctl.abort(); }, opt.timeout || 12000);
        if (opt.signal) { if (opt.signal.aborted) ctl.abort(); else opt.signal.addEventListener('abort', function () { ctl.abort(); }, { once: true }); }
        var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctl.signal };
        if (opt.body !== undefined) {
            init.method = 'POST';
            init.headers['Content-Type'] = 'application/json';
            init.headers['X-CSRF-Token'] = state.csrf || '';
            init.body = JSON.stringify(opt.body);
        }
        return fetch(url, init).then(function (r) {
            return r.text().then(function (t) {
                var j = null;
                try { j = JSON.parse(t); } catch (e) { throw mkErr('bad_response'); }
                if (!r.ok || !j || j.ok === false) {
                    var code = j && j.error ? (typeof j.error === 'string' ? j.error : (j.error.code || 'error')) : ('http_' + r.status);
                    throw mkErr(code, j);
                }
                return j;
            });
        }).catch(function (e) {
            if (e && e.name === 'AbortError') throw mkErr(timedOut ? 'timeout' : 'aborted');
            if (e instanceof TypeError) throw mkErr('network');
            throw e;
        }).then(function (v) { clearTimeout(timer); return v; }, function (e) { clearTimeout(timer); throw e; });
    }
    function errText(e) {
        var c = e && e.code;
        if (c === 'timeout') return T('err_timeout', 'The service is taking too long. Please try again.');
        if (c === 'network') return T('err_network', 'You appear to be offline or the server cannot be reached.');
        if (c === 'rate_limited' || c === 'http_429') return T('err_rate', 'Too many requests. Please wait a moment and try again.');
        if (c === 'no_route') return T('err_noroute', 'No route could be found between these points.');
        if (c === 'unsupported_mode') return T('err_mode', 'That travel mode is not available.');
        if (c === 'auth_required' || c === 'http_401') return T('err_auth', 'Please sign in to do that.');
        return T('err_generic', 'Something went wrong. Please try again.');
    }

    /* ------------------------------------------------------------------------------------------------ config */
    function catLabel(id) { var c = state.catById[id]; if (!c) return id || ''; return (LANG === 'bn' && c.label_bn) ? c.label_bn : (c.label || id); }
    function catColor(id) { var c = state.catById[id]; return (c && c.color) || '#1B7F5F'; }
    function catIconKey(id) { var c = state.catById[id] || {}; return U.iconKey(c.icon, id); }
    function placeCatLabel(p) { return p.category_label || catLabel(p.category) || ''; }

    function loadConfig() {
        return apiCall('config', { lang: LANG }).then(function (j) {
            var cfg = j.config || j;                                  // tolerate {config:{…}} and flat shapes
            state.cfg = cfg; state.csrf = cfg.csrf || j.csrf || '';
            (cfg.categories || []).forEach(function (c) { state.catById[c.id] = c; });
            return cfg;
        });
    }

    /* ------------------------------------------------------------------------------------------------ layout skeleton */
    var inner = $('#tmsPanelInner'), body, elQ, elSuggest, elQClear, elFiltersBox, elBadges, elCount, elTabs = {}, panes = {};
    var elCards, elEmpty, elDetail, elExploreMain, elHidden, elScopeBtn, elMoreBtn, elGeoList;

    function buildPanel() {
        clear(inner);
        // --- search header (not inside the scrolling body, so the dropdown is never clipped)
        var head = h('div', { class: 'tms-ex-head' });
        var form = h('form', { class: 'tms-search', role: 'search', 'aria-label': T('search_label', 'Search places') });
        elQ = h('input', {
            type: 'text', id: 'tmsQ', class: 'tms-search-input', autocomplete: 'off', spellcheck: 'false', maxlength: '120',
            placeholder: T('search_ph', 'Search places, areas, hotels… (English / বাংলা)'),
            role: 'combobox', 'aria-expanded': 'false', 'aria-controls': 'tmsSuggest', 'aria-autocomplete': 'list', 'aria-label': T('search_label', 'Search places')
        });
        elQClear = h('button', { type: 'button', class: 'tms-search-clear', 'aria-label': T('clear', 'Clear'), hidden: true }, [h('span', { 'aria-hidden': 'true', text: '×' })]);
        elSuggest = h('ul', { id: 'tmsSuggest', class: 'tms-suggest', role: 'listbox', hidden: true });
        form.appendChild(h('span', { class: 'tms-search-ico fa fa-search', 'aria-hidden': 'true' }));
        form.appendChild(elQ); form.appendChild(elQClear); form.appendChild(elSuggest);
        head.appendChild(form);

        var filtersBtn = h('button', { type: 'button', class: 'tms-btn tms-filters-toggle', 'aria-expanded': 'false', 'aria-controls': 'tmsFilters' },
            [h('span', { class: 'fa fa-sliders', 'aria-hidden': 'true' }), ' ', T('filters', 'Filters')]);
        elBadges = h('div', { class: 'tms-badges', 'aria-label': T('active_filters', 'Active filters') });
        head.appendChild(h('div', { class: 'tms-filter-row' }, [filtersBtn, elBadges]));
        elFiltersBox = h('div', { id: 'tmsFilters', class: 'tms-filters', hidden: true });
        head.appendChild(elFiltersBox);
        buildFilters();
        on(filtersBtn, 'click', function () {
            var open = elFiltersBox.hidden;
            elFiltersBox.hidden = !open; filtersBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) setSheet('half');
        });

        // --- tabs
        var tabs = h('div', { class: 'tms-tabs', role: 'tablist' });
        [['explore', T('tab_explore', 'Explore'), 'fa-compass'], ['route', T('tab_route', 'Directions'), 'fa-road'], ['trip', T('tab_trip', 'My trip'), 'fa-list-ol']].forEach(function (t) {
            var b = h('button', { type: 'button', role: 'tab', class: 'tms-tab', id: 'tmsTab-' + t[0], 'aria-controls': 'tmsPane-' + t[0], 'aria-selected': t[0] === 'explore' ? 'true' : 'false', tabindex: t[0] === 'explore' ? '0' : '-1' },
                [h('span', { class: 'fa ' + t[2], 'aria-hidden': 'true' }), ' ', t[1]]);
            on(b, 'click', function () { setTab(t[0]); });
            on(b, 'keydown', function (e) {
                var order = ['explore', 'route', 'trip'], i = order.indexOf(t[0]);
                if (e.key === 'ArrowRight') { setTab(order[(i + 1) % 3], true); e.preventDefault(); }
                if (e.key === 'ArrowLeft') { setTab(order[(i + 2) % 3], true); e.preventDefault(); }
            });
            elTabs[t[0]] = b; tabs.appendChild(b);
        });
        head.appendChild(tabs);
        inner.appendChild(head);

        body = h('div', { class: 'tms-ex-body' });
        ['explore', 'route', 'trip'].forEach(function (k) {
            panes[k] = h('div', { id: 'tmsPane-' + k, class: 'tms-pane', role: 'tabpanel', 'aria-labelledby': 'tmsTab-' + k, hidden: k !== 'explore' });
            body.appendChild(panes[k]);
        });
        inner.appendChild(body);

        buildExplorePane(); buildRoutePane(); buildTripPane();
        wireSearch(form);
    }

    function setTab(name, focus) {
        state.tab = name;
        Object.keys(panes).forEach(function (k) {
            panes[k].hidden = k !== name;
            elTabs[k].setAttribute('aria-selected', k === name ? 'true' : 'false');
            elTabs[k].setAttribute('tabindex', k === name ? '0' : '-1');
        });
        if (focus) elTabs[name].focus();
        if (name === 'trip') renderTrip();
        if (name === 'route') renderRoute();
        updateTripLayers();
    }

    /* ------------------------------------------------------------------------------------------------ filters */
    function buildFilters() {
        clear(elFiltersBox);
        var cats = (state.cfg && state.cfg.categories) || [];
        if (cats.length) {
            var fs = h('fieldset', { class: 'tms-fieldset' }, [h('legend', { text: T('categories', 'Categories') })]);
            var wrap = h('div', { class: 'tms-chips' });
            cats.forEach(function (c) {
                var pressed = state.filters.categories.indexOf(c.id) >= 0;
                var b = h('button', { type: 'button', class: 'tms-chip', 'aria-pressed': pressed ? 'true' : 'false', dataset: { cat: c.id } });
                b.appendChild(svgNode(U.iconSvg(c.color, U.iconKey(c.icon, c.id), 20)));
                b.appendChild(h('span', { text: (LANG === 'bn' && c.label_bn) ? c.label_bn : c.label }));
                on(b, 'click', function () { toggleCategory(c.id); });
                wrap.appendChild(b);
            });
            fs.appendChild(wrap); elFiltersBox.appendChild(fs);
        }
        var opts = h('fieldset', { class: 'tms-fieldset' }, [h('legend', { text: T('only_show', 'Only show places that are') })]);
        [['verified', T('f_verified', 'Verified'), 'verified'], ['family', T('f_family', 'Family-friendly'), 'family'], ['accessible', T('f_access', 'Accessible'), 'accessible'], ['free', T('f_free', 'Free admission'), 'free']].forEach(function (o) {
            var id = 'tmsF-' + o[0];
            var cb = h('input', { type: 'checkbox', id: id });
            cb.checked = !!state.filters[o[2]];
            on(cb, 'change', function () { state.filters[o[2]] = cb.checked; filtersChanged(); });
            opts.appendChild(h('label', { class: 'tms-check', for: id }, [cb, ' ', o[1]]));
        });
        opts.appendChild(h('p', { class: 'tms-hint', text: T('filter_hint', 'Places with no recorded information for a filter are not shown for it, and are never assumed to fail it.') }));
        elFiltersBox.appendChild(opts);
        var reset = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-undo', 'aria-hidden': 'true' }), ' ', T('reset_filters', 'Reset filters')]);
        on(reset, 'click', resetFilters);
        elFiltersBox.appendChild(reset);
    }
    function syncFilterControls() {
        $$('.tms-chip', elFiltersBox).forEach(function (b) { b.setAttribute('aria-pressed', state.filters.categories.indexOf(b.dataset.cat) >= 0 ? 'true' : 'false'); });
        ['verified', 'family', 'accessible', 'free'].forEach(function (k) { var cb = $('#tmsF-' + k, elFiltersBox); if (cb) cb.checked = !!state.filters[k]; });
    }
    function $$(sel, ctx) { return [].slice.call((ctx || root).querySelectorAll(sel)); }
    function toggleCategory(id) {
        var i = state.filters.categories.indexOf(id);
        if (i >= 0) state.filters.categories.splice(i, 1); else state.filters.categories.push(id);
        syncFilterControls(); filtersChanged();
    }
    function resetFilters() {
        state.filters = { categories: [], verified: false, family: false, accessible: false, free: false, q: '' };
        elQ.value = ''; elQClear.hidden = true;
        state.scope = 'view'; syncFilterControls(); filtersChanged(); announce(T('filters_reset', 'Filters reset'));
    }
    function activeFilterCount() { var f = state.filters; return f.categories.length + (f.verified ? 1 : 0) + (f.family ? 1 : 0) + (f.accessible ? 1 : 0) + (f.free ? 1 : 0); }
    function renderBadges() {
        clear(elBadges);
        var f = state.filters;
        function badge(text, fn) {
            var b = h('button', { type: 'button', class: 'tms-badge', 'aria-label': fmt(T('remove_filter', 'Remove filter: %1'), text) }, [text, ' ', h('span', { 'aria-hidden': 'true', text: '×' })]);
            on(b, 'click', fn); elBadges.appendChild(b);
        }
        f.categories.forEach(function (c) { badge(catLabel(c), function () { toggleCategory(c); }); });
        [['verified', T('f_verified', 'Verified')], ['family', T('f_family', 'Family-friendly')], ['accessible', T('f_access', 'Accessible')], ['free', T('f_free', 'Free admission')]].forEach(function (o) {
            if (f[o[0]]) badge(o[1], function () { f[o[0]] = false; syncFilterControls(); filtersChanged(); });
        });
        var tb = $('.tms-filters-toggle');
        if (tb) { var n = activeFilterCount(); tb.setAttribute('data-count', n ? String(n) : ''); }
    }
    function filtersChanged() {
        renderBadges(); state.cache.clear();
        if (state.scope === 'query' && state.filters.q) runListSearch(state.filters.q, { silent: true }); else { state.scope = 'view'; loadView(); }
        scheduleHash();
    }

    /* ------------------------------------------------------------------------------------------------ search combobox */
    function makeCombo(o) {
        var input = o.input, list = o.list, seq = 0, ctl = null, items = [], active = -1, lastQ = '';
        var run = debounce(function () {
            var q = input.value.trim();
            lastQ = q;
            if (q.length < 2) { close(); return; }
            if (ctl) ctl.abort();
            ctl = new AbortController(); var my = ++seq;
            showStatus(T('searching', 'Searching…'));
            var c = state.map ? state.map.getCenter() : null;
            apiCall('search', { q: q, lat: c ? c.lat.toFixed(5) : '', lng: c ? c.lng.toFixed(5) : '', limit: 8, lang: LANG }, { signal: ctl.signal })
                .then(function (j) { if (my !== seq) return; items = (j.items || []).filter(function (p) { return p && U.validCoord(p.lat, p.lng); }); render(q); })
                .catch(function (e) { if (my !== seq || e.code === 'aborted') return; items = []; render(q, errText(e)); });
        }, 250);
        function open() { list.hidden = false; input.setAttribute('aria-expanded', 'true'); }
        function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
        function showStatus(t) { clear(list); list.appendChild(h('li', { class: 'tms-suggest-status', role: 'presentation', text: t })); open(); }
        function render(q, err) {
            clear(list); active = -1;
            if (err) list.appendChild(h('li', { class: 'tms-suggest-status', role: 'presentation', text: err }));
            else if (!items.length) list.appendChild(h('li', { class: 'tms-suggest-status', role: 'presentation', text: fmt(T('no_local', 'No matching places in our records for “%1”.'), q) }));
            items.forEach(function (p, i) {
                var li = h('li', { role: 'option', id: o.id + '-' + i, class: 'tms-suggest-item', 'aria-selected': 'false' });
                li.appendChild(svgNode(U.iconSvg(catColor(p.category), catIconKey(p.category), 24)));
                var t = h('span', { class: 'tms-suggest-text' });
                t.appendChild(h('strong', { text: localName(p) }));
                var sub = [placeCatLabel(p), p.locality || p.district || ''].filter(Boolean).join(' · ');
                if (sub) t.appendChild(h('span', { text: sub }));
                li.appendChild(t);
                on(li, 'mousedown', function (e) { e.preventDefault(); });
                on(li, 'click', function () { pick(i); });
                list.appendChild(li);
            });
            if (o.geocode) {
                var gi = items.length;
                var li2 = h('li', { role: 'option', id: o.id + '-' + gi, class: 'tms-suggest-item tms-suggest-geo', 'aria-selected': 'false' },
                    [h('span', { class: 'fa fa-globe', 'aria-hidden': 'true' }), h('span', { class: 'tms-suggest-text' }, [h('strong', { text: fmt(T('search_web', 'Search the wider web map for “%1”'), q) }), h('span', { text: T('search_web_note', 'Uses a public geocoder — sent only when you choose this.') })])]);
                on(li2, 'mousedown', function (e) { e.preventDefault(); });
                on(li2, 'click', function () { pick(gi); });
                list.appendChild(li2);
            }
            open();
        }
        function total() { return items.length + (o.geocode ? 1 : 0); }
        function setActive(i) {
            var opts = list.querySelectorAll('[role="option"]');
            [].forEach.call(opts, function (n, k) { n.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
            active = i;
            if (i >= 0 && opts[i]) { input.setAttribute('aria-activedescendant', opts[i].id); opts[i].scrollIntoView({ block: 'nearest' }); } else input.removeAttribute('aria-activedescendant');
        }
        function pick(i) {
            if (i < items.length) { var p = items[i]; close(); o.onPick(p, input.value.trim()); }
            else { close(); o.onGeocode(input.value.trim()); }
        }
        on(input, 'input', function () { if (o.onInput) o.onInput(input.value); if (input.value.trim().length < 2) { close(); run.cancel(); return; } run(); });
        on(input, 'keydown', function (e) {
            var n = total();
            if (e.key === 'ArrowDown') { if (list.hidden) { if (input.value.trim().length >= 2) run(); return; } if (n) { setActive((active + 1) % n); e.preventDefault(); } }
            else if (e.key === 'ArrowUp') { if (n) { setActive(active <= 0 ? n - 1 : active - 1); e.preventDefault(); } }
            else if (e.key === 'Enter') { if (!list.hidden && active >= 0) { e.preventDefault(); pick(active); } else if (o.onSubmit) { e.preventDefault(); close(); o.onSubmit(input.value.trim()); } }
            else if (e.key === 'Escape') { if (!list.hidden) { close(); e.stopPropagation(); } }
        });
        on(input, 'blur', function () { setTimeout(close, 150); });
        return { close: close, abort: function () { run.cancel(); if (ctl) ctl.abort(); seq++; close(); } };
    }

    var mainCombo;
    function wireSearch(form) {
        mainCombo = makeCombo({
            input: elQ, list: elSuggest, id: 'tmsSug', geocode: true,
            onInput: function (v) { elQClear.hidden = !v; },
            onPick: function (p) { state.filters.q = ''; selectPlace(p, { fly: true, pin: true }); },
            onGeocode: function (q) { runGeocode(q); },
            onSubmit: function (q) { if (q.length >= 2) runListSearch(q, {}); }
        });
        on(form, 'submit', function (e) { e.preventDefault(); });
        on(elQClear, 'click', function () {
            elQ.value = ''; elQClear.hidden = true; mainCombo.abort(); elQ.focus();
            if (state.scope === 'query') { state.filters.q = ''; state.scope = 'view'; loadView(); scheduleHash(); }
        });
    }

    /* ------------------------------------------------------------------------------------------------ explore pane */
    function buildExplorePane() {
        var p = panes.explore;
        elExploreMain = h('div', { class: 'tms-explore-main' });
        elCount = h('p', { class: 'tms-count', 'aria-live': 'polite', role: 'status' });
        elHidden = h('p', { class: 'tms-note', hidden: true });
        elScopeBtn = h('button', { type: 'button', class: 'tms-btn tms-btn-primary', hidden: true }, [h('span', { class: 'fa fa-crosshairs', 'aria-hidden': 'true' }), ' ', T('search_area', 'Search this map area')]);
        on(elScopeBtn, 'click', function () { state.filters.q = ''; elQ.value = ''; elQClear.hidden = true; state.scope = 'view'; loadView(); scheduleHash(); });
        elEmpty = h('div', { class: 'tms-empty', hidden: true });
        elCards = h('ul', { class: 'tms-cards', role: 'list' });
        elMoreBtn = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost', hidden: true, text: T('show_more', 'Show more') });
        on(elMoreBtn, 'click', function () { state.rendered += 60; renderCards(); });
        elGeoList = h('div', { class: 'tms-geo-list', hidden: true });
        [elCount, elHidden, elScopeBtn, elEmpty, elGeoList, elCards, elMoreBtn].forEach(function (n) { elExploreMain.appendChild(n); });
        elDetail = h('div', { class: 'tms-detail', hidden: true });
        p.appendChild(elExploreMain); p.appendChild(elDetail);
        var um = document.getElementById('tmsUnmapped');
        if (um) { um.hidden = false; p.appendChild(um); }
    }

    function applyFilters() {
        var all = [];
        state.items.forEach(function (p) { all.push(p); });
        var shown = [], hidden = 0;
        all.forEach(function (p) {
            var r = U.matchesFilters(p, state.filters);
            if (r.pass) shown.push(p); else if (r.unknown) hidden++;
        });
        state.shown = shown; state.hiddenUnknown = hidden;
    }

    function inView(p) {
        if (!state.map) return true;
        var b = state.map.getBounds();
        return p.lng >= b.getWest() && p.lng <= b.getEast() && p.lat >= b.getSouth() && p.lat <= b.getNorth();
    }

    function visibleList() {
        var list = state.scope === 'view' ? state.shown.filter(inView) : state.shown.slice();
        var origin = state.from || (state.map ? { lat: state.map.getCenter().lat, lng: state.map.getCenter().lng } : null);
        if (state.scope === 'view' && origin) {
            list.forEach(function (p) { p._d = U.haversine(origin.lat, origin.lng, p.lat, p.lng); });
            list.sort(function (a, b) { return a._d - b._d; });
        }
        return list;
    }

    function refreshAll() {
        applyFilters();
        updateSource();
        renderCards();
    }

    function renderCards() {
        var list = visibleList();
        var n = list.length;
        var txt = state.scope === 'query'
            ? fmt(n === 1 ? T('count_query_one', '%1 place found') : T('count_query', '%1 places found'), n)
            : fmt(n === 1 ? T('count_view_one', '%1 place in this area') : T('count_view', '%1 places in this area'), n);
        if (state.total !== null && state.scope === 'view' && state.total > state.items.size) txt += ' ' + fmt(T('count_of', '(showing the nearest %1 of %2 — zoom in to see more)'), state.items.size, state.total);
        elCount.textContent = txt;
        elHidden.hidden = !state.hiddenUnknown;
        if (state.hiddenUnknown) elHidden.textContent = fmt(T('hidden_unknown', '%1 more places have no recorded information for the selected filters, so they are not shown.'), state.hiddenUnknown);
        elScopeBtn.hidden = state.scope !== 'query';
        clear(elCards);
        elEmpty.hidden = n > 0 || state.loading;
        if (!n && !state.loading) renderEmpty();
        list.slice(0, state.rendered).forEach(function (p) { elCards.appendChild(cardFor(p)); });
        elMoreBtn.hidden = list.length <= state.rendered;
        highlightCard();
    }

    function renderEmpty() {
        clear(elEmpty);
        elEmpty.appendChild(h('p', { class: 'tms-empty-title', text: activeFilterCount() || state.filters.q ? T('empty_filtered', 'No places match right now.') : T('empty_area', 'No places recorded in this map area yet.') }));
        elEmpty.appendChild(h('p', { class: 'tms-hint', text: T('empty_help', 'Try zooming out, moving the map, or removing a filter.') }));
        if (activeFilterCount()) { var b = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost', text: T('reset_filters', 'Reset filters') }); on(b, 'click', resetFilters); elEmpty.appendChild(b); }
    }

    function thumbNode(p) {
        var wrap = h('span', { class: 'tms-card-thumb' });
        var img = p.image && (p.image.thumb || p.image.url);
        var url = safeUrl(img);
        function ph() { clear(wrap); wrap.classList.add('is-ph'); wrap.appendChild(svgNode(U.iconSvg(catColor(p.category), catIconKey(p.category), 40))); }
        if (url) {
            var im = h('img', { src: url, alt: (p.image && p.image.alt) || '', loading: 'lazy', decoding: 'async', width: '96', height: '72', referrerpolicy: 'no-referrer' });
            on(im, 'error', ph);
            wrap.appendChild(im);
        } else ph();
        return wrap;
    }

    function badgesFor(p) {
        var out = [];
        if (p.is_demo) out.push(h('span', { class: 'tms-tag tms-tag-demo', text: T('demo', 'Demo data') }));
        else if (p.verified === true) out.push(h('span', { class: 'tms-tag tms-tag-ok' }, [h('span', { class: 'fa fa-check-circle', 'aria-hidden': 'true' }), ' ', T('verified', 'Verified')]));
        else out.push(h('span', { class: 'tms-tag tms-tag-unv', text: T('unverified', 'Unverified') }));
        return out;
    }

    function cardFor(p) {
        var li = h('li', { class: 'tms-card-li' });
        var b = h('button', { type: 'button', class: 'tms-card', dataset: { id: pid(p) }, 'aria-label': localName(p) });
        b.appendChild(thumbNode(p));
        var t = h('span', { class: 'tms-card-text' });
        t.appendChild(h('span', { class: 'tms-card-name', text: localName(p) }));
        var cat = h('span', { class: 'tms-card-cat' });
        cat.appendChild(svgNode(U.iconSvg(catColor(p.category), catIconKey(p.category), 16)));
        cat.appendChild(document.createTextNode(' ' + placeCatLabel(p)));
        t.appendChild(cat);
        var loc = [p.locality, p.district].filter(Boolean).join(', ');
        if (loc) t.appendChild(h('span', { class: 'tms-card-loc' }, [h('span', { class: 'fa fa-map-marker', 'aria-hidden': 'true' }), ' ' + loc]));
        if (p.summary) t.appendChild(h('span', { class: 'tms-card-sum', text: p.summary }));
        var meta = h('span', { class: 'tms-card-meta' }, badgesFor(p));
        var dist = p.distance_m != null ? p.distance_m : p._d;
        if (dist != null && isFinite(dist)) meta.appendChild(h('span', { class: 'tms-dist', text: U.formatDistance(dist) + (state.from ? ' ' + T('from_start', 'from start') : '') }));
        if (p.rating && p.rating.avg != null && p.rating.count) meta.appendChild(h('span', { class: 'tms-rating', text: '★ ' + Number(p.rating.avg).toFixed(1) + ' (' + p.rating.count + ')' }));
        t.appendChild(meta);
        b.appendChild(t);
        on(b, 'click', function () { selectPlace(p, { fly: true }); });
        li.appendChild(b);
        return li;
    }

    function highlightCard() {
        $$('.tms-card', elCards).forEach(function (c) { c.classList.toggle('is-active', c.dataset.id === state.selectedId); c.setAttribute('aria-current', c.dataset.id === state.selectedId ? 'true' : 'false'); });
    }

    /* ------------------------------------------------------------------------------------------------ loading places */
    function paramsFor(extra) {
        var f = state.filters, p = {};
        if (f.categories.length) p.categories = f.categories.join(',');
        if (f.verified) p.verified = 1;
        if (f.family) p.family = 1;
        if (f.accessible) p.accessible = 1;
        if (f.free) p.free = 1;
        p.limit = 300; p.lang = LANG;
        Object.keys(extra || {}).forEach(function (k) { p[k] = extra[k]; });
        return p;
    }
    function setLoading(on_) { state.loading = on_; root.classList.toggle('is-loading', on_); if (on_) { elCount.textContent = T('loading', 'Loading places…'); elEmpty.hidden = true; } }

    var loadViewDebounced;
    function loadView() {
        if (state.scope !== 'view' || !state.map) return;
        var b = U.boundsToBbox(state.map.getBounds()), z = state.map.getZoom();
        var sig = U.filterSignature(state.filters);
        var key = U.bboxKey(b, z, sig);
        if (state.cache.has(key)) { ingest(state.cache.get(key), true); return; }
        var bb = U.snapBbox(b, z).map(function (n, i) { return Math.max(i % 2 ? -85 : -180, Math.min(i % 2 ? 85 : 180, n)); });
        if (state.viewCtl) state.viewCtl.abort();
        state.viewCtl = new AbortController(); var my = ++state.viewSeq;
        setLoading(true);
        apiCall('places', paramsFor({ bbox: bb.map(function (n) { return n.toFixed(5); }).join(',') }), { signal: state.viewCtl.signal })
            .then(function (j) { if (my !== state.viewSeq) return; state.cache.set(key, j); if (state.cache.size > 40) state.cache.delete(state.cache.keys().next().value); ingest(j, false); })
            .catch(function (e) { if (my !== state.viewSeq || e.code === 'aborted') return; setLoading(false); showLoadError(e, loadView); });
    }
    function ingest(j, fromCache) {
        // the view scope shows exactly this response (plus pinned places): drop everything else from the previous view
        state.items = new Map();
        (j.items || []).forEach(function (p) { if (p && U.validCoord(p.lat, p.lng)) state.items.set(pid(p), p); });
        state.pinned.forEach(function (p, k) { if (!state.items.has(k)) state.items.set(k, p); });
        state.total = j.total != null ? Number(j.total) : null;
        setLoading(false); refreshAll();
        if (!fromCache) announce(elCount.textContent);
    }
    function showLoadError(e, retry) {
        clear(elEmpty); elEmpty.hidden = false;
        elEmpty.appendChild(h('p', { class: 'tms-empty-title', text: errText(e) }));
        var b = h('button', { type: 'button', class: 'tms-btn tms-btn-primary', text: T('retry', 'Try again') });
        on(b, 'click', retry); elEmpty.appendChild(b);
        elCount.textContent = '';
    }

    function runListSearch(q, o) {
        o = o || {};
        state.filters.q = q; state.scope = 'query';
        if (state.viewCtl) state.viewCtl.abort();
        state.viewCtl = new AbortController(); var my = ++state.viewSeq;
        clearGeo(); setLoading(true); setTab('explore'); showList();
        var c = state.map.getCenter();
        apiCall('places', paramsFor({ q: q, lat: c.lat.toFixed(5), lng: c.lng.toFixed(5) }), { signal: state.viewCtl.signal })
            .then(function (j) {
                if (my !== state.viewSeq) return;
                state.items = new Map();
                (j.items || []).forEach(function (p) { if (p && U.validCoord(p.lat, p.lng)) state.items.set(pid(p), p); });
                state.pinned.forEach(function (p, k) { if (!state.items.has(k)) state.items.set(k, p); });
                state.total = j.total != null ? Number(j.total) : null;
                setLoading(false); refreshAll();
                var list = state.shown;
                if (list.length) { ctl().fitToItems(list, { maxZoom: 14 }); announce(elCount.textContent); }
                else if (!o.silent && !activeFilterCount()) { runGeocode(q); }       // Enter with no local match = explicit request
                scheduleHash();
            })
            .catch(function (e) { if (my !== state.viewSeq || e.code === 'aborted') return; setLoading(false); showLoadError(e, function () { runListSearch(q, o); }); });
    }

    /* ------------------------------------------------------------------------------------------------ geocode (explicit only) */
    function runGeocode(q) {
        if (!q || q.length < 2) return;
        setTab('explore'); showList();
        clear(elGeoList); elGeoList.hidden = false;
        elGeoList.appendChild(h('p', { class: 'tms-note', text: T('geo_searching', 'Searching the wider web map…') }));
        apiCall('geocode', { q: q, lang: LANG }, { timeout: 15000 }).then(function (j) {
            var items = (j.items || []).filter(function (g) { return U.validCoord(g.lat, g.lng); });
            clear(elGeoList);
            elGeoList.appendChild(h('h3', { class: 'tms-h3', text: T('geo_title', 'Results from the wider web map') }));
            if (!items.length) { elGeoList.appendChild(h('p', { class: 'tms-note', text: fmt(T('geo_none', 'Nothing found for “%1”. Check the spelling or try a nearby town.'), q) })); return; }
            elGeoList.appendChild(h('p', { class: 'tms-hint', text: T('geo_note', 'These are general map results, not verified TMS destinations.') }));
            var ul = h('ul', { class: 'tms-geo-ul', role: 'list' });
            items.slice(0, 8).forEach(function (g, i) {
                var b = h('button', { type: 'button', class: 'tms-geo-item' }, [h('strong', { text: g.name || g.display }), h('span', { text: g.display || '' })]);
                on(b, 'click', function () {
                    var pseudo = { id: 'geo:' + i + ':' + g.lat + ',' + g.lng, name: g.name || g.display, name_bn: null, category: '', category_label: g.type || '', summary: g.display || '', lat: Number(g.lat), lng: Number(g.lng), verified: false, source: 'geocode', is_demo: false, _pseudo: true, image: null, facilities: [] };
                    state.geo = pseudo; updateGeoLayer();
                    if (g.bbox && g.bbox.length === 4) state.map.fitBounds([[g.bbox[0], g.bbox[1]], [g.bbox[2], g.bbox[3]]], { padding: 50, maxZoom: 15, duration: G.reducedMotion() ? 0 : 500 }); else ctl().flyTo(pseudo.lng, pseudo.lat, 14);
                    selectPlace(pseudo, { fly: false });
                });
                ul.appendChild(h('li', {}, [b]));
            });
            elGeoList.appendChild(ul);
            announce(fmt(T('geo_found', '%1 results from the wider map'), items.length));
        }).catch(function (e) {
            clear(elGeoList); elGeoList.hidden = false;
            elGeoList.appendChild(h('p', { class: 'tms-note tms-note-err', text: errText(e) }));
        });
    }
    function clearGeo() { state.geo = null; updateGeoLayer(); if (elGeoList) { elGeoList.hidden = true; clear(elGeoList); } }

    /* ------------------------------------------------------------------------------------------------ selection + detail */
    function showList() { elDetail.hidden = true; elExploreMain.hidden = false; }

    function selectPlace(p, o) {
        o = o || {};
        if (!p || !U.validCoord(p.lat, p.lng)) return;
        state.selectedId = pid(p); state.selected = p;
        if (o.pin !== false) { state.pinned.set(pid(p), p); }
        if (!state.items.has(pid(p)) && !p._pseudo) state.items.set(pid(p), p);
        updateSelectedLayer(); updateSource();
        if (o.fly !== false) ctl().flyTo(Number(p.lng), Number(p.lat), Math.max(state.map.getZoom(), 14));
        setTab('explore'); renderDetail(p, state.detailCache[pid(p)] || null);
        highlightCard();
        var card = $('.tms-card[data-id="' + (window.CSS && CSS.escape ? CSS.escape(pid(p)) : pid(p)) + '"]', elCards);
        if (card && card.scrollIntoView) card.scrollIntoView({ block: 'nearest' });
        if (!p._pseudo) {
            apiCall('place', { id: p.id, lang: LANG }).then(function (j) {
                var full = j.place || j.item || j;
                state.detailCache[pid(p)] = full;
                if (state.selectedId === pid(p)) renderDetail(Object.assign({}, p, full), full);
            }).catch(function () { /* the basic card stays; no fabricated extras */ });
        }
        setSheet('half'); scheduleHash();
        announce(fmt(T('selected', 'Selected %1'), localName(p)));
    }

    function clearSelection() {
        state.selectedId = null; state.selected = null;
        updateSelectedLayer(); showList(); highlightCard(); scheduleHash();
        state.geo = null; updateGeoLayer();
    }

    function infoRow(label, value) {
        return h('div', { class: 'tms-kv' }, [h('dt', { text: label }), h('dd', { text: value })]);
    }
    function hoursText(oh) {
        if (!oh) return '';
        if (typeof oh === 'string') return oh;
        if (Array.isArray(oh)) return oh.filter(Boolean).join(' · ');
        if (typeof oh === 'object') return Object.keys(oh).map(function (k) { return k + ': ' + oh[k]; }).join(' · ');
        return '';
    }
    function feeText(fee) {
        if (fee === null || fee === undefined || fee === '') return '';
        if (typeof fee === 'string') return fee;
        if (typeof fee === 'number') return fee === 0 ? T('free', 'Free') : String(fee);
        if (typeof fee === 'object') {
            if (fee.text) return String(fee.text);
            var fr = U.feeIsFree(fee);
            if (fr === true) return T('free', 'Free');
            var amt = fee.amount !== undefined ? fee.amount : fee.adult;
            if (amt !== undefined && amt !== null && amt !== '') return String(amt) + (fee.currency ? ' ' + fee.currency : '');
        }
        return '';
    }
    function accText(a) {
        if (!a) return '';
        if (typeof a === 'string') return a;
        if (typeof a === 'object') { if (a.text) return String(a.text); var s = U.accessibleState(a); return s === true ? T('acc_yes', 'Step-free / wheelchair access reported') : s === false ? T('acc_no', 'Limited accessibility reported') : ''; }
        return '';
    }

    function renderDetail(p, full) {
        elExploreMain.hidden = true; elDetail.hidden = false; clear(elDetail);
        var back = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost tms-back' }, [h('span', { class: 'fa fa-arrow-left', 'aria-hidden': 'true' }), ' ', T('back_results', 'Back to results')]);
        on(back, 'click', clearSelection);
        elDetail.appendChild(back);

        var gallery = (full && full.gallery && full.gallery.length) ? full.gallery : (p.image ? [p.image] : []);
        gallery = gallery.filter(function (g) { return g && safeUrl(g.url || g.thumb); });
        var hero = h('div', { class: 'tms-hero' });
        var heroCredit = h('p', { class: 'tms-credit' });
        function showImg(g) {
            clear(hero);
            var im = h('img', { src: safeUrl(g.url || g.thumb), alt: g.alt || localName(p), loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' });
            on(im, 'error', function () { clear(hero); hero.classList.add('is-ph'); hero.appendChild(svgNode(U.iconSvg(catColor(p.category), catIconKey(p.category), 64))); heroCredit.textContent = ''; });
            hero.classList.remove('is-ph'); hero.appendChild(im);
            heroCredit.textContent = g.credit ? fmt(T('photo_credit', 'Photo: %1'), g.credit) : '';
        }
        if (gallery.length) showImg(gallery[0]); else { hero.classList.add('is-ph'); hero.appendChild(svgNode(U.iconSvg(catColor(p.category), catIconKey(p.category), 64))); }
        elDetail.appendChild(hero); elDetail.appendChild(heroCredit);
        if (gallery.length > 1) {
            var strip = h('div', { class: 'tms-gallery', role: 'group', 'aria-label': T('gallery', 'Photo gallery') });
            gallery.slice(0, 12).forEach(function (g, i) {
                var tb = h('button', { type: 'button', class: 'tms-gthumb', 'aria-label': fmt(T('photo_n', 'Show photo %1'), i + 1) });
                tb.appendChild(h('img', { src: safeUrl(g.thumb || g.url), alt: '', loading: 'lazy', decoding: 'async', width: '64', height: '48', referrerpolicy: 'no-referrer' }));
                on(tb, 'click', function () { showImg(g); });
                strip.appendChild(tb);
            });
            elDetail.appendChild(strip);
        }

        elDetail.appendChild(h('h2', { class: 'tms-detail-name', text: localName(p), tabindex: '-1', id: 'tmsDetailName' }));
        if (altName(p)) elDetail.appendChild(h('p', { class: 'tms-altname', text: altName(p), lang: LANG === 'bn' ? 'en' : 'bn' }));
        var tags = h('p', { class: 'tms-detail-tags' }, badgesFor(p));
        var cat = placeCatLabel(p);
        if (cat) tags.insertBefore(h('span', { class: 'tms-tag tms-tag-cat' }, [svgNode(U.iconSvg(catColor(p.category), catIconKey(p.category), 16)), ' ' + cat]), tags.firstChild);
        elDetail.appendChild(tags);
        var loc = [p.locality, p.district, p.region].filter(Boolean).join(', ');
        if (loc) elDetail.appendChild(h('p', { class: 'tms-detail-loc' }, [h('span', { class: 'fa fa-map-marker', 'aria-hidden': 'true' }), ' ' + loc]));
        if (p.is_demo) elDetail.appendChild(h('p', { class: 'tms-note', text: T('demo_note', 'Sample listing — information may be placeholder content and is not live availability.') }));
        else if (p.verified !== true && !p._pseudo) elDetail.appendChild(h('p', { class: 'tms-note', text: T('unverified_note', 'This record has not been verified by TMS staff yet.') }));
        if (p._pseudo) elDetail.appendChild(h('p', { class: 'tms-note', text: T('pseudo_note', 'General map result — not a verified TMS destination.') }));
        var summary = (full && full.description) || p.summary;
        if (summary) elDetail.appendChild(h('p', { class: 'tms-detail-sum', text: summary }));

        var dl = h('dl', { class: 'tms-kvs' });
        if (p.rating && p.rating.avg != null && p.rating.count) dl.appendChild(infoRow(T('rating', 'Rating'), '★ ' + Number(p.rating.avg).toFixed(1) + ' · ' + p.rating.count + ' ' + T('reviews', 'reviews')));
        var oh = hoursText(p.opening_hours); if (oh) dl.appendChild(infoRow(T('hours', 'Opening hours'), oh));
        var ft = feeText(p.fee); if (ft) dl.appendChild(infoRow(T('fee', 'Admission'), ft));
        var at = accText(p.accessibility); if (at) dl.appendChild(infoRow(T('accessibility', 'Accessibility'), at));
        if (p.family_friendly === true) dl.appendChild(infoRow(T('family', 'Family'), T('family_yes', 'Family-friendly')));
        if (p.facilities && p.facilities.length) dl.appendChild(infoRow(T('facilities', 'Facilities'), p.facilities.join(', ')));
        if (p.source && !p._pseudo) dl.appendChild(infoRow(T('source', 'Data source'), String(p.source)));
        if (dl.childNodes.length) elDetail.appendChild(dl);

        var acts = h('div', { class: 'tms-actions' });
        var d = safeUrl(p.links && p.links.details);
        if (d) acts.appendChild(h('a', { class: 'tms-btn tms-btn-primary', href: d }, [h('span', { class: 'fa fa-info-circle', 'aria-hidden': 'true' }), ' ', T('view_details', 'View details')]));
        var bd = h('button', { type: 'button', class: 'tms-btn' }, [h('span', { class: 'fa fa-location-arrow', 'aria-hidden': 'true' }), ' ', T('directions', 'Directions')]);
        on(bd, 'click', function () { setTo(p); setTab('route'); });
        acts.appendChild(bd);
        var inTrip = state.trip.stops.some(function (s) { return s.key === pid(p); });
        var bt = h('button', { type: 'button', class: 'tms-btn' }, [h('span', { class: 'fa fa-plus', 'aria-hidden': 'true' }), ' ', inTrip ? T('in_trip', 'In your trip') : T('add_trip', 'Add to itinerary')]);
        if (inTrip) bt.disabled = true;
        on(bt, 'click', function () { addStop(p); bt.disabled = true; clear(bt); bt.appendChild(document.createTextNode(T('in_trip', 'In your trip'))); });
        acts.appendChild(bt);
        var pkgId = p.package_id || (p.source === 'package' && /^\d+$/.test(String(p.id)) ? p.id : null);
        if (SIGNED_IN && pkgId) {
            var bw = h('button', { type: 'button', class: 'tms-btn' }, [h('span', { class: 'fa fa-heart-o', 'aria-hidden': 'true' }), ' ', T('save_fav', 'Save')]);
            on(bw, 'click', function () { toggleWishlist(pkgId, bw); });
            acts.appendChild(bw);
        }
        var bs = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-link', 'aria-hidden': 'true' }), ' ', T('share', 'Copy link')]);
        on(bs, 'click', function () { copyLink(bs); });
        acts.appendChild(bs);
        elDetail.appendChild(acts);

        if (!p._pseudo) {
            elDetail.appendChild(nearbySection(p));
            elDetail.appendChild(correctionSection(p));
        }
        if (full && full.related && full.related.length) {
            var rel = h('section', { class: 'tms-section' }, [h('h3', { class: 'tms-h3', text: T('related', 'Related places') })]);
            var ul = h('ul', { class: 'tms-mini-list', role: 'list' });
            full.related.slice(0, 6).forEach(function (r) { if (r && U.validCoord(r.lat, r.lng)) ul.appendChild(miniItem(r)); });
            rel.appendChild(ul); elDetail.appendChild(rel);
        }
        var nm = $('#tmsDetailName'); if (nm) { try { nm.focus({ preventScroll: true }); } catch (e) { nm.focus(); } }
        elDetail.scrollTop = 0; body.scrollTop = 0;
    }

    function miniItem(r) {
        var b = h('button', { type: 'button', class: 'tms-mini' }, [h('strong', { text: localName(r) }), h('span', { text: [placeCatLabel(r), r.distance_m != null ? U.formatDistance(r.distance_m) : ''].filter(Boolean).join(' · ') })]);
        on(b, 'click', function () { selectPlace(r, { fly: true }); });
        return h('li', {}, [b]);
    }

    function nearbySection(p) {
        var sec = h('details', { class: 'tms-section tms-nearby' });
        sec.appendChild(h('summary', { text: T('nearby', 'Nearby places') }));
        var radiusId = 'tmsRadius';
        var sel = h('select', { id: radiusId, class: 'tms-select', 'aria-label': T('radius', 'Search radius') });
        [1, 2, 5, 10, 25].forEach(function (km) { var o = h('option', { value: String(km * 1000), text: km + ' km' }); if (km === 5) o.selected = true; sel.appendChild(o); });
        var out = h('div', { class: 'tms-nearby-out', 'aria-live': 'polite' });
        var seq = 0;
        function load() {
            var my = ++seq;
            clear(out); out.appendChild(h('p', { class: 'tms-note', text: T('loading', 'Loading places…') }));
            apiCall('nearby', { id: p.id, radius_m: sel.value, limit: 12, lang: LANG }).then(function (j) {
                if (my !== seq) return;
                var items = (j.items || []).filter(function (r) { return r && pid(r) !== pid(p) && U.validCoord(r.lat, r.lng); });
                items.sort(function (a, b) { return (a.distance_m == null ? 1e12 : a.distance_m) - (b.distance_m == null ? 1e12 : b.distance_m); });
                clear(out);
                if (!items.length) { out.appendChild(h('p', { class: 'tms-note', text: fmt(T('nearby_none', 'No other recorded places within %1.'), U.formatDistance(Number(sel.value))) })); return; }
                out.appendChild(h('p', { class: 'tms-hint', text: T('sorted_dist', 'Sorted by distance') }));
                var ul = h('ul', { class: 'tms-mini-list', role: 'list' });
                items.forEach(function (r) { ul.appendChild(miniItem(r)); });
                out.appendChild(ul);
            }).catch(function (e) { if (my !== seq || e.code === 'aborted') return; clear(out); out.appendChild(h('p', { class: 'tms-note tms-note-err', text: errText(e) })); });
        }
        on(sel, 'change', load);
        var loaded = false;
        on(sec, 'toggle', function () { if (sec.open && !loaded) { loaded = true; load(); } });
        sec.appendChild(h('label', { class: 'tms-label', for: radiusId, text: T('radius', 'Search radius') }));
        sec.appendChild(sel); sec.appendChild(out);
        return sec;
    }

    function correctionSection(p) {
        var sec = h('details', { class: 'tms-section' });
        sec.appendChild(h('summary', { text: T('suggest_fix', 'Report a wrong location or detail') }));
        var ta = h('textarea', { class: 'tms-textarea', rows: '3', maxlength: '800', 'aria-label': T('correction_label', 'What should be corrected?'), placeholder: T('correction_ph', 'Describe what is wrong or missing (this goes to a moderator, it does not change the map).') });
        var st = h('p', { class: 'tms-note', 'aria-live': 'polite' });
        var btn = h('button', { type: 'button', class: 'tms-btn', text: T('send', 'Send') });
        on(btn, 'click', function () {
            var note = ta.value.trim();
            if (note.length < 8) { st.textContent = T('correction_short', 'Please add a little more detail.'); return; }
            btn.disabled = true;
            apiCall('suggest_correction', {}, { body: { place_id: p.id, note: note } }).then(function () { st.textContent = T('correction_ok', 'Thank you — a moderator will review this.'); ta.value = ''; })
                .catch(function (e) { st.textContent = errText(e); }).then(function () { btn.disabled = false; });
        });
        [ta, btn, st].forEach(function (n) { sec.appendChild(n); });
        return sec;
    }

    function toggleWishlist(pkgId, btn) {
        var fd = new URLSearchParams(); fd.set('toggle_wish', '1'); fd.set('pkgid', String(pkgId)); fd.set('csrf_token', state.csrf || '');
        btn.disabled = true;
        fetch(BASE + 'wishlist.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() })
            .then(function (r) { if (!r.ok) throw new Error('x'); announce(T('wish_toggled', 'Saved list updated')); btn.textContent = T('save_fav_done', 'Saved list updated'); })
            .catch(function () { btn.textContent = T('err_generic', 'Something went wrong. Please try again.'); })
            .then(function () { btn.disabled = false; });
    }

    function copyLink(btn) {
        var url = location.href;
        function done(ok) { var t = btn.lastChild; var old = t.textContent; t.textContent = ok ? T('copied', 'Link copied') : url; announce(ok ? T('copied', 'Link copied') : ''); setTimeout(function () { t.textContent = old; }, 2500); }
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(function () { done(true); }, function () { done(false); });
        else done(false);
    }

    /* ------------------------------------------------------------------------------------------------ directions */
    var elFrom, elTo, elModes, elRtOut, elRtGo, elPickMsg;
    var fromCombo, toCombo;

    function modeList() {
        var m = (state.cfg && state.cfg.routing_modes) || [];
        return m.map(function (x) { return typeof x === 'string' ? { id: x, label: x } : x; }).filter(function (x) { return x && /^(driving|walking|cycling)$/.test(x.id); });
    }
    var MODE_UI = { driving: ['fa-car', 'Drive'], cycling: ['fa-bicycle', 'Cycle'], walking: ['fa-male', 'Walk'] };
    var ROUTE_COLORS = { driving: '#1D4ED8', cycling: '#B45309', walking: '#047857' };

    function buildRoutePane() {
        var p = panes.route; clear(p);
        var pt = function (key, ph, id) {
            var inp = h('input', { type: 'text', id: id, class: 'tms-input', autocomplete: 'off', maxlength: '120', placeholder: ph, role: 'combobox', 'aria-expanded': 'false', 'aria-controls': id + 'List', 'aria-autocomplete': 'list' });
            var list = h('ul', { id: id + 'List', class: 'tms-suggest', role: 'listbox', hidden: true });
            return { inp: inp, list: list };
        };
        var f = pt('from', T('from_ph', 'Start: search a place'), 'rtFrom'), t = pt('to', T('to_ph', 'Destination: search a place'), 'rtTo');
        elFrom = f.inp; elTo = t.inp;
        p.appendChild(h('div', { class: 'tms-route-field' }, [h('label', { class: 'tms-label', for: 'rtFrom', text: T('start', 'Start') }), h('div', { class: 'tms-route-in' }, [f.inp, f.list])]));
        var fbtns = h('div', { class: 'tms-route-btns' });
        var my = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-crosshairs', 'aria-hidden': 'true' }), ' ', T('use_my_loc', 'Use my location')]);
        on(my, 'click', useMyLocation);
        var pk = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-map-pin', 'aria-hidden': 'true' }), ' ', T('pick_map', 'Pick on map')]);
        on(pk, 'click', function () { startPick('from'); });
        var sw = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost', 'aria-label': T('swap', 'Swap start and destination') }, [h('span', { class: 'fa fa-exchange', 'aria-hidden': 'true' })]);
        on(sw, 'click', function () { var a = state.from; state.from = state.to; state.to = a; syncRouteInputs(); updateEndpoints(); });
        [my, pk, sw].forEach(function (n) { fbtns.appendChild(n); }); p.appendChild(fbtns);
        p.appendChild(h('div', { class: 'tms-route-field' }, [h('label', { class: 'tms-label', for: 'rtTo', text: T('destination', 'Destination') }), h('div', { class: 'tms-route-in' }, [t.inp, t.list])]));
        var tbtns = h('div', { class: 'tms-route-btns' });
        var pk2 = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-map-pin', 'aria-hidden': 'true' }), ' ', T('pick_map', 'Pick on map')]);
        on(pk2, 'click', function () { startPick('to'); });
        tbtns.appendChild(pk2); p.appendChild(tbtns);
        elPickMsg = h('p', { class: 'tms-note tms-pick-msg', role: 'status', hidden: true });
        p.appendChild(elPickMsg);
        elModes = h('div', { class: 'tms-modes', role: 'group', 'aria-label': T('travel_mode', 'Travel mode') });
        p.appendChild(elModes);
        elRtGo = h('button', { type: 'button', class: 'tms-btn tms-btn-primary tms-block' }, [h('span', { class: 'fa fa-road', 'aria-hidden': 'true' }), ' ', T('get_route', 'Show route')]);
        on(elRtGo, 'click', computeRoute);
        var rs = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-times', 'aria-hidden': 'true' }), ' ', T('reset_route', 'Reset route')]);
        on(rs, 'click', resetRoute);
        p.appendChild(h('div', { class: 'tms-route-btns' }, [elRtGo, rs]));
        elRtOut = h('div', { class: 'tms-route-out', 'aria-live': 'polite' });
        p.appendChild(elRtOut);

        var mk = function (combo, which) {
            return makeCombo({
                input: combo.inp, list: combo.list, id: 'rtSug' + which, geocode: false,
                onPick: function (pl) { if (which === 'From') setFrom({ lat: Number(pl.lat), lng: Number(pl.lng), label: localName(pl) }); else setTo(pl); },
                onSubmit: function () { /* choose from the list */ }
            });
        };
        fromCombo = mk(f, 'From'); toCombo = mk(t, 'To');
        renderModes();
    }
    function renderModes() {
        clear(elModes);
        var modes = modeList();
        if (!modes.length) { elModes.appendChild(h('p', { class: 'tms-note', text: T('no_routing', 'Route calculation is not available right now. You can still open the trip in another map app.') })); elRtGo.disabled = true; return; }
        elRtGo.disabled = false;
        if (!modes.some(function (m) { return m.id === state.mode; })) state.mode = modes[0].id;
        modes.forEach(function (m) {
            var ui = MODE_UI[m.id];
            var b = h('button', { type: 'button', class: 'tms-mode' + (m.id === state.mode ? ' is-on' : ''), 'aria-pressed': m.id === state.mode ? 'true' : 'false' }, [h('span', { class: 'fa ' + ui[0], 'aria-hidden': 'true' }), ' ', T('mode_' + m.id, m.label && m.label !== m.id ? m.label : ui[1])]);
            on(b, 'click', function () { state.mode = m.id; state.trip.mode = m.id; renderModes(); if (state.from && state.to && state.route) computeRoute(); });
            elModes.appendChild(b);
        });
    }
    function setFrom(pt) { state.from = pt; syncRouteInputs(); updateEndpoints(); refreshAll(); if (state.to) announce(T('route_ready', 'Start and destination set')); }
    function setTo(p) { state.to = { lat: Number(p.lat), lng: Number(p.lng), label: localName(p), place: p }; syncRouteInputs(); updateEndpoints(); if (!state.from) { elPickMsg.hidden = false; elPickMsg.textContent = T('need_start', 'Choose a start point: use your location, pick on the map, or search.'); } }
    function syncRouteInputs() { if (!elFrom) return; elFrom.value = state.from ? state.from.label || '' : ''; elTo.value = state.to ? state.to.label || '' : ''; }
    function useMyLocation() {
        if (!navigator.geolocation) { elPickMsg.hidden = false; elPickMsg.textContent = T('geo_unsupported', 'Your browser cannot share its location.'); return; }
        elPickMsg.hidden = false; elPickMsg.textContent = T('locating', 'Waiting for your permission and location…');
        navigator.geolocation.getCurrentPosition(function (pos) {
            elPickMsg.hidden = true;
            setFrom({ lat: pos.coords.latitude, lng: pos.coords.longitude, label: T('my_location', 'My location') });
            ctl().flyTo(pos.coords.longitude, pos.coords.latitude, 13);
        }, function (err) {
            elPickMsg.textContent = err && err.code === 1 ? T('geo_denied', 'Location permission was denied. You can pick the start on the map instead.') : T('geo_failed', 'Could not determine your location. Pick the start on the map instead.');
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
    }
    function startPick(which) {
        state.pick = which; setTab('route'); elPickMsg.hidden = false;
        elPickMsg.textContent = which === 'from' ? T('pick_from', 'Click the map to set the start point (Esc to cancel).') : T('pick_to', 'Click the map to set the destination (Esc to cancel).');
        state.map.getCanvas().style.cursor = 'crosshair'; setSheet('peek');
    }
    function endPick() { state.pick = null; if (state.map) state.map.getCanvas().style.cursor = ''; if (elPickMsg) elPickMsg.hidden = true; }

    function lnglat(pt) { return pt.lng.toFixed(6) + ',' + pt.lat.toFixed(6); }
    function computeRoute() {
        if (!state.from || !state.to) { elRtOut.textContent = ''; elRtOut.appendChild(h('p', { class: 'tms-note tms-note-err', text: T('need_both', 'Choose both a start and a destination.') })); return; }
        if (state.routeLoading) return;
        state.routeLoading = true; elRtGo.disabled = true; clear(elRtOut); elRtOut.appendChild(h('p', { class: 'tms-note', text: T('routing', 'Calculating route…') }));
        var mode = state.mode;
        apiCall('route', { mode: mode, coords: lnglat(state.from) + ';' + lnglat(state.to) }, { timeout: 20000 }).then(function (j) {
            var r = j.route || j;
            var coords = r.geometry && r.geometry.coordinates;
            if (!Array.isArray(coords) || coords.length < 2) throw mkErr('no_route');
            state.route = { mode: mode, distance_m: r.distance_m, duration_s: r.duration_s, geometry: r.geometry };
            drawRoute(); renderRoute(); fitCoords(coords, 70);
            announce(T('route_done', 'Route found'));
        }).catch(function (e) {
            state.route = null; drawRoute(); clear(elRtOut);
            elRtOut.appendChild(h('p', { class: 'tms-note tms-note-err', text: errText(e) }));
            if (state.from && state.to) elRtOut.appendChild(externalLinks(state.from, state.to, mode));
        }).then(function () { state.routeLoading = false; elRtGo.disabled = !modeList().length; });
    }
    function fitCoords(coords, pad) {
        var b = new maplibregl.LngLatBounds();
        coords.forEach(function (c) { if (U.validCoordLoose(c[1], c[0])) b.extend([c[0], c[1]]); });
        if (!b.isEmpty()) state.map.fitBounds(b, { padding: pad || 60, maxZoom: 16, duration: G.reducedMotion() ? 0 : 600 });
    }
    function externalLinks(from, to, mode) {
        var u = U.externalDirectionsUrls(from, to, mode);
        return h('p', { class: 'tms-ext' }, [T('open_in', 'Open in:') + ' ', h('a', { href: u.osm, target: '_blank', rel: 'noopener noreferrer', text: 'OpenStreetMap' }), ' · ', h('a', { href: u.google, target: '_blank', rel: 'noopener noreferrer', text: 'Google Maps' })]);
    }
    function renderRoute() {
        if (!elRtOut) return;
        clear(elRtOut);
        if (!state.route) { if (state.from && state.to) elRtOut.appendChild(externalLinks(state.from, state.to, state.mode)); return; }
        var r = state.route, bits = [];
        var d = Number(r.distance_m), s = Number(r.duration_s);
        if (isFinite(d) && r.distance_m !== null && r.distance_m !== undefined) bits.push(h('span', { class: 'tms-stat' }, [h('span', { class: 'fa fa-arrows-h', 'aria-hidden': 'true' }), ' ', U.formatDistance(d)]));
        if (isFinite(s) && r.duration_s !== null && r.duration_s !== undefined) bits.push(h('span', { class: 'tms-stat' }, [h('span', { class: 'fa fa-clock-o', 'aria-hidden': 'true' }), ' ', U.formatDuration(s)]));
        elRtOut.appendChild(h('div', { class: 'tms-route-sum' }, bits.length ? bits : [T('route_shown', 'Route shown on the map')]));
        elRtOut.appendChild(h('p', { class: 'tms-hint', text: T('route_est', 'Estimated by the routing service from map data. Real conditions can differ.') }));
        if (state.from && state.to) elRtOut.appendChild(externalLinks(state.from, state.to, r.mode));
        var fit = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-arrows-alt', 'aria-hidden': 'true' }), ' ', T('fit_route', 'Fit route')]);
        on(fit, 'click', fitRoute); elRtOut.appendChild(fit);
    }
    function fitRoute() { var g = (state.route && state.route.geometry) || (state.trip.route && state.trip.route.geometry); if (g && g.coordinates) fitCoords(g.coordinates, 70); }
    function resetRoute() {
        state.route = null; state.from = null; state.to = null; endPick();
        if (fromCombo) fromCombo.abort(); if (toCombo) toCombo.abort();
        syncRouteInputs(); drawRoute(); updateEndpoints(); renderRoute(); refreshAll();
        announce(T('route_reset', 'Route cleared'));
    }

    /* ------------------------------------------------------------------------------------------------ itinerary */
    var elTripList, elTripSum, elTripTitle, elTripSaved, elTripStatus, elTripCalc;
    var DRAFT_KEY = 'tms_trip_draft_v1';
    function loadDraft() {
        try {
            var d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
            if (d && Array.isArray(d.stops)) {
                state.trip.stops = d.stops.filter(function (s) { return s && s.key && U.validCoord(s.lat, s.lng); }).slice(0, 25).map(function (s) { return { key: String(s.key), id: s.id, name: String(s.name || ''), lat: Number(s.lat), lng: Number(s.lng), category: String(s.category || '') }; });
                state.trip.title = String(d.title || '').slice(0, 80);
            }
        } catch (e) { /* storage unavailable */ }
    }
    function saveDraft() { try { localStorage.setItem(DRAFT_KEY, JSON.stringify({ title: state.trip.title, stops: state.trip.stops })); } catch (e) { /* ignore */ } }

    function buildTripPane() {
        var p = panes.trip; clear(p);
        elTripTitle = h('input', { type: 'text', class: 'tms-input', maxlength: '80', 'aria-label': T('trip_title', 'Trip name'), placeholder: T('trip_title', 'Trip name') });
        elTripTitle.value = state.trip.title || '';
        on(elTripTitle, 'input', function () { state.trip.title = elTripTitle.value; saveDraft(); });
        p.appendChild(elTripTitle);
        elTripList = h('ol', { class: 'tms-trip-list' });
        p.appendChild(elTripList);
        elTripSum = h('div', { class: 'tms-trip-sum', 'aria-live': 'polite' });
        p.appendChild(elTripSum);
        elTripCalc = h('button', { type: 'button', class: 'tms-btn tms-btn-primary tms-block' }, [h('span', { class: 'fa fa-road', 'aria-hidden': 'true' }), ' ', T('calc_trip', 'Calculate route between stops')]);
        on(elTripCalc, 'click', computeTrip);
        var clr = h('button', { type: 'button', class: 'tms-btn tms-btn-ghost' }, [h('span', { class: 'fa fa-trash', 'aria-hidden': 'true' }), ' ', T('clear_trip', 'Clear trip')]);
        on(clr, 'click', function () { state.trip.stops = []; state.trip.route = null; state.trip.stale = false; saveDraft(); renderTrip(); updateTripLayers(); });
        var sv = h('button', { type: 'button', class: 'tms-btn' }, [h('span', { class: 'fa fa-floppy-o', 'aria-hidden': 'true' }), ' ', T('save_trip', 'Save trip')]);
        on(sv, 'click', saveTrip);
        p.appendChild(h('div', { class: 'tms-route-btns' }, [elTripCalc, sv, clr]));
        elTripStatus = h('p', { class: 'tms-note', 'aria-live': 'polite' });
        p.appendChild(elTripStatus);
        elTripSaved = h('div', { class: 'tms-trip-saved' });
        p.appendChild(elTripSaved);
        renderTrip();
    }
    function addStop(p) {
        if (state.trip.stops.length >= 25) { announce(T('trip_full', 'A trip can have up to 25 stops')); return; }
        if (state.trip.stops.some(function (s) { return s.key === pid(p); })) return;
        state.trip.stops.push({ key: pid(p), id: p._pseudo ? null : p.id, name: localName(p), lat: Number(p.lat), lng: Number(p.lng), category: p.category || '' });
        state.pinned.set(pid(p), p);
        markTripChanged(); announce(fmt(T('stop_added', '%1 added to your trip'), localName(p)));
    }
    function markTripChanged() { state.trip.stale = !!state.trip.route; saveDraft(); renderTrip(); updateTripLayers(); }
    function moveStop(i, d) {
        var j = i + d, s = state.trip.stops;
        if (j < 0 || j >= s.length) return;
        var t = s[i]; s[i] = s[j]; s[j] = t;
        markTripChanged(); announce(fmt(T('stop_moved', '%1 moved to position %2'), t.name, j + 1));
        var btn = elTripList.querySelectorAll('li')[j]; if (btn) { var f = btn.querySelector(d < 0 ? '.mv-up' : '.mv-down'); if (f && !f.disabled) f.focus(); else { var any = btn.querySelector('button'); if (any) any.focus(); } }
    }
    function removeStop(i) {
        var s = state.trip.stops.splice(i, 1)[0]; markTripChanged();
        if (s) announce(fmt(T('stop_removed', '%1 removed'), s.name));
    }
    function renderTrip() {
        if (!elTripList) return;
        clear(elTripList);
        var stops = state.trip.stops, legs = (!state.trip.stale && state.trip.route && state.trip.route.legs) || [];
        if (!stops.length) elTripList.appendChild(h('li', { class: 'tms-note', text: T('trip_empty', 'No stops yet. Open a place and choose “Add to itinerary”.') }));
        stops.forEach(function (s, i) {
            var li = h('li', { class: 'tms-stop' });
            var main = h('div', { class: 'tms-stop-main' }, [h('span', { class: 'tms-stop-n', 'aria-hidden': 'true', text: String(i + 1) }), h('button', { type: 'button', class: 'tms-link', text: s.name }),
                ]);
            on(main.querySelector('button'), 'click', function () { var p = state.items.get(s.key) || state.pinned.get(s.key); if (p) selectPlace(p, { fly: true }); else ctl().flyTo(s.lng, s.lat, 14); });
            var ctrls = h('div', { class: 'tms-stop-ctrl' });
            var up = h('button', { type: 'button', class: 'tms-icon-btn mv-up', 'aria-label': fmt(T('move_up', 'Move %1 up'), s.name) }, [h('span', { class: 'fa fa-chevron-up', 'aria-hidden': 'true' })]);
            var dn = h('button', { type: 'button', class: 'tms-icon-btn mv-down', 'aria-label': fmt(T('move_down', 'Move %1 down'), s.name) }, [h('span', { class: 'fa fa-chevron-down', 'aria-hidden': 'true' })]);
            var rm = h('button', { type: 'button', class: 'tms-icon-btn', 'aria-label': fmt(T('remove_stop', 'Remove %1'), s.name) }, [h('span', { class: 'fa fa-times', 'aria-hidden': 'true' })]);
            up.disabled = i === 0; dn.disabled = i === stops.length - 1;
            on(up, 'click', function () { moveStop(i, -1); }); on(dn, 'click', function () { moveStop(i, 1); }); on(rm, 'click', function () { removeStop(i); });
            [up, dn, rm].forEach(function (b) { ctrls.appendChild(b); });
            li.appendChild(main); li.appendChild(ctrls);
            if (i < stops.length - 1 && legs[i]) {
                var lg = legs[i], bits = [];
                if (lg.distance_m != null && isFinite(Number(lg.distance_m))) bits.push(U.formatDistance(Number(lg.distance_m)));
                if (lg.duration_s != null && isFinite(Number(lg.duration_s))) bits.push(U.formatDuration(Number(lg.duration_s)));
                if (bits.length) li.appendChild(h('div', { class: 'tms-leg', text: '↓ ' + bits.join(' · ') }));
            }
            elTripList.appendChild(li);
        });
        clear(elTripSum);
        var tr = state.trip.route;
        if (tr && !state.trip.stale) {
            var bits = [];
            if (tr.distance_m != null && isFinite(Number(tr.distance_m))) bits.push(h('span', { class: 'tms-stat' }, [T('total', 'Total') + ': ' + U.formatDistance(Number(tr.distance_m))]));
            if (tr.duration_s != null && isFinite(Number(tr.duration_s))) bits.push(h('span', { class: 'tms-stat' }, [U.formatDuration(Number(tr.duration_s)) + ' ' + T('driving_time', 'travel time')]));
            if (bits.length) { elTripSum.appendChild(h('div', { class: 'tms-route-sum' }, bits)); elTripSum.appendChild(h('p', { class: 'tms-hint', text: T('trip_est', 'Travel time only, from the routing service. It does not include time spent at each stop.') })); }
        } else if (state.trip.stale) elTripSum.appendChild(h('p', { class: 'tms-note', text: T('trip_stale', 'The stops changed — recalculate to update distances.') }));
        elTripCalc.disabled = stops.length < 2 || state.trip.loading || !modeList().length;
        renderSavedTrips();
    }
    function computeTrip() {
        var stops = state.trip.stops;
        if (stops.length < 2) return;
        state.trip.loading = true; elTripCalc.disabled = true; elTripStatus.textContent = T('routing', 'Calculating route…');
        var mode = state.mode;
        apiCall('route', { mode: mode, coords: stops.map(lnglat).join(';') }, { timeout: 25000 }).then(function (j) {
            var r = j.route || j;
            if (!r.geometry || !Array.isArray(r.geometry.coordinates) || r.geometry.coordinates.length < 2) throw mkErr('no_route');
            state.trip.route = { mode: mode, distance_m: r.distance_m, duration_s: r.duration_s, geometry: r.geometry, legs: Array.isArray(r.legs) && r.legs.length === stops.length - 1 ? r.legs : [] };
            state.trip.stale = false; elTripStatus.textContent = '';
            updateTripLayers(); fitCoords(r.geometry.coordinates, 70); announce(T('route_done', 'Route found'));
        }).catch(function (e) { state.trip.route = null; elTripStatus.textContent = errText(e); updateTripLayers(); })
            .then(function () { state.trip.loading = false; renderTrip(); });
    }
    function saveTrip() {
        if (!SIGNED_IN) { clear(elTripStatus); elTripStatus.appendChild(document.createTextNode(T('login_to_save', 'Sign in to save trips to your account. ') )); elTripStatus.appendChild(h('a', { href: LOGIN_URL, text: T('login', 'Sign in') })); return; }
        if (state.trip.stops.length < 1) { elTripStatus.textContent = T('trip_empty_save', 'Add at least one stop first.'); return; }
        var title = (state.trip.title || '').trim() || T('trip_default', 'My trip');
        apiCall('itinerary_save', {}, { body: { title: title, mode: state.mode, stops: state.trip.stops.map(function (s) { return { place_id: s.id, name: s.name, lat: s.lat, lng: s.lng, category: s.category }; }) } })
            .then(function () { elTripStatus.textContent = T('trip_saved', 'Trip saved.'); loadSavedTrips(); })
            .catch(function (e) { elTripStatus.textContent = errText(e); });
    }
    var savedTrips = null;
    function loadSavedTrips() {
        if (!SIGNED_IN) return;
        apiCall('itinerary_list').then(function (j) { savedTrips = j.items || j.itineraries || []; renderSavedTrips(); }).catch(function () { savedTrips = null; });
    }
    function renderSavedTrips() {
        if (!elTripSaved) return; clear(elTripSaved);
        if (!SIGNED_IN || !savedTrips || !savedTrips.length) return;
        elTripSaved.appendChild(h('h3', { class: 'tms-h3', text: T('saved_trips', 'Saved trips') }));
        var ul = h('ul', { class: 'tms-mini-list', role: 'list' });
        savedTrips.slice(0, 20).forEach(function (t) {
            var open = h('button', { type: 'button', class: 'tms-mini' }, [h('strong', { text: t.title || T('trip_default', 'My trip') }), h('span', { text: t.stops_count != null ? fmt(T('n_stops', '%1 stops'), t.stops_count) : '' })]);
            on(open, 'click', function () {
                apiCall('itinerary_get', { id: t.id }).then(function (j) {
                    var it = j.itinerary || j.item || j;
                    var stops = (it.stops || []).filter(function (s) { return s && U.validCoord(s.lat, s.lng); });
                    state.trip.stops = stops.slice(0, 25).map(function (s, i) { return { key: String(s.place_id || ('s' + i + ':' + s.lat + ',' + s.lng)), id: s.place_id || null, name: String(s.name || ''), lat: Number(s.lat), lng: Number(s.lng), category: String(s.category || '') }; });
                    state.trip.title = String(it.title || ''); elTripTitle.value = state.trip.title; state.trip.route = null; state.trip.stale = false; saveDraft(); renderTrip(); updateTripLayers();
                    if (it.mode && modeList().some(function (m) { return m.id === it.mode; })) { state.mode = it.mode; renderModes(); }
                    ctl().fitToItems(state.trip.stops, { maxZoom: 14 });
                }).catch(function (e) { elTripStatus.textContent = errText(e); });
            });
            var del = h('button', { type: 'button', class: 'tms-icon-btn', 'aria-label': fmt(T('delete_trip', 'Delete %1'), t.title || '') }, [h('span', { class: 'fa fa-trash', 'aria-hidden': 'true' })]);
            on(del, 'click', function () {
                if (!window.confirm(T('confirm_delete', 'Delete this saved trip?'))) return;
                apiCall('itinerary_delete', {}, { body: { id: t.id } }).then(loadSavedTrips).catch(function (e) { elTripStatus.textContent = errText(e); });
            });
            ul.appendChild(h('li', { class: 'tms-saved-li' }, [open, del]));
        });
        elTripSaved.appendChild(ul);
    }

    /* ------------------------------------------------------------------------------------------------ map layers */
    function ctl() { return state.ctl; }
    var EMPTY_FC = { type: 'FeatureCollection', features: [] };

    function featureFor(p) {
        return { type: 'Feature', geometry: { type: 'Point', coordinates: [Number(p.lng), Number(p.lat)] }, properties: { id: pid(p), name: localName(p), icon: 'tms-ic-' + (state.catById[p.category] ? p.category : '_') } };
    }
    function placesFC() {
        var seen = {}, feats = [];
        state.shown.forEach(function (p) { seen[pid(p)] = 1; feats.push(featureFor(p)); });
        state.pinned.forEach(function (p, k) { if (!seen[k] && !p._pseudo) feats.push(featureFor(p)); });
        return { type: 'FeatureCollection', features: feats };
    }

    function iconImage(id, color, glyph) {
        if (state.iconCache[id]) return Promise.resolve(state.iconCache[id]);
        return new Promise(function (res, rej) {
            var img = new Image();
            img.onload = function () { var c = document.createElement('canvas'); c.width = c.height = 64; var x = c.getContext('2d'); x.drawImage(img, 0, 0, 64, 64); var d = x.getImageData(0, 0, 64, 64); state.iconCache[id] = d; res(d); };
            img.onerror = rej;
            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(U.iconSvg(color, glyph, 64));
        });
    }
    function ensureIcons(map) {
        var jobs = [iconImage('tms-ic-_', '#1B7F5F', 'pin')];
        ((state.cfg && state.cfg.categories) || []).forEach(function (c) { jobs.push(iconImage('tms-ic-' + c.id, c.color, U.iconKey(c.icon, c.id))); });
        return Promise.all(jobs).then(function () {
            Object.keys(state.iconCache).forEach(function (id) { if (!map.hasImage(id)) map.addImage(id, state.iconCache[id], { pixelRatio: 2 }); });
        });
    }

    function addLayers(map) {
        var hasGlyphs = !!(map.getStyle() && map.getStyle().glyphs);
        function src(id, data, extra) { if (!map.getSource(id)) map.addSource(id, Object.assign({ type: 'geojson', data: data }, extra || {})); }
        src('tms-route', EMPTY_FC); src('tms-trip', EMPTY_FC); src('tms-trip-stops', EMPTY_FC); src('tms-endpoints', EMPTY_FC); src('tms-geo', EMPTY_FC);
        src('tms-places', placesFC(), { cluster: true, clusterRadius: 48, clusterMaxZoom: 14 });

        map.addLayer({ id: 'tms-trip-line', type: 'line', source: 'tms-trip', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#7C3AED', 'line-width': 5, 'line-opacity': 0.9 } });
        map.addLayer({ id: 'tms-route-casing', type: 'line', source: 'tms-route', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 10, 'line-opacity': 0.95 } });
        map.addLayer({ id: 'tms-route-line', type: 'line', source: 'tms-route', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': ['coalesce', ['get', 'color'], '#1D4ED8'], 'line-width': 5.5 } });
        map.addLayer({ id: 'tms-clusters', type: 'circle', source: 'tms-places', filter: ['has', 'point_count'], paint: { 'circle-color': ['step', ['get', 'point_count'], '#176B50', 15, '#0F766E', 60, '#8A5A00'], 'circle-radius': ['step', ['get', 'point_count'], 17, 15, 22, 60, 28], 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff' } });
        if (hasGlyphs) map.addLayer({ id: 'tms-cluster-count', type: 'symbol', source: 'tms-places', filter: ['has', 'point_count'], layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-font': ['Noto Sans Bold'], 'text-size': 13, 'text-allow-overlap': true }, paint: { 'text-color': '#ffffff' } });
        map.addLayer({ id: 'tms-selected-ring', type: 'circle', source: 'tms-places', filter: ['all', ['!', ['has', 'point_count']], ['==', ['get', 'id'], state.selectedId || '__none__']], paint: { 'circle-radius': 23, 'circle-color': 'rgba(245,179,1,0.25)', 'circle-stroke-width': 3, 'circle-stroke-color': '#F5B301' } });
        var iconLayout = { 'icon-image': ['get', 'icon'], 'icon-size': 1, 'icon-allow-overlap': true, 'icon-anchor': 'center' };
        if (hasGlyphs) Object.assign(iconLayout, { 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Regular'], 'text-size': 11.5, 'text-offset': [0, 1.7], 'text-anchor': 'top', 'text-max-width': 9, 'text-optional': true });
        map.addLayer({ id: 'tms-place-icons', type: 'symbol', source: 'tms-places', filter: ['!', ['has', 'point_count']], layout: iconLayout, paint: hasGlyphs ? { 'text-color': '#1F2937', 'text-halo-color': '#ffffff', 'text-halo-width': 1.6 } : {} });
        map.addLayer({ id: 'tms-trip-stop-circles', type: 'circle', source: 'tms-trip-stops', paint: { 'circle-radius': 12, 'circle-color': '#7C3AED', 'circle-stroke-width': 2.5, 'circle-stroke-color': '#ffffff' } });
        if (hasGlyphs) map.addLayer({ id: 'tms-trip-stop-num', type: 'symbol', source: 'tms-trip-stops', layout: { 'text-field': ['get', 'n'], 'text-font': ['Noto Sans Bold'], 'text-size': 12, 'text-allow-overlap': true }, paint: { 'text-color': '#ffffff' } });
        map.addLayer({ id: 'tms-endpoint-circles', type: 'circle', source: 'tms-endpoints', paint: { 'circle-radius': 11, 'circle-color': ['get', 'color'], 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff' } });
        if (hasGlyphs) map.addLayer({ id: 'tms-endpoint-text', type: 'symbol', source: 'tms-endpoints', layout: { 'text-field': ['get', 'label'], 'text-font': ['Noto Sans Bold'], 'text-size': 12, 'text-allow-overlap': true }, paint: { 'text-color': '#ffffff' } });
        map.addLayer({ id: 'tms-geo-dot', type: 'circle', source: 'tms-geo', paint: { 'circle-radius': 9, 'circle-color': '#DC2626', 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff' } });
        state.layersReady = true;
        updateSource(); drawRoute(); updateTripLayers(); updateEndpoints(); updateGeoLayer(); updateSelectedLayer();
    }
    function setData(id, data) { var m = state.map; if (m && state.layersReady && m.getSource(id)) m.getSource(id).setData(data); }
    function updateSource() { setData('tms-places', placesFC()); }
    function updateSelectedLayer() {
        var m = state.map; if (!m || !state.layersReady || !m.getLayer('tms-selected-ring')) return;
        m.setFilter('tms-selected-ring', ['all', ['!', ['has', 'point_count']], ['==', ['get', 'id'], state.selectedId || '__none__']]);
    }
    function lineFeature(coords, props) { return { type: 'FeatureCollection', features: coords && coords.length > 1 ? [{ type: 'Feature', properties: props || {}, geometry: { type: 'LineString', coordinates: coords } }] : [] }; }
    function drawRoute() { setData('tms-route', lineFeature(state.route && state.route.geometry && state.route.geometry.coordinates, { color: ROUTE_COLORS[state.route && state.route.mode] || '#1D4ED8' })); }
    function updateEndpoints() {
        var f = [];
        if (state.from) f.push({ type: 'Feature', properties: { label: 'A', color: '#047857' }, geometry: { type: 'Point', coordinates: [state.from.lng, state.from.lat] } });
        if (state.to) f.push({ type: 'Feature', properties: { label: 'B', color: '#B91C1C' }, geometry: { type: 'Point', coordinates: [state.to.lng, state.to.lat] } });
        setData('tms-endpoints', { type: 'FeatureCollection', features: f });
    }
    function updateGeoLayer() {
        setData('tms-geo', state.geo ? { type: 'FeatureCollection', features: [{ type: 'Feature', properties: {}, geometry: { type: 'Point', coordinates: [state.geo.lng, state.geo.lat] } }] } : EMPTY_FC);
    }
    function updateTripLayers() {
        var t = state.trip, show = state.tab === 'trip' || t.route;
        var line = t.route && !t.stale && t.route.geometry ? t.route.geometry.coordinates : (t.stops.length > 1 ? t.stops.map(function (s) { return [s.lng, s.lat]; }) : null);
        setData('tms-trip', show || t.stops.length ? lineFeature(line, {}) : EMPTY_FC);
        setData('tms-trip-stops', { type: 'FeatureCollection', features: t.stops.map(function (s, i) { return { type: 'Feature', properties: { n: String(i + 1) }, geometry: { type: 'Point', coordinates: [s.lng, s.lat] } }; }) });
    }

    function wireMapEvents(map) {
        function canvas() { return map.getCanvas(); }
        on(document, 'keydown', function (e) { if (e.key === 'Escape' && state.pick) { endPick(); } });
        map.on('click', function (e) {
            if (state.pick) {
                var which = state.pick; endPick();
                var pt = { lat: e.lngLat.lat, lng: e.lngLat.lng, label: T('map_point', 'Point on map') };
                if (which === 'from') setFrom(pt); else { state.to = pt; syncRouteInputs(); updateEndpoints(); }
                return;
            }
            var layers = ['tms-clusters', 'tms-place-icons'].filter(function (l) { return map.getLayer(l); });
            var feats = layers.length ? map.queryRenderedFeatures(e.point, { layers: layers }) : [];
            if (!feats.length) return;
            var f = feats[0];
            if (f.properties && f.properties.cluster_id !== undefined) {
                var src = map.getSource('tms-places'), cid = f.properties.cluster_id;
                Promise.resolve(src.getClusterExpansionZoom(cid)).then(function (z) { map.easeTo({ center: f.geometry.coordinates, zoom: z + 0.5, duration: G.reducedMotion() ? 0 : 400 }); }).catch(function () { /* ignore */ });
                return;
            }
            var p = state.items.get(String(f.properties.id)) || state.pinned.get(String(f.properties.id));
            if (p) selectPlace(p, { fly: false });
        });
        ['tms-clusters', 'tms-place-icons'].forEach(function (l) {
            map.on('mouseenter', l, function () { if (!state.pick) canvas().style.cursor = 'pointer'; });
            map.on('mouseleave', l, function () { if (!state.pick) canvas().style.cursor = ''; });
        });
        loadViewDebounced = debounce(function () { loadView(); }, 350);
        map.on('moveend', function () {
            scheduleHash();
            if (state.scope === 'view') loadViewDebounced();
            else if (state.scope === 'query') renderCards();       // only the "in view" ordering changes
        });
        on(window, 'hashchange', function () { /* shared links are applied at load only; navigating away keeps state */ });
    }

    /* ------------------------------------------------------------------------------------------------ toolbar + sheet */
    var elBar, elStyleBtns = {};
    function buildBar() {
        elBar = h('div', { class: 'tms-bar', role: 'toolbar', 'aria-label': T('map_tools', 'Map tools') });
        function btn(icon, label, fn) {
            var b = h('button', { type: 'button', class: 'tms-bar-btn', 'aria-label': label, title: label }, [h('span', { class: 'fa ' + icon, 'aria-hidden': 'true' }), h('span', { class: 'tms-bar-lbl', text: label })]);
            on(b, 'click', fn); elBar.appendChild(b); return b;
        }
        btn('fa-home', T('reset_view', 'Reset view'), function () { var c = state.cfg || {}; ctl().flyTo((c.center || [90.4125, 23.8103])[0], (c.center || [90.4125, 23.8103])[1], c.zoom || 6.5); });
        btn('fa-expand', T('fit_results', 'Fit results'), function () { var l = state.scope === 'view' ? state.shown.filter(inView) : state.shown; if (!ctl().fitToItems(l)) announce(T('nothing_to_fit', 'No results to fit')); });
        btn('fa-times-circle', T('clear_sel', 'Clear selection'), function () { clearSelection(); clearGeo(); });
        btn('fa-road', T('fit_route', 'Fit route'), fitRoute);
        btn('fa-list', T('toggle_list', 'Show or hide list'), function () { root.classList.toggle('list-hidden'); setTimeout(function () { ctl().resize(); }, 260); announce(root.classList.contains('list-hidden') ? T('list_hidden', 'List hidden') : T('list_shown', 'List shown')); });
        var sw = h('div', { class: 'tms-styles', role: 'group', 'aria-label': T('map_style', 'Map style') });
        ((state.cfg && state.cfg.styles) || []).forEach(function (s) {
            var b = h('button', { type: 'button', class: 'tms-style-btn', 'aria-pressed': 'false', dataset: { style: s.id }, text: s.label || s.id });
            on(b, 'click', function () {
                ctl().setStyle(s.id).then(function (ok) { if (!ok) { b.disabled = true; b.title = T('style_unavailable', 'This style could not be loaded'); announce(T('style_unavailable', 'This style could not be loaded')); } });
            });
            elStyleBtns[s.id] = b; sw.appendChild(b);
        });
        if (((state.cfg && state.cfg.styles) || []).length > 1) elBar.appendChild(sw);
        $('#tmsMapWrap').appendChild(elBar);
        syncStyleBtns();
    }
    function syncStyleBtns() { Object.keys(elStyleBtns).forEach(function (id) { elStyleBtns[id].setAttribute('aria-pressed', ctl() && ctl().styleId() === id ? 'true' : 'false'); }); }

    var sheetState = 'half';
    function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 860px)').matches; }
    function setSheet(s) {
        if (!isMobile()) return;
        sheetState = s; var panel = $('#tmsPanel'); if (!panel) return;
        panel.setAttribute('data-sheet', s);
        var hnd = $('#tmsSheetHandle'); if (hnd) hnd.setAttribute('aria-expanded', s === 'peek' ? 'false' : 'true');
        setTimeout(function () { if (ctl()) ctl().resize(); }, 260);
    }
    function wireSheet() {
        var hnd = $('#tmsSheetHandle'), panel = $('#tmsPanel');
        if (!hnd || !panel) return;
        var order = ['peek', 'half', 'full'];
        on(hnd, 'click', function (e) { if (hnd._dragged) { hnd._dragged = false; return; } setSheet(order[(order.indexOf(sheetState) + 1) % 3]); });
        on(hnd, 'keydown', function (e) {
            var i = order.indexOf(sheetState);
            if (e.key === 'ArrowUp') { setSheet(order[Math.min(2, i + 1)]); e.preventDefault(); }
            if (e.key === 'ArrowDown') { setSheet(order[Math.max(0, i - 1)]); e.preventDefault(); }
        });
        var startY = null, moved = 0;
        on(hnd, 'pointerdown', function (e) { startY = e.clientY; moved = 0; try { hnd.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ } });
        on(hnd, 'pointermove', function (e) { if (startY === null) return; moved = e.clientY - startY; });
        function end() {
            if (startY === null) return;
            if (Math.abs(moved) > 30) { var i = order.indexOf(sheetState); setSheet(order[Math.max(0, Math.min(2, i + (moved < 0 ? 1 : -1)))]); hnd._dragged = true; }
            startY = null; moved = 0;
        }
        on(hnd, 'pointerup', end); on(hnd, 'pointercancel', end);
        setSheet('half');
    }

    /* ------------------------------------------------------------------------------------------------ URL hash */
    var hashTimer = null, applyingHash = false;
    function scheduleHash() {
        if (applyingHash) return; clearTimeout(hashTimer);
        hashTimer = setTimeout(function () {
            if (!state.map) return;
            var c = state.map.getCenter();
            var h_ = U.hashEncode({ place: state.selectedId && !(state.selected && state.selected._pseudo) ? state.selectedId : '', center: [c.lng, c.lat], zoom: state.map.getZoom(), categories: state.filters.categories, verified: state.filters.verified, family: state.filters.family, accessible: state.filters.accessible, free: state.filters.free, q: state.scope === 'query' ? state.filters.q : '', style: ctl() && ctl().styleId() !== (state.cfg && state.cfg.default_style) && ctl().styleId() !== 'fallback' ? ctl().styleId() : '' });
            try { history.replaceState(null, '', location.pathname + location.search + '#' + h_); } catch (e) { /* ignore */ }
        }, 450);
    }

    /* ------------------------------------------------------------------------------------------------ legacy ?focus=ID */
    function applyLegacyFocus() {
        if (!focusInfo || !U.validCoord(focusInfo.lat, focusInfo.lng)) return;
        var lat = Number(focusInfo.lat), lng = Number(focusInfo.lng);
        ctl().flyTo(lng, lat, 12);
        var d = 0.03, bb = [lng - d, lat - d, lng + d, lat + d].map(function (n) { return n.toFixed(5); }).join(',');
        apiCall('places', { bbox: bb, limit: 50, lang: LANG }).then(function (j) {
            var best = null, bd = 1e12;
            (j.items || []).forEach(function (p) {
                if (!p || !U.validCoord(p.lat, p.lng)) return;
                var link = (p.links && p.links.details) || '';
                var byId = link.indexOf('pkgid=' + focusInfo.id) >= 0 || (p.source === 'package' && String(p.package_id || p.id) === String(focusInfo.id));
                var dist = U.haversine(lat, lng, p.lat, p.lng);
                if (byId) dist = -1;
                if (dist < bd && (byId || dist < 400)) { bd = dist; best = p; }
            });
            if (best) { selectPlace(best, { fly: true }); return; }
            selectPlace({ id: 'pkg:' + focusInfo.id, name: focusInfo.name, name_bn: null, category: '', category_label: T('tour_package', 'Tour package'), summary: focusInfo.location || '', lat: lat, lng: lng, verified: null, source: 'package', is_demo: false, image: null, facilities: [], links: { details: focusInfo.url || null }, _pseudo: true }, { fly: false });
        }).catch(function () {
            selectPlace({ id: 'pkg:' + focusInfo.id, name: focusInfo.name, category_label: T('tour_package', 'Tour package'), summary: focusInfo.location || '', lat: lat, lng: lng, verified: null, source: 'package', is_demo: false, image: null, facilities: [], links: { details: focusInfo.url || null }, _pseudo: true }, { fly: false });
        });
    }

    /* ------------------------------------------------------------------------------------------------ boot */
    function boot() {
        var hs = U.hashDecode(location.hash);
        applyingHash = true;
        state.filters.categories = hs.categories; state.filters.verified = hs.verified; state.filters.family = hs.family; state.filters.accessible = hs.accessible; state.filters.free = hs.free;
        loadDraft();
        buildPanel(); renderBadges();
        wireSheet();

        loadConfig().catch(function () { return { styles: [], categories: [], routing_modes: [], center: [90.4125, 23.8103], zoom: 6.5, lang: LANG, _failed: true }; }).then(function (cfg) {
            state.cfg = cfg;
            if (cfg._failed) { state.cfg.styles = []; showLoadError(mkErr('network'), function () { location.reload(); }); }
            buildFilters(); syncFilterControls(); renderBadges(); renderModes(); renderTrip(); buildBarOnce();
            var c = ctl = null; void c;
            state.ctl = G.createMap($('#tmsMap'), {
                config: cfg, lang: LANG, base: BASE, styleId: hs.style || undefined,
                center: hs.center || undefined, zoom: hs.zoom != null ? hs.zoom : undefined,
                labels: { mapFailed: T('map_failed', 'The map background could not be loaded. Markers and lists still work.'), tilesFailed: T('tiles_failed', 'Map tiles are not loading right now. Markers and lists still work.'), retry: T('retry', 'Try again') }
            });
            state.map = state.ctl.map;
            state.ctl.onStyleReady(function (map) { ensureIcons(map).then(function () { addLayers(map); syncStyleBtns(); }).catch(function (e) { if (window.console) console.error('[explorer] icons', e); addLayers(map); }); });
            state.ctl.on('stylechanged', syncStyleBtns);
            state.ctl.on('stylefailed', function (info) { var b = elStyleBtns[info.id]; if (b) { b.disabled = true; b.title = T('style_unavailable', 'This style could not be loaded'); } syncStyleBtns(); });
            wireMapEvents(state.map);
            state.map.once('load', function () {
                applyingHash = false;
                if (hs.q) { elQ.value = hs.q; elQClear.hidden = false; runListSearch(hs.q, { silent: true }); } else loadView();
                if (hs.place) {
                    apiCall('place', { id: hs.place, lang: LANG }).then(function (j) { var p = j.place || j.item || j; if (p && U.validCoord(p.lat, p.lng)) selectPlace(p, { fly: !hs.center }); }).catch(function () { /* stale link: ignore */ });
                } else applyLegacyFocus();
                loadSavedTrips(); updateTripLayers();
            });
            if (SIGNED_IN) { /* saved trips load after the map is ready */ }
        });
    }
    var buildBarDone = false;
    function buildBarOnce() { if (buildBarDone) return; buildBarDone = true; buildBar(); }

    function teardown() {
        cleanups.forEach(function (fn) { try { fn(); } catch (e) { /* ignore */ } });
        cleanups = [];
        if (state.viewCtl) state.viewCtl.abort();
        if (mainCombo) mainCombo.abort();
        if (state.ctl) state.ctl.destroy();
    }
    window.addEventListener('pagehide', teardown, { once: true });
    window.TmsExplorer = { teardown: teardown, _state: state };

    if (typeof maplibregl === 'undefined') {
        var box = document.getElementById('tmsMap');
        if (box) box.appendChild(h('p', { class: 'tms-map-notice', role: 'alert', text: T('lib_missing', 'The map library could not be loaded.') }));
        return;
    }
    boot();
})();

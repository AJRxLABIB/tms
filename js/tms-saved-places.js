/*
 * tms-saved-places.js — progressive enhancement for saved-places.php.
 * The page is complete without this file (server-rendered list, POST remove). This adds: category filter chips, the MapLibre
 * map (reusing TmsMapPages), "Save/Add to trip" buttons and instant removal. Text is always set with textContent.
 */
(function () {
    'use strict';
    var app = document.getElementById('savedApp');
    if (!app || !window.TmsTrips) return;
    var list = document.getElementById('savedList'), statusEl = document.getElementById('savedStatus');
    var chips = document.getElementById('savedChips'), none = document.getElementById('savedNone'), countEl = document.getElementById('savedCount');
    var pins = [], currentCat = '', pinLayer = null, mapCtl = null;
    try { pins = JSON.parse(document.getElementById('savedPins').textContent) || []; } catch (e) { pins = []; }

    function say(msg, bad) { statusEl.textContent = msg; statusEl.className = 'tms-tr-status ' + (bad ? 'is-err' : 'is-ok'); }
    function rows() { return Array.prototype.slice.call(list.querySelectorAll('.tms-tr-item')); }

    function visiblePins() { return pins.filter(function (p) { return !currentCat || p.category === currentCat; }); }
    function refreshMap() {
        if (!pinLayer) return;
        var vis = visiblePins();
        pinLayer.set(vis.map(function (p) { return { lat: p.lat, lng: p.lng, title: p.title, subtitle: p.subtitle }; }));
        if (vis.length) pinLayer.fit();
    }
    function apply() {
        var shown = 0;
        rows().forEach(function (li) { var ok = !currentCat || li.getAttribute('data-cat') === currentCat; li.hidden = !ok; if (ok) shown++; });
        none.hidden = shown !== 0;
        countEl.textContent = shown + ' saved place' + (shown === 1 ? '' : 's') + (currentCat ? ' in this category' : '');
        refreshMap();
    }

    // chips (hidden until JS runs, because without JS they would do nothing)
    chips.hidden = false;
    chips.addEventListener('click', function (e) {
        var b = e.target.closest('.tms-tr-chip'); if (!b) return;
        currentCat = b.getAttribute('data-cat') || '';
        Array.prototype.forEach.call(chips.querySelectorAll('.tms-tr-chip'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        apply();
    });

    // add-to-trip + save heart per row
    rows().forEach(function (li) {
        var host = li.querySelector('[data-tms-actions]');
        if (!host) return;
        var place = { place_key: li.getAttribute('data-key'), name: li.getAttribute('data-name'), lat: Number(li.getAttribute('data-lat')), lng: Number(li.getAttribute('data-lng')),
            category: li.getAttribute('data-cat') };
        var ctx = { destination: li.getAttribute('data-dest') || '' };
        host.appendChild(window.TmsTrips.actions(place, ctx));
        // On this page the heart means "keep saved": hide it, the Remove button does the opposite. Add to trip stays.
        var heart = host.querySelector('.tms-save-btn'); if (heart) heart.hidden = true;
    });

    // instant removal (the form still works without JS)
    list.addEventListener('submit', function (e) {
        var f = e.target.closest('.tms-saved-remove-form'); if (!f) return;
        e.preventDefault();
        var li = f.closest('.tms-tr-item'), key = li.getAttribute('data-key'), name = li.getAttribute('data-name');
        var btn = f.querySelector('button'); btn.disabled = true;
        window.TmsTrips.call('saved_remove', { body: { place_key: key } }).then(function () {
            li.parentNode.removeChild(li);
            pins = pins.filter(function (p) { return p.key !== key; });
            say(name + ' removed from your saved places.', false); window.TmsTrips.announce(name + ' removed');
            if (!rows().length) { window.location.reload(); return; }
            apply();
        }).catch(function (err) { btn.disabled = false; say(err.message, true); });
    });

    // map
    var note = document.getElementById('savedMapNote');
    if (window.TmsMapPages) {
        window.TmsMapPages.lazyMount('savedMap', { base: '', lang: document.documentElement.lang || 'en', zoom: 6 }, function (ctl) {
            mapCtl = ctl;
            pinLayer = window.TmsMapPages.pins(ctl, 'saved', { popup: true });
            note.textContent = 'Click a pin for its name. The list beside the map has the same places.';
            ctl.onStyleReady(function () { refreshMap(); });
        }, function () { note.textContent = 'The map could not be loaded. The list shows every saved place.'; note.className = 'tms-tr-status is-err'; });
    }
})();

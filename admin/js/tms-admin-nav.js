/* Admin sidebar: collapsible groups (state remembered) + client-side page filter. Purely presentational:
   it hides or shows existing links, it never adds access. */
(function (root) {
    'use strict';

    var KEY = 'tms-admin-nav';

    /** Pure: does a menu label match the typed query? (case-insensitive, every word must appear) */
    function matches(label, query) {
        var q = String(query || '').toLowerCase().trim();
        if (!q) return true;
        var l = String(label || '').toLowerCase();
        return q.split(/\s+/).every(function (w) { return l.indexOf(w) !== -1; });
    }

    /** Pure: merge saved open/closed state with the group that holds the current page (that one is always open). */
    function resolveOpen(saved, currentGroup, allGroups) {
        var open = {};
        allGroups.forEach(function (g) { open[g] = !!(saved && saved[g]); });
        if (currentGroup && open.hasOwnProperty(currentGroup)) open[currentGroup] = true;
        return open;
    }

    function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
    function save(state) { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* private mode */ } }

    function init() {
        var nav = document.getElementById('tmsAdminNav');
        if (!nav) return;
        var groups = [].slice.call(nav.querySelectorAll('.nav-group'));
        var ids = groups.map(function (g) { return g.getAttribute('data-group'); });
        var open = resolveOpen(load(), nav.getAttribute('data-open'), ids);

        function apply(g, isOpen) {
            g.classList.toggle('open', isOpen);
            var b = g.querySelector('.nav-group-head');
            if (b) b.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
        groups.forEach(function (g) {
            apply(g, open[g.getAttribute('data-group')]);
            var b = g.querySelector('.nav-group-head');
            if (b) b.addEventListener('click', function () {
                var id = g.getAttribute('data-group');
                open[id] = !g.classList.contains('open');
                apply(g, open[id]);
                save(open);
            });
        });

        var input = document.getElementById('tmsNavFilter');
        var empty = nav.querySelector('.nav-empty');
        if (input) input.addEventListener('input', function () {
            var q = input.value, any = false, filtering = q.trim() !== '';
            groups.forEach(function (g) {
                var shown = 0;
                [].forEach.call(g.querySelectorAll('.nav-item'), function (li) {
                    var ok = matches(li.textContent, q);
                    li.hidden = !ok; if (ok) shown++;
                });
                g.hidden = filtering && shown === 0;
                if (shown) any = true;
                // While filtering, show every group that has a hit; afterwards restore the remembered state.
                apply(g, filtering ? shown > 0 : !!open[g.getAttribute('data-group')]);
            });
            if (empty) empty.hidden = !filtering || any;
        });
    }

    var api = { matches: matches, resolveOpen: resolveOpen };
    if (typeof module === 'object' && module.exports) module.exports = api;
    else { root.TmsAdminNav = api; if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init(); }
})(typeof window !== 'undefined' ? window : this);

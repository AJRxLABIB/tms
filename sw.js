// v2: bumping this name makes `activate` delete the old generic cache, which (v1) had stored logged-in pages.
const CACHE = 'tms-cache-v2';
// Round 83 (master prompt item 52 -- "PWA / Offline"): the spec names a
// specific list of things offline mode should prioritize -- itinerary,
// booking confirmation, tickets, emergency contacts, hotel information,
// flight information, packing list, selected documents, previously cached
// trip information. The generic CACHE above only ever holds "whatever page
// the visitor happened to load" with no guarantee any of those specific
// things survive being evicted alongside everything else. TRIPKIT_CACHE is
// a separate, deliberately never-evicted cache that only ever holds pages a
// signed-in traveler explicitly chose to save for a specific real trip (via
// my-trip.php's "Save for Offline" button) -- itinerary (my-trip.php
// itself), booking confirmation/ticket (ticket.php), emergency contacts
// (emergency.php), and selected documents (wallet.php's document list) plus
// packing list (packing-assistant.php). "Hotel information" is already part
// of my-trip.php's own rendered content (HotelName/HotelRating/MealPlan),
// not a separate page. This system has no flight-specific booking model
// (tour packages only, with a TransportMode field already shown on
// my-trip.php), so there is no separate "flight information" page to cache.
const TRIPKIT_CACHE = 'tms-tripkit';
const PRECACHE = ['/', '/tms/', '/tms/index.php', '/tms/css/style.min.css', '/tms/offline.html'];

// REAL GAP FOUND AND CLOSED (Phase H continuation -- auditing what
// TRIPKIT_CACHE actually guarantees, not just what pages it lists): the
// whole point of TRIPKIT_CACHE being "never evicted" is that a saved trip
// survives exactly the moment it matters -- storage pressure on a phone
// with no signal, days into a trip to somewhere like Sajek Valley or the
// Sundarbans (see bd-guide.php). But TMS_CACHE_TRIP below only ever cached
// the HTML of the 5 saved page URLs themselves -- every CSS/JS/font asset
// those pages actually need to render (bootstrap, font-awesome incl. its
// icon font files, the shared design tokens, jquery, wow.js for
// emergency.php, qrcode.js for ticket.php's QR code, ai-feedback.js for
// my-trip.php) only ever lived in the generic, evictable CACHE. If the
// browser evicted that generic cache under storage pressure -- which is
// precisely the scenario this feature exists to survive -- a traveler's
// "saved for offline" itinerary/ticket/emergency/documents/packing pages
// would still open, but as broken, unstyled HTML with missing icons, right
// when they're relying on it most. Verified by checking each of the 5
// TripKit pages' actual <link>/<script> tags: none of these assets were in
// PRECACHE either, so nothing already covered them under TRIPKIT_CACHE.
const TRIPKIT_STATIC_ASSETS = [
  '/tms/css/bootstrap.min2.css',
  '/tms/css/font-awesome.min2.css',
  '/tms/css/style.min.css',
  '/tms/css/animate.css',
  '/tms/css/modern-theme.css',
  '/tms/js/jquery-1.12.0.min.js',
  '/tms/js/bootstrap.min.js',
  '/tms/js/wow.min.js',
  '/tms/js/qrcode.js',
  '/tms/js/ai-feedback.js',
  '/tms/fonts/fa-solid-900.woff2',
  '/tms/fonts/fa-regular-400.woff2',
];

self.addEventListener('install', e => {
  // One missing URL must not silently disable all precaching (addAll is all-or-nothing), so add each on its own.
  e.waitUntil(caches.open(CACHE).then(c => Promise.allSettled(PRECACHE.map(u => c.add(u)))));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  // Only ever clean up old versions of the generic runtime cache -- never
  // touch TRIPKIT_CACHE here. A traveler who saved a trip for offline before
  // a service-worker update shouldn't lose it just because CACHE's version
  // number bumped; TRIPKIT_CACHE has no version suffix and is only ever
  // modified by an explicit save/remove action from the page (see the
  // message handler below), never by a deploy.
  e.waitUntil(caches.keys().then(keys => Promise.all(
    keys.filter(k => k !== CACHE && k !== TRIPKIT_CACHE).map(k => caches.delete(k))
  )));
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  // BUG FIX (reported live: every package/destination thumbnail on
  // package-list.php -- and any other cross-origin image -- showed the
  // gray no-image placeholder, with the console logging "Fetch API cannot
  // load https://images.unsplash.com/... Refused to connect because it
  // violates the document's Content Security Policy" from this file).
  // Root cause: this handler used to re-issue EVERY GET request (same- and
  // cross-origin alike) through fetch() inside the service worker. A
  // service worker has no document/DOM, so it has no img-src/script-src/
  // style-src/font-src context to check a re-issued request against --
  // fetch() calls made from a worker are always evaluated against
  // connect-src, which .htaccess's Round-106 CSP intentionally left at
  // 'self' (img-src's permissive "https:" catch-all was written assuming
  // the browser loads images the normal way via <img>, not through this
  // worker). The result: any cross-origin resource this service worker
  // intercepted -- Unsplash package photos first and most visibly, but
  // the same would eventually hit any other cross-origin asset once cache
  // misses -- was silently blocked and fell back to caches.match(),
  // which had never successfully cached it either, so onerror's
  // placeholder image was all that was left to show.
  // Fix: only intercept same-origin requests. Cross-origin resources fall
  // through untouched and load the normal browser way, correctly governed
  // by img-src/script-src/etc. as the Round-106 CSP intended -- no CSP
  // header needs loosening, and every existing same-origin caching
  // behavior below (generic CACHE, offline.html fallback, TRIPKIT_CACHE
  // via the message handler) is unchanged, since none of it ever
  // targeted cross-origin URLs in the first place.
  const url = new URL(e.request.url);
  if (url.origin !== self.location.origin) return;

  // PRIVACY: logging out wipes every cache (generic AND saved-trip kit), so the next person on a shared phone cannot open the
  // previous traveller's pages offline. The logout request itself passes through untouched.
  if (/\/logout\.php$/.test(url.pathname)) {
    e.waitUntil(caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))));
    return;
  }

  // Pages that show a signed-in person's data (or run an action) are NEVER written to the generic cache and are not served from it.
  // They stay available offline only if the traveller explicitly saved them with "Save for Offline" (TRIPKIT_CACHE, matched below).
  const PRIVATE = /\/(dashboard|profile|wallet|wallet-document|tour-history|ticket|my-trip|payment|pay-now|checkout|cart|export-my-data|notifications|alerts|loyalty|loyalty-history|referral|group-trip|group-trips|expense-tracker|travel-memory|ai-memory|ai-copilot|two-factor-setup|change-password|login|logout|register|forgot-password|thankyou|my-marketplace-bookings|wishlist|mark-notifications-read|admin|supplier|api)(\/|\.php|$)/i;
  const isPrivate = PRIVATE.test(url.pathname) || url.pathname.indexOf('/api/') !== -1;
  const isStatic = /\/(css|js|fonts|images)\//.test(url.pathname) && /\.(css|js|woff2?|ttf|png|jpe?g|gif|svg|webp|ico)$/i.test(url.pathname);

  if (isPrivate) {
    e.respondWith(
      fetch(e.request).catch(() => caches.match(e.request, { cacheName: TRIPKIT_CACHE }).then(r => r || caches.match('/tms/offline.html')))
    );
    return;
  }

  if (isStatic) {
    // stale-while-revalidate: instant from cache, refreshed in the background.
    e.respondWith(caches.open(CACHE).then(cache => cache.match(e.request).then(hit => {
      const refresh = fetch(e.request).then(res => { if (res && res.status === 200) cache.put(e.request, res.clone()); return res; }).catch(() => hit);
      return hit || refresh;
    })));
    return;
  }

  // Public pages: network first, remember the last good copy for offline, never keep responses marked private/no-store, cap the size.
  e.respondWith(
    fetch(e.request).then(res => {
      const cc = (res && res.headers && res.headers.get('Cache-Control')) || '';
      if (res && res.status === 200 && !/no-store|private/i.test(cc)) {
        const clone = res.clone();
        caches.open(CACHE).then(async c => {
          await c.put(e.request, clone);
          const keys = await c.keys();
          if (keys.length > 80) await Promise.all(keys.slice(0, keys.length - 80).map(k => c.delete(k)));
        });
      }
      return res;
    }).catch(() => caches.match(e.request).then(r => r || caches.match('/tms/offline.html')))
  );
});

// Round 83: explicit save/remove/list commands from a page (my-trip.php's
// "Save for Offline" button), answered over the MessageChannel port the
// page provides -- this never runs on its own, only in direct response to
// something the signed-in traveler clicked.
self.addEventListener('message', event => {
  const data = event.data || {};
  const port = event.ports && event.ports[0];

  if (data.type === 'TMS_CACHE_TRIP' && Array.isArray(data.urls)) {
    event.waitUntil((async () => {
      const cache = await caches.open(TRIPKIT_CACHE);
      const results = [];
      for (const url of data.urls) {
        try {
          // cache: 'reload' bypasses the HTTP cache so this always stores
          // the real, current version of the page (today's booking status,
          // today's e-ticket QR value) rather than a stale browser-cached
          // copy from an earlier visit.
          const res = await fetch(url, { credentials: 'include', cache: 'reload' });
          if (res && res.status === 200) {
            await cache.put(url, res.clone());
            results.push({ url, ok: true });
          } else {
            results.push({ url, ok: false, status: res ? res.status : 0 });
          }
        } catch (err) {
          results.push({ url, ok: false, error: String(err) });
        }
      }
      // Reply to the page immediately with only the per-page results --
      // mtSaveOffline() on my-trip.php compares okCount against the exact
      // number of pages it asked for, so mixing the static-asset caching
      // below into this same `results` array would break that "N of N
      // pages saved" check (Rule 2: don't disturb working, tested UI logic
      // for an unrelated addition). The static assets are cached silently,
      // best-effort, after the reply -- their success/failure is never
      // user-visible because a partial CSS/font miss still leaves a
      // readable (if less polished) offline page, unlike a missing page
      // entirely.
      if (port) port.postMessage({ type: 'TMS_CACHE_TRIP_RESULT', results });
      await Promise.allSettled(TRIPKIT_STATIC_ASSETS.map(async (url) => {
        try {
          const res = await fetch(url, { credentials: 'include', cache: 'reload' });
          if (res && res.status === 200) await cache.put(url, res.clone());
        } catch (err) { /* best-effort -- never block or fail the trip save */ }
      }));
    })());
    return;
  }

  if (data.type === 'TMS_REMOVE_TRIP' && Array.isArray(data.urls)) {
    event.waitUntil((async () => {
      const cache = await caches.open(TRIPKIT_CACHE);
      const results = [];
      for (const url of data.urls) {
        results.push({ url, deleted: await cache.delete(url) });
      }
      if (port) port.postMessage({ type: 'TMS_REMOVE_TRIP_RESULT', results });
    })());
    return;
  }

  if (data.type === 'TMS_LIST_TRIPKIT') {
    event.waitUntil((async () => {
      const cache = await caches.open(TRIPKIT_CACHE);
      const keys = await cache.keys();
      if (port) port.postMessage({ type: 'TMS_LIST_TRIPKIT_RESULT', urls: keys.map(k => k.url) });
    })());
  }
});

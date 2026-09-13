const CACHE = 'tms-cache-v1';
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

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(PRECACHE).catch(()=>{})));
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
  e.respondWith(
    fetch(e.request).then(res => {
      if (res && res.status === 200) {
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, clone));
      }
      return res;
    }).catch(() => caches.match(e.request).then(r => r || caches.match('/tms/offline.html')))
    // Note: caches.match() here (the global CacheStorage.match, not a single
    // Cache's match) searches every cache under this origin, TRIPKIT_CACHE
    // included -- so a page saved via the message handler below is found
    // here automatically the moment the network fetch fails, with no
    // special-casing needed in this handler.
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
      if (port) port.postMessage({ type: 'TMS_CACHE_TRIP_RESULT', results });
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

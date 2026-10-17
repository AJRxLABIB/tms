// Service-worker behaviour test with a mock CacheStorage/fetch (no browser).   node tests/pwa/sw_test.js
const fs = require('fs'), vm = require('vm'), path = require('path');
let pass = 0, fail = 0; const t = (n, ok) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + n); };
class Resp { constructor(body, status = 200, headers = {}) { this.body = body; this.status = status; this.headers = { get: k => headers[k] ?? headers[k.toLowerCase()] ?? null }; } clone() { return new Resp(this.body, this.status, {}); } }
function make() {
  const stores = {}, listeners = {}; let netDown = false; const netHeaders = {};
  const cacheObj = n => (stores[n] ??= new Map(), { put: async (r, v) => { stores[n].set(r.url ?? r, v); }, add: async u => { stores[n].set(u, new Resp('pre')); }, match: async r => stores[n].get(r.url ?? r), keys: async () => [...stores[n].keys()].map(url => ({ url })), delete: async r => stores[n].delete(r.url ?? r) });
  const caches = { open: async n => cacheObj(n), keys: async () => Object.keys(stores), delete: async n => delete stores[n],
    match: async (r, o) => { const u = r.url ?? r; for (const n of o && o.cacheName ? [o.cacheName] : Object.keys(stores)) if (stores[n] && stores[n].has(u)) return stores[n].get(u); return undefined; } };
  const self = { location: { origin: 'http://x' }, addEventListener: (e, f) => listeners[e] = f, skipWaiting() {}, clients: { claim() {} } };
  const fetchFn = async u => { if (netDown) throw new Error('offline'); const url = u.url ?? u; return new Resp('net:' + url, 200, netHeaders[url] || {}); };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../sw.js'), 'utf8'), { self, caches, fetch: fetchFn, URL, Promise, console });
  const pending = [];
  const fire = async (urlPath, extra = {}) => { let res; const e = { request: { method: 'GET', url: 'http://x' + urlPath, ...extra }, respondWith: p => { res = p; }, waitUntil: p => pending.push(p) }; listeners.fetch(e); const out = res ? await res : undefined; await Promise.all(pending.splice(0)); await new Promise(r => setTimeout(r, 5)); return { intercepted: !!res, out }; };
  return { stores, caches, fire, setDown: v => netDown = v, netHeaders, listeners, cacheObj };
}
(async () => {
  let s = make();
  await s.fire('/tms/package-list.php');
  t('public page: cached for offline', s.stores['tms-cache-v2'].has('http://x/tms/package-list.php'));
  await s.fire('/tms/wallet.php'); await s.fire('/tms/tour-history.php'); await s.fire('/tms/dashboard.php'); await s.fire('/tms/ticket.php?bkid=1'); await s.fire('/tms/api/index.php?action=me'); await s.fire('/tms/admin/dashboard.php'); await s.fire('/tms/wallet-document.php?id=3');
  const keys = [...(s.stores['tms-cache-v2'] || new Map()).keys()];
  t('private pages (wallet, dashboard, tickets, API, admin, documents) are NEVER written to the generic cache', !keys.some(k => /wallet|tour-history|dashboard|ticket|api|admin/.test(k)));
  s.netHeaders['http://x/tms/some-public.php'] = { 'Cache-Control': 'no-store' }; await s.fire('/tms/some-public.php');
  t('responses marked no-store are not cached even on public paths', !s.stores['tms-cache-v2'].has('http://x/tms/some-public.php'));
  await s.fire('/tms/css/style.min.css');
  t('static asset: cached (stale-while-revalidate)', s.stores['tms-cache-v2'].has('http://x/tms/css/style.min.css'));
  s.setDown(true);
  const hit = await s.fire('/tms/css/style.min.css'); t('static asset: served from cache while offline', hit.out && hit.out.body.startsWith('net:'));
  const pl = await s.fire('/tms/package-list.php'); t('public page: served from cache while offline', pl.out && pl.out.body.startsWith('net:'));
  const w = await s.fire('/tms/wallet.php'); t('private page offline without a saved copy: falls back to offline page (never a stale generic copy)', !w.out || w.out.body === undefined || w.out.body === 'pre');
  // saved trip kit is still honoured offline
  await (await s.caches.open('tms-tripkit')).put({ url: 'http://x/tms/ticket.php?bkid=7' }, new Resp('SAVED-TICKET'));
  const sv = await s.fire('/tms/ticket.php?bkid=7'); t('explicitly saved trip page (TripKit) still opens offline', sv.out && sv.out.body === 'SAVED-TICKET');
  s.setDown(false);
  const lo = await s.fire('/tms/logout.php'); t('logout request is not intercepted (passes through)', lo.intercepted === false);
  t('logout wipes the generic cache AND the saved-trip kit', Object.keys(s.stores).length === 0);
  // old v1 cache is deleted on activate
  s = make(); await s.caches.open('tms-cache-v1'); await s.caches.open('tms-tripkit'); await s.caches.open('tms-cache-v2');
  s.listeners.activate({ waitUntil: p => p }); await new Promise(r => setTimeout(r, 10));
  t('activate removes the old v1 cache (that held logged-in pages) but keeps tripkit and v2', !('tms-cache-v1' in s.stores) && 'tms-tripkit' in s.stores && 'tms-cache-v2' in s.stores);
  const post = await s.fire('/tms/package-list.php', { method: 'POST' }); t('non-GET requests are ignored', post.intercepted === false);
  const cross = await s.fire('/x'); // same origin ok
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();

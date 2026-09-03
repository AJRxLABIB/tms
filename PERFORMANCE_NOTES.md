# TMS Performance Optimization Notes

## What was fixed in code (this pass)

1. **N+1 query eliminated** — `package-list.php` was running one `SELECT` per
   package card to check wishlist status (up to 6+ extra queries per page load).
   Replaced with a single batched query before the loop.

2. **Persistent DB connections** — `PDO::ATTR_PERSISTENT` enabled in
   `includes/config.php`. Verified safe: the only transaction code in the whole
   codebase is the one-time package seeder, which is already wrapped in a
   try/catch with rollback, so a persistent connection can't be left in a
   broken transactional state.

3. **New indexes added** for the query patterns introduced by the destination
   picker and package list: `Country`, `Region`, `PackageType`, `IsFeatured`,
   `ViewCount`, and a composite `(UserEmail, PackageId)` index on the wishlist
   table for the batched lookup above.

4. **Removed `ORDER BY RAND()`** on the Featured Destinations query in
   `destinations.php` — this forces a full filesort even on an indexed column
   and gets slower as the catalog grows. Replaced with a small indexed fetch +
   PHP-side shuffle.

5. **Lazy-loading audit** — added `loading="lazy"` across the codebase:
   package list, destinations grid, compare table, wishlist cards, and the
   travel-memory trip gallery. Above-the-fold hero images (blog post cover,
   page banners) were deliberately left eager — lazy-loading those would
   *hurt* load performance (delays LCP), so this was applied selectively,
   not blanket.

6. **Gzip compression extended** to `application/json` in `.htaccess`, so AI
   planner and other JSON API responses get compressed too (previously only
   HTML/CSS/JS were covered).

7. **Weather API caching** (added earlier in this session) — 30-minute DB
   cache per destination avoids re-hitting OpenWeatherMap on every page load.

## What's already good (verified, no changes needed)

- No render-blocking `<script>` tags anywhere in the codebase — everything
  already uses `defer`.
- Browser caching headers (`Cache-Control`, `ExpiresByType`) were already
  configured in `.htaccess` for images/CSS/JS.
- Prepared statements throughout (`PDO::ATTR_EMULATE_PREPARES => false`) —
  real server-side prepares, not just escaping.

## Infrastructure-level items I can't apply from code

These require access to your actual server/hosting environment, which I don't
have — you'll need to apply them there:

- **Enable PHP OPcache** (`opcache.enable=1` in `php.ini`) — the single biggest
  PHP performance win available for a project this size; skips re-parsing PHP
  source on every request.
- **Enable MySQL/MariaDB slow query log** temporarily after going live with
  the 400+ package dataset, to catch any query patterns that only show up
  under real traffic.
- **Serve images via a CDN or at least compressed/resized formats (WebP)**
  once you upload real photos for the new destinations — the current
  placeholder fallback has no size/format overhead, but real photos will.
- **HTTP/2 or HTTP/3** on your web server if not already enabled — lets the
  browser fetch the (now gzip'd) CSS/JS/JSON in parallel instead of queued.

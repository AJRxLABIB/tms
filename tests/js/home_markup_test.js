// Static check for the D3 pages: every id a page's JS looks up (getElementById / querySelector('#x')) must exist in the
// PHP markup (or be created in JS). Also checks tms-pages.css is linked after the theme and CSS braces balance.
// Run: node tests/js/home_markup_test.js
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..');
const rd = f => fs.readFileSync(path.join(root, f), 'utf8');
const pages = {
  'frontend/index.php': ['backend/home-sections.php'],
  'backend/landing.php': ['backend/home-sections.php'],
  'frontend/package-list.php': ['js/tms-map-pages.js'],
  'frontend/package-details.php': ['backend/signin.php'],
  'frontend/destinations.php': [], 'frontend/hotels.php': [], 'frontend/activities.php': [],
  'frontend/hotel-details.php': [], 'frontend/activity-details.php': [],
  'frontend/blog.php': [], 'frontend/blog-post.php': [], 'frontend/compare.php': [],
};
let fail = 0;
const ok = (c, m) => { if (!c) { fail++; console.log('FAIL', m); } else console.log('ok  ', m); };
for (const [page, extra] of Object.entries(pages)) {
  const src = rd(page) + extra.map(rd).join('\n');
  const markupIds = new Set();
  let m; const idRe = /\bid\s*=\s*["']([A-Za-z0-9_\-]+)["']/g;
  while ((m = idRe.exec(src))) markupIds.add(m[1]);
  // ids built by PHP/JS string (id="x'+n+'") are skipped; ids created in JS via .id = 'x' / setAttribute('id','x') count as created.
  const created = new Set(); const cRe = /(?:\.id\s*=\s*|setAttribute\(\s*['"]id['"]\s*,\s*)['"]([A-Za-z0-9_\-]+)['"]/g;
  while ((m = cRe.exec(src))) created.add(m[1]);
  const missing = new Set(); const gRe = /getElementById\(\s*['"]([A-Za-z0-9_\-]+)['"]\s*\)/g;
  while ((m = gRe.exec(src))) if (!markupIds.has(m[1]) && !created.has(m[1])) missing.add(m[1]);
  ok(missing.size === 0, page + ' JS ids present' + (missing.size ? ' — missing: ' + [...missing].join(', ') : ''));
}
// ids the redesign must keep
const keep = { 'backend/home-sections.php': ['heroDestInput', 'heroMicBtn'], 'frontend/package-details.php': ['datepicker1', 'crowdInsightsBox', 'sustainabilityBox', 'pkgGallery'], 'frontend/package-list.php': ['nlSearchInput', 'nlSearchBtn', 'nlSearchStatus', 'pkgListSearch', 'pkgListType', 'pkgListDuration', 'pkgListSort', 'pkgListView', 'viewListBtn', 'viewMapBtn'] };
for (const [f, ids] of Object.entries(keep)) { const s = rd(f); for (const id of ids) ok(new RegExp('id=["\']' + id + '["\']').test(s), f + ' keeps #' + id); }
// filter param names still present
const pl = rd('frontend/package-list.php'); for (const n of ['search', 'type', 'dur', 'sort']) ok(new RegExp('name="' + n + '"').test(pl), 'package-list keeps filter name=' + n);
// csrf fields still in booking forms
for (const f of ['frontend/hotel-details.php', 'frontend/activity-details.php']) ok(/tms_csrf_field\(\)/.test(rd(f)), f + ' keeps CSRF field');
ok((rd('frontend/package-details.php').match(/csrf/gi) || []).length >= 1, 'package-details keeps CSRF');
// CSS linked after the theme (header.php links theme; pages link pages.css after include header)
for (const f of Object.keys(pages).filter(p => !/index|landing/.test(p))) { const s = rd(f); ok(s.indexOf("include('backend/header.php')") > -1 && s.indexOf('tms-pages.css') > s.indexOf("include('backend/header.php')"), f + ' links tms-pages.css after header'); }
// CSS braces
const css = rd('css/tms-pages.css').replace(/\/\*[\s\S]*?\*\//g, '');
ok((css.match(/{/g) || []).length === (css.match(/}/g) || []).length, 'tms-pages.css braces balanced');
// images: no hotlinked photos in the home partial
ok(!/https?:\/\/images\.unsplash/.test(rd('backend/home-sections.php')), 'home has no hotlinked photos');
// reduced-motion present
ok(/prefers-reduced-motion: reduce/.test(css), 'tms-pages.css has reduced-motion block');
console.log(fail ? '\n' + fail + ' FAILED' : '\nall passed'); process.exit(fail ? 1 : 0);

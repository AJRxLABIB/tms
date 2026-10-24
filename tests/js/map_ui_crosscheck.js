// Static cross-check: DOM ids the explorer looks up exist in tour-map.php (or are created by the JS) and every tms-* class the JS
// creates has a CSS rule.   node tests/js/map_ui_crosscheck.js
const fs = require('fs'), path = require('path');
const R = p => fs.readFileSync(path.join(__dirname, '../..', p), 'utf8');
const js = R('js/tms-explorer.js') + '\n' + R('js/tms-maplibre.js'), css = R('css/tms-map-ui.css') + R('css/tms-a11y.css'), php = R('frontend/tour-map.php');
let bad = 0; const fail = m => { bad++; console.log('MISMATCH ' + m); };

// 1) ids queried by selector or getElementById
const queried = new Set();
for (const m of js.matchAll(/getElementById\('([A-Za-z0-9_-]+)'\)/g)) queried.add(m[1]);
for (const m of js.matchAll(/\$\('#([A-Za-z0-9_-]+)'/g)) queried.add(m[1]);
const created = new Set();
for (const m of js.matchAll(/\bid:\s*'([A-Za-z0-9_-]+)'/g)) created.add(m[1]);
for (const m of js.matchAll(/\.id\s*=\s*'([A-Za-z0-9_-]+)'/g)) created.add(m[1]);
for (const id of [...queried].sort()) {
  if (id.endsWith('-')) { console.log('ok       #' + id + '* (dynamic id built in JS)'); continue; }
  const inPhp = new RegExp('id="' + id + '"').test(php);
  const inJs = created.has(id);
  console.log((inPhp || inJs ? 'ok       ' : 'MISSING  ') + '#' + id + (inPhp ? ' (tour-map.php)' : inJs ? ' (created by JS)' : ''));
  if (!inPhp && !inJs) fail('#' + id + ' is looked up but defined nowhere');
}
// data attributes read from the root
for (const m of js.matchAll(/root\.getAttribute\('(data-[a-z-]+)'\)/g)) {
  const ok = php.includes(m[1] + '=');
  console.log((ok ? 'ok       ' : 'MISSING  ') + m[1]); if (!ok) fail(m[1] + ' is read but not set by tour-map.php');
}
// 2) classes created by the JS that have no CSS rule
const classes = new Set();
for (const m of js.matchAll(/class:\s*'([^']+)'/g)) m[1].split(/\s+/).forEach(c => classes.add(c));
for (const m of js.matchAll(/classList\.(?:add|toggle)\(\s*'([^']+)'/g)) classes.add(m[1]);
for (const m of js.matchAll(/className\s*=\s*'([^']+)'/g)) m[1].split(/\s+/).forEach(c => classes.add(c));
const skip = c => /^(fa|fa-.*|is-.*|list-hidden)$/.test(c) || !/^tms-/.test(c);
const noRule = [...classes].filter(c => !skip(c) && !css.includes('.' + c));
// classes that are layer ids / names, not DOM classes
const layerNames = new Set(['tms-clusters', 'tms-cluster-count', 'tms-place-icons', 'tms-selected-ring', 'tms-route-casing', 'tms-route-line', 'tms-trip-line', 'tms-trip-stop-circles', 'tms-trip-stop-num', 'tms-endpoint-circles', 'tms-endpoint-text', 'tms-geo-dot', 'tms-endpoints', 'tms-raster-base', 'tms-places', 'tms-route', 'tms-trip', 'tms-trip-stops', 'tms-geo', 'tms-ic-', 'tms-ic-_']);
noRule.filter(c => !layerNames.has(c)).forEach(c => fail('class .' + c + ' is created by the JS but has no CSS rule'));
console.log(`\nclasses created by JS: ${classes.size}, without CSS rule (excluding map layer ids): ${noRule.filter(c => !layerNames.has(c)).length}`);
// 3) state classes the CSS targets must be toggled by the JS
for (const c of ['is-active', 'is-loading', 'is-ph', 'is-on', 'list-hidden']) if (!js.includes(c)) fail('CSS state .' + c + ' never set by JS');
for (const a of ['data-sheet']) if (!js.includes(a) || !css.includes(a)) fail(a + ' mismatch between JS and CSS');
console.log(bad ? `\n${bad} mismatch(es)` : '\nno mismatches');
process.exit(bad ? 1 : 0);

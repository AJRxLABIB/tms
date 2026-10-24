// node tests/js/nav_links_test.js — every link in the new header/footer/plan hub points at a real page,
// every i18n key used by the new chrome exists in en + bn, and old IDs other code depends on still exist.
'use strict';
const fs = require('fs'), path = require('path');
const R = path.join(__dirname, '../..');
const read = f => fs.readFileSync(path.join(R, f), 'utf8');
let pass = 0, fail = 0;
function t(name, ok, extra) { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  ' + (extra || ''))); }

const nav = read('backend/nav.php'), foot = read('backend/footer.php'), plan = read('frontend/plan.php'), hdr = read('backend/header.php'), i18n = read('backend/i18n.php');
const targets = new Set();
for (const src of [nav, foot, plan]) {
  for (const m of src.matchAll(/'([a-z0-9-]+\.php)(?:\?[^']*)?'/g)) targets.add(m[1]);
  for (const m of src.matchAll(/href="([a-z0-9-]+\.php)(?:\?[^"]*)?"/g)) targets.add(m[1]);
}
for (const f of ['admin/index.php']) targets.add(f);
targets.delete('config.php'); targets.delete('nav.php');
// pages being built by another engineer in the same release: allowed to be missing until they land
const PENDING = new Set(['my-trips.php', 'saved-places.php', 'trip-planner.php']);
const missing = [...targets].filter(f => !PENDING.has(f) && !(fs.existsSync(path.join(R, f)) || fs.existsSync(path.join(R, 'frontend', f))));  // root index.php serves /<name>.php from frontend/
t('link targets found (' + targets.size + ')', targets.size >= 30);
t('every link target exists', missing.length === 0, missing.join(', '));
t('plan.php is served from frontend/ (front controller)', fs.existsSync(path.join(R, 'frontend/plan.php')) && fs.existsSync(path.join(R, 'index.php')));

const linksBlock = (nav.match(/\$tmsnLinks = \[([\s\S]*?)\n\];/) || [,''])[1];
const primary = linksBlock.split('\n').filter(l => /\['[a-z-]+\.php'/.test(l)).length;
const loggedExtra = (nav.match(/\$tmsnLinks\[\] = \['[a-z-]+\.php'/g) || []).length;
t('guest: Home, Explore, Map + Packages menu; signed-in adds My Trips + Saved Places (max 7 top items)', primary === 3 && loggedExtra === 2 && /class="tmsn-dd tmsn-dd-nav"/.test(nav) && primary + loggedExtra + 1 <= 7, 'primary=' + primary + ' extra=' + loggedExtra);
t('Packages menu keeps Hotels, Activities and the Plan hub reachable', /\['hotels\.php'/.test(nav) && /\['activities\.php'/.test(nav) && /href="plan\.php"/.test(nav));
t('signed-in mobile tab bar: 5 tabs, outside the header', /id="tmsnTabbar"/.test(nav) && nav.indexOf('</header>') < nav.indexOf('id="tmsnTabbar"') && nav.match(/\$tmsnTabs = \[([\s\S]*?)\n\];/)[1].split('\n').filter(l => /^\s*\['[a-z-]+\.php'/.test(l)).length === 5);
t('no mega menu / topbar left in header', !/tms-mega-menu|class="tms-topbar"|featMegaMenu/.test(nav + hdr.split('</style>')[0]));
t('account menu has at most 4 links + logout', (nav.match(/\$tmsnAccount = \[([\s\S]*?)\n\];/)[1].match(/\['[a-z-]+\.php'/g) || []).length === 4);
t('header includes nav.php', /include __DIR__ \. '\/nav\.php'/.test(hdr));
t('skip target #tmsMainContent and <main id="tmsPageMain"> kept', hdr.includes('id="tmsMainContent"') && hdr.includes('id="tmsPageMain"'));
t('sign-in / sign-up modals kept', hdr.includes('id="signinModal"') && hdr.includes('id="signupModal"'));
t('IDs used by footer PWA script exist (#tmsInstallBtn in nav, footer btn)', nav.includes('id="tmsInstallBtn"') && foot.includes('id="tmsInstallBtnFooter"') && foot.includes('id="darkModeBtn"'));
t('currency select posts via .tms-currency-select (header JS)', nav.includes('tms-currency-select') && hdr.includes("'set-currency.php'"));
t('logout link carries CSRF token', /logout\.php\?t=<\?php echo urlencode\(tms_csrf_token\(\)\)/.test(nav));
t('landing mode: only auth buttons', /\$tmsnLanding\): \?>[\s\S]*?openModal\('signinModal'\)[\s\S]*?<\?php else: \?>/.test(nav));
t('guest Admin link moved to footer (Staff)', /admin\/index\.php/.test(foot) && !/admin\/index\.php/.test(nav));
t('pre-paint theme script resolves auto', /\|\| 'auto'/.test(hdr) && /prefers-color-scheme: dark/.test(hdr));
t('no inline onclick handlers on drawer/dropdowns (JS-bound)', !/id="tmsnBurger"[^>]*onclick/.test(nav));

const keys = new Set();
for (const src of [nav, foot, plan]) for (const m of src.matchAll(/tms_t\('([a-z_]+)'/g)) keys.add(m[1]);
const bnStart = i18n.indexOf("'bn' => ["), hiStart = i18n.indexOf("'hi' => [");
const en = i18n.slice(i18n.indexOf("'en' => ["), bnStart), bn = i18n.slice(bnStart, hiStart);
// keys that already had a PHP default and an en entry elsewhere; only the new chrome keys are required in both
const newKeys = [...keys].filter(k => /^(nav_(plan|dashboard|menu|close|notifications|notif_empty|view_all|theme|install|currency|hotels|activities)|theme_(light|dark|auto)|footer_(discover|plan|help|blurb)|plan_[a-z_]+)$/.test(k));
const noEn = newKeys.filter(k => !en.includes("'" + k + "'=>")), noBn = newKeys.filter(k => !bn.includes("'" + k + "'=>"));
t('new i18n keys present in en (' + newKeys.length + ')', newKeys.length >= 20 && noEn.length === 0, noEn.join(','));
t('new i18n keys present in bn', noBn.length === 0, noBn.join(','));

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);

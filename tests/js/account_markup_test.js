// Static check for the D4 account/auth/booking pages and admin/supplier chrome:
//  1. every $_POST['x'] a page reads still has a matching name="x" in its markup (or is built by JS),
//  2. every id a page's JS looks up exists, 3. tms-account.css is linked, 4. CSS braces balance,
//  5. admin sidebar keeps every link, 6. pure helpers of admin/js/tms-admin-nav.js behave.
// Run: node tests/js/account_markup_test.js
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..');
const rd = f => fs.readFileSync(path.join(root, f), 'utf8');
let fail = 0;
const ok = (c, m) => { if (!c) { fail++; console.log('FAIL', m); } else console.log('ok  ', m); };

const pages = ['login', 'register', 'forgot-password', 'dashboard', 'profile', 'tour-history', 'checkout', 'payment', 'thankyou',
  'ticket', 'wishlist', 'notifications', 'loyalty', 'alerts', 'group-trips', 'custom-trip', 'appeal', 'wallet'].map(p => 'frontend/' + p + '.php');
// Posted by other pages/modals (backend/signin.php sign-in modal; wishlist hearts on listing pages), not by this page's own form.
const postAllow = { 'frontend/login.php': ['email', 'password', 'redirect'], 'frontend/wishlist.php': ['toggle_wish'] };
for (const page of pages) {
  const src = rd(page);
  const names = new Set(); let m;
  const nRe = /\bname\s*=\s*["']([A-Za-z0-9_\-\[\]]+)["']/g;
  while ((m = nRe.exec(src))) names.add(m[1].replace(/\[\]$/, ''));
  const jsNames = new Set(); const jRe = /(?:\.name\s*=\s*|setAttribute\(\s*['"]name['"]\s*,\s*)['"]([A-Za-z0-9_\-]+)['"]/g;
  while ((m = jRe.exec(src))) jsNames.add(m[1]);
  const missing = new Set(); const pRe = /\$_POST\[\s*['"]([A-Za-z0-9_\-]+)['"]\s*\]/g;
  while ((m = pRe.exec(src))) if (!names.has(m[1]) && !jsNames.has(m[1]) && !(postAllow[page] || []).includes(m[1])) missing.add(m[1]);
  ok(missing.size === 0, page + ' POST keys have inputs' + (missing.size ? ' — missing: ' + [...missing].join(', ') : ''));

  const ids = new Set(); const iRe = /\bid\s*=\s*["']([A-Za-z0-9_\-]+)["']/g;
  while ((m = iRe.exec(src))) ids.add(m[1]);
  const made = new Set(); const cRe = /(?:\.id\s*=\s*|setAttribute\(\s*['"]id['"]\s*,\s*)['"]([A-Za-z0-9_\-]+)['"]/g;
  while ((m = cRe.exec(src))) made.add(m[1]);
  const gone = new Set(); const gRe = /getElementById\(\s*['"]([A-Za-z0-9_\-]+)['"]\s*\)/g;
  while ((m = gRe.exec(src))) if (!ids.has(m[1]) && !made.has(m[1])) gone.add(m[1]);
  ok(gone.size === 0, page + ' JS ids present' + (gone.size ? ' — missing: ' + [...gone].join(', ') : ''));
  ok(/tms-account\.css/.test(src), page + ' links tms-account.css');
}

const keep = {
  'frontend/login.php': ['name="EmailId"', 'name="Password"', 'name="totp_code"', 'name="btn-verify-2fa"', 'id="loginForm"', 'id="twoFactorForm"', 'id="passField"', 'togglePass()', 'tms_csrf_field()', 'GoogleAuth::button', 'aria-describedby="authErr"'],
  'frontend/register.php': ['name="FullName"', 'name="MobileNumber"', 'name="EmailId"', 'name="Password"', 'name="CPassword"', 'name="ref"', 'tms_csrf_field()', 'GoogleAuth::button', 'id="regForm"'],
  'frontend/forgot-password.php': ['name="email"', 'name="submit_request"', 'name="newpassword"', 'name="confirmpassword"', 'name="submit_reset"', 'name="token"', 'tms_csrf_field()'],
  'frontend/tour-history.php': ['name="cancel_booking"', 'name="request_modification"', 'name="bkid"', 'name="new_fromdate"', 'name="new_todate"', 'pay-now.php', 'data-f="upcoming"', 'data-f="cancelled"'],
  'frontend/checkout.php': ['name="confirm_checkout"', 'name="accept_terms"', 'name="mobile"', 'tms_csrf_field()', 'tms-stepper'],
  'frontend/payment.php': ['tms_csrf_field()', 'tms-stepper', 'Test mode'],
  'frontend/profile.php': ['name="submit6"', 'name="submit_travel_prefs"', 'name="name"', 'name="mobileno"', 'name="travel_mode"', 'Account &amp; privacy', 'two-factor-setup.php', 'privacy.php'],
  'frontend/thankyou.php': ['tms-confetti', 'backend/write-us.php'],
  'frontend/dashboard.php': ['tms-stats', 'ticket.php?bkid='],
  'supplier/_layout.php': ['css/tms-theme.css', 'sp-more', 'blackouts.php', 'quality.php', 'settlements.php'],
  'admin/includes/header.php': ['tms-admin-theme.css'],
};
for (const [f, needles] of Object.entries(keep)) { const s = rd(f); for (const n of needles) ok(s.includes(n), f + ' keeps ' + n); }

for (const f of ['login', 'register', 'forgot-password']) { const s = rd('frontend/' + f + '.php'); ok(/tms-auth-art/.test(s) && /tms-auth-card/.test(s) && /class="eye-btn"/.test(s), f + ' is split-screen with show/hide password'); }
const acc = rd('css/tms-account.css');
ok(/prefers-reduced-motion[^{]*\{[^}]*\.tms-confetti\s*\{\s*display:\s*none/.test(acc), 'confetti disabled under prefers-reduced-motion');

const side = rd('admin/includes/sidebarmenu.php');
const oldLinks = ['dashboard.php', 'operations-dashboard.php', 'revenue-analytics.php', 'export-report.php', 'user-analytics.php', 'popular-destinations.php', 'bi-copilot.php', 'manage-bookings.php', 'booking-calendar.php', 'manage-marketplace-bookings.php', 'manage-custom-trips.php', 'supplier-settlements.php', 'manage-coupons.php', 'manage-packages.php', 'add-package.php', 'manage-pages.php', 'manage-blog.php', 'manage-reviews.php', 'manage-translations.php', 'map-places.php', 'manage-emergency.php', 'manage-travel-notices.php', 'manage-users.php', 'manage-enquiries.php', 'manage-issues.php', 'live-chat.php', 'manage-suppliers.php', 'supplier-quality.php', 'manage-admins.php', 'ai-dashboard.php', 'ai-setup.php', 'ai-safety.php', 'ai-evaluation.php', 'fraud-cases.php', 'activity-log.php', 'workflow-rules.php', 'system-health.php', 'change-password.php', 'two-factor-setup.php'];
for (const l of oldLinks) ok(side.includes("'" + l + "'"), 'admin sidebar keeps ' + l);
ok((side.match(/\$canSee\(/g) || []).length >= 15 && /tms_admin_role/.test(side), 'admin sidebar is still role-gated');

const nav = require(path.join(root, 'admin/js/tms-admin-nav.js'));
ok(nav.matches('Manage Bookings', 'book') && nav.matches('Manage Bookings', 'manage book') && !nav.matches('Manage Bookings', 'fraud') && nav.matches('x', ''), 'nav.matches');
const r = nav.resolveOpen({ a: true, b: false }, 'c', ['a', 'b', 'c']);
ok(r.a === true && r.b === false && r.c === true, 'nav.resolveOpen opens current group');
ok(nav.resolveOpen(null, 'zzz', ['a']).a === false, 'nav.resolveOpen tolerates unknown group');

for (const f of ['css/tms-account.css', 'admin/css/tms-admin-theme.css', 'supplier/supplier.css']) {
  const s = rd(f).replace(/\/\*[\s\S]*?\*\//g, '');
  const o = (s.match(/\{/g) || []).length, c = (s.match(/\}/g) || []).length;
  ok(o === c, f + ' braces balance (' + o + '/' + c + ')');
}
console.log(fail ? '\n' + fail + ' FAILED' : '\nAll D4 checks passed');
process.exit(fail ? 1 : 0);

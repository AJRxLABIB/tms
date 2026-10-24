/* Static check: exactly ONE chat launcher exists in the markup an Explore page renders (explore-view.php + footer include chain),
 * the old Explore launcher/panel/JS/CSS are gone, and the merged widget has the pieces it needs.  node tests/js/chat_single_widget_test.js */
'use strict';
const fs = require('fs'), path = require('path');
const root = path.join(__dirname, '..', '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8');
let pass = 0, fail = 0;
function t(name, ok, d) { (ok ? pass++ : fail++); console.log((ok ? 'PASS ' : 'FAIL ') + name + (d ? '  ' + d : '')); }

// follow include('...') / require chain from a page, collecting markup-bearing PHP files
function chain(file, seen = new Set()) {
  if (seen.has(file) || !fs.existsSync(path.join(root, file))) return seen;
  seen.add(file);
  const src = read(file);
  const re = /(?:include|include_once|require|require_once)\s*\(?\s*(?:__DIR__\s*\.\s*)?['"]\/?(?:\.\.\/)?([A-Za-z0-9_\-\/\.]+\.php)['"]/g;
  let m; while ((m = re.exec(src))) { const f = m[1].replace(/^\.\.\//, ''); if (/^(backend|frontend|admin|api)\//.test(f) || fs.existsSync(path.join(root, f))) chain(f, seen); }
  return seen;
}
const files = [...chain('frontend/explore-view.php')];
const launcherRe = /<button[^>]*id="(?:tms|explore|tmsHuman)[A-Za-z]*(?:Chat)[A-Za-z]*Toggle"/g;
let launchers = [];
files.forEach(f => { const m = read(f).match(launcherRe); if (m) m.forEach(x => launchers.push(f + ': ' + x.slice(0, 60))); });
t('explore-view.php chain includes the site assistant (backend/chatbot.php)', files.includes('backend/chatbot.php'));
t('exactly one chat launcher button in the Explore include chain', launchers.length === 1, JSON.stringify(launchers));
t('the human live-chat widget (separate launcher) is not part of the page chain', !files.includes('backend/chat-widget.php'));
t('explore-chatbot.php renders no markup (glue only)', !/<button|<div|<script/i.test(read('backend/explore-chatbot.php').replace(/<\?php[\s\S]*?\?>/, '')) && /tms_chat_place_context/.test(read('backend/explore-chatbot.php')));
t('old #exploreChatToggle / #exploreChatPanel markup is gone', !/id="exploreChat(Toggle|Panel)"/.test(files.map(read).join('\n')));
const ex = read('js/explore.js'), css = read('css/explore.css');
t('js/explore.js no longer builds a second launcher or exposes toggleExploreChat', !/exploreChatToggle|toggleExploreChat|sendExploreChat|initExploreChatbot/.test(ex));
t('css/explore.css has no leftover explore chat styles', !/exploreChat|exp-chat|exp-msg|exp-typing/.test(css));

const w = read('backend/chatbot.php');
t('widget has ARIA: launcher aria-expanded/controls, dialog role', /id="tmsChatToggle"[^>]*aria-expanded="false"[^>]*aria-controls="tmsChatPanel"/.test(w) && /id="tmsChatPanel" role="dialog"/.test(w));
t('close button is labelled', /class="chat-close" aria-label=/.test(w));
t('context chip and both topic buttons exist (place context only)', /id="tmsChatContextName"/.test(w) && /id="tmsModePlace"/.test(w) && /id="tmsModeSite"/.test(w) && /<\?php if \(\$tmsPlaceCtx\): \?>/.test(w));
t('place topic posts to api/explore.php action=chat with its own session id', /fetch\('api\/explore\.php'/.test(w) && /append\('action', 'chat'\)/.test(w) && /placeSession/.test(w));
t('site topic still posts to api/ai.php?action=chatbot', /api\/ai\.php\?action=chatbot/.test(w));
t('separate message lists per topic', /id="tmsWidgetChatMessages"/.test(w) && /id="tmsPlaceChatMessages"/.test(w));
t('Escape closes and Tab is trapped inside the panel', /e\.key === 'Escape'/.test(w) && /e\.key !== 'Tab'/.test(w));
t('launcher is lifted on map pages', /tms-chat-lift/.test(w) && /heatmap/.test(w));
t('no widget text is inserted into the page without escaping (place bubbles use chatEscape)', /chatEscape\(text\)\.replace/.test(w));
t('z-index stays below site modals (>=10000) and above map', (w.match(/z-index:\s*(\d+)/g) || []).every(z => +z.replace(/\D/g, '') < 10000));

// every other page that includes the footer gets the same single widget (no page includes explore-chatbot except explore-view)
const incl = [];
(function walk(d) { fs.readdirSync(path.join(root, d), { withFileTypes: true }).forEach(e => { const r = d + '/' + e.name; if (e.isDirectory()) { if (!/^(\.git|node_modules|tests|_deprecated|js|css|uploads|vendor)$/.test(e.name)) walk(r); } else if (/\.php$/.test(e.name) && /(?:include|require)(?:_once)?\s*\(\s*['"][^'"]*explore-chatbot\.php/.test(read(r))) incl.push(r); }); })('.');
t('only explore-view.php includes the Explore glue', incl.length === 1 && incl[0].replace(/^\.\//, '') === 'frontend/explore-view.php', JSON.stringify(incl));
console.log('\n' + pass + ' passed, ' + fail + ' failed'); process.exit(fail ? 1 : 0);

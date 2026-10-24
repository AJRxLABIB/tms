// WCAG 2.x contrast of the colour pairs in css/tms-map-ui.css.   node tests/js/map_ui_contrast.js
const lum = h => { const c = [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16) / 255).map(v => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
const L = { surface: '#FFFFFF', surface2: '#F3F6F3', text: '#17231E', muted: '#4A5850', borderStrong: '#7C8D83', primary: '#176B50', onPrimary: '#FFFFFF', warnBg: '#FEF3C7', warnText: '#6B4309', errBg: '#FEF3F2', errText: '#B42318', okBg: '#DCFCE7', okText: '#14532D', demoBg: '#E9E5F8', demoText: '#3B2A7A' };
const D = { surface: '#18211C', surface2: '#202B25', text: '#EAF1EC', muted: '#A9B8AE', borderStrong: '#5B6F63', primary: '#5CC79C', onPrimary: '#0B1F17', warnBg: '#3A2C10', warnText: '#FCD9A0', errBg: '#3A1414', errText: '#FCA5A5', okBg: '#12301F', okText: '#A7F0C4', demoBg: '#2A2450', demoText: '#D9D2FF' };
let bad = 0;
function check(theme, T, name) {
  const rows = [
    ['text on surface', T.text, T.surface, 4.5], ['text on surface-2', T.text, T.surface2, 4.5], ['muted on surface', T.muted, T.surface, 4.5], ['muted on surface-2', T.muted, T.surface2, 4.5],
    ['on-primary on primary (chips, tabs, buttons)', T.onPrimary, T.primary, 4.5], ['primary link on surface', T.primary, T.surface, 4.5],
    ['warn text on warn bg (unverified tag, notices)', T.warnText, T.warnBg, 4.5], ['error text on error bg', T.errText, T.errBg, 4.5],
    ['ok text on ok bg (verified tag)', T.okText, T.okBg, 4.5], ['demo text on demo bg', T.demoText, T.demoBg, 4.5],
    ['control border on surface (non-text)', T.borderStrong, T.surface, 3.0], ['primary ring on surface (non-text)', T.primary, T.surface, 3.0]
  ];
  rows.forEach(([n, fg, bg, min]) => { const r = ratio(fg, bg); const ok = r >= min; if (!ok) bad++; console.log((ok ? 'PASS ' : 'FAIL ') + name.padEnd(5) + n.padEnd(50) + r.toFixed(2) + ':1 (need ' + min + ')'); });
}
check('light', L, 'light'); check('dark', D, 'dark');
console.log(bad ? `\n${bad} pair(s) below target` : '\nall pairs meet WCAG AA');
process.exit(bad ? 1 : 0);

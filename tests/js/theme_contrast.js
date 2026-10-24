// WCAG contrast check for the "Tropical Journey" tokens in css/tms-theme.css.  Run: node tests/js/theme_contrast.js
'use strict';
const fs = require('fs'), path = require('path');
function lin(c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
function lum(h) { const n = parseInt(h.slice(1), 16); return 0.2126 * lin(n >> 16 & 255) + 0.7152 * lin(n >> 8 & 255) + 0.0722 * lin(n & 255); }
function ratio(a, b) { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
function mix(a, b, t) { const A = parseInt(a.slice(1), 16), B = parseInt(b.slice(1), 16); const f = s => Math.round(((A >> s & 255) * (1 - t)) + ((B >> s & 255) * t)); return '#' + [16, 8, 0].map(s => f(s).toString(16).padStart(2, '0')).join(''); }

const L = { bg:'#FFF9F2', surface:'#FFFFFF', surface2:'#FDF1E4', text:'#12303A', muted:'#4F6A73', primary:'#0E7C86', primaryStrong:'#0A5C64', primaryInk:'#FFFFFF',
  accent:'#FF7A59', accentInk:'#3B1206', link:'#0A6B74', sky:'#BFE9F2', skyInk:'#08333A', leaf:'#3FA66B', leafText:'#1F6B42', border:'#E9DCCB',
  ok:'#1F6B42', okBg:'#E3F4EA', warn:'#7A4A00', warnBg:'#FFF1D6', bad:'#9B2226', badBg:'#FBE4E1', info:'#0A5C64', infoBg:'#DDF2F6',
  chipBg:'#FFFFFF', chipText:'#0A5C64', footerBg:'#0A3F46', footerText:'#E6F3F4', footerMuted:'#B7D4D8', topbarBg:'#0A5C64', topbarText:'#FFFFFF' };
const D = { bg:'#0B1D26', surface:'#112B36', surface2:'#17384A', text:'#EAF6F7', muted:'#9FC0C8', primary:'#3FD0C9', primaryStrong:'#7FE3DD', primaryInk:'#062B2E',
  accent:'#FF9A7B', accentInk:'#2A0E05', link:'#7FE3DD', sky:'#17384A', skyInk:'#EAF6F7', leaf:'#6BD39A', leafText:'#8FE3B4', border:'#24475A',
  ok:'#8FE3B4', okBg:'#10301F', warn:'#FFD27A', warnBg:'#3A2A0A', bad:'#FFB3AD', badBg:'#3D1614', info:'#7FE3DD', infoBg:'#12333D',
  chipBg:'#17384A', chipText:'#EAF6F7', footerBg:'#071218', footerText:'#E6F3F4', footerMuted:'#9FC0C8', topbarBg:'#0A2A33', topbarText:'#FFFFFF' };

const pairs = (T) => [
  ['text on bg', T.text, T.bg, 4.5], ['text on surface', T.text, T.surface, 4.5], ['text on surface-2', T.text, T.surface2, 4.5],
  ['muted on bg', T.muted, T.bg, 4.5], ['muted on surface', T.muted, T.surface, 4.5], ['muted on surface-2', T.muted, T.surface2, 4.5],
  ['primary-ink on primary (button)', T.primaryInk, T.primary, 4.5], ['accent-ink on accent (CTA)', T.accentInk, T.accent, 4.5],
  ['link on bg', T.link, T.bg, 4.5], ['link on surface', T.link, T.surface, 4.5], ['primary-strong on surface (headings)', T.primaryStrong, T.surface, 4.5],
  ['sky-ink on sky (badge)', T.skyInk, T.sky, 4.5], ['chip text on chip bg', T.chipText, T.chipBg, 4.5], ['leaf text on surface', T.leafText, T.surface, 4.5],
  ['ok on ok-bg', T.ok, T.okBg, 4.5], ['warn on warn-bg', T.warn, T.warnBg, 4.5], ['bad on bad-bg', T.bad, T.badBg, 4.5], ['info on info-bg', T.info, T.infoBg, 4.5],
  ['topbar text on topbar', T.topbarText, T.topbarBg, 4.5], ['footer text on footer', T.footerText, T.footerBg, 4.5], ['footer muted on footer', T.footerMuted, T.footerBg, 4.5],
  ['border on bg (non-text, 3:1 not required: decorative)', T.border, T.bg, 1.0], ['primary on bg (focus ring, non-text)', T.primary, T.bg, 3.0], ['primary on surface (focus ring, non-text)', T.primary, T.surface, 3.0],
];
// Hero palettes: white text must stay >= 4.5:1 on every gradient stop AND on the 50% blend between stops (text can sit between stops).
const HERO = {
  dawn: ['#3A2760', '#7A3A6E', '#A63F5E'], day: ['#073B42', '#0A5C64', '#0F7580'], dusk: ['#232A66', '#7A3B72', '#A93D3A'], night: ['#071A24', '#0F3040', '#14485A'],
  'dark-theme-day': ['#062A31', '#0A4A52', '#0C6068'],
};
let fail = 0, min = { light: 99, dark: 99, hero: 99 };
function run(name, T) {
  console.log('\n== ' + name);
  for (const [label, fg, bg, need] of pairs(T)) {
    const r = ratio(fg, bg); min[name] = Math.min(min[name], need >= 4.5 ? r : 99);
    const ok = r >= need; if (!ok) fail++;
    console.log((ok ? 'PASS ' : 'FAIL ') + r.toFixed(2).padStart(6) + ' (need ' + need + ')  ' + label + '  ' + fg + ' on ' + bg);
  }
}
run('light', L); run('dark', D);
console.log('\n== hero (white text on gradient stops and midpoints)');
for (const [k, st] of Object.entries(HERO)) {
  const pts = [st[0], mix(st[0], st[1], .5), st[1], mix(st[1], st[2], .5), st[2]];
  const worst = Math.min(...pts.map(p => ratio('#FFFFFF', p))); min.hero = Math.min(min.hero, worst);
  const ok = worst >= 4.5; if (!ok) fail++;
  console.log((ok ? 'PASS ' : 'FAIL ') + worst.toFixed(2).padStart(6) + '  hero ' + k + ' ' + st.join(' > '));
}
// Scene silhouettes sit under white text: must keep white >= 4.5:1 as well.
const SCENE = { 'ridge-far(day)': '#0F6F78', 'ridge-near(day)': '#0A525A', 'palms': '#073F46', 'wave-1': '#0C6A73', 'wave-2': '#0A5C64', 'ridge-far(night)': '#12404F', 'ridge-near(night)': '#0C2D3A' };
for (const [k, c] of Object.entries(SCENE)) { const r = ratio('#FFFFFF', c); min.hero = Math.min(min.hero, r); const ok = r >= 4.5; if (!ok) fail++; console.log((ok ? 'PASS ' : 'FAIL ') + r.toFixed(2).padStart(6) + '  scene layer ' + k + ' ' + c); }
// Cross-check: the CSS file really declares the tokens used here.
const css = fs.readFileSync(path.join(__dirname, '../../css/tms-theme.css'), 'utf8');
const declared = (hex) => css.toLowerCase().includes(hex.toLowerCase());
for (const [k, v] of Object.entries({ bg: L.bg, text: L.text, primary: L.primary, accent: L.accent, 'dark bg': D.bg, 'dark primary': D.primary })) {
  const ok = declared(v); if (!ok) fail++; console.log((ok ? 'PASS ' : 'FAIL ') + 'css declares ' + k + ' ' + v);
}
console.log('\nMinimum text ratios: light ' + min.light.toFixed(2) + ', dark ' + min.dark.toFixed(2) + ', hero ' + min.hero.toFixed(2));
console.log(fail ? fail + ' check(s) FAILED' : 'all contrast checks passed');
process.exit(fail ? 1 : 0);

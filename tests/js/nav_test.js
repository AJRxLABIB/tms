// node tests/js/nav_test.js — pure-logic tests for js/tms-nav.js (theme resolution, roving focus, header hide/show, focus trap)
'use strict';
const N = require('../../js/tms-nav.js');
let pass = 0, fail = 0;
function t(name, ok, extra) { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  ' + (extra || ''))); }

// theme
t('explicit dark wins over OS light', N.resolveTheme('dark', false) === 'dark');
t('explicit light wins over OS dark', N.resolveTheme('light', true) === 'light');
t('auto follows OS dark', N.resolveTheme('auto', true) === 'dark');
t('auto follows OS light', N.resolveTheme('auto', false) === 'light');
t('unknown/empty/null treated as auto', N.resolveTheme('', true) === 'dark' && N.resolveTheme(null, false) === 'light' && N.resolveTheme('x', true) === 'dark');
t('normalizePref keeps valid, defaults to auto', N.normalizePref('dark') === 'dark' && N.normalizePref('light') === 'light' && N.normalizePref('auto') === 'auto' && N.normalizePref('<b>') === 'auto' && N.normalizePref(null) === 'auto');

// roving focus
t('ArrowDown from -1 -> 0', N.nextIndex(-1, 4, 'ArrowDown') === 0);
t('ArrowDown wraps last -> 0', N.nextIndex(3, 4, 'ArrowDown') === 0);
t('ArrowUp from -1 -> last', N.nextIndex(-1, 4, 'ArrowUp') === 3);
t('ArrowUp wraps first -> last', N.nextIndex(0, 4, 'ArrowUp') === 3);
t('Home/End', N.nextIndex(2, 4, 'Home') === 0 && N.nextIndex(1, 4, 'End') === 3);
t('other keys unhandled (-1)', N.nextIndex(1, 4, 'a') === -1 && N.nextIndex(1, 4, 'Tab') === -1);
t('empty list -> -1', N.nextIndex(0, 0, 'ArrowDown') === -1);

// header hide/show
t('near top never hidden', N.headerState(0, 50, { wasHidden: true }).hidden === false);
t('scroll down past threshold hides', N.headerState(200, 260, {}).hidden === true);
t('tiny scroll jitter keeps state', N.headerState(300, 303, { wasHidden: true }).hidden === true && N.headerState(300, 303, { wasHidden: false }).hidden === false);
t('scroll up reveals', N.headerState(400, 380, { wasHidden: true }).hidden === false);
t('locked (menu open) never hides', N.headerState(200, 900, { locked: true }).hidden === false);
t('scrolled flag after 20px', N.headerState(0, 21, {}).scrolled === true && N.headerState(0, 20, {}).scrolled === false);

// focus trap
const a = {}, b = {}, c = {};
t('Tab on last wraps to first', N.trapTarget([a, b, c], c, false) === a);
t('Shift+Tab on first wraps to last', N.trapTarget([a, b, c], a, true) === c);
t('middle focus not intercepted', N.trapTarget([a, b, c], b, false) === null && N.trapTarget([a, b, c], b, true) === null);
t('empty list -> null', N.trapTarget([], a, false) === null);

// active link
t('isCurrent ignores query/hash', N.isCurrent('package-list.php?x=1#top', 'package-list.php'));
t('isCurrent different file false', !N.isCurrent('hotels.php', 'package-list.php') && !N.isCurrent('', ''));

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);

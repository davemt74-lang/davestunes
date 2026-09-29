import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../'+path, import.meta.url), 'utf8');
const page = read('desktop.php');
const core = read('assets/desktop/desktop-core.js');
const effects = read('assets/desktop/z-scroll-effects.js');
const runtime = read('assets/desktop/module-z-scroll.js');
const css = read('assets/desktop/desktop.css');
const workflow = read('.github/workflows/foundation-v1.yml');

assert.match(page, /'zScroll'=>true/);
assert.match(page, /data-zscroll-toggle/);
assert.match(page, /data-zscroll-stage/);
assert.match(page, /data-zscroll-scenes/);
assert.match(page, /data-zscroll-progress-fill/);
assert.match(page, /data-zscroll-markers/);
assert.match(page, /z-scroll-effects\.js/);
assert.match(page, /module-z-scroll\.js/);

assert.match(core, /\['desktop','z-scroll'\]/);

for (const id of [
  'zscroll.now-playing',
  'zscroll.albums',
  'zscroll.artists',
  'zscroll.playlists',
  'zscroll.discovery',
  'zscroll.library'
]) assert.match(effects, new RegExp(id.replace('.','\\.')));

for (const order of ['order: 10','order: 20','order: 30','order: 40','order: 50','order: 60']) {
  assert.match(effects, new RegExp(order.replace(' ','\\s*')));
}
assert.match(effects, /category: 'z-scroll-scene'/);
assert.match(effects, /player\.replace-queue/);
assert.match(effects, /player\.play-recording/);
assert.doesNotMatch(effects, /innerHTML/);
assert.doesNotMatch(effects, /new Audio\s*\(/);

assert.match(runtime, /id:'z-scroll-runtime'/);
assert.match(runtime, /event\.code!=='KeyZ'/);
assert.match(runtime, /event\.repeat/);
assert.match(runtime, /isTypingTarget/);
assert.match(runtime, /event\.ctrlKey\|\|event\.metaKey\|\|event\.altKey/);
assert.match(runtime, /window\.addEventListener\('wheel',onWheel,\{passive:false\}\)/);
assert.match(runtime, /event\.preventDefault\(\)/);
assert.match(runtime, /requestAnimationFrame/);
assert.match(runtime, /state\.progress\+=delta\*0\.16/);
assert.match(runtime, /prefers-reduced-motion: reduce/);
assert.match(runtime, /event\.pointerType!=='touch'/);
assert.match(runtime, /visibilitychange/);
assert.match(runtime, /window\.addEventListener\('blur',onBlur\)/);
assert.match(runtime, /desktop\.setMode\('z-scroll'\)/);
assert.match(runtime, /desktop\.setMode\('desktop'\)/);
assert.match(runtime, /id:'zscroll.enter'/);
assert.match(runtime, /id:'zscroll.exit'/);
assert.match(runtime, /id:'zscroll.set-progress'/);
assert.match(runtime, /fetchView\('home'\)/);
assert.match(runtime, /fetchView\('albums'\)/);
assert.match(runtime, /fetchView\('artists'\)/);
assert.match(runtime, /fetchView\('playlists'\)/);
assert.match(runtime, /fetchView\('songs'\)/);
assert.match(runtime, /if\(state\.latched\)setLatched\(false\)/);
assert.doesNotMatch(runtime, /innerHTML/);
assert.doesNotMatch(runtime, /new Audio\s*\(/);

assert.match(css, /data-desktop-mode="z-scroll".*z-scroll-stage/s);
assert.match(css, /z-scroll-scene/);
assert.match(css, /z-scroll-vinyl/);
assert.match(css, /z-scroll-album-rail/);
assert.match(css, /z-scroll-artist-rail/);
assert.match(css, /z-scroll-playlist-rail/);
assert.match(css, /prefers-reduced-motion:reduce/);
assert.match(css, /data-zscroll-toggle/);
assert.match(css, /a\.desktop-chip\[href="\/library\.php"\]/);

assert.match(workflow, /node --check assets\/desktop\/z-scroll-effects\.js/);
assert.match(workflow, /node --check assets\/desktop\/module-z-scroll\.js/);
assert.match(workflow, /node tests\/desktop-v230-contract\.mjs/);

console.log('MUSIC_DESKTOP_V1_SECTION4_CONTRACT=PASS');

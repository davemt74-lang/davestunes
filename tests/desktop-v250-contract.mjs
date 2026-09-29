import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../'+path, import.meta.url), 'utf8');
const page=read('desktop.php');
const turntable=read('assets/desktop/module-turntable.js');
const objects=read('assets/desktop/module-object-runtime.js');
const player=read('assets/player.js');
const css=read('assets/desktop/desktop.css');
const workflow=read('.github/workflows/foundation-v1.yml');

assert.match(page, /'version'=>'music-desktop-v1-section6'/);
assert.match(page, /'turntable'=>true/);
assert.match(page, /data-turntable/);
assert.match(page, /data-turntable-platter/);
assert.match(page, /data-turntable-label/);
assert.match(page, /data-turntable-arm/);
assert.match(page, /data-turntable-title/);
assert.match(page, /data-turntable-progress/);
assert.match(page, /module-turntable\.js/);

assert.match(turntable,/id:'digital-vinyl-turntable'/);
assert.match(turntable,/id:'turntable\.load-release'/);
assert.match(turntable,/id:'turntable\.toggle'/);
assert.match(turntable,/window\.DaveTunesPlayer/);
assert.match(turntable,/davestunes:desktop:object-dropped/);
assert.match(turntable,/object\.type!=='album-sleeve'/);
assert.match(turntable,/object\.resource\?\.type!=='release'/);
assert.match(turntable,/desktop-media\.php/);
assert.match(turntable,/player\.replace-queue/);
assert.match(turntable,/player\.play-recording/);
assert.match(turntable,/14\+p\*28/);
assert.match(turntable,/data\.turntablePlaying|turntablePlaying/);
assert.match(turntable,/safeCover/);
assert.match(turntable,/Album unavailable/);
assert.doesNotMatch(turntable,/new Audio\s*\(/);
assert.doesNotMatch(turntable,/innerHTML/);

assert.match(objects,/desktop\.emit\('object-dropped'/);
assert.match(objects,/clientX:event\.clientX/);
assert.match(objects,/clientY:event\.clientY/);
assert.equal((player.match(/new Audio\s*\(/g)||[]).length,1,'Canonical player must remain the only Audio owner.');

assert.match(css,/right:-128px/);
assert.match(css,/animation-play-state:paused/);
assert.match(css,/data-turntable-playing="true"/);
assert.match(css,/@keyframes turntableSpin/);
assert.match(css,/turntable-record-label/);
assert.match(css,/turntable-arm-base/);
assert.match(css,/turntable-drop-hint/);
assert.match(css,/prefers-reduced-motion:reduce/);

assert.match(workflow,/node --check assets\/desktop\/module-turntable\.js/);
assert.match(workflow,/node tests\/desktop-v250-contract\.mjs/);

console.log('MUSIC_DESKTOP_V1_SECTION6_CONTRACT=PASS');

import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../'+path, import.meta.url), 'utf8');
const page=read('desktop.php');
const core=read('assets/desktop/desktop-core.js');
const bridge=read('assets/desktop/module-player-bridge.js');
const turntable=read('assets/desktop/module-turntable.js');
const player=read('assets/player.js');
const workflow=read('.github/workflows/foundation-v1.yml');

const v7=page.match(/'version'=>'music-desktop-v1-section(\d+)'/);
assert.ok(v7&&Number(v7[1])>=7,'Music Desktop boot version must remain at Section 7 or later.');
assert.match(page, /'unifiedPlayer'=>true/);
assert.match(page, /module-player-bridge\.js/);
assert.match(page, /module-turntable\.js/);

assert.match(core,/playerSnapshot:null/);

for (const command of [
  'player.play-recording','player.replace-queue','player.play','player.pause','player.toggle',
  'player.next','player.previous','player.seek','player.seek-fraction','player.set-volume','player.snapshot'
]) {
  assert.match(bridge,new RegExp("id:'"+command.replace(/[.*+?^$\{\}()|[\]\\]/g,'\\$&')+"'"),'Missing unified player command '+command);
}
assert.match(bridge,/desktop\.state\.playerSnapshot=lastSnapshot/);
assert.match(bridge,/desktop\.emit\('player-snapshot'/);
assert.match(bridge,/davestunes:player:/);
assert.match(bridge,/queueRevision/);
assert.match(bridge,/currentIndex/);
assert.match(bridge,/positionSeconds/);
assert.match(bridge,/durationSeconds/);
assert.match(bridge,/progress/);
assert.match(bridge,/volume/);
assert.match(bridge,/canPrevious/);
assert.match(bridge,/canNext/);
assert.match(bridge,/Object\.freeze/);

assert.match(player,/const schedulePersist =/);
assert.match(player,/const flushPersist =/);
assert.match(player,/emit\('seek'/);
assert.match(player,/emit\('volume'/);
assert.match(player,/seek,/);
assert.match(player,/setVolume,/);
assert.equal((player.match(/new Audio\s*\(/g)||[]).length,1,'Canonical player must remain the only Audio owner.');

assert.match(turntable,/desktop\.runCommand\('player\.toggle'/);
assert.match(turntable,/desktop\.runCommand\('player\.previous'/);
assert.match(turntable,/desktop\.runCommand\('player\.next'/);
assert.match(turntable,/desktop\.runCommand\('player\.seek-fraction'/);
assert.doesNotMatch(turntable,/window\.DaveTunesPlayer/);
assert.doesNotMatch(turntable,/new Audio\s*\(/);

assert.match(workflow,/node tests\/desktop-v260-contract\.mjs/);

console.log('MUSIC_DESKTOP_V1_SECTION7_CONTRACT=PASS');

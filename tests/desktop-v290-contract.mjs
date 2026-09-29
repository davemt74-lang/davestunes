import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=path=>fs.readFileSync(new URL('../'+path,import.meta.url),'utf8');
const studio=read('experience-studio.php');
const data=read('experience-studio-data.php');
const js=read('assets/experience-studio.js');
const service=read('includes/experience-v280.php');
const dashboard=read('dashboard.php');
const desktop=read('desktop.php');
const workflow=read('.github/workflows/foundation-v1.yml');

const v10=desktop.match(/'version'=>'music-desktop-v1-section(\d+)'/);
assert.ok(v10&&Number(v10[1])>=9,'Music Desktop boot version must remain compatible with Section 10.');
assert.match(desktop,/'flowBuilder'=>true/);

assert.match(studio,/Experience Studio/);
assert.match(studio,/data-studio-filmstrip/);
assert.match(studio,/data-studio-canvas/);
assert.match(studio,/data-studio-inspector/);
assert.match(studio,/data-studio-action="add-scene"/);
assert.match(studio,/data-studio-action="add-node"/);
assert.match(studio,/data-studio-action="connect"/);
assert.match(studio,/data-studio-action="preview"/);
assert.match(studio,/data-studio-action="publish"/);

assert.match(data,/dt_experience_editor_snapshot/);
assert.match(service,/dt_experience_editor_snapshot/);
assert.match(service,/dt_experience_find_for_owner/);
assert.match(service,/dt_experience_require_owner/);

for(const action of ['add-scene','update-scene','delete-scene','add-layer','update-layer','delete-layer','add-node','update-node','delete-node','add-edge','update-edge','delete-edge','publish']){
  assert.match(js,new RegExp("'"+action.replace(/[.*+?^$\{\}()|[\]\\]/g,'\\$&')+"'"),'Studio missing action '+action);
}
assert.match(js,/draggable=true/);
assert.match(js,/dragstart/);
assert.match(js,/pointerdown/);
assert.match(js,/pointermove/);
assert.match(js,/pointerup/);
assert.match(js,/studio-edge/);
assert.match(js,/confirm\('Publish this draft as the live experience\?'/);
assert.match(js,/beforeunload/);
assert.doesNotMatch(js,/innerHTML\s*=\s*[^'"]/);
assert.doesNotMatch(js,/new Audio\s*\(/);

assert.match(dashboard,/experience-studio\.php/);
assert.match(workflow,/node tests\/desktop-v290-contract\.mjs/);
assert.match(workflow,/php tests\/desktop-v290-mysql\.php/);
assert.match(workflow,/node --check assets\/experience-studio\.js/);

console.log('MUSIC_DESKTOP_V1_SECTION10_CONTRACT=PASS');

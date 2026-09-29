import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../'+path, import.meta.url), 'utf8');
const page = read('desktop.php');
const core = read('assets/desktop/desktop-core.js');
const template = read('assets/desktop/template-midnight.js');
const bridge = read('assets/desktop/module-player-bridge.js');
const css = read('assets/desktop/desktop.css');
const view = read('includes/view.php');
const dashboard = read('dashboard.php');
const workflow = read('.github/workflows/foundation-v1.yml');

for (const layer of ['site','objects','turntable','z-scroll','system']) {
  assert.match(page, new RegExp('data-desktop-layer="'+layer.replace('-','\\-')+'"'));
}
assert.match(css, /--dt-layer-site:10/);
assert.match(css, /--dt-layer-objects:20/);
assert.match(css, /--dt-layer-turntable:30/);
assert.match(css, /--dt-layer-zscroll:40/);
assert.match(css, /--dt-layer-system:100/);

for (const contract of ['registerModule','registerEffect','registerObjectType','registerCommand','registerTemplate']) {
  assert.match(core, new RegExp(contract));
}
assert.match(core, /runCommand/);
assert.match(core, /applyTemplate/);
assert.match(core, /setMode/);
assert.match(core, /davestunes:desktop:/);
assert.match(core, /Unsupported desktop registry type/);
assert.match(core, /already registered/);
assert.doesNotMatch(core, /new Audio\s*\(/, 'Desktop must not create a second audio engine.');

assert.match(template, /id: 'midnight-desk'/);
assert.match(template, /--dt-desk-base/);
assert.match(bridge, /window\.DaveTunesPlayer/);
assert.match(bridge, /player\.play-recording/);
assert.match(bridge, /player\.replace-queue/);
assert.doesNotMatch(bridge, /new Audio\s*\(/, 'Player bridge must reuse the canonical player.');

assert.match(page, /JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT/);
assert.match(page, /data-desktop-mount="library"/);
assert.match(page, /data-default-template="midnight-desk"/);
assert.match(page, /dt_player_dock\(\)/);
assert.doesNotMatch(page, /keydown|KeyZ|wheel/, 'Z-scroll behavior belongs to its dedicated section, not the shell.');

assert.match(css, /right:-128px/);
assert.match(css, /prefers-reduced-motion:reduce/);
assert.doesNotMatch(css, /url\(/, 'Base desktop template must not depend on external decorative imagery.');

assert.match(view, /function dt_player_dock/);
assert.match(dashboard, /\/desktop\.php/);
assert.match(workflow, /node tests\/desktop-v200-contract\.mjs/);
assert.match(workflow, /node --check assets\/desktop\/desktop-core\.js/);

console.log('MUSIC_DESKTOP_V1_SECTION1_CONTRACT=PASS');

import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../'+path, import.meta.url), 'utf8');
const service=read('includes/desktop-media-v240.php');
const endpoint=read('desktop-media.php');
const module=read('assets/desktop/module-media-objects.js');
const adapter=read('assets/desktop/module-library-adapter.js');
const page=read('desktop.php');
const bootstrap=read('includes/bootstrap.php');
const css=read('assets/desktop/desktop.css');
const workflow=read('.github/workflows/foundation-v1.yml');

assert.match(service,/dt_desktop_media_release/);
assert.match(service,/dt_library_can_collect/);
assert.match(service,/dt_library_owned_releases/);
assert.match(service,/dt_library_available_releases/);
assert.match(service,/dt_desktop_data_release_dto/);
assert.match(endpoint,/dt_current_user/);
assert.match(endpoint,/Cache-Control: private, no-store/);
assert.match(endpoint,/Media object was not found/);
assert.doesNotMatch(endpoint,/SELECT\s/i);

assert.match(module,/id:'album-sleeve'/);
assert.match(module,/id:'media\.place-release'/);
assert.match(module,/id:'media\.refresh-release'/);
assert.match(module,/key:'release:'\+id/);
assert.match(module,/label:'Album'/);
assert.match(module,/resourceType:'release'/);
assert.match(module,/desktop-media\.php/);
assert.match(module,/finally\(\(\)=>hydration\.delete\(key\)\)/);
assert.match(module,/This album is no longer available/);
assert.match(module,/player\.replace-queue/);
assert.match(module,/player\.play-recording/);
assert.match(module,/dblclick/);
assert.doesNotMatch(module,/innerHTML/);
assert.doesNotMatch(module,/new Audio\s*\(/);
assert.doesNotMatch(module,/title:item\.title|label:item\.title/);

assert.match(adapter,/media\.place-release/);
assert.match(adapter,/'Place'/);
assert.match(page,/module-media-objects\.js/);
const v5=page.match(/'version'=>'music-desktop-v1-section(\d+)'/);
assert.ok(v5&&Number(v5[1])>=5,'Music Desktop boot version must remain at Section 5 or later.');
assert.match(bootstrap,/desktop-media-v240\.php/);
assert.match(css,/media-object-sleeve/);
assert.match(css,/media-object-actions/);

assert.match(workflow,/node --check assets\/desktop\/module-media-objects\.js/);
assert.match(workflow,/node tests\/desktop-v240-contract\.mjs/);
assert.match(workflow,/php tests\/desktop-v240-mysql\.php/);

console.log('MUSIC_DESKTOP_V1_SECTION5_CONTRACT=PASS');

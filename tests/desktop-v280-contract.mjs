import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=path=>fs.readFileSync(new URL('../'+path,import.meta.url),'utf8');
const page=read('desktop.php');
const core=read('assets/desktop/desktop-core.js');
const runtime=read('assets/desktop/module-experience-runtime.js');
const zscroll=read('assets/desktop/module-z-scroll.js');
const schema=read('includes/experience-schema-v280.php');
const service=read('includes/experience-v280.php');
const api=read('experience.php');
const manage=read('experience-manage.php');
const workflow=read('.github/workflows/foundation-v1.yml');

const v9=page.match(/'version'=>'music-desktop-v1-section(\d+)'/);
assert.ok(v9&&Number(v9[1])>=9,'Music Desktop boot version must remain at Section 9 or later.');
assert.match(page,/'experienceGraph'=>true/);
assert.match(page,/module-experience-runtime\.js/);

for(const table of [
  'experiences_v280','experience_versions_v280','experience_scenes_v280','experience_layers_v280',
  'experience_flow_nodes_v280','experience_flow_edges_v280','experience_events_v280'
])assert.match(schema,new RegExp(table));
assert.match(schema,/active_version_id/);
assert.match(schema,/manifest_sha256 CHAR\(64\)/);
assert.match(schema,/UNIQUE KEY uq_experience_owner_key_v280/);
assert.match(schema,/UNIQUE KEY uq_experience_scene_key_v280/);
assert.match(schema,/UNIQUE KEY uq_experience_node_key_v280/);
assert.match(schema,/UNIQUE KEY uq_experience_edge_key_v280/);

assert.match(service,/\['user','artist','release','recording'\]/);
assert.match(service,/dt_experience_require_owner/);
assert.match(service,/version_status'\]!=='draft'/);
assert.match(service,/dt_experience_validate_manifest/);
assert.match(service,/hash\('sha256',\$json\)/);
assert.match(service,/version_status='published'/);
assert.match(service,/active_version_id/);
assert.match(service,/dt_experience_clone_draft/);
assert.match(service,/dt_experience_active/);
assert.match(service,/dt_experience_public_allowed/);

assert.match(core,/experienceManifest:\s*null/);
assert.match(core,/experienceHash:\s*''/);
assert.match(runtime,/id:'experience\.load'/);
assert.match(runtime,/id:'experience\.clear'/);
assert.match(runtime,/DaveTunesExperienceRuntime/);
assert.match(runtime,/descriptors/);
assert.match(runtime,/renderScene/);
assert.match(runtime,/layer\?\.type/);
assert.doesNotMatch(runtime,/innerHTML/);
assert.doesNotMatch(runtime,/new Audio\s*\(/);

assert.match(zscroll,/DaveTunesExperienceRuntime\?\.descriptors/);
assert.match(zscroll,/desktop\.state\.experienceManifest/);
assert.match(zscroll,/davestunes:desktop:experience-loaded/);
assert.match(zscroll,/davestunes:desktop:experience-cleared/);
assert.match(zscroll,/category==='z-scroll-scene'/);

assert.match(api,/dt_experience_public_allowed/);
assert.match(api,/dt_experience_active/);
assert.match(manage,/dt_verify_csrf/);
assert.match(manage,/add-scene/);
assert.match(manage,/add-layer/);
assert.match(manage,/add-node/);
assert.match(manage,/add-edge/);
assert.match(manage,/publish/);
assert.match(manage,/new-draft/);

assert.match(workflow,/node tests\/desktop-v280-contract\.mjs/);
assert.match(workflow,/php tests\/desktop-v280-mysql\.php/);
assert.match(workflow,/node --check assets\/desktop\/module-experience-runtime\.js/);

console.log('MUSIC_DESKTOP_V1_SECTION9_CONTRACT=PASS');

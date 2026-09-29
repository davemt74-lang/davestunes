import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const effects=read('assets/desktop/experience-effects.js');
const runtime=read('assets/desktop/module-experience-runtime.js');
const zscroll=read('assets/desktop/module-z-scroll.js');
const studio=read('assets/experience-studio.js');
const page=read('desktop.php');
const studioPage=read('experience-studio.php');
const workflow=read('.github/workflows/foundation-v1.yml');

for(const id of ['experience.fade','experience.zoom','experience.parallax','experience.slide-up','experience.slide-left','experience.blur','experience.rotate','experience.scale','experience.crossfade','experience.depth']){
  assert.match(effects,new RegExp(id.replace(/[.*+?^$\{\}()|[\]\\]/g,'\\$&')));
}
assert.match(effects,/category:'experience-animation'/);
assert.match(effects,/DaveTunesEffectsLibrary/);
assert.match(effects,/cinematic-reveal/);
assert.match(effects,/deep-parallax/);
assert.match(effects,/soft-entry/);
assert.match(effects,/dream-blur/);
assert.match(effects,/side-reveal/);
assert.match(effects,/applyStack/);

assert.match(runtime,/scene\.settings\?\.effects/);
assert.match(runtime,/layer\.settings\?\.effects/);
assert.match(runtime,/data\.experienceLayerKey/);
assert.match(zscroll,/scene\.effect\.update/);
assert.match(zscroll,/reducedMotion/);

assert.match(page,/'effectsLibrary'=>true/);
assert.match(page,/experience-effects\.js/);
assert.match(studioPage,/data-studio-action="effect-preset"/);
assert.match(studioPage,/experience-effects\.js/);
assert.match(studio,/DaveTunesEffectsLibrary\?\.presets/);
assert.match(studio,/update-scene/);
assert.match(studio,/effects:preset\.effects/);

assert.match(workflow,/node --check assets\/desktop\/experience-effects\.js/);
assert.match(workflow,/node tests\/desktop-v300-contract\.mjs/);

console.log('MUSIC_DESKTOP_V1_SECTION11_CONTRACT=PASS');

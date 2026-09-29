import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const media=read('assets/desktop/module-media-objects.js');
const library=read('assets/desktop/module-library-adapter.js');
const data=read('includes/desktop-data-v210.php');
const hydrate=read('includes/desktop-media-v240.php');
const endpoint=read('desktop-media.php');
const desktop=read('desktop.php');
const workflow=read('.github/workflows/foundation-v1.yml');

assert.match(desktop,/'version'=>'music-desktop-v1-section14'/);
assert.match(desktop,/'collectionIntegration'=>true/);

for(const token of [
  "id:'artist-shortcut'",
  "id:'media.place-artist'",
  "id:'media.refresh-artist'",
  "key:'artist:'+id",
  "resourceType:'artist'",
  "Experience",
  "/artist-experience.php?artist=",
  "/album-experience.php?release=",
  "/artist.php?artist=",
  "/album.php?release="
]) assert.ok(media.includes(token),'Media object integration missing '+token);

assert.match(media,/hydration\.delete\('release:'\+id\)/);
assert.match(media,/hydration\.delete\('artist:'\+id\)/);
assert.doesNotMatch(media,/new Audio\s*\(/);

assert.match(library,/media\.place-artist/);
assert.match(library,/media\.place-release/);
assert.match(library,/artist-experience\.php/);
assert.match(library,/album-experience\.php/);
assert.match(library,/experienceAvailable/);

assert.match(data,/experienceAvailable/);
assert.match(data,/dt_experience_public_allowed\(\$pdo,'release'/);
assert.match(data,/dt_experience_public_allowed\(\$pdo,'artist'/);
assert.match(data,/profileUrl/);
assert.match(data,/experienceUrl/);

assert.match(hydrate,/function dt_desktop_media_artist/);
assert.match(endpoint,/\['release','artist'\]/);
assert.match(endpoint,/desktop-media-v330/);

assert.match(workflow,/node tests\/desktop-v330-contract\.mjs/);
assert.match(workflow,/php tests\/desktop-v330-mysql\.php/);

console.log('MUSIC_DESKTOP_V1_SECTION14_CONTRACT=PASS');

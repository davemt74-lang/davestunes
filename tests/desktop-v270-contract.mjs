import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=path=>fs.readFileSync(new URL('../'+path,import.meta.url),'utf8');
const page=read('desktop.php');
const featured=read('assets/desktop/module-featured-content.js');
const endpoint=read('desktop-featured.php');
const admin=read('admin-featured.php');
const schema=read('includes/featured-schema-v270.php');
const runtime=read('includes/featured-v270.php');
const objects=read('includes/desktop-object-schema-v220.php');
const workflow=read('.github/workflows/foundation-v1.yml');
const config=read('config.example.php');

const v8=page.match(/'version'=>'music-desktop-v1-section(\d+)'/);
assert.ok(v8&&Number(v8[1])>=8,'Music Desktop boot version must remain at Section 8 or later.');
assert.match(page,/'featuredContent'=>true/);
assert.match(page,/data-desktop-featured/);
assert.match(page,/module-featured-content\.js/);

assert.match(schema,/featured_posts_v270/);
assert.match(schema,/featured_post_events_v270/);
assert.match(schema,/placement VARCHAR/);
assert.match(schema,/post_status VARCHAR/);
assert.match(schema,/starts_at DATETIME NULL/);
assert.match(schema,/ends_at DATETIME NULL/);
assert.match(schema,/priority INT/);
assert.doesNotMatch(schema,/desktop_objects_v220/);

assert.match(runtime,/DAVESTUNES_ADMIN_EMAILS|admin\.emails/);
assert.match(runtime,/dt_require_admin/);
assert.match(runtime,/post_status='active'/);
assert.match(runtime,/starts_at IS NULL OR f\.starts_at<=NOW\(\)/);
assert.match(runtime,/ends_at IS NULL OR f\.ends_at>NOW\(\)/);
assert.match(runtime,/dt_desktop_data_release_dto/);
assert.match(runtime,/dt_desktop_data_song_dto/);

assert.match(featured,/id:'featured\.refresh'/);
assert.match(featured,/id:'featured-content'/);
assert.match(featured,/media\.place-release/);
assert.match(featured,/player\.play-recording/);
assert.match(featured,/featured-added-to-desktop/);
assert.doesNotMatch(featured,/object\.create/);
assert.doesNotMatch(featured,/new Audio\s*\(/);

assert.match(endpoint,/dt_featured_desktop/);
assert.match(endpoint,/Cache-Control: private, no-store/);
assert.match(admin,/dt_require_admin/);
assert.match(admin,/Featured Content/);
assert.match(admin,/content_type/);
assert.match(admin,/post_status/);
assert.match(config,/DAVESTUNES_ADMIN_EMAILS/);

assert.match(objects,/UNIQUE KEY uq_desktop_object_user_key_v220 \(user_id,object_key\)/);
assert.match(workflow,/node tests\/desktop-v270-contract\.mjs/);
assert.match(workflow,/php tests\/desktop-v270-mysql\.php/);

console.log('MUSIC_DESKTOP_V1_SECTION8_CONTRACT=PASS');

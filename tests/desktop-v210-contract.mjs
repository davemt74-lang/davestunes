import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../'+path, import.meta.url), 'utf8');
const server = read('includes/desktop-data-v210.php');
const endpoint = read('desktop-data.php');
const adapter = read('assets/desktop/module-library-adapter.js');
const page = read('desktop.php');
const bootstrap = read('includes/bootstrap.php');
const css = read('assets/desktop/desktop.css');
const workflow = read('.github/workflows/foundation-v1.yml');

for (const view of ['home','albums','artists','songs','crates','playlists']) {
  assert.match(server, new RegExp("'"+view+"'"));
  assert.match(page, new RegExp('data-library-view="'+view+'"'));
}
assert.match(server, /dt_library_owned_releases/);
assert.match(server, /dt_library_available_releases/);
assert.match(server, /dt_library_saved_releases/);
assert.match(server, /dt_library_owned_recordings/);
assert.match(server, /dt_library_saved_recordings/);
assert.match(server, /dt_desktop_data_direct_access_recordings/);
assert.match(server, /dt_desktop_data_song_map/);
assert.match(server, /dt_playback_recording_payload/);
assert.match(server, /dt_library_can_collect/);
assert.match(server, /dt_playback_recent_history/);
assert.match(server, /static \$cache/);
assert.match(server, /desktop-data-v210/);
assert.match(server, /dt_desktop_data_cover_url/);
assert.match(server, /!dt_library_release_is_public/);
assert.match(server, /!dt_library_recording_is_public/);
assert.doesNotMatch(server, /password_hash|storage_key/, 'Desktop DTOs must not expose credentials or protected media storage keys.');

assert.match(endpoint, /dt_current_user/);
assert.match(endpoint, /Authentication required/);
assert.match(endpoint, /REQUEST_METHOD.*GET/s);
assert.match(endpoint, /Allow: GET/);
assert.match(endpoint, /Cache-Control: private, no-store/);
assert.match(endpoint, /Desktop library is temporarily unavailable/);
assert.doesNotMatch(endpoint, /SELECT\s/i, 'Endpoint must delegate persistence reads to the adapter service.');

assert.match(adapter, /canonical-library-adapter/);
assert.match(adapter, /AbortController/);
assert.match(adapter, /requestId/);
assert.match(adapter, /aria-busy/);
assert.match(adapter, /textContent/);
assert.doesNotMatch(adapter, /innerHTML/);
assert.doesNotMatch(adapter, /new Audio\s*\(/);
assert.match(adapter, /player\.replace-queue/);
assert.match(adapter, /player\.play-recording/);
assert.match(adapter, /library\.open-view/);
assert.match(adapter, /library\.search/);
assert.match(adapter, /library\.refresh/);
assert.match(adapter, /data-desktop-mount="library-content"/);
assert.match(page, /module-library-adapter\.js/);
assert.match(page, /data-library-search/);
assert.match(page, /'dataAdapter'=>true/);
assert.match(bootstrap, /desktop-data-v210\.php/);
assert.match(css, /desktop-album-card/);
assert.match(css, /desktop-song-row/);

assert.match(workflow, /node --check assets\/desktop\/module-library-adapter\.js/);
assert.match(workflow, /node tests\/desktop-v210-contract\.mjs/);
assert.match(workflow, /php tests\/desktop-v210-mysql\.php/);

console.log('MUSIC_DESKTOP_V1_SECTION2_CONTRACT=PASS');

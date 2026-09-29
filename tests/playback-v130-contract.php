<?php
declare(strict_types=1);

function playback_read(string $path): string {
    $value=file_get_contents(dirname(__DIR__).'/'.$path);
    if($value===false)throw new RuntimeException('Could not read '.$path);
    return $value;
}
function playback_has(string $text,string $needle,string $message): void {
    if(!str_contains($text,$needle))throw new RuntimeException($message);
}
function playback_not(string $text,string $needle,string $message): void {
    if(str_contains($text,$needle))throw new RuntimeException($message);
}

$schema=playback_read('includes/playback-schema-v130.php');
$runtime=playback_read('includes/playback-v130.php');
$media=playback_read('media.php');
$api=playback_read('player-state.php');
$js=playback_read('assets/player.js');
$bootstrap=playback_read('includes/bootstrap.php');
$migrate=playback_read('migrate.php');
$view=playback_read('includes/view.php');
$upload=playback_read('media-manage.php');
$workflow=playback_read('.github/workflows/foundation-v1.yml');

foreach(['recording_media_v130','playback_sessions_v130','playback_queue_items_v130','playback_listens_v130','playback_events_v130'] as $table){
    playback_has($schema,'CREATE TABLE IF NOT EXISTS '.$table,'Missing playback table '.$table);
}
playback_has($schema,'UNIQUE KEY uq_playback_session_user_key_v130','Player sessions must be durable and device-keyed.');
playback_has($schema,'UNIQUE KEY uq_playback_listen_token_v130','Listen starts must be idempotent.');
playback_has($runtime,'dt_playback_access_mode','Playback authorization resolver is missing.');
playback_has($runtime,"return dt_library_recording_is_public($pdo,$recordingId)?'preview':'none';",'Public music must resolve to preview rather than full playback.');
playback_has($runtime,'dt_entitlement_user_has_recording','Full playback must use canonical entitlement authority.');
playback_has($runtime,'dt_artist_can','Artist teams must be able to review their own media.');
playback_has($runtime,'dt_playback_register_media','Governed media registration is missing.');
playback_has($runtime,'dt_playback_store_upload','Governed artist media upload is missing.');
playback_has($runtime,'$segment===\'.\'||$segment===\'..\'','Protected storage keys must reject dot path segments.');
playback_has($runtime,"asset_status='superseded'",'Media replacement must preserve prior asset history.');
playback_has($runtime,'FOR UPDATE','Media replacement and queue mutation need serialized writes.');
playback_has($runtime,'dt_playback_replace_queue','Canonical queue service is missing.');
playback_has($runtime,'Player queue changed on another surface.','Queue mutations must reject stale revisions.');
playback_has($runtime,'dt_playback_begin_listen','Listening-history start is missing.');
playback_has($runtime,'$elapsedMs+5000','Listening credit must be bounded by server-observed elapsed time.');
playback_has($runtime,'min(120000','Listening heartbeat must have a hard credit ceiling.');
playback_has($media,'HTTP_RANGE','Protected media endpoint must support range requests.');
playback_has($media,"(int)$selected['asset']['id']!==$requestedAssetId",'Stream requests must be pinned to the currently authorized asset.');
playback_has($media,'Cache-Control: private, no-store','Protected media must not be publicly cached.');
playback_not($media,'storage_key]','Protected storage keys must not be emitted as response metadata.');
playback_has($api,'dt_verify_csrf();','Player mutations must be CSRF-protected.');
playback_has($api,"'Authentication required.'",'Player API must return a JSON authentication error.');
playback_has($api,"'Player service is temporarily unavailable.'",'Unexpected player failures must not leak internals.');
playback_has($js,'window.DaveTunesPlayer','Frontend needs one canonical player command surface.');
playback_has($js,"davestunes:player:",'Player must expose an event bus for future surfaces.');
playback_has($js,'new Audio()','Player must own a single audio engine.');
playback_has($js,'davestunes.player.session','Player session identity must persist in the browser.');
playback_has($js,'catch (_)','Player must tolerate unavailable browser storage.');
playback_has($js,'expected_queue_revision','Frontend queue writes must use optimistic revision checks.');
playback_has($bootstrap,"require_once __DIR__.'/playback-schema-v130.php';",'Playback schema must load in bootstrap.');
playback_has($bootstrap,"require_once __DIR__.'/playback-v130.php';",'Playback runtime must load in bootstrap.');
playback_has($migrate,'dt_playback_ensure_schema($pdo);','Migration must install playback after library.');
playback_has($view,'dt-player-dock','Base shell must expose the canonical player surface.');
playback_has($upload,'enctype="multipart/form-data"','Artist media page must support protected audio upload.');
playback_has($upload,'dt_playback_store_upload','Artist media page must use governed storage service.');
playback_has($workflow,'php tests/playback-v130-contract.php','CI must run playback contract.');
playback_has($workflow,'php tests/playback-v130-mysql.php','CI must run playback MySQL integration.');

echo "FOUNDATION_V1_SECTION4_CONTRACT=PASS\n";

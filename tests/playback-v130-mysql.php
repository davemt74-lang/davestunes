<?php
declare(strict_types=1);

$mediaRoot=sys_get_temp_dir().'/davestunes-playback-'.bin2hex(random_bytes(4));
if(!mkdir($mediaRoot,0770,true)&&!is_dir($mediaRoot))throw new RuntimeException('Could not create playback test media directory.');

putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=playback-test-key-do-not-use-in-production');
putenv('DAVESTUNES_MEDIA_ROOT='.$mediaRoot);
$_SERVER['REMOTE_ADDR']='127.0.0.4';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
    'playback_events_v130','playback_listens_v130','playback_queue_items_v130','playback_sessions_v130','recording_media_v130',
    'music_playlist_recordings_v120','music_playlists_v120','music_crate_items_v120','music_crates_v120',
    'user_artist_follows_v120','user_saved_recordings_v120','user_saved_releases_v120',
    'music_entitlement_events_v120','music_entitlements_v120',
    'music_catalog_events_v110','music_release_tracks_v110','music_release_editions_v110','music_releases_v110','music_recordings_v110',
    'artist_authority_events','artist_memberships','artists','auth_login_attempts','user_profiles','users'
] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

dt_foundation_ensure_schema($pdo);
dt_catalog_ensure_schema($pdo);
dt_library_ensure_schema($pdo);
dt_playback_ensure_schema($pdo);
if(!dt_playback_schema_ready($pdo))throw new RuntimeException('Playback schema is not ready.');

$owner=dt_auth_register($pdo,'playback-owner@example.com','playback owner pass 123','Owner');
$listener=dt_auth_register($pdo,'playback-listener@example.com','playback listener pass 123','Listener');
$artist=dt_artist_create($pdo,$owner,'Playback Artist');
$artistId=(int)$artist['id'];
$recording=dt_catalog_create_recording($pdo,$artistId,$owner,['title'=>'Protected Song','isrc'=>'USPLY2612345','duration_ms'=>180000]);
$recordingId=(int)$recording['id'];
$release=dt_catalog_create_release($pdo,$artistId,$owner,['title'=>'Protected Album','release_type'=>'album']);
dt_catalog_add_recording_to_release($pdo,$artistId,(int)$release['id'],$recordingId,$owner,1,1);
dt_catalog_publish_release($pdo,$artistId,(int)$release['id'],$owner);

$fullKey='artist-'.$artistId.'/recording-'.$recordingId.'/master.mp3';
$previewKey='artist-'.$artistId.'/recording-'.$recordingId.'/preview.mp3';
@mkdir(dirname($mediaRoot.'/'.$fullKey),0770,true);
file_put_contents($mediaRoot.'/'.$fullKey,'ID3'.str_repeat('F',2048));
file_put_contents($mediaRoot.'/'.$previewKey,'ID3'.str_repeat('P',512));

$full=dt_playback_register_media($pdo,$artistId,$recordingId,$owner,[
    'media_role'=>'full','storage_key'=>$fullKey,'mime_type'=>'audio/mpeg',
    'byte_size'=>filesize($mediaRoot.'/'.$fullKey),'sha256'=>hash_file('sha256',$mediaRoot.'/'.$fullKey),'duration_ms'=>180000,
]);
$preview=dt_playback_register_media($pdo,$artistId,$recordingId,$owner,[
    'media_role'=>'preview','storage_key'=>$previewKey,'mime_type'=>'audio/mpeg',
    'byte_size'=>filesize($mediaRoot.'/'.$previewKey),'sha256'=>hash_file('sha256',$mediaRoot.'/'.$previewKey),
    'duration_ms'=>30000,'preview_start_ms'=>30000,'preview_end_ms'=>60000,
]);

if(dt_playback_access_mode($pdo,$recordingId,null)!=='preview')throw new RuntimeException('Public listener did not resolve to preview access.');
if(dt_playback_access_mode($pdo,$recordingId,$owner)!=='full')throw new RuntimeException('Artist owner did not resolve to full access.');
if(dt_playback_access_mode($pdo,$recordingId,$listener)!=='preview')throw new RuntimeException('Unentitled listener incorrectly received full access.');

$selected=dt_playback_select_media($pdo,$recordingId,$listener);
if((string)$selected['access_mode']!=='preview'||(int)$selected['asset']['id']!==(int)$preview['id'])throw new RuntimeException('Preview media selection failed.');

$grant=dt_entitlement_grant($pdo,[
    'grant_key'=>'purchase:playback-order:1',
    'user_id'=>(int)$listener['id'],
    'resource_type'=>'release',
    'resource_id'=>(int)$release['id'],
    'entitlement_type'=>'own',
    'source_type'=>'purchase',
    'source_ref'=>'playback-order',
]);
if(dt_playback_access_mode($pdo,$recordingId,$listener)!=='full')throw new RuntimeException('Entitlement did not unlock full playback.');
$selected=dt_playback_select_media($pdo,$recordingId,$listener);
if((int)$selected['asset']['id']!==(int)$full['id'])throw new RuntimeException('Full media selection failed after entitlement.');

$session=dt_playback_session($pdo,(int)$listener['id'],'browser:test');
$queue=dt_playback_replace_queue($pdo,(int)$listener['id'],'browser:test',[$recordingId,$recordingId],'release',(int)$release['id']);
if(count($queue)!==1)throw new RuntimeException('Queue did not de-duplicate canonical recordings.');
$state=dt_playback_update_state($pdo,(int)$listener['id'],'browser:test',[
    'recording_id'=>$recordingId,'playback_state'=>'playing','position_ms'=>12000,'volume'=>0.65,
]);
if((string)$state['playback_state']!=='playing'||(int)$state['position_ms']!==12000)throw new RuntimeException('Persistent playback state failed.');

$token='550e8400-e29b-41d4-a716-446655440000';
$listen=dt_playback_begin_listen($pdo,(int)$listener['id'],'browser:test',$recordingId,$token,'release',(int)$release['id']);
$replay=dt_playback_begin_listen($pdo,(int)$listener['id'],'browser:test',$recordingId,$token,'release',(int)$release['id']);
if((int)$listen['id']!==(int)$replay['id'])throw new RuntimeException('Listen start is not idempotent.');
$beat=dt_playback_heartbeat($pdo,(int)$listener['id'],$token,30000,999999,false);
if((int)$beat['listened_ms']!==120000)throw new RuntimeException('Heartbeat time delta was not bounded.');
$complete=dt_playback_heartbeat($pdo,(int)$listener['id'],$token,180000,5000,true);
if(empty($complete['completed_at']))throw new RuntimeException('Listen completion was not recorded.');
if(count(dt_playback_recent_history($pdo,(int)$listener['id']))!==1)throw new RuntimeException('Listening history did not project.');

dt_entitlement_revoke($pdo,(int)$grant['id'],(int)$owner['id'],'refund');
if(dt_playback_access_mode($pdo,$recordingId,$listener)!=='preview')throw new RuntimeException('Revocation did not downgrade playback to preview.');

$replacementKey='artist-'.$artistId.'/recording-'.$recordingId.'/preview-v2.mp3';
file_put_contents($mediaRoot.'/'.$replacementKey,'ID3'.str_repeat('N',700));
$preview2=dt_playback_register_media($pdo,$artistId,$recordingId,$owner,[
    'media_role'=>'preview','storage_key'=>$replacementKey,'mime_type'=>'audio/mpeg',
    'byte_size'=>filesize($mediaRoot.'/'.$replacementKey),'sha256'=>hash_file('sha256',$mediaRoot.'/'.$replacementKey),
    'duration_ms'=>30000,'preview_start_ms'=>0,'preview_end_ms'=>30000,
]);
$old=dt_playback_media_asset($pdo,(int)$preview['id']);
if((string)$old['asset_status']!=='superseded'||(string)$preview2['asset_status']!=='active')throw new RuntimeException('Media replacement lifecycle failed.');

$events=(int)$pdo->query('SELECT COUNT(*) FROM playback_events_v130')->fetchColumn();
if($events<4)throw new RuntimeException('Playback event history is incomplete.');

dt_playback_ensure_schema($pdo);
if((int)$pdo->query('SELECT COUNT(*) FROM recording_media_v130')->fetchColumn()!==3)throw new RuntimeException('Idempotent playback migration changed media records.');

echo "FOUNDATION_V1_SECTION4_MYSQL=PASS\n";

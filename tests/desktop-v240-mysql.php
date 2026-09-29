<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
$mediaRoot=sys_get_temp_dir().'/dt-media-objects-'.bin2hex(random_bytes(4));
mkdir($mediaRoot,0770,true);
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=media-object-ci-key');
putenv('DAVESTUNES_MEDIA_ROOT='.$mediaRoot);
$_SERVER['REMOTE_ADDR']='127.0.0.7';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
 'desktop_object_events_v220','desktop_objects_v220',
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
dt_desktop_object_ensure_schema($pdo);

$owner=dt_auth_register($pdo,'media-owner@example.com','media owner pass 123','Owner');
$user=dt_auth_register($pdo,'media-user@example.com','media user pass 123','User');
$other=dt_auth_register($pdo,'media-other@example.com','media other pass 123','Other');
$artist=dt_artist_create($pdo,$owner,'Night Objects');
$artistId=(int)$artist['id'];

function media_release(PDO $pdo,array $owner,int $artistId,string $title,string $isrc,bool $published): array {
    $song=dt_catalog_create_recording($pdo,$artistId,$owner,['title'=>$title.' Song','isrc'=>$isrc,'duration_ms'=>180000]);
    $release=dt_catalog_create_release($pdo,$artistId,$owner,['title'=>$title,'release_type'=>'album']);
    dt_catalog_add_recording_to_release($pdo,$artistId,(int)$release['id'],(int)$song['id'],$owner,1,1);
    if($published)dt_catalog_publish_release($pdo,$artistId,(int)$release['id'],$owner);
    return [$song,$release];
}
function media_audio(PDO $pdo,array $owner,int $artistId,array $song,string $root,string $role): void {
    $id=(int)$song['id']; $key='artist-'.$artistId.'/recording-'.$id.'/'.$role.'.mp3';
    @mkdir(dirname($root.'/'.$key),0770,true);
    file_put_contents($root.'/'.$key,'ID3'.str_repeat('M',700));
    dt_playback_register_media($pdo,$artistId,$id,$owner,[
      'media_role'=>$role,'storage_key'=>$key,'mime_type'=>'audio/mpeg',
      'byte_size'=>filesize($root.'/'.$key),'sha256'=>hash_file('sha256',$root.'/'.$key),
      'duration_ms'=>$role==='preview'?30000:180000,'preview_start_ms'=>0,'preview_end_ms'=>$role==='preview'?30000:0
    ]);
}

[$publicSong,$publicRelease]=media_release($pdo,$owner,$artistId,'Public Sleeve','USDME2612345',true);
[$privateSong,$privateRelease]=media_release($pdo,$owner,$artistId,'Private Sleeve','USDME2612346',false);
media_audio($pdo,$owner,$artistId,$publicSong,$mediaRoot,'preview');
media_audio($pdo,$owner,$artistId,$privateSong,$mediaRoot,'full');

$public=dt_desktop_media_release($pdo,$user,(int)$publicRelease['id']);
if(!$public||$public['title']!=='Public Sleeve'||$public['accessState']!=='public')throw new RuntimeException('Public sleeve hydration failed.');
if($public['playableTrackIds']!==[(int)$publicSong['id']])throw new RuntimeException('Public preview was not playable from the sleeve.');

if(dt_desktop_media_release($pdo,$user,(int)$privateRelease['id'])!==null)throw new RuntimeException('Private sleeve leaked before entitlement.');
$grant=dt_entitlement_grant($pdo,[
 'grant_key'=>'promotion:private-sleeve','user_id'=>(int)$user['id'],'resource_type'=>'release',
 'resource_id'=>(int)$privateRelease['id'],'entitlement_type'=>'access','source_type'=>'promotion','source_ref'=>'private-sleeve'
]);
$private=dt_desktop_media_release($pdo,$user,(int)$privateRelease['id']);
if(!$private||$private['title']!=='Private Sleeve'||$private['accessState']!=='available')throw new RuntimeException('Entitled private sleeve hydration failed.');
if($private['playableTrackIds']!==[(int)$privateSong['id'])throw new RuntimeException('Private full track did not hydrate as playable.');
if(dt_desktop_media_release($pdo,$other,(int)$privateRelease['id'])!==null)throw new RuntimeException('Private sleeve leaked to another user.');

$object=dt_desktop_object_create($pdo,(int)$user['id'],[
 'key'=>'release:'.(int)$privateRelease['id'],'type'=>'album-sleeve',
 'resource_type'=>'release','resource_id'=>(int)$privateRelease['id'],'label'=>'Album',
 'payload'=>['schema'=>'album-sleeve-v1']
]);
if($object['label']!=='Album'||isset($object['payload']['title']))throw new RuntimeException('Spatial album object persisted sensitive release metadata.');

dt_entitlement_revoke($pdo,(int)$grant['id'],(int)$owner['id'],'access ended');
if(dt_desktop_media_release($pdo,$user,(int)$privateRelease['id'])!==null)throw new RuntimeException('Revoked private sleeve still hydrated.');
if(count(dt_desktop_objects($pdo,(int)$user['id']))!==1)throw new RuntimeException('Revocation incorrectly destroyed the user layout object.');

echo "MUSIC_DESKTOP_V1_SECTION5_MYSQL=PASS\n";

<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
$mediaRoot=sys_get_temp_dir().'/dt-desktop-data-'.bin2hex(random_bytes(4));
mkdir($mediaRoot,0770,true);
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=desktop-data-ci-key');
putenv('DAVESTUNES_MEDIA_ROOT='.$mediaRoot);
$_SERVER['REMOTE_ADDR']='127.0.0.5';

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

$owner=dt_auth_register($pdo,'artist@example.com','artist account pass 123','Artist');
$listener=dt_auth_register($pdo,'listener@example.com','listener account pass 123','Listener');
$other=dt_auth_register($pdo,'other@example.com','other account password 123','Other');
$artist=dt_artist_create($pdo,$owner,'Glass Harbor');
$artistId=(int)$artist['id'];

function make_release(PDO $pdo,array $owner,int $artistId,string $title,string $isrc,bool $publish): array {
    $recording=dt_catalog_create_recording($pdo,$artistId,$owner,['title'=>$title,'isrc'=>$isrc,'duration_ms'=>180000]);
    $release=dt_catalog_create_release($pdo,$artistId,$owner,['title'=>$title,'release_type'=>'single']);
    dt_catalog_add_recording_to_release($pdo,$artistId,(int)$release['id'],(int)$recording['id'],$owner,1,1);
    if($publish)dt_catalog_publish_release($pdo,$artistId,(int)$release['id'],$owner);
    return [$recording,$release];
}
function add_media(PDO $pdo,array $owner,int $artistId,array $recording,string $root,string $role): void {
    $id=(int)$recording['id'];
    $key='artist-'.$artistId.'/recording-'.$id.'/'.$role.'.mp3';
    @mkdir(dirname($root.'/'.$key),0770,true);
    file_put_contents($root.'/'.$key,'ID3'.str_repeat($role==='full'?'F':'P',700));
    dt_playback_register_media($pdo,$artistId,$id,$owner,[
        'media_role'=>$role,'storage_key'=>$key,'mime_type'=>'audio/mpeg',
        'byte_size'=>filesize($root.'/'.$key),'sha256'=>hash_file('sha256',$root.'/'.$key),
        'duration_ms'=>$role==='preview'?30000:180000,'preview_start_ms'=>0,'preview_end_ms'=>$role==='preview'?30000:0,
    ]);
}

[$ownedSong,$ownedRelease]=make_release($pdo,$owner,$artistId,'Blue Room','USDTA2612345',true);
[$savedSong,$savedRelease]=make_release($pdo,$owner,$artistId,'Open Window','USDTA2612346',true);
[$privateSong,$privateRelease]=make_release($pdo,$owner,$artistId,'Secret Dawn','USDTA2612347',false);
$directSong=dt_catalog_create_recording($pdo,$artistId,$owner,['title'=>'Direct Signal','isrc'=>'USDTA2612348','duration_ms'=>150000]);
add_media($pdo,$owner,$artistId,$ownedSong,$mediaRoot,'full');
add_media($pdo,$owner,$artistId,$savedSong,$mediaRoot,'preview');
add_media($pdo,$owner,$artistId,$privateSong,$mediaRoot,'full');
add_media($pdo,$owner,$artistId,$directSong,$mediaRoot,'full');

dt_entitlement_grant($pdo,[
 'grant_key'=>'purchase:blue','user_id'=>(int)$listener['id'],'resource_type'=>'release',
 'resource_id'=>(int)$ownedRelease['id'],'entitlement_type'=>'own','source_type'=>'purchase','source_ref'=>'order-blue'
]);
dt_entitlement_grant($pdo,[
 'grant_key'=>'promotion:secret','user_id'=>(int)$listener['id'],'resource_type'=>'release',
 'resource_id'=>(int)$privateRelease['id'],'entitlement_type'=>'access','source_type'=>'promotion','source_ref'=>'invite-secret'
]);
dt_entitlement_grant($pdo,[
 'grant_key'=>'promotion:direct-signal','user_id'=>(int)$listener['id'],'resource_type'=>'recording',
 'resource_id'=>(int)$directSong['id'],'entitlement_type'=>'access','source_type'=>'promotion','source_ref'=>'direct-signal'
]);
dt_library_save_release($pdo,(int)$listener['id'],(int)$savedRelease['id']);
dt_library_follow_artist($pdo,(int)$listener['id'],$artistId);

$crate=dt_library_create_crate($pdo,(int)$listener['id'],'Night Shelf');
dt_library_add_to_crate($pdo,(int)$listener['id'],(int)$crate['id'],'release',(int)$ownedRelease['id']);
$playlist=dt_library_create_playlist($pdo,(int)$listener['id'],'Night Drive');
dt_library_add_to_playlist($pdo,(int)$listener['id'],(int)$playlist['id'],(int)$ownedSong['id']);

$albums=dt_desktop_data_payload($pdo,$listener,'albums','');
if(count($albums['data']['items'])!==3)throw new RuntimeException('Album adapter did not merge the personal library.');
$state=[];
foreach($albums['data']['items'] as $item)$state[$item['title']]=$item['accessState'];
if(($state['Blue Room']??'')!=='owned'||($state['Open Window']??'')!=='saved'||($state['Secret Dawn']??'')!=='available'){
    throw new RuntimeException('Album access states are incorrect.');
}

$secret=dt_desktop_data_payload($pdo,$listener,'albums','secret');
if(count($secret['data']['items'])!==1||$secret['data']['items'][0]['title']!=='Secret Dawn')throw new RuntimeException('Entitled private search failed.');
if(count(dt_desktop_data_payload($pdo,$other,'albums','secret')['data']['items'])!==0)throw new RuntimeException('Private release leaked across users.');

$songs=dt_desktop_data_payload($pdo,$listener,'songs','');
$titles=array_column($songs['data']['items'],'title');
foreach(['Blue Room','Open Window','Secret Dawn','Direct Signal'] as $title)if(!in_array($title,$titles,true))throw new RuntimeException('Song adapter missed '.$title);
$savedRows=array_values(array_filter($songs['data']['items'],fn($x)=>$x['title']==='Open Window'));
if(!$savedRows||$savedRows[0]['accessState']!=='saved')throw new RuntimeException('Saved album did not project its track into Songs.');
$directRows=array_values(array_filter($songs['data']['items'],fn($x)=>$x['title']==='Direct Signal'));
if(!$directRows||$directRows[0]['accessState']!=='available'||!$directRows[0]['playable'])throw new RuntimeException('Direct recording entitlement did not project into Songs.');
$privateRows=array_values(array_filter($songs['data']['items'],fn($x)=>$x['title']==='Secret Dawn'));
if(!$privateRows||!$privateRows[0]['playable']||$privateRows[0]['accessMode']!=='full')throw new RuntimeException('Private playable access was not preserved.');

$artists=dt_desktop_data_payload($pdo,$listener,'artists','glass');
if(count($artists['data']['items'])!==1||!$artists['data']['items'][0]['followed'])throw new RuntimeException('Artist relationship projection failed.');
if(count(dt_desktop_data_payload($pdo,$listener,'crates','night')['data']['items'])!==1)throw new RuntimeException('Crate projection failed.');
if(count(dt_desktop_data_payload($pdo,$other,'crates','')['data']['items'])!==0)throw new RuntimeException('Private crate leaked.');
$playlists=dt_desktop_data_payload($pdo,$listener,'playlists','');
if(count($playlists['data']['items'])!==1||$playlists['data']['items'][0]['playableTrackIds']!==[(int)$ownedSong['id']])throw new RuntimeException('Playlist projection failed.');

$home=dt_desktop_data_payload($pdo,$listener,'home','');
if(($home['schemaVersion']??'')!=='desktop-data-v210')throw new RuntimeException('Desktop schema version is missing.');
$discover=array_values(array_filter($home['data']['sections'],fn($x)=>$x['id']==='discover'))[0]['items']??[];
if(in_array('Open Window',array_column($discover,'title'),true))throw new RuntimeException('Saved music was duplicated into Discover.');

echo "MUSIC_DESKTOP_V1_SECTION2_MYSQL=PASS\n";

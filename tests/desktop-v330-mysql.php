<?php
declare(strict_types=1);
if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=desktop-collection-ci-key');
$_SERVER['REMOTE_ADDR']='127.0.0.14';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
 'experience_events_v280','experience_flow_edges_v280','experience_flow_nodes_v280','experience_layers_v280','experience_scenes_v280','experience_versions_v280','experiences_v280',
 'featured_post_events_v270','featured_posts_v270','desktop_object_events_v220','desktop_objects_v220',
 'playback_events_v130','playback_listens_v130','playback_queue_items_v130','playback_sessions_v130','recording_media_v130',
 'music_playlist_recordings_v120','music_playlists_v120','music_crate_items_v120','music_crates_v120',
 'user_artist_follows_v120','user_saved_recordings_v120','user_saved_releases_v120',
 'music_entitlement_events_v120','music_entitlements_v120','music_catalog_events_v110',
 'music_release_tracks_v110','music_release_editions_v110','music_releases_v110','music_recordings_v110',
 'artist_authority_events','artist_memberships','artists','auth_login_attempts','user_profiles','users'
] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

dt_foundation_ensure_schema($pdo);
dt_catalog_ensure_schema($pdo);
dt_library_ensure_schema($pdo);
dt_playback_ensure_schema($pdo);
dt_desktop_object_ensure_schema($pdo);
dt_featured_ensure_schema($pdo);
dt_experience_ensure_schema($pdo);

$user=dt_auth_register($pdo,'desktop-user@example.com','desktop user pass 123','Desktop User');
$artistOwner=dt_auth_register($pdo,'desktop-artist@example.com','desktop artist pass 123','Artist Owner');
$artist=dt_artist_create($pdo,$artistOwner,'Desktop Artist');
$recording=dt_catalog_create_recording($pdo,(int)$artist['id'],$artistOwner,['title'=>'Desktop Track']);
$release=dt_catalog_create_release($pdo,(int)$artist['id'],$artistOwner,['title'=>'Desktop Album','release_type'=>'album']);
dt_catalog_add_recording_to_release($pdo,(int)$artist['id'],(int)$release['id'],(int)$recording['id'],$artistOwner,1,1);
dt_catalog_publish_release($pdo,(int)$artist['id'],(int)$release['id'],$artistOwner);

$exp=dt_experience_create($pdo,$artistOwner,'release',(int)$release['id'],'Album Experience');
$vid=(int)$exp['version']['id'];
dt_experience_scene_add($pdo,$vid,$artistOwner,['scene_key'=>'intro','title'=>'Intro','sort_order'=>10,'weight'=>1,'is_enabled'=>true]);
dt_experience_publish($pdo,$vid,$artistOwner);

$aexp=dt_experience_create($pdo,$artistOwner,'artist',(int)$artist['id'],'Artist Experience');
$avid=(int)$aexp['version']['id'];
dt_experience_scene_add($pdo,$avid,$artistOwner,['scene_key'=>'intro','title'=>'Intro','sort_order'=>10,'weight'=>1,'is_enabled'=>true]);
dt_experience_publish($pdo,$avid,$artistOwner);

$pdo->prepare('INSERT INTO user_saved_releases_v120 (user_id,release_id) VALUES (?,?)')->execute([(int)$user['id'],(int)$release['id']]);
$pdo->prepare('INSERT INTO user_artist_follows_v120 (user_id,artist_id) VALUES (?,?)')->execute([(int)$user['id'],(int)$artist['id']]);

$albums=dt_desktop_data_albums($pdo,(int)$user['id'],$user,'',false);
$album=array_values(array_filter($albums,static fn(array $i): bool => (int)$i['id']===(int)$release['id']))[0]??null;
if(!$album)throw new RuntimeException('Album missing from user Desktop data.');
if(empty($album['experienceAvailable']))throw new RuntimeException('Published album experience not surfaced on Desktop.');
if(($album['profileUrl']??'')!=='/album.php?release='.(int)$release['id'])throw new RuntimeException('Album profile URL is invalid.');
if(($album['experienceUrl']??'')!=='/album-experience.php?release='.(int)$release['id'])throw new RuntimeException('Album experience URL is invalid.');

$artists=dt_desktop_data_artists($pdo,(int)$user['id'],$user);
$artistItem=array_values(array_filter($artists,static fn(array $i): bool => (int)$i['id']===(int)$artist['id']))[0]??null;
if(!$artistItem)throw new RuntimeException('Artist missing from user Desktop data.');
if(empty($artistItem['experienceAvailable']))throw new RuntimeException('Published artist experience not surfaced on Desktop.');
if(($artistItem['profileUrl']??'')!=='/artist.php?artist=desktop-artist')throw new RuntimeException('Artist profile URL is invalid.');
if(($artistItem['experienceUrl']??'')!=='/artist-experience.php?artist='.(int)$artist['id'])throw new RuntimeException('Artist experience URL is invalid.');

$hydratedRelease=dt_desktop_media_release($pdo,$user,(int)$release['id']);
if(!$hydratedRelease||empty($hydratedRelease['experienceAvailable']))throw new RuntimeException('Album sleeve hydration lost experience metadata.');
$hydratedArtist=dt_desktop_media_artist($pdo,$user,(int)$artist['id']);
if(!$hydratedArtist||empty($hydratedArtist['experienceAvailable']))throw new RuntimeException('Artist shortcut hydration failed.');

$albumObject=dt_desktop_object_create($pdo,(int)$user['id'],[
 'key'=>'release:'.(int)$release['id'],'type'=>'album-sleeve','resource_type'=>'release','resource_id'=>(int)$release['id'],
 'label'=>'Album','x'=>.2,'y'=>.3,'rotation'=>0,'scale'=>1,'payload'=>['schema'=>'album-sleeve-v1']
]);
$artistObject=dt_desktop_object_create($pdo,(int)$user['id'],[
 'key'=>'artist:'.(int)$artist['id'],'type'=>'artist-shortcut','resource_type'=>'artist','resource_id'=>(int)$artist['id'],
 'label'=>'Artist','x'=>.5,'y'=>.4,'rotation'=>0,'scale'=>1,'payload'=>['schema'=>'artist-shortcut-v1']
]);
if(($albumObject['type']??'')!=='album-sleeve'||($artistObject['type']??'')!=='artist-shortcut')throw new RuntimeException('Desktop collection object types did not persist.');
if(count(dt_desktop_objects($pdo,(int)$user['id']))!==2)throw new RuntimeException('Desktop collection objects did not persist.');

$again=dt_desktop_object_create($pdo,(int)$user['id'],[
 'key'=>'artist:'.(int)$artist['id'],'type'=>'artist-shortcut','resource_type'=>'artist','resource_id'=>(int)$artist['id'],
 'label'=>'Artist','x'=>.7,'y'=>.7
]);
if((int)$again['id']!==(int)$artistObject['id'])throw new RuntimeException('Artist shortcut placement must be idempotent.');

$pdo->prepare("UPDATE music_releases_v110 SET release_status='withdrawn' WHERE id=?")->execute([(int)$release['id']]);
$albumAfter=dt_desktop_data_release_dto($pdo,(int)$user['id'],$user,dt_catalog_release($pdo,(int)$artist['id'],(int)$release['id']),'saved',true,false);
if(!empty($albumAfter['experienceAvailable']))throw new RuntimeException('Withdrawn album must not expose an experience launch.');

echo "MUSIC_DESKTOP_V1_SECTION14_MYSQL=PASS\n";

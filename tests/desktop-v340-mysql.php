<?php
declare(strict_types=1);
if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=experience-delivery-ci-key');
$_SERVER['REMOTE_ADDR']='127.0.0.15';

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

$owner=dt_auth_register($pdo,'delivery-owner@example.com','delivery owner pass 123','Delivery Owner');
$artist=dt_artist_create($pdo,$owner,'Delivery Artist');
$recording=dt_catalog_create_recording($pdo,(int)$artist['id'],$owner,['title'=>'Delivery Track']);
$release=dt_catalog_create_release($pdo,(int)$artist['id'],$owner,['title'=>'Delivery Album','release_type'=>'album']);
dt_catalog_add_recording_to_release($pdo,(int)$artist['id'],(int)$release['id'],(int)$recording['id'],$owner,1,1);
dt_catalog_publish_release($pdo,(int)$artist['id'],(int)$release['id'],$owner);

$created=dt_experience_create($pdo,$owner,'release',(int)$release['id'],'Delivery Experience');
$v1=(int)$created['version']['id'];
dt_experience_scene_add($pdo,$v1,$owner,[
 'scene_key'=>'intro','title'=>'Version One','sort_order'=>10,'weight'=>1,'is_enabled'=>true,
 'settings'=>['title'=>'Version One']
]);
$p1=dt_experience_publish($pdo,$v1,$owner);

$draft2=dt_experience_clone_draft($pdo,(int)$created['experience']['id'],$owner);
$v2=(int)$draft2['id'];
dt_experience_scene_update($pdo,$v2,$owner,'intro',[
 'title'=>'Version Two','settings'=>['title'=>'Version Two']
]);
$p2=dt_experience_publish($pdo,$v2,$owner);

if(strlen((string)$p1['sha256'])!==64||strlen((string)$p2['sha256'])!==64)throw new RuntimeException('Published hashes are invalid.');
if((string)$p1['sha256']===(string)$p2['sha256'])throw new RuntimeException('Published versions must have distinct hashes.');

$active=dt_experience_active($pdo,'release',(int)$release['id'],'default');
if(!$active||(int)$active['versionNumber']!==2||(string)$active['sha256']!==(string)$p2['sha256'])throw new RuntimeException('Active version did not advance.');

$old=dt_experience_published($pdo,'release',(int)$release['id'],'default',1);
if(!$old||(int)$old['versionNumber']!==1||(string)$old['sha256']!==(string)$p1['sha256'])throw new RuntimeException('Historical published version did not resolve.');
if(($old['manifest']['scenes'][0]['title']??'')!=='Version One')throw new RuntimeException('Historical published manifest was mutated.');

$current=dt_experience_published($pdo,'release',(int)$release['id'],'default',2);
if(!$current||(string)$current['sha256']!==(string)$p2['sha256'])throw new RuntimeException('Current published version lookup failed.');
if(dt_experience_published($pdo,'release',(int)$release['id'],'default',99)!==null)throw new RuntimeException('Unknown version unexpectedly resolved.');

$url1=dt_experience_delivery_url('release',(int)$release['id'],'default',1,(string)$p1['sha256']);
$url2=dt_experience_delivery_url('release',(int)$release['id'],'default',2,(string)$p2['sha256']);
if($url1===$url2||!str_contains($url1,'version=1')||!str_contains($url2,'version=2'))throw new RuntimeException('Versioned delivery URLs are not unique.');
if(!str_contains($url1,'hash='.(string)$p1['sha256'])||!str_contains($url2,'hash='.(string)$p2['sha256']))throw new RuntimeException('Delivery URLs are not hash-pinned.');

$invalid=false;
try{dt_experience_delivery_url('release',(int)$release['id'],'default',1,str_repeat('x',64));}catch(RuntimeException $e){$invalid=true;}
if(!$invalid)throw new RuntimeException('Invalid delivery hash was accepted.');

if(!dt_experience_public_allowed($pdo,'release',(int)$release['id'],null))throw new RuntimeException('Published release must allow public delivery.');
$pdo->prepare("UPDATE music_releases_v110 SET release_status='withdrawn' WHERE id=?")->execute([(int)$release['id']]);
if(dt_experience_public_allowed($pdo,'release',(int)$release['id'],null))throw new RuntimeException('Withdrawn release must block public delivery.');

echo "MUSIC_DESKTOP_V1_SECTION15_MYSQL=PASS\n";

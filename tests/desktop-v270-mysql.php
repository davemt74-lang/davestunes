<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=featured-ci-key');
putenv('DAVESTUNES_ADMIN_EMAILS=admin@example.com');
$_SERVER['REMOTE_ADDR']='127.0.0.8';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
 'featured_post_events_v270','featured_posts_v270',
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
dt_featured_ensure_schema($pdo);

$admin=dt_auth_register($pdo,'admin@example.com','admin password 123','Admin');
$user=dt_auth_register($pdo,'listener@example.com','listener pass 123','Listener');
$owner=dt_auth_register($pdo,'artist@example.com','artist password 123','Artist');
if(!dt_is_admin($admin)||dt_is_admin($user))throw new RuntimeException('Admin email authorization failed.');

$artist=dt_artist_create($pdo,$owner,'Featured Artist');
$artistId=(int)$artist['id'];
$song=dt_catalog_create_recording($pdo,$artistId,$owner,['title'=>'Featured Song','isrc'=>'USDTS2612345','duration_ms'=>180000]);
$release=dt_catalog_create_release($pdo,$artistId,$owner,['title'=>'Featured Album','release_type'=>'album']);
dt_catalog_add_recording_to_release($pdo,$artistId,(int)$release['id'],(int)$song['id'],$owner,1,1);
dt_catalog_publish_release($pdo,$artistId,(int)$release['id'],$owner);

$object=dt_desktop_object_create($pdo,(int)$user['id'],[
 'key'=>'listener:keepsake','type'=>'note','label'=>'My saved object','x'=>0.21,'y'=>0.37,'payload'=>['mine'=>true]
]);
$before=dt_desktop_objects($pdo,(int)$user['id']);

$postId=dt_featured_save($pdo,$admin,[
 'content_type'=>'release','content_id'=>(int)$release['id'],'headline'=>'Staff Pick','body_text'=>'A featured album',
 'post_status'=>'active','priority'=>10
]);
$items=dt_featured_desktop($pdo,$user,10);
if(count($items)!==1||$items[0]['id']!==$postId||$items[0]['content']['title']!=='Featured Album')throw new RuntimeException('Active featured album projection failed.');

$after=dt_desktop_objects($pdo,(int)$user['id']);
if(count($before)!==1||count($after)!==1||$after[0]['id']!==$object['id']||abs($after[0]['transform']['x']-0.21)>0.001)throw new RuntimeException('Featured projection mutated the listener Desktop.');

$future=dt_featured_save($pdo,$admin,[
 'content_type'=>'recording','content_id'=>(int)$song['id'],'headline'=>'Later','post_status'=>'active','priority'=>20,
 'starts_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s')
]);
if(count(dt_featured_desktop($pdo,$user,10))!==1)throw new RuntimeException('Future featured post leaked early.');

dt_featured_save($pdo,$admin,[
 'content_type'=>'recording','content_id'=>(int)$song['id'],'headline'=>'Song Now','post_status'=>'active','priority'=>30,
 'starts_at'=>(new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
 'ends_at'=>(new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s')
],$future);
$active=dt_featured_desktop($pdo,$user,10);
if(count($active)!==2||$active[0]['type']!=='recording')throw new RuntimeException('Featured priority or song projection failed.');

$expiredId=dt_featured_save($pdo,$admin,[
 'content_type'=>'release','content_id'=>(int)$release['id'],'headline'=>'Expired','post_status'=>'expired','priority'=>99
]);
if(count(dt_featured_desktop($pdo,$user,10))!==2)throw new RuntimeException('Expired featured post leaked onto Desktop.');

$unauthorized=false;
try{dt_featured_save($pdo,$user,['content_type'=>'release','content_id'=>(int)$release['id'],'post_status'=>'active']);}
catch(RuntimeException $e){$unauthorized=true;}
if(!$unauthorized)throw new RuntimeException('Non-admin user created featured content.');

dt_featured_delete($pdo,$admin,$expiredId);
$rows=dt_featured_admin_rows($pdo);
if(count($rows)!==2)throw new RuntimeException('Featured admin rows or delete failed.');

echo "MUSIC_DESKTOP_V1_SECTION8_MYSQL=PASS\n";

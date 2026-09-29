<?php
declare(strict_types=1);
if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=soft-launch-rc2-ci-key');
$_SERVER['REMOTE_ADDR']='127.0.0.22';

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

$user=dt_auth_register($pdo,'starter@example.com','starter password 123','Starter User');
if(dt_desktop_personalization_state($pdo,(int)$user['id'])!=='starter')throw new RuntimeException('Brand-new user must start in starter state.');

$artistOwner=dt_auth_register($pdo,'artist@example.com','artist password 123','Artist Owner');
$artist=dt_artist_create($pdo,$artistOwner,'Starter Artist');
$recording=dt_catalog_create_recording($pdo,(int)$artist['id'],$artistOwner,['title'=>'Starter Song']);
$release=dt_catalog_create_release($pdo,(int)$artist['id'],$artistOwner,['title'=>'Starter Album','release_type'=>'album']);
dt_catalog_add_recording_to_release($pdo,(int)$artist['id'],(int)$release['id'],(int)$recording['id'],$artistOwner,1,1);
dt_catalog_publish_release($pdo,(int)$artist['id'],(int)$release['id'],$artistOwner);

$publicAlbums=dt_featured_public_albums($pdo,8);
if(!$publicAlbums||(int)$publicAlbums[0]['id']!==(int)$release['id'])throw new RuntimeException('Public Desktop must fall back to published albums.');
$news=dt_featured_public_news($pdo,5);
if(!$news)throw new RuntimeException('Public Desktop must have fallback news/notes.');

$pdo->prepare('INSERT INTO user_saved_releases_v120 (user_id,release_id) VALUES (?,?)')->execute([(int)$user['id'],(int)$release['id']]);
if(dt_desktop_personalization_state($pdo,(int)$user['id'])!=='personalized')throw new RuntimeException('Saving an album must move the Desktop to personalized state.');

$second=dt_auth_register($pdo,'starter-two@example.com','starter two password 123','Starter Two');
if(dt_desktop_personalization_state($pdo,(int)$second['id'])!=='starter')throw new RuntimeException('Second new user must start in starter state.');
$pdo->prepare('INSERT INTO user_artist_follows_v120 (user_id,artist_id) VALUES (?,?)')->execute([(int)$second['id'],(int)$artist['id']]);
if(dt_desktop_personalization_state($pdo,(int)$second['id'])!=='personalized')throw new RuntimeException('Following an artist must personalize the Desktop.');

echo "SOFT_LAUNCH_RC2_MYSQL=PASS\n";

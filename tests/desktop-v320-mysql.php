<?php
declare(strict_types=1);
if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=artist-desktop-ci-key');
$_SERVER['REMOTE_ADDR']='127.0.0.13';

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

$owner=dt_auth_register($pdo,'artist-owner@example.com','artist owner pass 123','Artist Owner');
$artist=dt_artist_create($pdo,$owner,'Public Artist');
$pdo->prepare("UPDATE artists SET bio=?,location=?,website_url=?,instagram_url=?,profile_image_path=? WHERE id=?")
    ->execute(['Public bio','Phoenix','https://example.com','javascript:alert(1)','/uploads/artist.jpg',(int)$artist['id']]);
$recording=dt_catalog_create_recording($pdo,(int)$artist['id'],$owner,['title'=>'Artist Song']);
$release=dt_catalog_create_release($pdo,(int)$artist['id'],$owner,['title'=>'Artist Album','release_type'=>'album']);
dt_catalog_add_recording_to_release($pdo,(int)$artist['id'],(int)$release['id'],(int)$recording['id'],$owner,1,1);
dt_catalog_publish_release($pdo,(int)$artist['id'],(int)$release['id'],$owner);

$created=dt_experience_create($pdo,$owner,'artist',(int)$artist['id'],'Public Artist Experience');
$versionId=(int)$created['version']['id'];
dt_experience_scene_add($pdo,$versionId,$owner,['scene_key'=>'intro','title'=>'Intro','sort_order'=>10,'weight'=>1,'is_enabled'=>true,'settings'=>['title'=>'Public Artist']]);
$published=dt_experience_publish($pdo,$versionId,$owner);
if(strlen((string)$published['sha256'])!==64)throw new RuntimeException('Artist experience publish hash is invalid.');

$ctx=dt_experience_owner_context($pdo,'artist',(int)$artist['id']);
if(($ctx['title']??'')!=='Public Artist · Experience Studio')throw new RuntimeException('Artist Studio context title is invalid.');
if(($ctx['back_url']??'')!=='/artist.php?artist=public-artist')throw new RuntimeException('Artist Studio back link is invalid.');

if(!dt_experience_public_allowed($pdo,'artist',(int)$artist['id'],null))throw new RuntimeException('Active artist experience should be public.');
$active=dt_experience_active($pdo,'artist',(int)$artist['id'],'default');
if(!$active||($active['sha256']??'')!==$published['sha256'])throw new RuntimeException('Artist active experience did not resolve.');

if(dt_artist_public_url('javascript:alert(1)')!=='')throw new RuntimeException('Unsafe artist URL was not blocked.');
if(dt_artist_public_url('https://example.com')!=='https://example.com')throw new RuntimeException('Safe artist URL was rejected.');
if(dt_artist_public_image('../secret.jpg')!=='')throw new RuntimeException('Unsafe artist image path was not blocked.');
if(dt_artist_public_image('/uploads/artist.jpg')!=='/uploads/artist.jpg')throw new RuntimeException('Safe artist image path was rejected.');

$pdo->prepare("UPDATE artists SET artist_status='archived' WHERE id=?")->execute([(int)$artist['id']]);
if(dt_experience_public_allowed($pdo,'artist',(int)$artist['id'],null))throw new RuntimeException('Archived artist experience must not remain public.');

$other=dt_auth_register($pdo,'artist-other@example.com','artist other pass 123','Other User');
$blocked=false;
try{dt_experience_require_owner($pdo,'artist',(int)$artist['id'],$other);}catch(RuntimeException $e){$blocked=true;}
if(!$blocked)throw new RuntimeException('Unauthorized user could author another artist experience.');

echo "MUSIC_DESKTOP_V1_SECTION13_MYSQL=PASS\n";

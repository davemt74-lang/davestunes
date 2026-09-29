<?php
declare(strict_types=1);
if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=soft-launch-news-ci-key');
putenv('DAVESTUNES_ADMIN_EMAILS=admin@example.com');
$_SERVER['REMOTE_ADDR']='127.0.0.31';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
 'news_post_events_v110','news_posts_v110',
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

if(!dt_news_schema_ready($pdo))throw new RuntimeException('News schema was not installed.');

$admin=dt_auth_register($pdo,'admin@example.com','admin password 123','Admin');
$user=dt_auth_register($pdo,'listener@example.com','listener password 123','Listener');
if(!dt_is_admin($admin))throw new RuntimeException('Configured admin was not recognized.');

$id=dt_news_save($pdo,$admin,[
 'headline'=>'Soft launch notes',
 'body_text'=>str_repeat('Editorial update. ',40),
 'image_url'=>'/uploads/news/launch.jpg',
 'link_url'=>'/album.php?release=1',
 'link_label'=>'Explore',
 'placement'=>'public-desktop',
 'post_status'=>'active',
 'priority'=>25,
]);
if($id<1)throw new RuntimeException('News post was not created.');

$rows=dt_news_active($pdo,'public-desktop',5);
if(count($rows)!==1)throw new RuntimeException('Active public news post did not project.');
if((int)$rows[0]['id']!==$id)throw new RuntimeException('Wrong public news post projected.');
if(mb_strlen((string)$rows[0]['body_text'])>420)throw new RuntimeException('Desktop news excerpt exceeded bound.');
if(dt_news_active($pdo,'signed-in-desktop',5)!==[])throw new RuntimeException('Public-only post leaked to signed-in-only projection.');

$second=dt_news_save($pdo,$admin,[
 'headline'=>'Soft launch notes',
 'body_text'=>'Signed-in listener update.',
 'image_url'=>'https://example.com/news.jpg',
 'link_url'=>'https://example.com/update',
 'link_label'=>'Read update',
 'placement'=>'signed-in-desktop',
 'post_status'=>'active',
 'priority'=>30,
]);
$adminRows=dt_news_admin_rows($pdo);
$slugs=array_column($adminRows,'slug');
if(count(array_unique($slugs))!==2)throw new RuntimeException('News slugs must be unique.');

$signed=dt_news_active($pdo,'signed-in-desktop',5);
if(count($signed)!==1||(int)$signed[0]['id']!==$second)throw new RuntimeException('Signed-in News placement did not project correctly.');

dt_news_save($pdo,$admin,[
 'headline'=>'Scheduled later',
 'body_text'=>'Not visible yet.',
 'placement'=>'both',
 'post_status'=>'active',
 'starts_at'=>date('Y-m-d H:i:s',time()+3600),
 'priority'=>100,
]);
if(count(dt_news_active($pdo,'public-desktop',10))!==1)throw new RuntimeException('Future news post became visible early.');

$unsafe=false;
try{dt_news_save($pdo,$admin,[
 'headline'=>'Unsafe',
 'body_text'=>'Unsafe URL test.',
 'image_url'=>'javascript:alert(1)',
 'post_status'=>'draft',
]);}catch(RuntimeException $e){$unsafe=true;}
if(!$unsafe)throw new RuntimeException('Unsafe news image URL was accepted.');

$nonAdmin=false;
try{dt_news_save($pdo,$user,['headline'=>'No','body_text'=>'No','post_status'=>'draft']);}catch(RuntimeException $e){$nonAdmin=true;}
if(!$nonAdmin)throw new RuntimeException('Non-admin user could create news.');

$events=(int)$pdo->query("SELECT COUNT(*) FROM news_post_events_v110 WHERE event_type IN ('news.created','news.updated')")->fetchColumn();
if($events<3)throw new RuntimeException('News audit events were not recorded.');

dt_news_delete($pdo,$admin,$second);
if((int)$pdo->query('SELECT COUNT(*) FROM news_posts_v110 WHERE id='.(int)$second)->fetchColumn()!==0)throw new RuntimeException('News post was not deleted.');
if((int)$pdo->query("SELECT COUNT(*) FROM news_post_events_v110 WHERE event_type='news.deleted'")->fetchColumn()<1)throw new RuntimeException('News delete event was not recorded.');

echo "SOFT_LAUNCH_V110_NEWS_MYSQL=PASS\n";

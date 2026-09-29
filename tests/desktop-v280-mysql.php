<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=experience-ci-key');
$_SERVER['REMOTE_ADDR']='127.0.0.9';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
 'experience_events_v280','experience_flow_edges_v280','experience_flow_nodes_v280','experience_layers_v280','experience_scenes_v280','experience_versions_v280','experiences_v280',
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
dt_experience_ensure_schema($pdo);
dt_experience_ensure_schema($pdo);
if(!dt_experience_schema_ready($pdo))throw new RuntimeException('Experience schema is not ready.');

$owner=dt_auth_register($pdo,'experience-owner@example.com','experience owner pass 123','Experience Owner');
$other=dt_auth_register($pdo,'experience-other@example.com','experience other pass 123','Other');
$artist=dt_artist_create($pdo,$owner,'Scene Graph Artist');
$artistId=(int)$artist['id'];
$song=dt_catalog_create_recording($pdo,$artistId,$owner,['title'=>'Graph Song','isrc'=>'USDTS2699991','duration_ms'=>180000]);
$release=dt_catalog_create_release($pdo,$artistId,$owner,['title'=>'Graph Album','release_type'=>'album']);
dt_catalog_add_recording_to_release($pdo,$artistId,(int)$release['id'],(int)$song['id'],$owner,1,1);
dt_catalog_publish_release($pdo,$artistId,(int)$release['id'],$owner);

$created=dt_experience_create($pdo,$owner,'release',(int)$release['id'],'Graph Album Experience');
$experienceId=(int)$created['experience']['id'];
$v1=(int)$created['version']['id'];

dt_experience_scene_add($pdo,$v1,$owner,[
 'scene_key'=>'intro','title'=>'Intro','sort_order'=>10,'weight'=>2,'is_enabled'=>true,
 'settings'=>['eyebrow'=>'Scene One','title'=>'Enter the album']
]);
dt_experience_scene_add($pdo,$v1,$owner,[
 'scene_key'=>'finale','title'=>'Finale','sort_order'=>20,'weight'=>1,'is_enabled'=>true,
 'settings'=>['title'=>'Final scene']
]);
dt_experience_layer_add($pdo,$v1,$owner,[
 'scene_key'=>'intro','layer_key'=>'headline','layer_type'=>'heading','sort_order'=>1,
 'settings'=>['text'=>'Graph Album']
]);
dt_experience_layer_add($pdo,$v1,$owner,[
 'scene_key'=>'intro','layer_key'=>'copy','layer_type'=>'text','sort_order'=>2,
 'settings'=>['text'=>'A canonical authored scene.']
]);
dt_experience_node_add($pdo,$v1,$owner,[
 'node_key'=>'start','node_type'=>'scene-enter','scene_key'=>'intro','x'=>20,'y'=>30,
 'settings'=>['trigger'=>'scene-start']
]);
dt_experience_node_add($pdo,$v1,$owner,[
 'node_key'=>'next','node_type'=>'scene-goto','scene_key'=>'finale','x'=>220,'y'=>30,
 'settings'=>['target'=>'finale']
]);
dt_experience_edge_add($pdo,$v1,$owner,[
 'edge_key'=>'start-to-next','from_node_key'=>'start','to_node_key'=>'next','condition'=>['after'=>'scroll']
]);

$draft=dt_experience_manifest($pdo,$v1);
if(count($draft['scenes'])!==2||count($draft['scenes'][0]['layers'])!==2)throw new RuntimeException('Draft scene/layer assembly failed.');
if(count($draft['flow']['nodes'])!==2||count($draft['flow']['edges'])!==1)throw new RuntimeException('Draft flow graph assembly failed.');

$published=dt_experience_publish($pdo,$v1,$owner);
if(strlen((string)$published['sha256'])!==64)throw new RuntimeException('Published manifest hash is invalid.');
$active=dt_experience_active($pdo,'release',(int)$release['id']);
if(!$active||$active['sha256']!==$published['sha256']||$active['manifest']['scenes'][0]['key']!=='intro')throw new RuntimeException('Active published experience resolution failed.');
if(!dt_experience_public_allowed($pdo,'release',(int)$release['id'],null))throw new RuntimeException('Published release experience should be public.');

$locked=false;
try{dt_experience_scene_add($pdo,$v1,$owner,['scene_key'=>'late','title'=>'Late','is_enabled'=>true]);}
catch(RuntimeException $e){$locked=str_contains($e->getMessage(),'not editable');}
if(!$locked)throw new RuntimeException('Published version accepted a scene mutation.');

$v2row=dt_experience_clone_draft($pdo,$experienceId,$owner);
$v2=(int)$v2row['id'];
if((int)$v2row['version_number']!==2||(string)$v2row['version_status']!=='draft')throw new RuntimeException('Draft version clone failed.');
$clone=dt_experience_manifest($pdo,$v2);
if($clone['scenes']!=$published['manifest']['scenes']||$clone['flow']!=$published['manifest']['flow'])throw new RuntimeException('Draft clone did not preserve scene graph.');

dt_experience_scene_update($pdo,$v2,$owner,'intro',['title'=>'Intro Revised','weight'=>3]);
dt_experience_layer_update($pdo,$v2,$owner,'intro','copy',['settings'=>['text'=>'Draft-only revision.']]);
dt_experience_scene_add($pdo,$v2,$owner,['scene_key'=>'encore','title'=>'Encore','sort_order'=>30,'weight'=>1,'is_enabled'=>true]);
dt_experience_layer_add($pdo,$v2,$owner,['scene_key'=>'encore','layer_key'=>'temp','layer_type'=>'text','settings'=>['text'=>'temporary']]);
dt_experience_layer_delete($pdo,$v2,$owner,'encore','temp');
dt_experience_node_add($pdo,$v2,$owner,['node_key'=>'temp-a','node_type'=>'trigger','scene_key'=>'encore']);
dt_experience_node_add($pdo,$v2,$owner,['node_key'=>'temp-b','node_type'=>'action','scene_key'=>'encore']);
dt_experience_edge_add($pdo,$v2,$owner,['edge_key'=>'temp-edge','from_node_key'=>'temp-a','to_node_key'=>'temp-b']);
$guarded=false;
try{dt_experience_node_delete($pdo,$v2,$owner,'temp-a');}catch(RuntimeException $e){$guarded=true;}
if(!$guarded)throw new RuntimeException('Connected flow node was deleted without removing its edge.');
dt_experience_edge_update($pdo,$v2,$owner,'temp-edge',['condition'=>['when'=>'always']]);
dt_experience_edge_delete($pdo,$v2,$owner,'temp-edge');
dt_experience_node_delete($pdo,$v2,$owner,'temp-a');
dt_experience_node_delete($pdo,$v2,$owner,'temp-b');
dt_experience_scene_delete($pdo,$v2,$owner,'encore');
$draftAfterMutations=dt_experience_manifest($pdo,$v2);
if($draftAfterMutations['scenes'][0]['title']!=='Intro Revised'||($draftAfterMutations['scenes'][0]['layers'][1]['settings']['text']??'')!=='Draft-only revision.')throw new RuntimeException('Draft update mutations were not persisted.');
$activeStill=dt_experience_active($pdo,'release',(int)$release['id']);
if($activeStill['sha256']!==$published['sha256']||count($activeStill['manifest']['scenes'])!==2)throw new RuntimeException('Draft edits changed the active published manifest.');

$unauthorized=false;
try{dt_experience_create($pdo,$other,'release',(int)$release['id'],'Unauthorized','other');}
catch(RuntimeException $e){$unauthorized=true;}
if(!$unauthorized)throw new RuntimeException('Unauthorized user created an artist release experience.');

$userExperience=dt_experience_create($pdo,$other,'user',(int)$other['id'],'Private Desktop Experience');
$userVersion=(int)$userExperience['version']['id'];
dt_experience_scene_add($pdo,$userVersion,$other,['scene_key'=>'private','title'=>'Private','is_enabled'=>true]);
dt_experience_publish($pdo,$userVersion,$other);
if(dt_experience_public_allowed($pdo,'user',(int)$other['id'],$owner))throw new RuntimeException('Another user gained access to a private user experience.');
if(!dt_experience_public_allowed($pdo,'user',(int)$other['id'],$other))throw new RuntimeException('User could not access their own experience.');

$eventCount=(int)$pdo->query('SELECT COUNT(*) FROM experience_events_v280 WHERE experience_id='.(int)$experienceId)->fetchColumn();
if($eventCount<14)throw new RuntimeException('Experience audit trail is incomplete.');

echo "MUSIC_DESKTOP_V1_SECTION9_MYSQL=PASS\n";

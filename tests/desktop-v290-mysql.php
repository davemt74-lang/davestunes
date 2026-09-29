<?php
declare(strict_types=1);
if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=studio-ci-key');
$_SERVER['REMOTE_ADDR']='127.0.0.10';

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

$user=dt_auth_register($pdo,'studio@example.com','studio password 123','Studio User');
$created=dt_experience_create($pdo,$user,'user',(int)$user['id'],'Studio Experience');
$experienceId=(int)$created['experience']['id'];
$v1=(int)$created['version']['id'];

dt_experience_scene_add($pdo,$v1,$user,['scene_key'=>'one','title'=>'Scene One','sort_order'=>10,'weight'=>1,'is_enabled'=>true,'settings'=>['title'=>'One']]);
dt_experience_scene_add($pdo,$v1,$user,['scene_key'=>'two','title'=>'Scene Two','sort_order'=>20,'weight'=>1,'is_enabled'=>true,'settings'=>['title'=>'Two']]);
dt_experience_node_add($pdo,$v1,$user,['node_key'=>'a','node_type'=>'trigger','scene_key'=>'one','x'=>10,'y'=>20]);
dt_experience_node_add($pdo,$v1,$user,['node_key'=>'b','node_type'=>'action','scene_key'=>'two','x'=>210,'y'=>20]);
dt_experience_edge_add($pdo,$v1,$user,['edge_key'=>'a-b','from_node_key'=>'a','to_node_key'=>'b']);

$snapshot=dt_experience_editor_snapshot($pdo,$experienceId,$user);
if((int)$snapshot['draft']['id']!==$v1)throw new RuntimeException('Studio did not resolve the existing draft.');
if(array_column($snapshot['draft']['manifest']['scenes'],'key')!==['one','two'])throw new RuntimeException('Scene navigator order is invalid.');
if(count($snapshot['draft']['manifest']['flow']['nodes'])!==2||count($snapshot['draft']['manifest']['flow']['edges'])!==1)throw new RuntimeException('Flow Builder graph was not returned.');

dt_experience_scene_update($pdo,$v1,$user,'two',['sort_order'=>5,'title'=>'Scene Two First']);
$reordered=dt_experience_editor_snapshot($pdo,$experienceId,$user);
if(array_column($reordered['draft']['manifest']['scenes'],'key')!==['two','one'])throw new RuntimeException('Scene reorder did not persist.');

dt_experience_node_update($pdo,$v1,$user,'a',['x'=>333,'y'=>144]);
$moved=dt_experience_editor_snapshot($pdo,$experienceId,$user);
$nodeA=array_values(array_filter($moved['draft']['manifest']['flow']['nodes'],static fn(array $n): bool => $n['key']==='a'))[0]??null;
if(!$nodeA||abs((float)$nodeA['x']-333)>0.01||abs((float)$nodeA['y']-144)>0.01)throw new RuntimeException('Flow node position did not persist.');

$published=dt_experience_publish($pdo,$v1,$user);
if(strlen((string)$published['sha256'])!==64)throw new RuntimeException('Studio publish did not freeze a manifest hash.');

$afterPublish=dt_experience_editor_snapshot($pdo,$experienceId,$user);
if((int)$afterPublish['draft']['number']!==2||(string)$afterPublish['draft']['status']!=='draft')throw new RuntimeException('Studio did not create a new draft from the published version.');
if((int)$afterPublish['experience']['activeVersionId']!==$v1)throw new RuntimeException('Studio active published pointer is incorrect.');

$other=dt_auth_register($pdo,'studio-other@example.com','studio other pass 123','Other');
$blocked=false;
try{dt_experience_editor_snapshot($pdo,$experienceId,$other);}catch(RuntimeException $e){$blocked=true;}
if(!$blocked)throw new RuntimeException('Unauthorized user opened another user Studio.');

echo "MUSIC_DESKTOP_V1_SECTION10_MYSQL=PASS\n";

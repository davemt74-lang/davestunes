<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=desktop-object-ci-key');
$_SERVER['REMOTE_ADDR']='127.0.0.6';

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
dt_desktop_object_ensure_schema($pdo);
if(!dt_desktop_object_schema_ready($pdo))throw new RuntimeException('Desktop object schema is not ready.');

$userA=dt_auth_register($pdo,'objects-a@example.com','objects account pass 123','Objects A');
$userB=dt_auth_register($pdo,'objects-b@example.com','objects account pass 456','Objects B');
$a=(int)$userA['id'];
$b=(int)$userB['id'];

$created=dt_desktop_object_create($pdo,$a,[
 'key'=>'release:42',
 'type'=>'album-card',
 'resource_type'=>'release',
 'resource_id'=>42,
 'label'=>'Blue Room',
 'x'=>0.25,
 'y'=>0.35,
 'rotation'=>-7,
 'scale'=>1.1,
 'z'=>4,
 'payload'=>['variant'=>'sleeve'],
]);
if($created['revision']!==1||$created['transform']['x']!==0.25||$created['payload']['variant']!=='sleeve')throw new RuntimeException('Desktop object create projection failed.');

$idempotent=dt_desktop_object_create($pdo,$a,[
 'key'=>'release:42','type'=>'album-card','resource_type'=>'release','resource_id'=>42
]);
if($idempotent['id']!==$created['id'])throw new RuntimeException('Desktop object create is not idempotent by key.');

try{
 dt_desktop_object_create($pdo,$a,['key'=>'release:42','type'=>'photo','resource_type'=>'release','resource_id'=>42]);
 throw new RuntimeException('Conflicting Desktop object key was accepted.');
}catch(RuntimeException $e){
 if($e->getMessage()==='Conflicting Desktop object key was accepted.')throw $e;
}

$other=dt_desktop_object_create($pdo,$b,[
 'key'=>'release:42','type'=>'album-card','resource_type'=>'release','resource_id'=>42,'label'=>'Other user card'
]);
if($other['id']===$created['id'])throw new RuntimeException('Desktop object key was incorrectly global.');
if(count(dt_desktop_objects($pdo,$a))!==1||count(dt_desktop_objects($pdo,$b))!==1)throw new RuntimeException('Desktop object listing leaked across users.');

$updated=dt_desktop_object_update($pdo,$a,$created['id'],1,[
 'x'=>0.8,'y'=>0.2,'rotation'=>15,'scale'=>1.4,'z'=>20,'payload'=>['variant'=>'stacked']
]);
if($updated['revision']!==2||$updated['transform']['x']!==0.8||$updated['transform']['z']!==20||$updated['payload']['variant']!=='stacked'){
 throw new RuntimeException('Desktop transform update failed.');
}

try{
 dt_desktop_object_update($pdo,$a,$created['id'],1,['x'=>0.4]);
 throw new RuntimeException('Stale Desktop object revision was accepted.');
}catch(RuntimeException $e){
 if($e->getMessage()==='Stale Desktop object revision was accepted.')throw $e;
}

try{
 dt_desktop_object_update($pdo,$b,$created['id'],2,['x'=>0.4]);
 throw new RuntimeException('Cross-user Desktop object update was accepted.');
}catch(RuntimeException $e){
 if($e->getMessage()==='Cross-user Desktop object update was accepted.')throw $e;
}

$pinned=dt_desktop_object_update($pdo,$a,$created['id'],2,['pinned'=>true]);
if(!$pinned['pinned']||$pinned['revision']!==3)throw new RuntimeException('Desktop pin state failed.');

try{
 dt_desktop_object_update($pdo,$a,$created['id'],3,['x'=>0.1]);
 throw new RuntimeException('Pinned Desktop object moved without unpinning.');
}catch(RuntimeException $e){
 if($e->getMessage()==='Pinned Desktop object moved without unpinning.')throw $e;
}

$unpinMove=dt_desktop_object_update($pdo,$a,$created['id'],3,['pinned'=>false,'x'=>0.1]);
if($unpinMove['pinned']||$unpinMove['transform']['x']!==0.1||$unpinMove['revision']!==4)throw new RuntimeException('Atomic unpin and move failed.');

foreach([
 ['x'=>1.1],
 ['y'=>-0.1],
 ['rotation'=>181],
 ['scale'=>0.1],
] as $bad){
 try{
  dt_desktop_object_update($pdo,$a,$created['id'],4,$bad);
  throw new RuntimeException('Out-of-range Desktop transform was accepted.');
 }catch(RuntimeException $e){
  if($e->getMessage()==='Out-of-range Desktop transform was accepted.')throw $e;
 }
}

dt_desktop_object_delete($pdo,$a,$created['id'],4);
if(dt_desktop_object_row($pdo,$a,$created['id'])!==null)throw new RuntimeException('Desktop object delete failed.');
$deleteEvents=(int)$pdo->query("SELECT COUNT(*) FROM desktop_object_events_v220 WHERE event_type='desktop.object.deleted'")->fetchColumn();
if($deleteEvents!==1)throw new RuntimeException('Desktop delete event did not survive row deletion.');

$one=dt_desktop_object_create($pdo,$a,['key'=>'note:1','type'=>'note','label'=>'One','x'=>0.2,'y'=>0.2]);
$two=dt_desktop_object_create($pdo,$a,['key'=>'note:2','type'=>'note','label'=>'Two','x'=>0.6,'y'=>0.6]);
if(dt_desktop_objects_reset($pdo,$a)!==2)throw new RuntimeException('Desktop reset count is incorrect.');
if(count(dt_desktop_objects($pdo,$a))!==0)throw new RuntimeException('Desktop reset did not clear the user layout.');
if(count(dt_desktop_objects($pdo,$b))!==1)throw new RuntimeException('Desktop reset affected another user.');
$resetEvents=(int)$pdo->query("SELECT COUNT(*) FROM desktop_object_events_v220 WHERE event_type='desktop.object.reset'")->fetchColumn();
if($resetEvents!==2)throw new RuntimeException('Desktop reset history is incomplete.');

dt_desktop_object_ensure_schema($pdo);
if(count(dt_desktop_objects($pdo,$b))!==1)throw new RuntimeException('Idempotent Desktop object migration changed data.');

echo "MUSIC_DESKTOP_V1_SECTION3_MYSQL=PASS\n";

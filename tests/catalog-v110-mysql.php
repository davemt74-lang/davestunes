<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=catalog-test-key-do-not-use-in-production');
$_SERVER['REMOTE_ADDR']='127.0.0.2';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
    'music_catalog_events_v110','music_release_tracks_v110','music_release_editions_v110','music_releases_v110','music_recordings_v110',
    'artist_authority_events','artist_memberships','artists','auth_login_attempts','user_profiles','users'
] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

dt_foundation_ensure_schema($pdo);
dt_catalog_ensure_schema($pdo);
if(!dt_catalog_schema_ready($pdo))throw new RuntimeException('Catalog schema is not ready.');

$owner=dt_auth_register($pdo,'owner2@example.com','owner catalog password 123','Owner');
$manager=dt_auth_register($pdo,'manager2@example.com','manager catalog pass 123','Manager');
$editor=dt_auth_register($pdo,'editor@example.com','editor catalog pass 1234','Editor');
$producer=dt_auth_register($pdo,'producer2@example.com','producer catalog pass 123','Producer');
$outsider=dt_auth_register($pdo,'outsider2@example.com','outsider catalog pass 123','Outsider');

$artist=dt_artist_create($pdo,$owner,'Night Drive');
$artistId=(int)$artist['id'];
dt_artist_set_membership($pdo,$artistId,(int)$manager['id'],'manager','active',$owner);
dt_artist_set_membership($pdo,$artistId,(int)$editor['id'],'editor','active',$owner);
dt_artist_set_membership($pdo,$artistId,(int)$producer['id'],'producer','active',$owner);

$recording=dt_catalog_create_recording($pdo,$artistId,$owner,[
    'title'=>'Neon Streets',
    'version_label'=>'Master',
    'isrc'=>'USABC2612345',
    'duration_ms'=>223000,
]);
$recordingId=(int)$recording['id'];
if((string)$recording['isrc']!=='USABC2612345')throw new RuntimeException('ISRC normalization failed.');

$album=dt_catalog_create_release($pdo,$artistId,$owner,['title'=>'Night Drive','release_type'=>'album','upc'=>'012345678905']);
$single=dt_catalog_create_release($pdo,$artistId,$manager,['title'=>'Neon Streets','release_type'=>'single']);
dt_catalog_add_recording_to_release($pdo,$artistId,(int)$album['id'],$recordingId,$owner,1,1);
dt_catalog_add_recording_to_release($pdo,$artistId,(int)$single['id'],$recordingId,$manager,1,1);

$recordingCount=(int)$pdo->query('SELECT COUNT(*) FROM music_recordings_v110')->fetchColumn();
$appearanceCount=(int)$pdo->query('SELECT COUNT(*) FROM music_release_tracks_v110')->fetchColumn();
if($recordingCount!==1||$appearanceCount!==2)throw new RuntimeException('Canonical recording was duplicated across releases.');

try{
    dt_catalog_create_recording($pdo,$artistId,$manager,['title'=>'Duplicate ISRC','isrc'=>'USABC2612345']);
    throw new RuntimeException('Duplicate ISRC was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Duplicate ISRC was accepted.')throw $e;
}

$otherArtist=dt_artist_create($pdo,$outsider,'Other Artist');
$otherRelease=dt_catalog_create_release($pdo,(int)$otherArtist['id'],$outsider,['title'=>'Other Album','release_type'=>'album']);
try{
    dt_catalog_add_recording_to_release($pdo,(int)$otherArtist['id'],(int)$otherRelease['id'],$recordingId,$outsider,1,1);
    throw new RuntimeException('Cross-artist recording attachment was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Cross-artist recording attachment was accepted.')throw $e;
}

try{
    dt_catalog_create_recording($pdo,$artistId,$producer,['title'=>'Producer Cannot Publish']);
    throw new RuntimeException('Producer gained catalog creation authority.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Producer gained catalog creation authority.')throw $e;
}

if(!dt_artist_can($pdo,$artistId,(int)$editor['id'],'catalog'))throw new RuntimeException('Editor should have catalog authority.');
if(dt_artist_can($pdo,$artistId,(int)$producer['id'],'catalog'))throw new RuntimeException('Producer should not have catalog authority.');

$empty=dt_catalog_create_release($pdo,$artistId,$owner,['title'=>'Empty Album','release_type'=>'album']);
try{
    dt_catalog_publish_release($pdo,$artistId,(int)$empty['id'],$owner);
    throw new RuntimeException('Empty release was published.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Empty release was published.')throw $e;
}

dt_catalog_create_edition($pdo,$artistId,(int)$album['id'],$owner,[
    'edition_name'=>'Digital Standard',
    'edition_code'=>'digital',
    'edition_format'=>'digital',
    'grants_digital_access'=>1,
]);
dt_catalog_create_edition($pdo,$artistId,(int)$album['id'],$owner,[
    'edition_name'=>'180g Vinyl',
    'edition_code'=>'vinyl',
    'edition_format'=>'vinyl',
    'grants_digital_access'=>1,
]);
if(count(dt_catalog_release_editions($pdo,$artistId,(int)$album['id']))!==2)throw new RuntimeException('Release editions were not preserved.');

dt_catalog_publish_release($pdo,$artistId,(int)$album['id'],$owner);
$published=dt_catalog_release($pdo,$artistId,(int)$album['id']);
if((string)$published['release_status']!=='published'||empty($published['published_at']))throw new RuntimeException('Release publishing failed.');

try{
    dt_catalog_archive_recording($pdo,$artistId,$recordingId,$owner);
    throw new RuntimeException('Recording in an active release was archived.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Recording in an active release was archived.')throw $e;
}

$events=(int)$pdo->query('SELECT COUNT(*) FROM music_catalog_events_v110')->fetchColumn();
if($events<8)throw new RuntimeException('Catalog event audit coverage is incomplete.');

dt_catalog_ensure_schema($pdo);
if((int)$pdo->query('SELECT COUNT(*) FROM music_recordings_v110')->fetchColumn()!==1)throw new RuntimeException('Idempotent catalog migration changed recording data.');

echo "FOUNDATION_V1_SECTION2_MYSQL=PASS\n";

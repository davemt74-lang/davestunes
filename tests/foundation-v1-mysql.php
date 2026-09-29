<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=test-key-that-is-long-enough-for-ci-only');
$_SERVER['REMOTE_ADDR']='127.0.0.1';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['artist_authority_events','artist_memberships','artists','auth_login_attempts','user_profiles','users'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

dt_foundation_ensure_schema($pdo);
if(!dt_foundation_schema_ready($pdo))throw new RuntimeException('Foundation schema is not ready.');

$owner=dt_auth_register($pdo,'Owner@Example.com','correct horse battery staple','Owner');
$manager=dt_auth_register($pdo,'manager@example.com','manager password 12345','Manager');
$producer=dt_auth_register($pdo,'producer@example.com','producer password 1234','Producer');
$outsider=dt_auth_register($pdo,'outsider@example.com','outsider password 1234','Outsider');

if((string)$owner['email']!=='owner@example.com')throw new RuntimeException('Email normalization failed.');
$storedHash=(string)$pdo->query("SELECT password_hash FROM users WHERE id=".(int)$owner['id'])->fetchColumn();
if($storedHash===''||$storedHash==='correct horse battery staple'||!password_verify('correct horse battery staple',$storedHash))throw new RuntimeException('Password hashing contract failed.');
if(array_key_exists('password_hash',$owner))throw new RuntimeException('Safe user projection leaked the password hash.');

$login=dt_auth_attempt_login($pdo,'owner@example.com','correct horse battery staple');
if(!$login||(int)$login['id']!==(int)$owner['id'])throw new RuntimeException('Valid login failed.');
if(dt_auth_attempt_login($pdo,'owner@example.com','wrong password')!==null)throw new RuntimeException('Invalid login succeeded.');

$artist=dt_artist_create($pdo,$owner,'Midnight Coast');
$artistId=(int)$artist['id'];
if(dt_artist_role($pdo,$artistId,(int)$owner['id'])!=='owner')throw new RuntimeException('Artist creator did not become owner.');

$second=dt_artist_create($pdo,$owner,'Second Project');
if((int)$second['owner_user_id']!==(int)$owner['id'])throw new RuntimeException('One user could not own multiple artists.');

dt_artist_set_membership($pdo,$artistId,(int)$manager['id'],'manager','active',$owner);
dt_artist_set_membership($pdo,$artistId,(int)$producer['id'],'producer','active',$owner);
try{
    dt_artist_set_membership($pdo,$artistId,999999,'viewer','active',$owner);
    throw new RuntimeException('Nonexistent user was added to an artist.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Nonexistent user was added to an artist.')throw $e;
}
if(!dt_artist_can($pdo,$artistId,(int)$manager['id'],'catalog'))throw new RuntimeException('Manager lacks catalog capability.');
if(!dt_artist_can($pdo,$artistId,(int)$producer['id'],'production'))throw new RuntimeException('Producer lacks production capability.');
if(dt_artist_can($pdo,$artistId,(int)$producer['id'],'profile'))throw new RuntimeException('Producer gained profile capability.');
if(dt_artist_can($pdo,$artistId,(int)$outsider['id'],'view'))throw new RuntimeException('Outsider gained artist access.');

try{
    dt_artist_set_membership($pdo,$artistId,(int)$manager['id'],'owner','active',$owner);
    throw new RuntimeException('Owner role was assigned through membership mutation.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Owner role was assigned through membership mutation.')throw $e;
}

dt_artist_transfer_owner($pdo,$artistId,(int)$manager['id'],$owner);
$after=dt_artist_by_id($pdo,$artistId);
if((int)$after['owner_user_id']!==(int)$manager['id'])throw new RuntimeException('Ownership transfer failed.');
if(dt_artist_role($pdo,$artistId,(int)$owner['id'])!=='manager')throw new RuntimeException('Prior owner was not preserved as manager.');
if(dt_artist_role($pdo,$artistId,(int)$manager['id'])!=='owner')throw new RuntimeException('New owner membership is incorrect.');

$events=(int)$pdo->query('SELECT COUNT(*) FROM artist_authority_events')->fetchColumn();
if($events<5)throw new RuntimeException('Artist authority events were not durably recorded.');

dt_foundation_ensure_schema($pdo);
$count=(int)$pdo->query('SELECT COUNT(*) FROM artists')->fetchColumn();
if($count!==2)throw new RuntimeException('Idempotent schema migration changed artist records.');

echo "FOUNDATION_V1_SECTION1_MYSQL=PASS\n";

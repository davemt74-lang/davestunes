<?php
declare(strict_types=1);

if((string)getenv('DAVESTUNES_DB_DSN')==='')throw new RuntimeException('DAVESTUNES_DB_DSN is required.');
putenv('DAVESTUNES_APP_ENV=test');
putenv('DAVESTUNES_APP_KEY=library-test-key-do-not-use-in-production');
$_SERVER['REMOTE_ADDR']='127.0.0.3';

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=dt_db();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
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
if(!dt_library_schema_ready($pdo))throw new RuntimeException('Library schema is not ready.');

$artistOwner=dt_auth_register($pdo,'artist-owner@example.com','artist owner password 123','Artist Owner');
$listener=dt_auth_register($pdo,'listener@example.com','listener password 12345','Listener');
$other=dt_auth_register($pdo,'other-listener@example.com','other listener pass 123','Other');

$artist=dt_artist_create($pdo,$artistOwner,'Signal Fire');
$artistId=(int)$artist['id'];
$recording=dt_catalog_create_recording($pdo,$artistId,$artistOwner,['title'=>'After Midnight','isrc'=>'USXYZ2612345','duration_ms'=>210000]);
$release=dt_catalog_create_release($pdo,$artistId,$artistOwner,['title'=>'Signal Fire','release_type'=>'album','upc'=>'012345678912']);
dt_catalog_add_recording_to_release($pdo,$artistId,(int)$release['id'],(int)$recording['id'],$artistOwner,1,1);
$edition=dt_catalog_create_edition($pdo,$artistId,(int)$release['id'],$artistOwner,[
    'edition_name'=>'Digital Standard','edition_code'=>'digital','edition_format'=>'digital','grants_digital_access'=>1,
]);
dt_catalog_publish_release($pdo,$artistId,(int)$release['id'],$artistOwner);

if(dt_entitlement_user_has_release($pdo,(int)$listener['id'],(int)$release['id']))throw new RuntimeException('Listener had ownership before a grant.');
if(dt_entitlement_user_has_recording($pdo,(int)$listener['id'],(int)$recording['id']))throw new RuntimeException('Listener had recording access before a grant.');

dt_library_save_release($pdo,(int)$listener['id'],(int)$release['id']);
dt_library_save_recording($pdo,(int)$listener['id'],(int)$recording['id']);
dt_library_unsave_recording($pdo,(int)$listener['id'],(int)$recording['id']);
if((int)$pdo->query('SELECT COUNT(*) FROM user_saved_recordings_v120')->fetchColumn()!==0)throw new RuntimeException('Saved recording removal failed.');
dt_library_save_recording($pdo,(int)$listener['id'],(int)$recording['id']);
dt_library_follow_artist($pdo,(int)$listener['id'],$artistId);
if(count(dt_library_saved_releases($pdo,(int)$listener['id']))!==1)throw new RuntimeException('Saved release was not retained.');
if(count(dt_library_followed_artists($pdo,(int)$listener['id']))!==1)throw new RuntimeException('Artist follow was not retained.');

$crate=dt_library_create_crate($pdo,(int)$listener['id'],'Late Night');
dt_library_add_to_crate($pdo,(int)$listener['id'],(int)$crate['id'],'release',(int)$release['id']);
if((int)$pdo->query('SELECT COUNT(*) FROM music_crate_items_v120')->fetchColumn()!==1)throw new RuntimeException('Crate item was not stored.');
dt_library_remove_from_crate($pdo,(int)$listener['id'],(int)$crate['id'],'release',(int)$release['id']);
if((int)$pdo->query('SELECT COUNT(*) FROM music_crate_items_v120')->fetchColumn()!==0)throw new RuntimeException('Crate item correction failed.');
dt_library_add_to_crate($pdo,(int)$listener['id'],(int)$crate['id'],'release',(int)$release['id']);

$playlist=dt_library_create_playlist($pdo,(int)$listener['id'],'Night Drive');
dt_library_add_to_playlist($pdo,(int)$listener['id'],(int)$playlist['id'],(int)$recording['id']);
if((int)$pdo->query('SELECT COUNT(*) FROM music_playlist_recordings_v120')->fetchColumn()!==1)throw new RuntimeException('Playlist recording was not stored.');
dt_library_remove_from_playlist($pdo,(int)$listener['id'],(int)$playlist['id'],(int)$recording['id']);
if((int)$pdo->query('SELECT COUNT(*) FROM music_playlist_recordings_v120')->fetchColumn()!==0)throw new RuntimeException('Playlist correction failed.');
dt_library_add_to_playlist($pdo,(int)$listener['id'],(int)$playlist['id'],(int)$recording['id']);

$grant=dt_entitlement_grant($pdo,[
    'grant_key'=>'purchase:order-100:item-1',
    'user_id'=>(int)$listener['id'],
    'resource_type'=>'release',
    'resource_id'=>(int)$release['id'],
    'entitlement_type'=>'own',
    'source_type'=>'purchase',
    'source_ref'=>'order-100',
]);
if(!dt_entitlement_user_has_release($pdo,(int)$listener['id'],(int)$release['id']))throw new RuntimeException('Release entitlement did not activate.');
if(!dt_entitlement_user_has_recording($pdo,(int)$listener['id'],(int)$recording['id']))throw new RuntimeException('Release entitlement did not project recording access.');
if(count(dt_library_owned_releases($pdo,(int)$listener['id']))!==1)throw new RuntimeException('Owned library did not project the release.');

$replay=dt_entitlement_grant($pdo,[
    'grant_key'=>'purchase:order-100:item-1',
    'user_id'=>(int)$listener['id'],
    'resource_type'=>'release',
    'resource_id'=>(int)$release['id'],
    'entitlement_type'=>'own',
    'source_type'=>'purchase',
    'source_ref'=>'order-100',
]);
if((int)$replay['id']!==(int)$grant['id'])throw new RuntimeException('Entitlement grant was not idempotent.');
if((int)$pdo->query('SELECT COUNT(*) FROM music_entitlements_v120')->fetchColumn()!==1)throw new RuntimeException('Idempotent replay duplicated entitlement.');

try{
    dt_entitlement_grant($pdo,[
        'grant_key'=>'purchase:order-100:item-1',
        'user_id'=>(int)$other['id'],
        'resource_type'=>'release',
        'resource_id'=>(int)$release['id'],
        'entitlement_type'=>'own',
        'source_type'=>'purchase',
        'source_ref'=>'different-order',
    ]);
    throw new RuntimeException('Conflicting entitlement replay was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Conflicting entitlement replay was accepted.')throw $e;
}

dt_entitlement_revoke($pdo,(int)$grant['id'],(int)$artistOwner['id'],'refund');
if(dt_entitlement_user_has_release($pdo,(int)$listener['id'],(int)$release['id']))throw new RuntimeException('Revoked entitlement still granted release access.');
if(count(dt_library_saved_releases($pdo,(int)$listener['id']))!==1)throw new RuntimeException('Revocation incorrectly removed saved-library state.');

$editionGrant=dt_entitlement_grant($pdo,[
    'grant_key'=>'gift:gift-44',
    'user_id'=>(int)$listener['id'],
    'resource_type'=>'edition',
    'resource_id'=>(int)$edition['id'],
    'entitlement_type'=>'own',
    'source_type'=>'gift',
    'source_ref'=>'gift-44',
]);
if(!dt_entitlement_user_has_release($pdo,(int)$listener['id'],(int)$release['id']))throw new RuntimeException('Digital edition did not project release access.');
if(!dt_entitlement_user_has_recording($pdo,(int)$listener['id'],(int)$recording['id']))throw new RuntimeException('Digital edition did not project recording access.');
if(count(dt_library_owned_releases($pdo,(int)$listener['id']))!==1)throw new RuntimeException('Edition ownership did not project into the owned library.');
if(count(dt_library_owned_recordings($pdo,(int)$listener['id']))!==1)throw new RuntimeException('Owned release did not project its canonical recording into owned songs.');

$expired=dt_entitlement_grant($pdo,[
    'grant_key'=>'promo:expired',
    'user_id'=>(int)$other['id'],
    'resource_type'=>'release',
    'resource_id'=>(int)$release['id'],
    'entitlement_type'=>'access',
    'source_type'=>'promotion',
    'source_ref'=>'expired-promo',
    'starts_at'=>'2020-01-01 00:00:00',
    'ends_at'=>'2020-01-02 00:00:00',
]);
if(dt_entitlement_user_has_release($pdo,(int)$other['id'],(int)$release['id']))throw new RuntimeException('Expired entitlement still granted access.');

try{
    dt_library_add_to_crate($pdo,(int)$other['id'],(int)$crate['id'],'release',(int)$release['id']);
    throw new RuntimeException('Another user modified a private crate.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Another user modified a private crate.')throw $e;
}

$draftRecording=dt_catalog_create_recording($pdo,$artistId,$artistOwner,['title'=>'Before Dawn','isrc'=>'USXYZ2612346','duration_ms'=>198000]);
$draftRelease=dt_catalog_create_release($pdo,$artistId,$artistOwner,['title'=>'Before Dawn','release_type'=>'single']);
dt_catalog_add_recording_to_release($pdo,$artistId,(int)$draftRelease['id'],(int)$draftRecording['id'],$artistOwner,1,1);
try{
    dt_library_save_release($pdo,(int)$other['id'],(int)$draftRelease['id']);
    throw new RuntimeException('Private draft release was saved without entitlement.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Private draft release was saved without entitlement.')throw $e;
}
$draftGrant=dt_entitlement_grant($pdo,[
    'grant_key'=>'promo:prerelease-1',
    'user_id'=>(int)$other['id'],
    'resource_type'=>'release',
    'resource_id'=>(int)$draftRelease['id'],
    'entitlement_type'=>'access',
    'source_type'=>'promotion',
    'source_ref'=>'prerelease-1',
    'granted_by_user_id'=>(int)$artistOwner['id'],
]);
if(!dt_entitlement_user_has_release($pdo,(int)$other['id'],(int)$draftRelease['id']))throw new RuntimeException('Pre-release entitlement did not activate.');
dt_library_save_release($pdo,(int)$other['id'],(int)$draftRelease['id']);
if(count(dt_library_saved_releases($pdo,(int)$other['id']))!==1)throw new RuntimeException('Entitled private release could not be saved.');
if(count(dt_library_owned_releases($pdo,(int)$other['id']))!==0)throw new RuntimeException('Temporary pre-release access was incorrectly labeled as ownership.');
if(count(dt_library_available_releases($pdo,(int)$other['id']))!==1)throw new RuntimeException('Temporary pre-release access was not projected as available.');

try{
    dt_entitlement_grant($pdo,[
        'grant_key'=>'promo:prerelease-1',
        'user_id'=>(int)$other['id'],
        'resource_type'=>'release',
        'resource_id'=>(int)$draftRelease['id'],
        'entitlement_type'=>'access',
        'source_type'=>'promotion',
        'source_ref'=>'prerelease-1',
        'granted_by_user_id'=>(int)$listener['id'],
    ]);
    throw new RuntimeException('Grantor-changing entitlement replay was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Grantor-changing entitlement replay was accepted.')throw $e;
}

$events=(int)$pdo->query('SELECT COUNT(*) FROM music_entitlement_events_v120')->fetchColumn();
if($events<5)throw new RuntimeException('Entitlement audit history is incomplete.');

dt_library_ensure_schema($pdo);
if((int)$pdo->query('SELECT COUNT(*) FROM music_entitlements_v120')->fetchColumn()!==4)throw new RuntimeException('Idempotent library migration changed entitlement data.');

echo "FOUNDATION_V1_SECTION3_MYSQL=PASS\n";

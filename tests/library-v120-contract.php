<?php
declare(strict_types=1);

function library_read(string $path): string {
    $value=file_get_contents(dirname(__DIR__).'/'.$path);
    if($value===false)throw new RuntimeException('Could not read '.$path);
    return $value;
}
function library_has(string $text,string $needle,string $message): void {
    if(!str_contains($text,$needle))throw new RuntimeException($message);
}
function library_not(string $text,string $needle,string $message): void {
    if(str_contains($text,$needle))throw new RuntimeException($message);
}

$schema=library_read('includes/library-schema-v120.php');
$library=library_read('includes/library-v120.php');
$page=library_read('library.php');
$bootstrap=library_read('includes/bootstrap.php');
$migrate=library_read('migrate.php');
$dashboard=library_read('dashboard.php');
$workflow=library_read('.github/workflows/foundation-v1.yml');

foreach([
    'music_entitlements_v120','music_entitlement_events_v120','user_saved_releases_v120','user_saved_recordings_v120',
    'user_artist_follows_v120','music_crates_v120','music_crate_items_v120','music_playlists_v120','music_playlist_recordings_v120'
] as $table)library_has($schema,'CREATE TABLE IF NOT EXISTS '.$table,'Missing library table '.$table);

library_has($schema,'UNIQUE KEY uq_music_entitlement_grant_v120 (grant_key)','Entitlement grants must be idempotent.');
library_has($schema,'entitlement_status VARCHAR(20)','Entitlements require durable lifecycle state.');
library_has($library,'dt_entitlement_grant','Entitlement grant primitive is missing.');
library_has($library,'dt_entitlement_revoke','Entitlement revocation is missing.');
library_has($library,'Entitlement idempotency key conflicts','Idempotency conflicts must fail closed.');
library_has($library,'Entitlement source reference is required.','Fulfillment grants must retain authoritative source lineage.');
library_has($library,"resource_type='edition'",'Edition entitlements must project release access.');
library_has($library,'grants_digital_access=1','Edition access must honor edition policy.');
library_has($library,'dt_entitlement_user_has_recording','Recording access resolver is missing.');
library_has($library,'dt_library_save_release','Saved releases are missing.');
library_has($library,'dt_library_follow_artist','Artist following is missing.');
library_has($library,'dt_library_create_crate','Crates are missing.');
library_has($library,'dt_library_create_playlist','Playlists are missing.');
library_has($library,'dt_library_remove_from_crate','Crate correction path is missing.');
library_has($library,'dt_library_remove_from_playlist','Playlist correction path is missing.');
library_has($library,'dt_library_can_collect','Crates/playlists must use one collection-availability guard.');
library_not($page,'dt_entitlement_grant','Users must not self-grant entitlements from the library UI.');
library_has($page,"value=\"add_to_crate\"",'Library UI must allow available music to be placed into crates.');

library_has($bootstrap,"require_once __DIR__.'/library-schema-v120.php';",'Library schema must load in bootstrap.');
library_has($bootstrap,"require_once __DIR__.'/library-v120.php';",'Library service must load in bootstrap.');
library_has($migrate,'dt_library_ensure_schema($pdo);','Migration must install the library after catalog.');
library_has($dashboard,'/library.php','Dashboard must expose the personal library.');
library_has($workflow,'php tests/library-v120-contract.php','CI must run the library contract.');
library_has($workflow,'php tests/library-v120-mysql.php','CI must run the library MySQL integration.');

echo "FOUNDATION_V1_SECTION3_CONTRACT=PASS\n";

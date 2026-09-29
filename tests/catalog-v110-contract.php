<?php
declare(strict_types=1);

function catalog_read(string $path): string {
    $value=file_get_contents(dirname(__DIR__).'/'.$path);
    if($value===false)throw new RuntimeException('Could not read '.$path);
    return $value;
}
function catalog_has(string $text,string $needle,string $message): void {
    if(!str_contains($text,$needle))throw new RuntimeException($message);
}
function catalog_not(string $text,string $needle,string $message): void {
    if(str_contains($text,$needle))throw new RuntimeException($message);
}

$schema=catalog_read('includes/music-catalog-schema-v110.php');
$catalog=catalog_read('includes/music-catalog-v110.php');
$bootstrap=catalog_read('includes/bootstrap.php');
$migrate=catalog_read('migrate.php');
$page=catalog_read('catalog.php');
$dashboard=catalog_read('dashboard.php');
$workflow=catalog_read('.github/workflows/foundation-v1.yml');

foreach(['music_recordings_v110','music_releases_v110','music_release_editions_v110','music_release_tracks_v110','music_catalog_events_v110'] as $table){
    catalog_has($schema,'CREATE TABLE IF NOT EXISTS '.$table,'Missing catalog table '.$table);
}
catalog_has($schema,'UNIQUE KEY uq_music_recording_isrc_v110 (isrc)','Canonical recordings must protect ISRC identity.');
catalog_has($schema,'UNIQUE KEY uq_music_release_artist_slug_v110 (artist_id,slug)','Release slug must be unique per artist.');
catalog_has($schema,'UNIQUE KEY uq_music_release_track_slot_v110 (release_id,disc_number,track_number)','Release track positions must be unique.');
catalog_has($schema,'recording_id BIGINT UNSIGNED NOT NULL','Release appearances must reference canonical recordings.');
catalog_has($schema,'release_id BIGINT UNSIGNED NOT NULL','Release tracks must be appearances, not copied songs.');

catalog_has($catalog,'dt_catalog_create_recording','Recording creation service is missing.');
catalog_has($catalog,'dt_catalog_create_release','Release creation service is missing.');
catalog_has($catalog,'dt_catalog_add_recording_to_release','Release-track composition is missing.');
catalog_has($catalog,'Release and recording must belong to the same artist.','Cross-artist release composition guard is missing.');
catalog_has($catalog,'dt_catalog_create_edition','Edition model is missing.');
catalog_has($catalog,'dt_catalog_remove_release_track','Release composition must support governed corrections.');
catalog_has($catalog,'have a frozen track list','Published release composition must be immutable.');
catalog_has($catalog,'dt_catalog_publish_release','Publishing gate is missing.');
catalog_has($catalog,'at least one active recording','Empty release publish guard is missing.');
catalog_has($catalog,'music_catalog_events_v110','Catalog mutations must be auditable.');
catalog_has($catalog,"dt_artist_can($pdo,$artistId,$userId,$capability)",'Catalog authority must use artist-role capabilities.');
catalog_not($catalog,'password_hash','Catalog must not create another identity/auth system.');

catalog_has($bootstrap,"require_once __DIR__.'/music-catalog-schema-v110.php';",'Catalog schema must load in bootstrap.');
catalog_has($bootstrap,"require_once __DIR__.'/music-catalog-v110.php';",'Catalog service must load in bootstrap.');
catalog_has($migrate,'dt_catalog_ensure_schema($pdo);','Migration must install the catalog after foundation.');
catalog_has($page,'dt_catalog_add_recording_to_release','Catalog UI must exercise canonical release composition.');
catalog_has($page,"value=\"remove_track\"",'Catalog UI must allow governed release-track corrections.');
catalog_has($dashboard,'/catalog.php?artist=','Artist dashboard must link to the catalog.');
catalog_has($workflow,'php tests/catalog-v110-contract.php','CI must run the catalog contract.');
catalog_has($workflow,'php tests/catalog-v110-mysql.php','CI must run the catalog MySQL integration.');

echo "FOUNDATION_V1_SECTION2_CONTRACT=PASS\n";

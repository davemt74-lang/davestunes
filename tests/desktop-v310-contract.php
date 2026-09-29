<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/catalog.php');
$studio=file_get_contents($root.'/experience-studio.php');
$album=file_get_contents($root.'/album.php');
$experience=file_get_contents($root.'/album-experience.php');
$boot=file_get_contents($root.'/assets/album-experience.js');
$service=file_get_contents($root.'/includes/experience-v280.php');
$workflow=file_get_contents($root.'/.github/workflows/foundation-v1.yml');

foreach(compact('catalog','studio','album','experience','boot','service','workflow') as $name=>$content){
    if($content===false)throw new RuntimeException('Could not load '.$name.'.');
}
foreach([
    '/experience-studio.php?owner_type=release&owner_id=',
    'Build Experience',
    '/album.php?release=',
] as $needle)if(!str_contains($catalog,$needle))throw new RuntimeException('Catalog is missing album Studio integration: '.$needle);

if(!str_contains($studio,'dt_experience_owner_context'))throw new RuntimeException('Studio is missing album owner context.');
if(!str_contains($studio,'dt_experience_require_owner'))throw new RuntimeException('Studio must enforce owner permissions before rendering.');
if(!str_contains($studio,'Back to Album'))throw new RuntimeException('Studio must link back to the album.');

if(!str_contains($album,'Enter Experience'))throw new RuntimeException('Album page is missing the experience launch.');
if(!str_contains($album,'dt_experience_active'))throw new RuntimeException('Album page must resolve the canonical published experience.');
if(!str_contains($album,"release_status']!=='published'&&!$canEdit"))throw new RuntimeException('Album fallback must hide unpublished releases from unauthorized visitors.');

if(!str_contains($experience,"release_status']!=='published'"))throw new RuntimeException('Published album experience surface must reject unpublished releases.');
if(!str_contains($experience,'dt_experience_active'))throw new RuntimeException('Album experience must use the canonical active experience.');
if(!str_contains($experience,'module-z-scroll.js'))throw new RuntimeException('Album experience must use canonical Z-Scroll.');
if(!str_contains($experience,'module-experience-runtime.js'))throw new RuntimeException('Album experience must use canonical experience runtime.');
if(!str_contains($experience,'experience-effects.js'))throw new RuntimeException('Album experience must use canonical effects library.');
if(str_contains($experience,'new Audio(')||str_contains($boot,'new Audio('))throw new RuntimeException('Album experience must not create a second playback engine.');

if(!str_contains($boot,'desktop.state.experienceManifest'))throw new RuntimeException('Album experience boot must inject the published manifest.');
if(!str_contains($boot,"zscroll.enter"))throw new RuntimeException('Album experience must enter the canonical Z-Scroll runtime.');

if(!str_contains($service,'dt_experience_owner_context'))throw new RuntimeException('Album owner context helper is missing.');
if(!str_contains($workflow,'php tests/desktop-v310-contract.php'))throw new RuntimeException('Section 12 contract is not wired into CI.');
if(!str_contains($workflow,'php tests/desktop-v310-mysql.php'))throw new RuntimeException('Section 12 MySQL gate is not wired into CI.');
if(!str_contains($workflow,'node --check assets/album-experience.js'))throw new RuntimeException('Album experience JS syntax gate is not wired into CI.');

echo "MUSIC_DESKTOP_V1_SECTION12_CONTRACT=PASS\n";

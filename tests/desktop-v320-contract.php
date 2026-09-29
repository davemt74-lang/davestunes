<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dashboard=file_get_contents($root.'/dashboard.php');
$artist=file_get_contents($root.'/artist.php');
$album=file_get_contents($root.'/album.php');
$experience=file_get_contents($root.'/artist-experience.php');
$boot=file_get_contents($root.'/assets/artist-experience.js');
$artists=file_get_contents($root.'/includes/artists.php');
$service=file_get_contents($root.'/includes/experience-v280.php');
$workflow=file_get_contents($root.'/.github/workflows/foundation-v1.yml');

foreach(compact('dashboard','artist','album','experience','boot','artists','service','workflow') as $name=>$content){
    if($content===false)throw new RuntimeException('Could not load '.$name.'.');
}
foreach(['Public Profile','Build Experience','/artist.php?artist=','owner_type=artist'] as $needle){
    if(!str_contains($dashboard,$needle))throw new RuntimeException('Dashboard artist integration missing: '.$needle);
}
if(!str_contains($artist,'Artist Desktop'))throw new RuntimeException('Public Artist Desktop shell is missing.');
if(!str_contains($album,'/artist.php?artist='))throw new RuntimeException('Album pages must link back to the Artist Desktop.');
if(!str_contains($artist,'dt_catalog_releases'))throw new RuntimeException('Artist Desktop must use canonical catalog releases.');
if(!str_contains($artist,"release_status']==='published'"))throw new RuntimeException('Artist Desktop must only project published releases.');
if(!str_contains($artist,'Enter Experience'))throw new RuntimeException('Artist Desktop is missing Experience launch.');
if(!str_contains($artist,'dt_artist_public_url'))throw new RuntimeException('Artist Desktop must sanitize public links.');
if(!str_contains($artist,'dt_artist_public_image'))throw new RuntimeException('Artist Desktop must sanitize public images.');
if(!str_contains($artist,"artist_status']!=='active'&&!$canEdit"))throw new RuntimeException('Non-active artist profiles must be hidden from public visitors.');

foreach(['experience-effects.js','module-experience-runtime.js','module-z-scroll.js'] as $asset){
    if(!str_contains($experience,$asset))throw new RuntimeException('Artist Experience must use canonical runtime asset '.$asset.'.');
}
if(!str_contains($experience,"artist_status']!=='active'"))throw new RuntimeException('Artist Experience must reject non-active artists.');
if(!str_contains($experience,'dt_experience_active'))throw new RuntimeException('Artist Experience must resolve the canonical active experience.');
if(str_contains($experience,'new Audio(')||str_contains($boot,'new Audio('))throw new RuntimeException('Artist Experience must not create a second audio engine.');
if(!str_contains($boot,'desktop.state.experienceManifest'))throw new RuntimeException('Artist Experience boot must inject the canonical manifest.');
if(!str_contains($boot,"zscroll.enter"))throw new RuntimeException('Artist Experience must enter canonical Z-Scroll.');

if(!str_contains($artists,'function dt_artist_public_url'))throw new RuntimeException('Public artist URL sanitizer is missing.');
if(!str_contains($artists,"['http','https']"))throw new RuntimeException('Public artist URL sanitizer must only allow HTTP(S).');
if(!str_contains($artists,'function dt_artist_public_image'))throw new RuntimeException('Public artist image sanitizer is missing.');

if(!str_contains($service,"'back_url'=>$artist?'/artist.php?artist='"))throw new RuntimeException('Artist Studio must link back to the public profile.');
if(!str_contains($workflow,'php tests/desktop-v320-contract.php'))throw new RuntimeException('Section 13 contract is not wired into CI.');
if(!str_contains($workflow,'php tests/desktop-v320-mysql.php'))throw new RuntimeException('Section 13 MySQL gate is not wired into CI.');
if(!str_contains($workflow,'node --check assets/artist-experience.js'))throw new RuntimeException('Artist Experience JS syntax gate is missing.');

echo "MUSIC_DESKTOP_V1_SECTION13_CONTRACT=PASS\n";

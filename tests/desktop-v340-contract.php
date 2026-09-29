<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$service=file_get_contents($root.'/includes/experience-v280.php');
$delivery=file_get_contents($root.'/experience-delivery.php');
$activeApi=file_get_contents($root.'/experience.php');
$album=file_get_contents($root.'/album-experience.php');
$artist=file_get_contents($root.'/artist-experience.php');
$boot=file_get_contents($root.'/assets/public-experience.js');
$zscroll=file_get_contents($root.'/assets/desktop/module-z-scroll.js');
$core=file_get_contents($root.'/assets/desktop/desktop-core.js');
$desktop=file_get_contents($root.'/desktop.php');
$workflow=file_get_contents($root.'/.github/workflows/foundation-v1.yml');

foreach(compact('service','delivery','activeApi','album','artist','boot','zscroll','core','desktop','workflow') as $name=>$content){
    if($content===false)throw new RuntimeException('Could not load '.$name.'.');
}

foreach([
    'function dt_experience_published',
    'function dt_experience_delivery_url',
    'versionNumber',
    'manifest_sha256',
] as $needle){
    if(!str_contains($service,$needle))throw new RuntimeException('Published delivery service missing '.$needle.'.');
}

if(!str_contains($delivery,'experience-delivery-v340'))throw new RuntimeException('Immutable delivery schema version is missing.');
if(!str_contains($delivery,'max-age=31536000, immutable'))throw new RuntimeException('Immutable cache policy is missing.');
if(!str_contains($delivery,"header('ETag: '"))throw new RuntimeException('Delivery ETag is missing.');
if(!str_contains($delivery,'HTTP_IF_NONE_MATCH'))throw new RuntimeException('Conditional 304 handling is missing.');
if(!str_contains($delivery,"hash_equals((string)$published['sha256'],$expectedHash)"))throw new RuntimeException('Pinned hash verification is missing.');
if(!str_contains($delivery,"header_remove('Set-Cookie')"))throw new RuntimeException('Public immutable delivery must strip session cookies.');
if(!str_contains($delivery,'Cross-Origin-Resource-Policy: same-origin'))throw new RuntimeException('Delivery CORP header is missing.');

if(!str_contains($activeApi,'stale-while-revalidate=120'))throw new RuntimeException('Active experience discovery cache policy is missing.');
if(!str_contains($activeApi,'HTTP_IF_NONE_MATCH'))throw new RuntimeException('Active experience ETag revalidation is missing.');
if(!str_contains($activeApi,"header_remove('Set-Cookie')"))throw new RuntimeException('Public active discovery must strip session cookies.');
if(!str_contains($activeApi,"schemaVersion'=>'experience-v340'"))throw new RuntimeException('Active experience API schema was not advanced.');

foreach([$album,$artist] as $surface){
    if(!str_contains($surface,'dt-public-experience-boot'))throw new RuntimeException('Public experience surface must use the shared boot payload.');
    if(!str_contains($surface,'dt_experience_delivery_url'))throw new RuntimeException('Public experience surface must use immutable delivery URL.');
    if(!str_contains($surface,'public-experience.js'))throw new RuntimeException('Public experience surface must use the shared bootstrap.');
    if(str_contains($surface,"'manifest'=>"))throw new RuntimeException('Public experience HTML must not inline the published manifest.');
}

if(!str_contains($boot,"credentials:'omit'"))throw new RuntimeException('Public bootstrap must fetch immutable delivery without credentials.');
if(!str_contains($boot,"cache:'reload'"))throw new RuntimeException('Public bootstrap must recover from a bare conditional 304 response.');
if(!str_contains($boot,'expectedHash'))throw new RuntimeException('Public bootstrap must pin the expected hash.');
if(!str_contains($boot,'expectedVersion'))throw new RuntimeException('Public bootstrap must pin the expected version.');
if(!str_contains($boot,"experienceDataMode='authored-only'"))throw new RuntimeException('Public bootstrap must enable authored-only data mode.');
if(!str_contains($zscroll,"experienceDataMode!=='authored-only'"))throw new RuntimeException('Z-Scroll must skip library fetches for public authored experiences.');
if(!str_contains($core,"experienceDataMode: 'library'"))throw new RuntimeException('Desktop core must define the experience data mode.');
if(!str_contains($desktop,"'version'=>'music-desktop-v1-section15'"))throw new RuntimeException('Desktop runtime version must be Section 15.');

if(file_exists($root.'/assets/album-experience.js')||file_exists($root.'/assets/artist-experience.js')){
    throw new RuntimeException('Duplicate public experience bootstrap files must be removed.');
}

if(!str_contains($workflow,'php tests/desktop-v340-contract.php'))throw new RuntimeException('Section 15 contract gate is not wired into CI.');
if(!str_contains($workflow,'php tests/desktop-v340-mysql.php'))throw new RuntimeException('Section 15 MySQL gate is not wired into CI.');
if(!str_contains($workflow,'node --check assets/public-experience.js'))throw new RuntimeException('Shared public bootstrap syntax gate is missing.');

echo "MUSIC_DESKTOP_V1_SECTION15_CONTRACT=PASS\n";

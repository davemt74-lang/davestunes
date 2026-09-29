<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$release=file_get_contents($root.'/includes/release.php');
$index=file_get_contents($root.'/index.php');
$desktop=file_get_contents($root.'/desktop.php');
$featured=file_get_contents($root.'/includes/featured-v270.php');
$data=file_get_contents($root.'/includes/desktop-data-v210.php');
$splash=file_get_contents($root.'/assets/desktop/loading-splash.js');
$featuredJs=file_get_contents($root.'/assets/desktop/module-featured-content.js');
$css=file_get_contents($root.'/assets/desktop/desktop.css');
$signup=file_get_contents($root.'/signup.php');
$login=file_get_contents($root.'/login.php');
$workflow=file_get_contents($root.'/.github/workflows/foundation-v1.yml');

foreach(compact('release','index','desktop','featured','data','splash','featuredJs','css','signup','login','workflow') as $name=>$content){
    if($content===false)throw new RuntimeException('Could not load '.$name.'.');
}

if(!str_contains($release,"DAVESTUNES_RELEASE_VERSION='1.0.0-rc2'"))throw new RuntimeException('RC2 release version is missing.');
if(!str_contains($release,"DAVESTUNES_RELEASE_LABEL='Soft Launch RC2'"))throw new RuntimeException('RC2 release label is missing.');

foreach([
    'desktop-shell',
    'public-home-surface',
    'Featured Albums',
    'News & Notes',
    'turntable-mount',
    'data-desktop-splash',
    'data-splash-mode="public"',
] as $needle){
    if(!str_contains($index,$needle))throw new RuntimeException('Public Desktop home is missing '.$needle.'.');
}
if(str_contains($index,'soft-launch-grid'))throw new RuntimeException('Old marketing landing page must not remain the homepage.');
if(!str_contains($index,"header('Location: /desktop.php')"))throw new RuntimeException('Signed-in homepage visits must resolve to the personalized Desktop.');

foreach([
    'function dt_featured_public_albums',
    'function dt_featured_public_news',
    'dt_library_published_releases',
] as $needle){
    if(!str_contains($featured,$needle))throw new RuntimeException('Public discovery fallback is missing '.$needle.'.');
}

if(!str_contains($data,'function dt_desktop_personalization_state'))throw new RuntimeException('Personalization-state resolver is missing.');
foreach(['desktop_objects_v220','user_saved_releases_v120','user_artist_follows_v120','playback_listens_v130'] as $table){
    if(!str_contains($data,$table))throw new RuntimeException('Personalization-state resolver is missing '.$table.'.');
}
if(!str_contains($desktop,"'personalizationState'=>$personalizationState"))throw new RuntimeException('Desktop boot must expose personalization state.');
if(!str_contains($desktop,'data-personalization-state'))throw new RuntimeException('Desktop DOM must expose personalization state.');

foreach(['window','desktop','library','featured','objects'] as $gate){
    if(!str_contains($splash,"'".$gate."'"))throw new RuntimeException('Splash readiness gate missing '.$gate.'.');
}
foreach(['library-loaded','library-error','featured-loaded','featured-error','objects-loaded','object-error'] as $event){
    if(!str_contains($splash,$event))throw new RuntimeException('Splash readiness event missing '.$event.'.');
}
if(!str_contains($splash,"data-splash-state")&&!str_contains($splash,"splashState"))throw new RuntimeException('Splash exit state is missing.');
if(!str_contains($css,'.desktop-splash'))throw new RuntimeException('Splash styling is missing.');
if(!str_contains($css,'@keyframes desktopSplashOut'))throw new RuntimeException('Splash exit animation is missing.');
if(!str_contains($splash,"setTimeout(()=>release('failsafe'),7000)"))throw new RuntimeException('Splash must have a hard fail-safe.');
if(!str_contains($splash,"data-desktop-featured"))throw new RuntimeException('Splash must tolerate missing optional featured mount.');

if(!str_contains($featuredJs,"personalizationState==='starter'"))throw new RuntimeException('Starter Desktop featured messaging is missing.');
if(!str_contains($featuredJs,"featured-loaded',{items:[],empty:true}"))throw new RuntimeException('Empty featured state must still satisfy splash readiness.');
if(!str_contains($signup,"dt_redirect('/desktop.php')"))throw new RuntimeException('Signup must land on the Music Desktop.');
if(!str_contains($login,"dt_redirect('/desktop.php')"))throw new RuntimeException('Login must land on the Music Desktop.');

if(!str_contains($workflow,'node --check assets/desktop/loading-splash.js'))throw new RuntimeException('Splash syntax gate is missing.');
if(!str_contains($workflow,'php tests/soft-launch-rc2-contract.php'))throw new RuntimeException('RC2 contract gate is missing.');
if(!str_contains($workflow,'php tests/soft-launch-rc2-mysql.php'))throw new RuntimeException('RC2 MySQL gate is missing.');

echo "SOFT_LAUNCH_RC2_CONTRACT=PASS\n";

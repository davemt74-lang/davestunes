<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$release=file_get_contents($root.'/includes/release.php');
$bootstrap=file_get_contents($root.'/includes/bootstrap.php');
$index=file_get_contents($root.'/index.php');
$dashboard=file_get_contents($root.'/dashboard.php');
$desktop=file_get_contents($root.'/desktop.php');
$desktopCss=file_get_contents($root.'/assets/desktop/desktop.css');
$appCss=file_get_contents($root.'/assets/app.css');
$readme=file_get_contents($root.'/README.md');
$installerContract=file_get_contents($root.'/tests/installer-v101-contract.php');
$workflow=file_get_contents($root.'/.github/workflows/foundation-v1.yml');

foreach(compact('release','bootstrap','index','dashboard','desktop','desktopCss','appCss','readme','installerContract','workflow') as $name=>$content){
    if($content===false)throw new RuntimeException('Could not load '.$name.'.');
}

foreach([
    "DAVESTUNES_RELEASE_VERSION='1.0.0-rc1'",
    "DAVESTUNES_RELEASE_CHANNEL='soft-launch'",
    "DAVESTUNES_RELEASE_LABEL='Soft Launch RC1'",
] as $needle){
    if(!str_contains($release,$needle))throw new RuntimeException('Release metadata missing '.$needle.'.');
}

if(!str_contains($bootstrap,"require_once __DIR__.'/release.php'"))throw new RuntimeException('Release metadata is not loaded by bootstrap.');
if(!str_contains($index,'dt_release_label()'))throw new RuntimeException('Landing page must surface the soft-launch label.');
if(!str_contains($index,'Music Desktop'))throw new RuntimeException('Landing page must explain the Music Desktop.');
if(!str_contains($index,'Digital Turntable'))throw new RuntimeException('Landing page must explain the turntable.');
if(str_contains(strtolower($index),'purchase')||str_contains(strtolower($index),'buy music'))throw new RuntimeException('RC1 landing page must not advertise commerce that is not live.');
if(!str_contains($dashboard,'dt_release_label()'))throw new RuntimeException('Dashboard must surface the release label.');
if(!str_contains($desktop,"'release'=>dt_release_version()"))throw new RuntimeException('Desktop boot must expose the release version.');
if(!str_contains($desktop,'desktop-release-chip'))throw new RuntimeException('Desktop must surface Soft Launch RC1 status.');
if(!str_contains($desktopCss,'Soft Launch RC1 — physical desk depth'))throw new RuntimeException('CSS desk-depth treatment is missing.');
if(!str_contains($desktopCss,'.desktop-shell:after'))throw new RuntimeException('Bottom desktop layer must remain CSS-rendered.');
if(preg_match('/url\([^)]*\.(?:png|jpe?g|webp)/i',$desktopCss))throw new RuntimeException('Default Desktop must not depend on raster background imagery for RC1.');
if(!str_contains($appCss,'.soft-launch-grid'))throw new RuntimeException('Soft-launch landing styles are missing.');
if(!str_contains($readme,'Commerce is not part of RC1'))throw new RuntimeException('README must document RC1 commerce scope.');
if(!str_contains($readme,'generated with CSS'))throw new RuntimeException('README must document CSS-based Desktop visuals.');
if(str_contains($installerContract,'Undefined variable'))throw new RuntimeException('Installer contract must remain warning-free.');
if(!str_contains($workflow,'php tests/soft-launch-rc1-contract.php'))throw new RuntimeException('Soft Launch RC1 gate is not wired into CI.');

echo "SOFT_LAUNCH_RC1_CONTRACT=PASS\n";

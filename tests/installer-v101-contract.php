<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$install=file_get_contents($root.'/install.php');
$index=file_get_contents($root.'/index.php');
$login=file_get_contents($root.'/login.php');
$signup=file_get_contents($root.'/signup.php');
$readme=file_get_contents($root.'/README.md');
$workflow=file_get_contents($root.'/.github/workflows/foundation-v1.yml');

if($install===false||$index===false||$login===false||$signup===false||$readme===false||$workflow===false){
    throw new RuntimeException('Installer contract files could not be loaded.');
}
foreach([
    "dt_foundation_ensure_schema",
    "dt_catalog_ensure_schema",
    "dt_library_ensure_schema",
    "dt_playback_ensure_schema",
    "dt_desktop_object_ensure_schema",
    "dt_featured_ensure_schema",
    "dt_experience_ensure_schema",
    "dt_auth_register",
] as $required){
    if(!str_contains($install,$required))throw new RuntimeException('Installer is missing '.$required.'.');
}
if(!str_contains($install,"bin2hex(random_bytes(32))"))throw new RuntimeException('Installer must generate the local application secret automatically.');
if(!str_contains($install,"'emails'=>\$adminEmail"))throw new RuntimeException('First user must become the initial administrator.');
if(!str_contains($install,"SELECT COUNT(*) FROM users"))throw new RuntimeException('Installer must lock after the first user exists.');
if(!str_contains($install,'Install & Create First User'))throw new RuntimeException('First-user setup form is missing.');
if(!str_contains($install,'No API keys are required.'))throw new RuntimeException('Installer must explicitly avoid API-key setup.');
if(preg_match('/name=["\'](?:api|app)_?key/i',$install))throw new RuntimeException('Installer must not request an API or application key from the user.');
foreach([$index,$login,$signup] as $surface){
    if(!str_contains($surface,"/install.php"))throw new RuntimeException('Fresh-install redirect is missing.');
}
if(!str_contains($readme,'No external API keys are required.'))throw new RuntimeException('README installer guidance is incomplete.');
if(!str_contains($workflow,'php tests/installer-v101-contract.php'))throw new RuntimeException('Installer acceptance gate is not wired into CI.');

echo "FOUNDATION_WEB_INSTALLER_V101=PASS\n";

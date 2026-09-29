<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function dt_desktop_media_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')dt_desktop_media_json(['ok'=>false,'error'=>'Method not allowed.'],405);

try{
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    if(!$user)dt_desktop_media_json(['ok'=>false,'error'=>'Authentication required.'],401);
    $type=(string)($_GET['type']??'');
    $id=max(0,(int)($_GET['id']??0));
    if(!in_array($type,['release','artist'],true)||$id<1)dt_desktop_media_json(['ok'=>false,'error'=>'Media object was not found.'],404);

    $media=$type==='release'
        ?dt_desktop_media_release($pdo,$user,$id)
        :dt_desktop_media_artist($pdo,$user,$id);
    if(!$media)dt_desktop_media_json(['ok'=>false,'error'=>'Media object was not found.'],404);
    dt_desktop_media_json(['ok'=>true,'schemaVersion'=>'desktop-media-v330','media'=>$media]);
}catch(Throwable $e){
    error_log('DaveTunes Desktop media hydrate failure: '.$e->getMessage());
    dt_desktop_media_json(['ok'=>false,'error'=>'Media object is temporarily unavailable.'],500);
}

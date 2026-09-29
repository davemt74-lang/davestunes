<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function dt_experience_api_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')dt_experience_api_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    $ownerType=(string)($_GET['owner_type']??'');
    $ownerId=(int)($_GET['owner_id']??0);
    $key=(string)($_GET['key']??'default');
    if(!dt_experience_public_allowed($pdo,$ownerType,$ownerId,$user))dt_experience_api_json(['ok'=>false,'error'=>'Experience was not found.'],404);
    $active=dt_experience_active($pdo,$ownerType,$ownerId,$key);
    if(!$active)dt_experience_api_json(['ok'=>false,'error'=>'Experience was not found.'],404);
    $etag='"'.(string)$active['sha256'].'"';
    header('ETag: '.$etag);
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Referrer-Policy: no-referrer');
    if($ownerType==='user'){
        header('Cache-Control: private, no-store');
        header('Vary: Cookie');
    }else{
        header('Cache-Control: public, max-age=30, stale-while-revalidate=120');
    }
    if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){
        http_response_code(304);
        exit;
    }
    dt_experience_api_json(['ok'=>true,'schemaVersion'=>'experience-v340']+$active);
}catch(RuntimeException $e){
    dt_experience_api_json(['ok'=>false,'error'=>$e->getMessage()],400);
}catch(Throwable $e){
    error_log('DaveTunes experience API failure: '.$e->getMessage());
    dt_experience_api_json(['ok'=>false,'error'=>'Experience is temporarily unavailable.'],500);
}

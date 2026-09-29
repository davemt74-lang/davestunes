<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');

function dt_experience_delivery_json(array $payload,int $status=200): never
{
    http_response_code($status);
    if($status!==304)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
        header('Allow: GET');
        dt_experience_delivery_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    }
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    $ownerType=dt_experience_owner((string)($_GET['owner_type']??''));
    $ownerId=(int)($_GET['owner_id']??0);
    $key=dt_experience_key((string)($_GET['key']??'default'));
    $version=(int)($_GET['version']??0);
    $expectedHash=strtolower(trim((string)($_GET['hash']??'')));

    if($ownerId<1||$version<1||!preg_match('/^[a-f0-9]{64}$/',$expectedHash)){
        dt_experience_delivery_json(['ok'=>false,'error'=>'Experience delivery identity is invalid.'],400);
    }
    if(!dt_experience_public_allowed($pdo,$ownerType,$ownerId,$user)){
        dt_experience_delivery_json(['ok'=>false,'error'=>'Experience was not found.'],404);
    }

    $published=dt_experience_published($pdo,$ownerType,$ownerId,$key,$version);
    if(!$published||!hash_equals((string)$published['sha256'],$expectedHash)){
        dt_experience_delivery_json(['ok'=>false,'error'=>'Experience version was not found.'],404);
    }

    $etag='"'.$published['sha256'].'"';
    header('ETag: '.$etag);
    if($ownerType==='user'){
        header('Cache-Control: private, no-store');
        header('Vary: Cookie');
    }else{
        header('Cache-Control: public, max-age=31536000, immutable');
    }
    if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){
        http_response_code(304);
        exit;
    }

    dt_experience_delivery_json([
        'ok'=>true,
        'schemaVersion'=>'experience-delivery-v340',
        'owner'=>['type'=>$ownerType,'id'=>$ownerId],
        'key'=>$key,
        'experienceId'=>(int)$published['experienceId'],
        'versionId'=>(int)$published['versionId'],
        'versionNumber'=>(int)$published['versionNumber'],
        'sha256'=>(string)$published['sha256'],
        'publishedAt'=>$published['publishedAt'],
        'manifest'=>$published['manifest'],
    ]);
}catch(RuntimeException $e){
    dt_experience_delivery_json(['ok'=>false,'error'=>$e->getMessage()],400);
}catch(Throwable $e){
    error_log('DaveTunes experience delivery failure: '.$e->getMessage());
    dt_experience_delivery_json(['ok'=>false,'error'=>'Experience delivery is temporarily unavailable.'],500);
}

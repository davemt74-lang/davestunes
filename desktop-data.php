<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function dt_desktop_data_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')dt_desktop_data_json(['ok'=>false,'error'=>'Method not allowed.'],405);

try{
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    if(!$user)dt_desktop_data_json(['ok'=>false,'error'=>'Authentication required.'],401);
    $payload=dt_desktop_data_payload($pdo,$user,(string)($_GET['view']??'home'),(string)($_GET['q']??''));
    dt_desktop_data_json(['ok'=>true]+$payload);
}catch(RuntimeException $e){
    dt_desktop_data_json(['ok'=>false,'error'=>$e->getMessage()],400);
}catch(Throwable $e){
    error_log('DaveTunes desktop data failure: '.$e->getMessage());
    dt_desktop_data_json(['ok'=>false,'error'=>'Desktop library is temporarily unavailable.'],500);
}

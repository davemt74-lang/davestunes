<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function dt_featured_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')dt_featured_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    if(!$user)dt_featured_json(['ok'=>false,'error'=>'Authentication required.'],401);
    dt_featured_json([
        'ok'=>true,
        'schemaVersion'=>'featured-v270',
        'items'=>dt_featured_desktop($pdo,$user,10),
    ]);
}catch(Throwable $e){
    error_log('DaveTunes featured content failure: '.$e->getMessage());
    dt_featured_json(['ok'=>false,'error'=>'Featured content is temporarily unavailable.'],500);
}

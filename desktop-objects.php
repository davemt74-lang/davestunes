<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function dt_desktop_object_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    if(!$user)dt_desktop_object_json(['ok'=>false,'error'=>'Authentication required.'],401);
    $userId=(int)$user['id'];

    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        dt_desktop_object_json([
            'ok'=>true,
            'schemaVersion'=>'desktop-objects-v220',
            'objects'=>dt_desktop_objects($pdo,$userId),
        ]);
    }

    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')dt_desktop_object_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    dt_verify_csrf();
    $action=(string)($_POST['action']??'');

    if($action==='create'){
        $object=dt_desktop_object_create($pdo,$userId,[
            'key'=>$_POST['key']??'',
            'type'=>$_POST['type']??'',
            'resource_type'=>$_POST['resource_type']??null,
            'resource_id'=>$_POST['resource_id']??null,
            'label'=>$_POST['label']??'',
            'x'=>$_POST['x']??0.5,
            'y'=>$_POST['y']??0.5,
            'rotation'=>$_POST['rotation']??0,
            'scale'=>$_POST['scale']??1,
            'z'=>$_POST['z']??1,
            'pinned'=>$_POST['pinned']??0,
            'payload'=>$_POST['payload']??null,
        ]);
        dt_desktop_object_json(['ok'=>true,'object'=>$object]);
    }

    if($action==='update'){
        $input=[];
        foreach(['x','y','rotation','scale','z','pinned','label','payload'] as $field){
            if(array_key_exists($field,$_POST))$input[$field]=$_POST[$field];
        }
        $object=dt_desktop_object_update(
            $pdo,$userId,(int)($_POST['id']??0),(int)($_POST['expected_revision']??0),$input
        );
        dt_desktop_object_json(['ok'=>true,'object'=>$object]);
    }

    if($action==='delete'){
        dt_desktop_object_delete(
            $pdo,$userId,(int)($_POST['id']??0),(int)($_POST['expected_revision']??0)
        );
        dt_desktop_object_json(['ok'=>true]);
    }

    if($action==='reset'){
        dt_desktop_object_json(['ok'=>true,'deleted'=>dt_desktop_objects_reset($pdo,$userId)]);
    }

    dt_desktop_object_json(['ok'=>false,'error'=>'Unknown Desktop object action.'],400);
}catch(RuntimeException $e){
    dt_desktop_object_json(['ok'=>false,'error'=>$e->getMessage()],400);
}catch(Throwable $e){
    error_log('DaveTunes Desktop object failure: '.$e->getMessage());
    dt_desktop_object_json(['ok'=>false,'error'=>'Desktop objects are temporarily unavailable.'],500);
}

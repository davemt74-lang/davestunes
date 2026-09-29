<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function dt_desktop_layout_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    if(!$user)dt_desktop_layout_json(['ok'=>false,'error'=>'Authentication required.'],401);
    $userId=(int)$user['id'];
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    $layoutKey=(string)($_GET['layout']??$_POST['layout_key']??'primary');

    if($method==='GET'){
        dt_desktop_layout_json(['ok'=>true]+dt_desktop_layout_state($pdo,$userId,$user,$layoutKey));
    }
    if($method!=='POST'){
        header('Allow: GET, POST');
        dt_desktop_layout_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    }

    dt_verify_csrf();
    $action=(string)($_POST['action']??'');

    if($action==='create'){
        $payload=json_decode((string)($_POST['payload_json']??'{}'),true);
        if(!is_array($payload))$payload=[];
        $result=dt_desktop_object_create($pdo,$userId,$user,$layoutKey,[
            'object_type'=>$_POST['object_type']??'',
            'resource_id'=>$_POST['resource_id']??null,
            'x'=>$_POST['x']??0.12,
            'y'=>$_POST['y']??0.16,
            'rotation'=>$_POST['rotation']??0,
            'scale'=>$_POST['scale']??1,
            'pinned'=>$_POST['pinned']??0,
            'payload'=>$payload,
            'expected_layout_revision'=>$_POST['expected_layout_revision']??null,
        ]);
        dt_desktop_layout_json(['ok'=>true]+$result);
    }

    if($action==='transform'){
        $result=dt_desktop_object_transform(
            $pdo,$userId,$user,(string)($_POST['object_uuid']??''),(int)($_POST['expected_version']??0),[
                'x'=>$_POST['x']??null,
                'y'=>$_POST['y']??null,
                'rotation'=>$_POST['rotation']??null,
                'scale'=>$_POST['scale']??null,
                'bring_to_front'=>$_POST['bring_to_front']??0,
            ]
        );
        dt_desktop_layout_json(['ok'=>true]+$result);
    }

    if($action==='set_lock'){
        $result=dt_desktop_object_set_lock(
            $pdo,$userId,$user,(string)($_POST['object_uuid']??''),(int)($_POST['expected_version']??0),
            !empty($_POST['locked'])
        );
        dt_desktop_layout_json(['ok'=>true]+$result);
    }

    if($action==='update_payload'){
        $payload=json_decode((string)($_POST['payload_json']??'{}'),true);
        if(!is_array($payload))$payload=[];
        $result=dt_desktop_object_update_payload(
            $pdo,$userId,$user,(string)($_POST['object_uuid']??''),(int)($_POST['expected_version']??0),$payload
        );
        dt_desktop_layout_json(['ok'=>true]+$result);
    }

    if($action==='remove'){
        $revision=dt_desktop_object_remove(
            $pdo,$userId,(string)($_POST['object_uuid']??''),(int)($_POST['expected_version']??0)
        );
        dt_desktop_layout_json(['ok'=>true,'layoutRevision'=>$revision]);
    }

    if($action==='reset'){
        $result=dt_desktop_layout_reset($pdo,$userId,$layoutKey,(int)($_POST['expected_layout_revision']??-1));
        dt_desktop_layout_json(['ok'=>true]+$result);
    }

    dt_desktop_layout_json(['ok'=>false,'error'=>'Unknown Desktop layout action.'],400);
}catch(DesktopObjectConflictException $e){
    dt_desktop_layout_json(['ok'=>false,'error'=>$e->getMessage(),'conflict'=>true],409);
}catch(RuntimeException $e){
    dt_desktop_layout_json(['ok'=>false,'error'=>$e->getMessage()],400);
}catch(Throwable $e){
    error_log('DaveTunes Desktop layout failure: '.$e->getMessage());
    dt_desktop_layout_json(['ok'=>false,'error'=>'Desktop layout service is temporarily unavailable.'],500);
}

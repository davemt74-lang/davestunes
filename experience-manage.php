<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function dt_experience_manage_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $pdo=dt_db();
    $user=dt_current_user($pdo);
    if(!$user)dt_experience_manage_json(['ok'=>false,'error'=>'Authentication required.'],401);
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')dt_experience_manage_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    dt_verify_csrf();
    $action=(string)($_POST['action']??'');

    if($action==='create'){
        $result=dt_experience_create($pdo,$user,(string)($_POST['owner_type']??''),(int)($_POST['owner_id']??0),(string)($_POST['name']??''),(string)($_POST['key']??'default'));
        dt_experience_manage_json(['ok'=>true]+$result);
    }
    if($action==='add-scene'){
        $scene=dt_experience_scene_add($pdo,(int)($_POST['version_id']??0),$user,[
            'scene_key'=>$_POST['scene_key']??'','title'=>$_POST['title']??'','sort_order'=>$_POST['sort_order']??0,
            'weight'=>$_POST['weight']??1,'is_enabled'=>($_POST['is_enabled']??'1')==='1','settings'=>$_POST['settings']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'scene'=>$scene]);
    }
    if($action==='add-layer'){
        $layer=dt_experience_layer_add($pdo,(int)($_POST['version_id']??0),$user,[
            'scene_key'=>$_POST['scene_key']??'','layer_key'=>$_POST['layer_key']??'','layer_type'=>$_POST['layer_type']??'',
            'sort_order'=>$_POST['sort_order']??0,'settings'=>$_POST['settings']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'layer'=>$layer]);
    }
    if($action==='add-node'){
        $node=dt_experience_node_add($pdo,(int)($_POST['version_id']??0),$user,[
            'node_key'=>$_POST['node_key']??'','node_type'=>$_POST['node_type']??'','scene_key'=>$_POST['scene_key']??'',
            'x'=>$_POST['x']??0,'y'=>$_POST['y']??0,'settings'=>$_POST['settings']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'node'=>$node]);
    }
    if($action==='add-edge'){
        $edge=dt_experience_edge_add($pdo,(int)($_POST['version_id']??0),$user,[
            'edge_key'=>$_POST['edge_key']??'','from_node_key'=>$_POST['from_node_key']??'','from_port'=>$_POST['from_port']??'out',
            'to_node_key'=>$_POST['to_node_key']??'','to_port'=>$_POST['to_port']??'in','condition'=>$_POST['condition']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'edge'=>$edge]);
    }
    if($action==='update-scene'){
        $scene=dt_experience_scene_update($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['scene_key']??''),[
            'title'=>$_POST['title']??null,'sort_order'=>$_POST['sort_order']??null,'weight'=>$_POST['weight']??null,
            'is_enabled'=>array_key_exists('is_enabled',$_POST)?($_POST['is_enabled']==='1'):null,'settings'=>$_POST['settings']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'scene'=>$scene]);
    }
    if($action==='delete-scene'){
        dt_experience_scene_delete($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['scene_key']??''));
        dt_experience_manage_json(['ok'=>true]);
    }
    if($action==='update-layer'){
        $layer=dt_experience_layer_update($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['scene_key']??''),(string)($_POST['layer_key']??''),[
            'layer_type'=>$_POST['layer_type']??null,'sort_order'=>$_POST['sort_order']??null,'settings'=>$_POST['settings']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'layer'=>$layer]);
    }
    if($action==='delete-layer'){
        dt_experience_layer_delete($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['scene_key']??''),(string)($_POST['layer_key']??''));
        dt_experience_manage_json(['ok'=>true]);
    }
    if($action==='update-node'){
        $node=dt_experience_node_update($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['node_key']??''),[
            'node_type'=>$_POST['node_type']??null,'scene_key'=>$_POST['scene_key']??null,'x'=>$_POST['x']??null,'y'=>$_POST['y']??null,'settings'=>$_POST['settings']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'node'=>$node]);
    }
    if($action==='delete-node'){
        dt_experience_node_delete($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['node_key']??''));
        dt_experience_manage_json(['ok'=>true]);
    }
    if($action==='update-edge'){
        $edge=dt_experience_edge_update($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['edge_key']??''),[
            'from_node_key'=>$_POST['from_node_key']??null,'from_port'=>$_POST['from_port']??null,
            'to_node_key'=>$_POST['to_node_key']??null,'to_port'=>$_POST['to_port']??null,'condition'=>$_POST['condition']??null
        ]);
        dt_experience_manage_json(['ok'=>true,'edge'=>$edge]);
    }
    if($action==='delete-edge'){
        dt_experience_edge_delete($pdo,(int)($_POST['version_id']??0),$user,(string)($_POST['edge_key']??''));
        dt_experience_manage_json(['ok'=>true]);
    }
    if($action==='publish'){
        dt_experience_manage_json(['ok'=>true]+dt_experience_publish($pdo,(int)($_POST['version_id']??0),$user));
    }
    if($action==='new-draft'){
        dt_experience_manage_json(['ok'=>true,'version'=>dt_experience_clone_draft($pdo,(int)($_POST['experience_id']??0),$user)]);
    }
    dt_experience_manage_json(['ok'=>false,'error'=>'Unknown experience action.'],400);
}catch(RuntimeException $e){
    dt_experience_manage_json(['ok'=>false,'error'=>$e->getMessage()],400);
}catch(Throwable $e){
    error_log('DaveTunes experience manage failure: '.$e->getMessage());
    dt_experience_manage_json(['ok'=>false,'error'=>'Experience changes are temporarily unavailable.'],500);
}

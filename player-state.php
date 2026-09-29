<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dt_player_json(mixed $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $pdo=dt_db();
    $user=dt_require_user($pdo);
    $userId=(int)$user['id'];
    $sessionKey=(string)($_GET['session']??$_POST['session_key']??'primary');

    if($_SERVER['REQUEST_METHOD']==='GET'){
        $session=dt_playback_session($pdo,$userId,$sessionKey);
        dt_player_json([
            'ok'=>true,
            'session'=>$session,
            'queue'=>dt_playback_queue($pdo,(int)$session['id']),
            'current'=>$session['current_recording_id']?dt_playback_recording_payload($pdo,(int)$session['current_recording_id'],$user):null,
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST')dt_player_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    dt_verify_csrf();
    $action=(string)($_POST['action']??'');

    if($action==='state'){
        $session=dt_playback_update_state($pdo,$userId,$sessionKey,[
            'recording_id'=>$_POST['recording_id']??null,
            'playback_state'=>$_POST['playback_state']??null,
            'position_ms'=>$_POST['position_ms']??null,
            'volume'=>$_POST['volume']??null,
            'repeat_mode'=>$_POST['repeat_mode']??null,
            'shuffle_enabled'=>$_POST['shuffle_enabled']??0,
        ]);
        dt_player_json(['ok'=>true,'session'=>$session,'current'=>$session['current_recording_id']?dt_playback_recording_payload($pdo,(int)$session['current_recording_id'],$user):null]);
    }

    if($action==='queue'){
        $ids=json_decode((string)($_POST['recording_ids']??'[]'),true);
        if(!is_array($ids))throw new RuntimeException('Queue payload is invalid.');
        $queue=dt_playback_replace_queue($pdo,$userId,$sessionKey,$ids,(string)($_POST['source_type']??''),isset($_POST['source_id'])?(int)$_POST['source_id']:null);
        $session=dt_playback_session($pdo,$userId,$sessionKey);
        dt_player_json(['ok'=>true,'session'=>$session,'queue'=>$queue]);
    }

    if($action==='begin_listen'){
        $listen=dt_playback_begin_listen(
            $pdo,$userId,$sessionKey,(int)($_POST['recording_id']??0),(string)($_POST['play_token']??''),
            (string)($_POST['source_type']??''),isset($_POST['source_id'])?(int)$_POST['source_id']:null
        );
        dt_player_json(['ok'=>true,'listen'=>$listen,'current'=>dt_playback_recording_payload($pdo,(int)$listen['recording_id'],$user)]);
    }

    if($action==='heartbeat'){
        $listen=dt_playback_heartbeat(
            $pdo,$userId,(string)($_POST['play_token']??''),(int)($_POST['position_ms']??0),
            (int)($_POST['listened_delta_ms']??0),!empty($_POST['completed'])
        );
        dt_player_json(['ok'=>true,'listen'=>$listen]);
    }

    dt_player_json(['ok'=>false,'error'=>'Unknown player action.'],400);
}catch(Throwable $e){
    dt_player_json(['ok'=>false,'error'=>$e->getMessage()],400);
}

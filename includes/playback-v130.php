<?php
declare(strict_types=1);

function dt_playback_media_root(): string
{
    $root=(string)dt_config('storage.media_root','');
    if($root==='')throw new RuntimeException('Playback media storage is not configured.');
    return rtrim($root,DIRECTORY_SEPARATOR);
}

function dt_playback_media_roles(): array
{
    return ['full','preview'];
}

function dt_playback_allowed_mimes(): array
{
    return [
        'audio/mpeg'=>'mp3',
        'audio/mp4'=>'m4a',
        'audio/x-m4a'=>'m4a',
        'audio/aac'=>'aac',
        'audio/ogg'=>'ogg',
        'audio/wav'=>'wav',
        'audio/x-wav'=>'wav',
        'audio/flac'=>'flac',
    ];
}

function dt_playback_assert_storage_key(string $key): string
{
    $key=str_replace('\\','/',trim($key));
    if($key===''||str_starts_with($key,'/'))throw new RuntimeException('Media storage key is invalid.');
    if(!preg_match('#^[A-Za-z0-9/_\.\-]+$#',$key))throw new RuntimeException('Media storage key contains unsupported characters.');
    foreach(explode('/',$key) as $segment){
        if($segment===''||$segment==='.'||$segment==='..')throw new RuntimeException('Media storage key is invalid.');
    }
    return $key;
}

function dt_playback_storage_path(string $key): string
{
    $key=dt_playback_assert_storage_key($key);
    $root=dt_playback_media_root();
    return $root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$key);
}

function dt_playback_store_upload(PDO $pdo,int $artistId,int $recordingId,array $user,array $upload,string $role,int $previewStartMs=0,int $previewEndMs=0): array
{
    dt_catalog_require_artist($pdo,$artistId,$user,'media');
    if(!dt_catalog_recording($pdo,$artistId,$recordingId))throw new RuntimeException('Recording was not found.');
    if(!in_array($role,dt_playback_media_roles(),true))throw new RuntimeException('Media role must be full or preview.');
    if((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Audio upload did not complete.');
    $tmp=(string)($upload['tmp_name']??'');
    if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Audio upload could not be verified.');
    $bytes=(int)($upload['size']??0);
    $max=max(1048576,(int)dt_config('storage.max_upload_bytes',536870912));
    if($bytes<1||$bytes>$max)throw new RuntimeException('Audio upload exceeds the configured size limit.');

    $finfo=new finfo(FILEINFO_MIME_TYPE);
    $mime=(string)$finfo->file($tmp);
    $allowed=dt_playback_allowed_mimes();
    if(!isset($allowed[$mime]))throw new RuntimeException('Unsupported audio file type.');
    $extension=$allowed[$mime];
    $key='artist-'.$artistId.'/recording-'.$recordingId.'/'.$role.'-'.bin2hex(random_bytes(16)).'.'.$extension;
    $path=dt_playback_storage_path($key);
    $dir=dirname($path);
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Protected media directory could not be created.');
    if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Audio upload could not be moved into protected storage.');
    @chmod($path,0660);

    try{
        return dt_playback_register_media($pdo,$artistId,$recordingId,$user,[
            'media_role'=>$role,
            'storage_driver'=>'local',
            'storage_key'=>$key,
            'mime_type'=>$mime,
            'byte_size'=>(int)filesize($path),
            'sha256'=>(string)hash_file('sha256',$path),
            'preview_start_ms'=>$previewStartMs,
            'preview_end_ms'=>$previewEndMs,
        ]);
    }catch(Throwable $e){
        @unlink($path);
        throw $e;
    }
}

function dt_playback_media_asset(PDO $pdo,int $assetId): ?array
{
    if($assetId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM recording_media_v130 WHERE id=? LIMIT 1');
    $stmt->execute([$assetId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_playback_active_media(PDO $pdo,int $recordingId,string $role): ?array
{
    if($recordingId<1||!in_array($role,dt_playback_media_roles(),true))return null;
    $stmt=$pdo->prepare("SELECT * FROM recording_media_v130
        WHERE recording_id=? AND media_role=? AND asset_status='active'
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$recordingId,$role]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_playback_register_media(PDO $pdo,int $artistId,int $recordingId,array $user,array $media): array
{
    dt_catalog_require_artist($pdo,$artistId,$user,'media');
    $recording=dt_catalog_recording($pdo,$artistId,$recordingId);
    if(!$recording)throw new RuntimeException('Recording was not found.');

    $role=(string)($media['media_role']??'');
    if(!in_array($role,dt_playback_media_roles(),true))throw new RuntimeException('Media role must be full or preview.');
    $driver=(string)($media['storage_driver']??'local');
    if($driver!=='local')throw new RuntimeException('This playback runtime currently supports local protected media storage only.');
    $key=dt_playback_assert_storage_key((string)($media['storage_key']??''));
    $mime=(string)($media['mime_type']??'');
    if(!isset(dt_playback_allowed_mimes()[$mime]))throw new RuntimeException('Unsupported audio MIME type.');
    $bytes=max(0,(int)($media['byte_size']??0));
    if($bytes<1)throw new RuntimeException('Audio file size is invalid.');
    $sha=strtolower(trim((string)($media['sha256']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$sha))throw new RuntimeException('Audio SHA-256 is invalid.');
    $duration=max(0,(int)($media['duration_ms']??0));
    $duration=$duration>0?$duration:null;
    $previewStart=max(0,(int)($media['preview_start_ms']??0));
    $previewEnd=max(0,(int)($media['preview_end_ms']??0));
    if($role==='preview'&&$previewEnd>0&&$previewEnd<=$previewStart)throw new RuntimeException('Preview end must be after preview start.');

    $path=dt_playback_storage_path($key);
    if(!is_file($path))throw new RuntimeException('Protected media file was not found.');
    if((int)filesize($path)!==$bytes)throw new RuntimeException('Protected media byte size does not match registration.');
    if(strtolower((string)hash_file('sha256',$path))!==$sha)throw new RuntimeException('Protected media checksum does not match registration.');

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM music_recordings_v110 WHERE id=? AND artist_id=? FOR UPDATE');
        $lock->execute([$recordingId,$artistId]);
        if(!$lock->fetchColumn())throw new RuntimeException('Recording was not found.');

        $pdo->prepare("UPDATE recording_media_v130 SET asset_status='superseded',updated_at=NOW()
            WHERE recording_id=? AND media_role=? AND asset_status='active'")->execute([$recordingId,$role]);

        $stmt=$pdo->prepare("INSERT INTO recording_media_v130
            (recording_id,uploaded_by_user_id,media_role,storage_driver,storage_key,mime_type,byte_size,sha256,duration_ms,preview_start_ms,preview_end_ms,asset_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'active')");
        $stmt->execute([
            $recordingId,(int)$user['id'],$role,$driver,$key,$mime,$bytes,$sha,$duration,
            $role==='preview'?$previewStart:null,$role==='preview'&&$previewEnd>0?$previewEnd:null,
        ]);
        $id=(int)$pdo->lastInsertId();
        dt_catalog_event($pdo,$artistId,'recording_media',$id,'recording.media_registered',(int)$user['id'],[
            'recording_id'=>$recordingId,'media_role'=>$role,'sha256'=>$sha,
        ]);
        if($ownsTransaction)$pdo->commit();
        return dt_playback_media_asset($pdo,$id)??throw new RuntimeException('Media asset could not be loaded.');
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_playback_access_mode(PDO $pdo,int $recordingId,?array $user=null): string
{
    $stmt=$pdo->prepare("SELECT r.*,a.artist_status FROM music_recordings_v110 r
        INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
    $stmt->execute([$recordingId]);
    $recording=$stmt->fetch();
    if(!$recording||(string)$recording['recording_status']!=='active'||(string)$recording['artist_status']==='archived')return 'none';

    if($user){
        $userId=(int)($user['id']??0);
        if($userId>0&&dt_artist_can($pdo,(int)$recording['artist_id'],$userId,'view'))return 'full';
        if($userId>0&&dt_entitlement_user_has_recording($pdo,$userId,$recordingId))return 'full';
    }
    return dt_library_recording_is_public($pdo,$recordingId)?'preview':'none';
}

function dt_playback_select_media(PDO $pdo,int $recordingId,?array $user=null): ?array
{
    $mode=dt_playback_access_mode($pdo,$recordingId,$user);
    if($mode==='none')return null;
    if($mode==='full'){
        $full=dt_playback_active_media($pdo,$recordingId,'full');
        if($full)return ['access_mode'=>'full','asset'=>$full];
    }
    $preview=dt_playback_active_media($pdo,$recordingId,'preview');
    return $preview?['access_mode'=>'preview','asset'=>$preview]:null;
}

function dt_playback_recording_payload(PDO $pdo,int $recordingId,?array $user=null): ?array
{
    $stmt=$pdo->prepare("SELECT r.id,r.artist_id,r.title,r.version_label,r.duration_ms,a.name artist_name,
        (
            SELECT rel.cover_path
            FROM music_release_tracks_v110 rt INNER JOIN music_releases_v110 rel ON rel.id=rt.release_id
            WHERE rt.recording_id=r.id AND rel.release_status='published'
            ORDER BY rel.release_date DESC,rel.id DESC LIMIT 1
        ) cover_path
        FROM music_recordings_v110 r INNER JOIN artists a ON a.id=r.artist_id
        WHERE r.id=? AND r.recording_status='active' LIMIT 1");
    $stmt->execute([$recordingId]);
    $row=$stmt->fetch();
    if(!$row)return null;
    $selected=dt_playback_select_media($pdo,$recordingId,$user);
    $row['access_mode']=$selected['access_mode']??'none';
    $row['stream_url']=$selected?'/media.php?recording='.$recordingId.'&asset='.(int)$selected['asset']['id']:'';
    return $row;
}

function dt_playback_event(PDO $pdo,int $userId,?int $sessionId,?int $recordingId,string $eventType,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO playback_events_v130 (user_id,session_id,recording_id,event_type,metadata_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$userId,$sessionId?:null,$recordingId?:null,substr(trim($eventType),0,80),$json]);
}

function dt_playback_normalize_session_key(string $key): string
{
    $key=trim($key);
    if($key===''||strlen($key)>80||!preg_match('/^[A-Za-z0-9._:-]+$/',$key))throw new RuntimeException('Player session key is invalid.');
    return $key;
}

function dt_playback_session(PDO $pdo,int $userId,string $sessionKey='primary'): array
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $sessionKey=dt_playback_normalize_session_key($sessionKey);
    $stmt=$pdo->prepare('SELECT * FROM playback_sessions_v130 WHERE user_id=? AND session_key=? LIMIT 1');
    $stmt->execute([$userId,$sessionKey]);
    $row=$stmt->fetch();
    if($row)return $row;

    try{
        $pdo->prepare("INSERT INTO playback_sessions_v130 (user_id,session_key) VALUES (?,?)")->execute([$userId,$sessionKey]);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
    }
    $stmt->execute([$userId,$sessionKey]);
    $row=$stmt->fetch();
    return $row?:throw new RuntimeException('Player session could not be created.');
}

function dt_playback_queue(PDO $pdo,int $sessionId): array
{
    $stmt=$pdo->prepare("SELECT q.*,r.title,r.version_label,a.name artist_name
        FROM playback_queue_items_v130 q
        INNER JOIN music_recordings_v110 r ON r.id=q.recording_id
        INNER JOIN artists a ON a.id=r.artist_id
        WHERE q.session_id=? ORDER BY q.queue_position,q.id");
    $stmt->execute([$sessionId]);
    return $stmt->fetchAll()?:[];
}

function dt_playback_can_queue(PDO $pdo,int $userId,int $recordingId): bool
{
    $user=dt_auth_user_by_id($pdo,$userId);
    if(!$user)return false;
    return dt_playback_select_media($pdo,$recordingId,$user)!==null;
}

function dt_playback_replace_queue(PDO $pdo,int $userId,string $sessionKey,array $recordingIds,string $sourceType='',?int $sourceId=null,?int $expectedRevision=null): array
{
    $sourceType=substr(trim($sourceType),0,30);
    $session=dt_playback_session($pdo,$userId,$sessionKey);
    $clean=[];
    foreach($recordingIds as $id){
        $id=(int)$id;
        if($id<1)continue;
        if(!dt_playback_can_queue($pdo,$userId,$id))throw new RuntimeException('Queue contains music that is not available to this user.');
        if(!in_array($id,$clean,true))$clean[]=$id;
        if(count($clean)>=500)break;
    }

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id,queue_revision FROM playback_sessions_v130 WHERE id=? AND user_id=? FOR UPDATE');
        $lock->execute([(int)$session['id'],$userId]);
        $locked=$lock->fetch();
        if(!$locked)throw new RuntimeException('Player session was not found.');
        if($expectedRevision!==null&&(int)$locked['queue_revision']!==$expectedRevision)throw new RuntimeException('Player queue changed on another surface. Refresh before replacing it.');
        $pdo->prepare('DELETE FROM playback_queue_items_v130 WHERE session_id=?')->execute([(int)$session['id']]);
        $insert=$pdo->prepare('INSERT INTO playback_queue_items_v130 (session_id,recording_id,queue_position,source_type,source_id) VALUES (?,?,?,?,?)');
        foreach($clean as $position=>$recordingId)$insert->execute([(int)$session['id'],$recordingId,$position,$sourceType,$sourceId]);
        $pdo->prepare("UPDATE playback_sessions_v130
            SET queue_revision=queue_revision+1,last_source_type=?,last_source_id=?,updated_at=NOW() WHERE id=?")
            ->execute([substr($sourceType,0,30),$sourceId,(int)$session['id']]);
        dt_playback_event($pdo,$userId,(int)$session['id'],null,'queue.replaced',['count'=>count($clean),'source_type'=>$sourceType,'source_id'=>$sourceId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return dt_playback_queue($pdo,(int)$session['id']);
}

function dt_playback_update_state(PDO $pdo,int $userId,string $sessionKey,array $state): array
{
    $session=dt_playback_session($pdo,$userId,$sessionKey);
    $hasRecording=array_key_exists('recording_id',$state)&&$state['recording_id']!==null&&$state['recording_id']!=='';
    $recordingId=$hasRecording?max(0,(int)$state['recording_id']):(int)($session['current_recording_id']??0);
    if($recordingId>0&&!dt_playback_can_queue($pdo,$userId,$recordingId))throw new RuntimeException('Recording is not available for playback.');

    $playbackState=(string)($state['playback_state']??$session['playback_state']??'paused');
    if(!in_array($playbackState,['playing','paused','stopped'],true))throw new RuntimeException('Playback state is invalid.');
    $position=max(0,(int)($state['position_ms']??$session['position_ms']??0));
    $volume=(float)($state['volume']??$session['volume']??1);
    $volume=max(0,min(1,$volume));
    $repeat=(string)($state['repeat_mode']??$session['repeat_mode']??'off');
    if(!in_array($repeat,['off','one','all'],true))throw new RuntimeException('Repeat mode is invalid.');
    $shuffle=array_key_exists('shuffle_enabled',$state)?(!empty($state['shuffle_enabled'])?1:0):(int)($session['shuffle_enabled']??0);

    $stmt=$pdo->prepare("UPDATE playback_sessions_v130 SET
        current_recording_id=?,playback_state=?,position_ms=?,volume=?,repeat_mode=?,shuffle_enabled=?,updated_at=NOW()
        WHERE id=? AND user_id=?");
    $stmt->execute([$recordingId?:null,$playbackState,$position,$volume,$repeat,$shuffle,(int)$session['id'],$userId]);
    dt_playback_event($pdo,$userId,(int)$session['id'],$recordingId?:null,'player.state',[
        'playback_state'=>$playbackState,'position_ms'=>$position,'volume'=>$volume,'repeat_mode'=>$repeat,'shuffle_enabled'=>$shuffle,
    ]);

    $fresh=$pdo->prepare('SELECT * FROM playback_sessions_v130 WHERE id=?');
    $fresh->execute([(int)$session['id']]);
    return $fresh->fetch()?:throw new RuntimeException('Player state could not be loaded.');
}

function dt_playback_begin_listen(PDO $pdo,int $userId,string $sessionKey,int $recordingId,string $playToken,string $sourceType='',?int $sourceId=null): array
{
    $playToken=strtolower(trim($playToken));
    $sourceType=substr(trim($sourceType),0,30);
    if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',$playToken))throw new RuntimeException('Play token must be a UUID v4.');
    $user=dt_auth_user_by_id($pdo,$userId);
    if(!$user)throw new RuntimeException('User was not found.');
    $selected=dt_playback_select_media($pdo,$recordingId,$user);
    if(!$selected)throw new RuntimeException('Recording is not available for playback.');
    $mode=(string)$selected['access_mode'];
    $session=dt_playback_session($pdo,$userId,$sessionKey);

    $stmt=$pdo->prepare('SELECT * FROM playback_listens_v130 WHERE play_token=? LIMIT 1');
    $stmt->execute([$playToken]);
    $existing=$stmt->fetch();
    if($existing){
        if(
            (int)$existing['user_id']!==$userId
            ||(int)$existing['recording_id']!==$recordingId
            ||(int)$existing['session_id']!==(int)$session['id']
            ||(string)$existing['source_type']!==$sourceType
            ||(int)($existing['source_id']??0)!==(int)($sourceId??0)
        ){
            throw new RuntimeException('Play token conflicts with an existing listen.');
        }
        return $existing;
    }

    $insert=$pdo->prepare("INSERT INTO playback_listens_v130
        (play_token,user_id,session_id,recording_id,access_mode,source_type,source_id)
        VALUES (?,?,?,?,?,?,?)");
    $insert->execute([$playToken,$userId,(int)$session['id'],$recordingId,$mode,$sourceType,$sourceId]);
    $id=(int)$pdo->lastInsertId();
    dt_playback_event($pdo,$userId,(int)$session['id'],$recordingId,'listen.started',['play_token'=>$playToken,'access_mode'=>$mode]);
    $stmt=$pdo->prepare('SELECT * FROM playback_listens_v130 WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch()?:throw new RuntimeException('Listen could not be loaded.');
}

function dt_playback_heartbeat(PDO $pdo,int $userId,string $playToken,int $positionMs,int $listenedDeltaMs,bool $completed=false): array
{
    $stmt=$pdo->prepare('SELECT * FROM playback_listens_v130 WHERE play_token=? AND user_id=? LIMIT 1');
    $stmt->execute([strtolower(trim($playToken)),$userId]);
    $listen=$stmt->fetch();
    if(!$listen)throw new RuntimeException('Listen was not found.');
    if(!empty($listen['completed_at']))return $listen;
    $position=max(0,$positionMs);
    $updatedAt=strtotime((string)$listen['updated_at'])?:time();
    $elapsedMs=max(0,(time()-$updatedAt)*1000);
    $creditCap=min(120000,$elapsedMs+5000);
    $delta=max(0,min($creditCap,$listenedDeltaMs));
    $completeAt=$completed?'NOW()':'completed_at';
    $sql="UPDATE playback_listens_v130 SET last_position_ms=?,listened_ms=listened_ms+?,completed_at={$completeAt},updated_at=NOW() WHERE id=?";
    $pdo->prepare($sql)->execute([$position,$delta,(int)$listen['id']]);
    if($completed)dt_playback_event($pdo,$userId,(int)$listen['session_id'],(int)$listen['recording_id'],'listen.completed',['play_token'=>$playToken,'position_ms'=>$position]);
    $stmt=$pdo->prepare('SELECT * FROM playback_listens_v130 WHERE id=?');
    $stmt->execute([(int)$listen['id']]);
    return $stmt->fetch()?:throw new RuntimeException('Listen could not be loaded.');
}

function dt_playback_recent_history(PDO $pdo,int $userId,int $limit=50): array
{
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT l.*,r.title,r.version_label,a.name artist_name
        FROM playback_listens_v130 l
        INNER JOIN music_recordings_v110 r ON r.id=l.recording_id
        INNER JOIN artists a ON a.id=r.artist_id
        WHERE l.user_id=? ORDER BY l.started_at DESC,l.id DESC LIMIT ".$limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

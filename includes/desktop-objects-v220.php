<?php
declare(strict_types=1);

class DesktopObjectConflictException extends RuntimeException {}

function dt_desktop_object_types(): array
{
    return ['release','recording','artist','crate','playlist','note','photo'];
}

function dt_desktop_layout_key(string $key): string
{
    $key=trim($key);
    if($key===''||strlen($key)>80||!preg_match('/^[A-Za-z0-9._:-]+$/',$key))throw new RuntimeException('Desktop layout key is invalid.');
    return $key;
}

function dt_desktop_object_uuid(): string
{
    $bytes=random_bytes(16);
    $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
    $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    $hex=bin2hex($bytes);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}

function dt_desktop_ratio(mixed $value,float $default=0.1): float
{
    return round(max(0,min(0.92,is_numeric($value)?(float)$value:$default)),5);
}

function dt_desktop_rotation(mixed $value,float $default=0): float
{
    return round(max(-180,min(180,is_numeric($value)?(float)$value:$default)),3);
}

function dt_desktop_scale(mixed $value,float $default=1): float
{
    return round(max(0.55,min(1.8,is_numeric($value)?(float)$value:$default)),4);
}

function dt_desktop_object_payload(string $type,mixed $payload): array
{
    if(is_string($payload)){
        $decoded=json_decode($payload,true);
        $payload=is_array($decoded)?$decoded:[];
    }
    if(!is_array($payload))$payload=[];

    if($type==='note'){
        $color=(string)($payload['color']??'yellow');
        if(!in_array($color,['yellow','pink','blue','green','white'],true))$color='yellow';
        return [
            'text'=>mb_substr(trim((string)($payload['text']??'New note')),0,4000),
            'color'=>$color,
        ];
    }
    if($type==='photo'){
        $imageUrl=dt_desktop_data_cover_url((string)($payload['imageUrl']??''));
        if($imageUrl==='')throw new RuntimeException('Photo objects require a safe local image URL.');
        return [
            'imageUrl'=>$imageUrl,
            'caption'=>mb_substr(trim((string)($payload['caption']??'')),0,500),
        ];
    }
    // Canonical resource metadata is never copied into layout persistence.
    return [];
}

function dt_desktop_layout(PDO $pdo,int $userId,string $layoutKey='primary'): array
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $layoutKey=dt_desktop_layout_key($layoutKey);
    $stmt=$pdo->prepare('SELECT * FROM desktop_layouts_v220 WHERE user_id=? AND layout_key=? LIMIT 1');
    $stmt->execute([$userId,$layoutKey]);
    $row=$stmt->fetch();
    if($row)return $row;
    try{
        $pdo->prepare("INSERT INTO desktop_layouts_v220 (user_id,layout_key,template_id) VALUES (?,?,'midnight-desk')")
            ->execute([$userId,$layoutKey]);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
    }
    $stmt->execute([$userId,$layoutKey]);
    return $stmt->fetch()?:throw new RuntimeException('Desktop layout could not be created.');
}

function dt_desktop_layout_event(PDO $pdo,int $layoutId,string $eventType,int $actorUserId,?string $objectUuid=null,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO desktop_layout_events_v220 (layout_id,object_uuid,event_type,actor_user_id,metadata_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$layoutId,$objectUuid,substr(trim($eventType),0,80),$actorUserId,$json]);
}

function dt_desktop_object_resource_allowed(PDO $pdo,int $userId,array $user,string $type,?int $resourceId): bool
{
    if(in_array($type,['note','photo'],true))return $resourceId===null;
    if(!$resourceId||$resourceId<1)return false;

    if($type==='release'){
        $stmt=$pdo->prepare('SELECT artist_id FROM music_releases_v110 WHERE id=? LIMIT 1');
        $stmt->execute([$resourceId]);
        $artistId=(int)$stmt->fetchColumn();
        if($artistId<1)return false;
        return dt_library_release_is_public($pdo,$resourceId)
            ||dt_entitlement_user_has_release($pdo,$userId,$resourceId)
            ||dt_artist_can($pdo,$artistId,$userId,'view');
    }
    if($type==='recording'){
        $stmt=$pdo->prepare('SELECT artist_id FROM music_recordings_v110 WHERE id=? LIMIT 1');
        $stmt->execute([$resourceId]);
        $artistId=(int)$stmt->fetchColumn();
        if($artistId<1)return false;
        return dt_library_recording_is_public($pdo,$resourceId)
            ||dt_entitlement_user_has_recording($pdo,$userId,$resourceId)
            ||dt_artist_can($pdo,$artistId,$userId,'view');
    }
    if($type==='artist'){
        $artist=dt_artist_by_id($pdo,$resourceId);
        return (bool)$artist&&(string)$artist['artist_status']!=='archived';
    }
    if($type==='crate')return dt_library_crate($pdo,$userId,$resourceId)!==null;
    if($type==='playlist')return dt_library_playlist($pdo,$userId,$resourceId)!==null;
    return false;
}

function dt_desktop_object_resource_dto(PDO $pdo,int $userId,array $user,string $type,?int $resourceId): ?array
{
    if(!$resourceId||!dt_desktop_object_resource_allowed($pdo,$userId,$user,$type,$resourceId))return null;

    if($type==='release'){
        $map=dt_desktop_data_release_map($pdo,$userId,$user,false);
        if(isset($map[$resourceId]))return $map[$resourceId];
        $stmt=$pdo->prepare("SELECT r.*,a.name artist_name,a.slug artist_slug FROM music_releases_v110 r
            INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
        $stmt->execute([$resourceId]);
        $release=$stmt->fetch();
        if(!$release)return null;
        $state=dt_library_release_is_public($pdo,$resourceId)?'public':
            (dt_entitlement_user_has_release($pdo,$userId,$resourceId)?'available':'managed');
        return dt_desktop_data_release_dto($pdo,$userId,$user,$release,$state,false);
    }
    if($type==='recording'){
        $map=dt_desktop_data_song_map($pdo,$userId,$user);
        if(isset($map[$resourceId]))return $map[$resourceId];
        $stmt=$pdo->prepare("SELECT r.*,a.name artist_name FROM music_recordings_v110 r
            INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
        $stmt->execute([$resourceId]);
        $recording=$stmt->fetch();
        if(!$recording)return null;
        $state=dt_library_recording_is_public($pdo,$resourceId)?'public':
            (dt_entitlement_user_has_recording($pdo,$userId,$resourceId)?'available':'managed');
        return dt_desktop_data_song_dto($pdo,$userId,$user,$recording,$state,false);
    }
    if($type==='artist'){
        $artist=dt_artist_by_id($pdo,$resourceId);
        if(!$artist)return null;
        return [
            'id'=>(int)$artist['id'],'type'=>'artist','name'=>(string)$artist['name'],'slug'=>(string)$artist['slug'],
            'location'=>(string)$artist['location'],'profileImageUrl'=>dt_desktop_data_cover_url((string)$artist['profile_image_path']),
        ];
    }
    if($type==='crate'){
        static $crateCache=[];
        if(!isset($crateCache[$userId])){
            $crateCache[$userId]=[];
            foreach(dt_desktop_data_crates($pdo,$userId,$user,'',100) as $row)$crateCache[$userId][(int)$row['id']]=$row;
        }
        return $crateCache[$userId][$resourceId]??null;
    }
    if($type==='playlist'){
        static $playlistCache=[];
        if(!isset($playlistCache[$userId])){
            $playlistCache[$userId]=[];
            foreach(dt_desktop_data_playlists($pdo,$userId,$user,'',100) as $row)$playlistCache[$userId][(int)$row['id']]=$row;
        }
        return $playlistCache[$userId][$resourceId]??null;
    }
    return null;
}

function dt_desktop_object_row(PDO $pdo,int $userId,string $uuid,bool $forUpdate=false): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$uuid))return null;
    $sql="SELECT o.*,l.user_id,l.layout_key,l.revision layout_revision
        FROM desktop_objects_v220 o INNER JOIN desktop_layouts_v220 l ON l.id=o.layout_id
        WHERE o.object_uuid=? AND l.user_id=? LIMIT 1";
    if($forUpdate)$sql.=' FOR UPDATE';
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$uuid,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_desktop_object_dto(PDO $pdo,int $userId,array $user,array $row): array
{
    $type=(string)$row['object_type'];
    $payload=json_decode((string)($row['payload_json']??'{}'),true);
    if(!is_array($payload))$payload=[];
    $resourceId=$row['resource_id']!==null?(int)$row['resource_id']:null;
    $resource=in_array($type,['note','photo'],true)?null:dt_desktop_object_resource_dto($pdo,$userId,$user,$type,$resourceId);
    return [
        'uuid'=>(string)$row['object_uuid'],
        'type'=>$type,
        'resourceId'=>$resourceId,
        'resource'=>$resource,
        'unavailable'=>!in_array($type,['note','photo'],true)&&$resource===null,
        'x'=>(float)$row['x_ratio'],
        'y'=>(float)$row['y_ratio'],
        'rotation'=>(float)$row['rotation_deg'],
        'scale'=>(float)$row['scale_factor'],
        'z'=>(int)$row['z_order'],
        'locked'=>(bool)$row['is_locked'],
        'pinned'=>(bool)$row['is_pinned'],
        'version'=>(int)$row['object_version'],
        'payload'=>$payload,
    ];
}

function dt_desktop_seed_position(int $userId,int $resourceId): array
{
    $hash=hash('sha256',$userId.':'.$resourceId);
    $a=hexdec(substr($hash,0,6));
    $b=hexdec(substr($hash,6,6));
    $c=hexdec(substr($hash,12,6));
    return [
        'x'=>round(0.025+(($a%6500)/10000),5),
        'y'=>round(0.08+(($b%6500)/10000),5),
        'rotation'=>round(-11+(($c%2200)/100),3),
        'scale'=>round(0.88+(($a%2100)/10000),4),
    ];
}

function dt_desktop_seed_owned_releases(PDO $pdo,int $userId,array $user,string $layoutKey='primary'): int
{
    $layout=dt_desktop_layout($pdo,$userId,$layoutKey);
    $owned=dt_library_owned_releases($pdo,$userId);
    if(!$owned)return 0;

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT * FROM desktop_layouts_v220 WHERE id=? AND user_id=? FOR UPDATE');
        $lock->execute([(int)$layout['id'],$userId]);
        $locked=$lock->fetch();
        if(!$locked)throw new RuntimeException('Desktop layout was not found.');

        $seenStmt=$pdo->prepare("SELECT resource_id FROM desktop_objects_v220
            WHERE layout_id=? AND object_type='release' AND resource_id IS NOT NULL");
        $seenStmt->execute([(int)$layout['id']]);
        $seen=array_fill_keys(array_map('intval',$seenStmt->fetchAll(PDO::FETCH_COLUMN)?:[]),true);

        $maxStmt=$pdo->prepare('SELECT COALESCE(MAX(z_order),0) FROM desktop_objects_v220 WHERE layout_id=?');
        $maxStmt->execute([(int)$layout['id']]);
        $z=(int)$maxStmt->fetchColumn();
        $insert=$pdo->prepare("INSERT INTO desktop_objects_v220
            (layout_id,object_uuid,object_type,resource_id,x_ratio,y_ratio,rotation_deg,scale_factor,z_order,is_pinned,payload_json)
            VALUES (?,?,?,?,?,?,?,?,?,1,?)");
        $created=0;
        foreach($owned as $release){
            $releaseId=(int)$release['id'];
            if(isset($seen[$releaseId]))continue;
            $position=dt_desktop_seed_position($userId,$releaseId);
            $uuid=dt_desktop_object_uuid();
            $z++;
            $insert->execute([
                (int)$layout['id'],$uuid,'release',$releaseId,$position['x'],$position['y'],
                $position['rotation'],$position['scale'],$z,'{"source":"auto-owned"}',
            ]);
            dt_desktop_layout_event($pdo,(int)$layout['id'],'object.auto_seeded',$userId,$uuid,['type'=>'release','resource_id'=>$releaseId]);
            $seen[$releaseId]=true;
            $created++;
        }
        if($created>0)$pdo->prepare('UPDATE desktop_layouts_v220 SET revision=revision+?,updated_at=NOW() WHERE id=?')->execute([$created,(int)$layout['id']]);
        if($ownsTransaction)$pdo->commit();
        return $created;
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_desktop_layout_state(PDO $pdo,int $userId,array $user,string $layoutKey='primary'): array
{
    dt_desktop_seed_owned_releases($pdo,$userId,$user,$layoutKey);
    $layout=dt_desktop_layout($pdo,$userId,$layoutKey);
    $stmt=$pdo->prepare("SELECT * FROM desktop_objects_v220 WHERE layout_id=? AND object_status='active' ORDER BY z_order,id LIMIT 400");
    $stmt->execute([(int)$layout['id']]);
    $objects=[];
    foreach($stmt->fetchAll()?:[] as $row)$objects[]=dt_desktop_object_dto($pdo,$userId,$user,$row);
    return [
        'schemaVersion'=>'desktop-objects-v220',
        'layout'=>[
            'key'=>(string)$layout['layout_key'],
            'templateId'=>(string)$layout['template_id'],
            'revision'=>(int)$layout['revision'],
        ],
        'objects'=>$objects,
    ];
}

function dt_desktop_object_create(PDO $pdo,int $userId,array $user,string $layoutKey,array $input): array
{
    $layout=dt_desktop_layout($pdo,$userId,$layoutKey);
    $type=(string)($input['object_type']??'');
    if(!in_array($type,dt_desktop_object_types(),true))throw new RuntimeException('Desktop object type is invalid.');
    $resourceId=isset($input['resource_id'])&&$input['resource_id']!==''?(int)$input['resource_id']:null;
    if(!dt_desktop_object_resource_allowed($pdo,$userId,$user,$type,$resourceId))throw new RuntimeException('Desktop resource is not available to this user.');
    $payload=dt_desktop_object_payload($type,$input['payload']??[]);
    $payloadJson=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($payloadJson===false)$payloadJson='{}';

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT * FROM desktop_layouts_v220 WHERE id=? AND user_id=? FOR UPDATE');
        $lock->execute([(int)$layout['id'],$userId]);
        $locked=$lock->fetch();
        if(!$locked)throw new RuntimeException('Desktop layout was not found.');
        if(isset($input['expected_layout_revision'])&&$input['expected_layout_revision']!==''
            &&(int)$input['expected_layout_revision']!==(int)$locked['revision']){
            throw new DesktopObjectConflictException('Desktop layout changed on another surface. Refresh before adding an object.');
        }

        if($resourceId!==null){
            $existing=$pdo->prepare("SELECT * FROM desktop_objects_v220
                WHERE layout_id=? AND object_type=? AND resource_id=? AND object_status='active'
                ORDER BY id DESC LIMIT 1");
            $existing->execute([(int)$layout['id'],$type,$resourceId]);
            $row=$existing->fetch();
            if($row){
                if($ownsTransaction)$pdo->commit();
                return ['layoutRevision'=>(int)$locked['revision'],'object'=>dt_desktop_object_dto($pdo,$userId,$user,$row)];
            }
        }

        $max=$pdo->prepare('SELECT COALESCE(MAX(z_order),0) FROM desktop_objects_v220 WHERE layout_id=?');
        $max->execute([(int)$layout['id']]);
        $z=(int)$max->fetchColumn()+1;
        $uuid=dt_desktop_object_uuid();
        $stmt=$pdo->prepare("INSERT INTO desktop_objects_v220
            (layout_id,object_uuid,object_type,resource_id,x_ratio,y_ratio,rotation_deg,scale_factor,z_order,is_pinned,payload_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            (int)$layout['id'],$uuid,$type,$resourceId,
            dt_desktop_ratio($input['x']??0.12),dt_desktop_ratio($input['y']??0.16),
            dt_desktop_rotation($input['rotation']??0),dt_desktop_scale($input['scale']??1),$z,
            !empty($input['pinned'])?1:0,$payloadJson,
        ]);
        $pdo->prepare('UPDATE desktop_layouts_v220 SET revision=revision+1,updated_at=NOW() WHERE id=?')->execute([(int)$layout['id']]);
        dt_desktop_layout_event($pdo,(int)$layout['id'],'object.created',$userId,$uuid,['type'=>$type,'resource_id'=>$resourceId]);
        $row=dt_desktop_object_row($pdo,$userId,$uuid,false);
        if($ownsTransaction)$pdo->commit();
        return ['layoutRevision'=>(int)$locked['revision']+1,'object'=>dt_desktop_object_dto($pdo,$userId,$user,$row?:[])];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_desktop_object_transform(PDO $pdo,int $userId,array $user,string $uuid,int $expectedVersion,array $input): array
{
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $row=dt_desktop_object_row($pdo,$userId,$uuid,true);
        if(!$row||(string)$row['object_status']!=='active')throw new RuntimeException('Desktop object was not found.');
        if((int)$row['object_version']!==$expectedVersion)throw new DesktopObjectConflictException('Desktop object changed on another surface.');
        if(!empty($row['is_locked']))throw new RuntimeException('Unlock this Desktop object before moving it.');

        $z=(int)$row['z_order'];
        if(!empty($input['bring_to_front'])){
            $max=$pdo->prepare('SELECT COALESCE(MAX(z_order),0) FROM desktop_objects_v220 WHERE layout_id=?');
            $max->execute([(int)$row['layout_id']]);
            $z=(int)$max->fetchColumn()+1;
        }
        $stmt=$pdo->prepare("UPDATE desktop_objects_v220 SET
            x_ratio=?,y_ratio=?,rotation_deg=?,scale_factor=?,z_order=?,object_version=object_version+1,updated_at=NOW()
            WHERE id=?");
        $stmt->execute([
            dt_desktop_ratio($input['x']??$row['x_ratio'],(float)$row['x_ratio']),
            dt_desktop_ratio($input['y']??$row['y_ratio'],(float)$row['y_ratio']),
            dt_desktop_rotation($input['rotation']??$row['rotation_deg'],(float)$row['rotation_deg']),
            dt_desktop_scale($input['scale']??$row['scale_factor'],(float)$row['scale_factor']),
            $z,(int)$row['id'],
        ]);
        $pdo->prepare('UPDATE desktop_layouts_v220 SET revision=revision+1,updated_at=NOW() WHERE id=?')->execute([(int)$row['layout_id']]);
        dt_desktop_layout_event($pdo,(int)$row['layout_id'],'object.transformed',$userId,$uuid,['z'=>$z]);
        $fresh=dt_desktop_object_row($pdo,$userId,$uuid,false);
        if($ownsTransaction)$pdo->commit();
        return ['layoutRevision'=>(int)$row['layout_revision']+1,'object'=>dt_desktop_object_dto($pdo,$userId,$user,$fresh?:[])];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_desktop_object_set_lock(PDO $pdo,int $userId,array $user,string $uuid,int $expectedVersion,bool $locked): array
{
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $row=dt_desktop_object_row($pdo,$userId,$uuid,true);
        if(!$row||(string)$row['object_status']!=='active')throw new RuntimeException('Desktop object was not found.');
        if((int)$row['object_version']!==$expectedVersion)throw new DesktopObjectConflictException('Desktop object changed on another surface.');
        $pdo->prepare('UPDATE desktop_objects_v220 SET is_locked=?,object_version=object_version+1,updated_at=NOW() WHERE id=?')
            ->execute([$locked?1:0,(int)$row['id']]);
        $pdo->prepare('UPDATE desktop_layouts_v220 SET revision=revision+1,updated_at=NOW() WHERE id=?')->execute([(int)$row['layout_id']]);
        dt_desktop_layout_event($pdo,(int)$row['layout_id'],'object.lock_changed',$userId,$uuid,['locked'=>$locked]);
        $fresh=dt_desktop_object_row($pdo,$userId,$uuid,false);
        if($ownsTransaction)$pdo->commit();
        return ['layoutRevision'=>(int)$row['layout_revision']+1,'object'=>dt_desktop_object_dto($pdo,$userId,$user,$fresh?:[])];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_desktop_object_update_payload(PDO $pdo,int $userId,array $user,string $uuid,int $expectedVersion,mixed $payload): array
{
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $row=dt_desktop_object_row($pdo,$userId,$uuid,true);
        if(!$row||(string)$row['object_status']!=='active')throw new RuntimeException('Desktop object was not found.');
        if((int)$row['object_version']!==$expectedVersion)throw new DesktopObjectConflictException('Desktop object changed on another surface.');
        $type=(string)$row['object_type'];
        if(!in_array($type,['note','photo'],true))throw new RuntimeException('Canonical resource objects do not store editable metadata.');
        $clean=dt_desktop_object_payload($type,$payload);
        $json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false)$json='{}';
        $pdo->prepare('UPDATE desktop_objects_v220 SET payload_json=?,object_version=object_version+1,updated_at=NOW() WHERE id=?')
            ->execute([$json,(int)$row['id']]);
        $pdo->prepare('UPDATE desktop_layouts_v220 SET revision=revision+1,updated_at=NOW() WHERE id=?')->execute([(int)$row['layout_id']]);
        dt_desktop_layout_event($pdo,(int)$row['layout_id'],'object.payload_changed',$userId,$uuid,['type'=>$type]);
        $fresh=dt_desktop_object_row($pdo,$userId,$uuid,false);
        if($ownsTransaction)$pdo->commit();
        return ['layoutRevision'=>(int)$row['layout_revision']+1,'object'=>dt_desktop_object_dto($pdo,$userId,$user,$fresh?:[])];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_desktop_object_remove(PDO $pdo,int $userId,string $uuid,int $expectedVersion): int
{
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $row=dt_desktop_object_row($pdo,$userId,$uuid,true);
        if(!$row||(string)$row['object_status']!=='active')throw new RuntimeException('Desktop object was not found.');
        if((int)$row['object_version']!==$expectedVersion)throw new DesktopObjectConflictException('Desktop object changed on another surface.');
        $pdo->prepare("UPDATE desktop_objects_v220 SET object_status='removed',removed_at=NOW(),object_version=object_version+1,updated_at=NOW() WHERE id=?")
            ->execute([(int)$row['id']]);
        $pdo->prepare('UPDATE desktop_layouts_v220 SET revision=revision+1,updated_at=NOW() WHERE id=?')->execute([(int)$row['layout_id']]);
        dt_desktop_layout_event($pdo,(int)$row['layout_id'],'object.removed',$userId,$uuid,['type'=>(string)$row['object_type'],'resource_id'=>$row['resource_id']]);
        if($ownsTransaction)$pdo->commit();
        return (int)$row['layout_revision']+1;
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_desktop_layout_reset(PDO $pdo,int $userId,string $layoutKey,int $expectedRevision): array
{
    $layout=dt_desktop_layout($pdo,$userId,$layoutKey);
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT * FROM desktop_layouts_v220 WHERE id=? AND user_id=? FOR UPDATE');
        $lock->execute([(int)$layout['id'],$userId]);
        $locked=$lock->fetch();
        if(!$locked)throw new RuntimeException('Desktop layout was not found.');
        if((int)$locked['revision']!==$expectedRevision)throw new DesktopObjectConflictException('Desktop layout changed on another surface. Refresh before resetting it.');
        $countStmt=$pdo->prepare("SELECT COUNT(*) FROM desktop_objects_v220 WHERE layout_id=? AND object_status='active'");
        $countStmt->execute([(int)$layout['id']]);
        $count=(int)$countStmt->fetchColumn();
        $pdo->prepare("UPDATE desktop_objects_v220 SET object_status='removed',removed_at=NOW(),object_version=object_version+1,updated_at=NOW()
            WHERE layout_id=? AND object_status='active'")->execute([(int)$layout['id']]);
        $pdo->prepare('UPDATE desktop_layouts_v220 SET revision=revision+1,updated_at=NOW() WHERE id=?')->execute([(int)$layout['id']]);
        dt_desktop_layout_event($pdo,(int)$layout['id'],'layout.reset',$userId,null,['removed_count'=>$count]);
        if($ownsTransaction)$pdo->commit();
        return ['layoutRevision'=>(int)$locked['revision']+1,'removedCount'=>$count];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

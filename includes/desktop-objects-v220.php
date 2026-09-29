<?php
declare(strict_types=1);

function dt_desktop_object_key(string $key): string
{
    $key=trim($key);
    if($key===''||strlen($key)>120||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',$key))throw new RuntimeException('Desktop object key is invalid.');
    return $key;
}

function dt_desktop_object_type(string $type): string
{
    $type=trim($type);
    if($type===''||strlen($type)>80||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',$type))throw new RuntimeException('Desktop object type is invalid.');
    return $type;
}

function dt_desktop_resource_type(?string $type): ?string
{
    $type=trim((string)$type);
    if($type==='')return null;
    if(strlen($type)>40||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',$type))throw new RuntimeException('Desktop resource type is invalid.');
    return $type;
}

function dt_desktop_object_payload(mixed $payload): ?string
{
    if($payload===null||$payload===''||$payload===[])return null;
    if(is_string($payload)){
        $decoded=json_decode($payload,true);
        if(json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('Desktop object payload is invalid JSON.');
        $payload=$decoded;
    }
    if(!is_array($payload))throw new RuntimeException('Desktop object payload must be an object.');
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false||strlen($json)>65535)throw new RuntimeException('Desktop object payload is too large.');
    return $json;
}

function dt_desktop_object_float(mixed $value,float $min,float $max,string $field): float
{
    if(!is_numeric($value))throw new RuntimeException($field.' must be numeric.');
    $number=(float)$value;
    if(!is_finite($number)||$number<$min||$number>$max)throw new RuntimeException($field.' is outside the supported range.');
    return $number;
}

function dt_desktop_object_event(PDO $pdo,int $userId,?int $objectId,string $eventType,string $objectKey,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO desktop_object_events_v220 (user_id,object_id,event_type,object_key,metadata_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$userId,$objectId?:null,substr(trim($eventType),0,80),substr($objectKey,0,120),$json]);
}

function dt_desktop_object_row(PDO $pdo,int $userId,int $objectId): ?array
{
    if($userId<1||$objectId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM desktop_objects_v220 WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$objectId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_desktop_object_dto(array $row): array
{
    $payload=[];
    if(!empty($row['payload_json'])){
        $decoded=json_decode((string)$row['payload_json'],true);
        if(is_array($decoded))$payload=$decoded;
    }
    return [
        'id'=>(int)$row['id'],
        'key'=>(string)$row['object_key'],
        'type'=>(string)$row['object_type'],
        'resource'=>[
            'type'=>$row['resource_type']!==null?(string)$row['resource_type']:null,
            'id'=>$row['resource_id']!==null?(int)$row['resource_id']:null,
        ],
        'label'=>(string)$row['label'],
        'transform'=>[
            'x'=>(float)$row['x_norm'],
            'y'=>(float)$row['y_norm'],
            'rotation'=>(float)$row['rotation_deg'],
            'scale'=>(float)$row['scale_factor'],
            'z'=>(int)$row['z_order'],
        ],
        'pinned'=>(bool)$row['is_pinned'],
        'payload'=>$payload,
        'revision'=>(int)$row['revision'],
        'createdAt'=>(string)$row['created_at'],
        'updatedAt'=>(string)$row['updated_at'],
    ];
}

function dt_desktop_objects(PDO $pdo,int $userId): array
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $stmt=$pdo->prepare('SELECT * FROM desktop_objects_v220 WHERE user_id=? ORDER BY z_order,id');
    $stmt->execute([$userId]);
    return array_map('dt_desktop_object_dto',$stmt->fetchAll()?:[]);
}

function dt_desktop_object_create(PDO $pdo,int $userId,array $input): array
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $key=dt_desktop_object_key((string)($input['key']??''));
    $type=dt_desktop_object_type((string)($input['type']??''));
    $resourceType=dt_desktop_resource_type($input['resource_type']??null);
    $resourceId=max(0,(int)($input['resource_id']??0))?:null;
    if(($resourceType===null)!==($resourceId===null))throw new RuntimeException('Desktop object resource type and id must be supplied together.');
    $label=mb_substr(trim((string)($input['label']??'')),0,190);
    $x=dt_desktop_object_float($input['x']??0.5,0,1,'Desktop X');
    $y=dt_desktop_object_float($input['y']??0.5,0,1,'Desktop Y');
    $rotation=dt_desktop_object_float($input['rotation']??0,-180,180,'Desktop rotation');
    $scale=dt_desktop_object_float($input['scale']??1,0.25,3,'Desktop scale');
    $z=max(1,min(100000,(int)($input['z']??1)));
    $pinned=!empty($input['pinned'])?1:0;
    $payload=dt_desktop_object_payload($input['payload']??null);

    $existingStmt=$pdo->prepare('SELECT * FROM desktop_objects_v220 WHERE user_id=? AND object_key=? LIMIT 1');
    $existingStmt->execute([$userId,$key]);
    $existing=$existingStmt->fetch();
    if($existing){
        if(
            (string)$existing['object_type']!==$type
            ||(string)($existing['resource_type']??'')!==(string)($resourceType??'')
            ||(int)($existing['resource_id']??0)!==(int)($resourceId??0)
        )throw new RuntimeException('Desktop object key conflicts with an existing object.');
        return dt_desktop_object_dto($existing);
    }

    try{
        $stmt=$pdo->prepare("INSERT INTO desktop_objects_v220
            (user_id,object_key,object_type,resource_type,resource_id,label,x_norm,y_norm,rotation_deg,scale_factor,z_order,is_pinned,payload_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$userId,$key,$type,$resourceType,$resourceId,$label,$x,$y,$rotation,$scale,$z,$pinned,$payload]);
        $id=(int)$pdo->lastInsertId();
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
        $existingStmt->execute([$userId,$key]);
        $existing=$existingStmt->fetch();
        if(!$existing)throw $e;
        if(
            (string)$existing['object_type']!==$type
            ||(string)($existing['resource_type']??'')!==(string)($resourceType??'')
            ||(int)($existing['resource_id']??0)!==(int)($resourceId??0)
        )throw new RuntimeException('Desktop object key conflicts with an existing object.');
        return dt_desktop_object_dto($existing);
    }

    dt_desktop_object_event($pdo,$userId,$id,'desktop.object.created',$key,[
        'type'=>$type,'resource_type'=>$resourceType,'resource_id'=>$resourceId
    ]);
    return dt_desktop_object_dto(dt_desktop_object_row($pdo,$userId,$id)??throw new RuntimeException('Desktop object could not be loaded.'));
}

function dt_desktop_object_update(PDO $pdo,int $userId,int $objectId,int $expectedRevision,array $input): array
{
    if($objectId<1||$expectedRevision<1)throw new RuntimeException('Desktop object revision is required.');
    $row=dt_desktop_object_row($pdo,$userId,$objectId);
    if(!$row)throw new RuntimeException('Desktop object was not found.');
    if((int)$row['revision']!==$expectedRevision)throw new RuntimeException('Desktop object changed on another surface. Refresh before saving.');

    $x=array_key_exists('x',$input)?dt_desktop_object_float($input['x'],0,1,'Desktop X'):(float)$row['x_norm'];
    $y=array_key_exists('y',$input)?dt_desktop_object_float($input['y'],0,1,'Desktop Y'):(float)$row['y_norm'];
    $rotation=array_key_exists('rotation',$input)?dt_desktop_object_float($input['rotation'],-180,180,'Desktop rotation'):(float)$row['rotation_deg'];
    $scale=array_key_exists('scale',$input)?dt_desktop_object_float($input['scale'],0.25,3,'Desktop scale'):(float)$row['scale_factor'];
    $z=array_key_exists('z',$input)?max(1,min(100000,(int)$input['z'])):(int)$row['z_order'];
    $pinned=array_key_exists('pinned',$input)?(!empty($input['pinned'])?1:0):(int)$row['is_pinned'];
    $label=array_key_exists('label',$input)?mb_substr(trim((string)$input['label']),0,190):(string)$row['label'];
    $payload=array_key_exists('payload',$input)?dt_desktop_object_payload($input['payload']):($row['payload_json']!==null?(string)$row['payload_json']:null);

    $stmt=$pdo->prepare("UPDATE desktop_objects_v220 SET
        label=?,x_norm=?,y_norm=?,rotation_deg=?,scale_factor=?,z_order=?,is_pinned=?,payload_json=?,revision=revision+1,updated_at=NOW()
        WHERE id=? AND user_id=? AND revision=?");
    $stmt->execute([$label,$x,$y,$rotation,$scale,$z,$pinned,$payload,$objectId,$userId,$expectedRevision]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Desktop object changed on another surface. Refresh before saving.');

    $updated=dt_desktop_object_row($pdo,$userId,$objectId)??throw new RuntimeException('Desktop object could not be loaded.');
    dt_desktop_object_event($pdo,$userId,$objectId,'desktop.object.updated',(string)$updated['object_key'],[
        'revision'=>(int)$updated['revision'],
        'x'=>(float)$updated['x_norm'],'y'=>(float)$updated['y_norm'],
        'rotation'=>(float)$updated['rotation_deg'],'scale'=>(float)$updated['scale_factor'],
        'z'=>(int)$updated['z_order'],'pinned'=>(bool)$updated['is_pinned'],
    ]);
    return dt_desktop_object_dto($updated);
}

function dt_desktop_object_delete(PDO $pdo,int $userId,int $objectId,int $expectedRevision): void
{
    if($objectId<1||$expectedRevision<1)throw new RuntimeException('Desktop object revision is required.');
    $row=dt_desktop_object_row($pdo,$userId,$objectId);
    if(!$row)throw new RuntimeException('Desktop object was not found.');
    if((int)$row['revision']!==$expectedRevision)throw new RuntimeException('Desktop object changed on another surface. Refresh before deleting.');
    $key=(string)$row['object_key'];

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        dt_desktop_object_event($pdo,$userId,$objectId,'desktop.object.deleted',$key,['revision'=>$expectedRevision]);
        $stmt=$pdo->prepare('DELETE FROM desktop_objects_v220 WHERE id=? AND user_id=? AND revision=?');
        $stmt->execute([$objectId,$userId,$expectedRevision]);
        if($stmt->rowCount()!==1)throw new RuntimeException('Desktop object changed on another surface. Refresh before deleting.');
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_desktop_objects_reset(PDO $pdo,int $userId): int
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT id,object_key,revision FROM desktop_objects_v220 WHERE user_id=? FOR UPDATE');
        $stmt->execute([$userId]);
        $rows=$stmt->fetchAll()?:[];
        foreach($rows as $row)dt_desktop_object_event($pdo,$userId,(int)$row['id'],'desktop.object.reset',(string)$row['object_key'],['revision'=>(int)$row['revision']]);
        $delete=$pdo->prepare('DELETE FROM desktop_objects_v220 WHERE user_id=?');
        $delete->execute([$userId]);
        if($ownsTransaction)$pdo->commit();
        return count($rows);
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

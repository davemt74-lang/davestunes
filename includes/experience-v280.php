<?php
declare(strict_types=1);

function dt_experience_key(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9._-]+/','-',$value)??'';
    $value=trim($value,'-');
    if($value===''||strlen($value)>100)throw new RuntimeException('Experience key is invalid.');
    return $value;
}

function dt_experience_owner(string $type): string
{
    $type=strtolower(trim($type));
    if(!in_array($type,['user','artist','release','recording'],true))throw new RuntimeException('Experience owner type is invalid.');
    return $type;
}

function dt_experience_json(mixed $value,int $maxBytes=262144): ?string
{
    if($value===null||$value===''||$value===[])return null;
    if(is_string($value)){
        $decoded=json_decode($value,true);
        if(json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('Experience JSON is invalid.');
        $value=$decoded;
    }
    if(!is_array($value))throw new RuntimeException('Experience settings must be an object or array.');
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false||strlen($json)>$maxBytes)throw new RuntimeException('Experience settings are too large.');
    return $json;
}

function dt_experience_decode(?string $json): array
{
    if(!$json)return [];
    $decoded=json_decode($json,true);
    return is_array($decoded)?$decoded:[];
}

function dt_experience_event(PDO $pdo,int $experienceId,?int $versionId,?int $actorId,string $type,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO experience_events_v280 (experience_id,version_id,actor_user_id,event_type,metadata_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$experienceId,$versionId?:null,$actorId?:null,substr($type,0,80),$json]);
}

function dt_experience_owner_artist_id(PDO $pdo,string $ownerType,int $ownerId): ?int
{
    if($ownerType==='artist')return dt_artist_by_id($pdo,$ownerId)?$ownerId:null;
    if($ownerType==='release'){
        $stmt=$pdo->prepare('SELECT artist_id FROM music_releases_v110 WHERE id=? LIMIT 1');
        $stmt->execute([$ownerId]);
        $value=$stmt->fetchColumn();
        return $value!==false?(int)$value:null;
    }
    if($ownerType==='recording'){
        $stmt=$pdo->prepare('SELECT artist_id FROM music_recordings_v110 WHERE id=? LIMIT 1');
        $stmt->execute([$ownerId]);
        $value=$stmt->fetchColumn();
        return $value!==false?(int)$value:null;
    }
    return null;
}

function dt_experience_require_owner(PDO $pdo,string $ownerType,int $ownerId,array $user): void
{
    if($ownerId<1)throw new RuntimeException('Experience owner was not found.');
    $userId=(int)($user['id']??0);
    if($ownerType==='user'){
        if($userId!==$ownerId)throw new RuntimeException('You cannot edit another user experience.');
        return;
    }
    $artistId=dt_experience_owner_artist_id($pdo,$ownerType,$ownerId);
    if(!$artistId||!dt_artist_can($pdo,$artistId,$userId,'catalog'))throw new RuntimeException('Your artist role does not allow this experience action.');
}

function dt_experience_row(PDO $pdo,int $experienceId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM experiences_v280 WHERE id=? LIMIT 1');
    $stmt->execute([$experienceId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_experience_version_row(PDO $pdo,int $versionId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM experience_versions_v280 WHERE id=? LIMIT 1');
    $stmt->execute([$versionId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_experience_require_draft(PDO $pdo,int $versionId,array $user): array
{
    $version=dt_experience_version_row($pdo,$versionId);
    if(!$version||(string)$version['version_status']!=='draft')throw new RuntimeException('Experience version is not editable.');
    $experience=dt_experience_row($pdo,(int)$version['experience_id']);
    if(!$experience)throw new RuntimeException('Experience was not found.');
    dt_experience_require_owner($pdo,(string)$experience['owner_type'],(int)$experience['owner_id'],$user);
    return [$experience,$version];
}

function dt_experience_create(PDO $pdo,array $user,string $ownerType,int $ownerId,string $name,string $key='default'): array
{
    $ownerType=dt_experience_owner($ownerType);
    $key=dt_experience_key($key);
    $name=trim($name);
    if($name===''||mb_strlen($name)>190)throw new RuntimeException('Experience name must be between 1 and 190 characters.');
    dt_experience_require_owner($pdo,$ownerType,$ownerId,$user);

    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('INSERT INTO experiences_v280 (owner_type,owner_id,experience_key,name,created_by_user_id) VALUES (?,?,?,?,?)');
        $stmt->execute([$ownerType,$ownerId,$key,$name,(int)$user['id']]);
        $experienceId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare("INSERT INTO experience_versions_v280 (experience_id,version_number,version_status,created_by_user_id) VALUES (?,1,'draft',?)");
        $stmt->execute([$experienceId,(int)$user['id']]);
        $versionId=(int)$pdo->lastInsertId();
        dt_experience_event($pdo,$experienceId,$versionId,(int)$user['id'],'experience.created',['owner_type'=>$ownerType,'owner_id'=>$ownerId]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&(string)$e->getCode()==='23000')throw new RuntimeException('An experience with that key already exists for this owner.');
        throw $e;
    }
    return ['experience'=>dt_experience_row($pdo,$experienceId),'version'=>dt_experience_version_row($pdo,$versionId)];
}

function dt_experience_scene_add(PDO $pdo,int $versionId,array $user,array $input): array
{
    [$experience]=$unused=dt_experience_require_draft($pdo,$versionId,$user);
    $key=dt_experience_key((string)($input['scene_key']??''));
    $title=trim((string)($input['title']??''));
    if($title===''||mb_strlen($title)>190)throw new RuntimeException('Scene title is required.');
    $weight=max(0.1,min(1000,(float)($input['weight']??1)));
    $stmt=$pdo->prepare('INSERT INTO experience_scenes_v280 (version_id,scene_key,title,sort_order,weight,is_enabled,settings_json) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$versionId,$key,$title,max(0,(int)($input['sort_order']??0)),$weight,!empty($input['is_enabled'])?1:0,dt_experience_json($input['settings']??null)]);
    $id=(int)$pdo->lastInsertId();
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'scene.added',['scene_key'=>$key]);
    $q=$pdo->prepare('SELECT * FROM experience_scenes_v280 WHERE id=?');$q->execute([$id]);
    return $q->fetch()?:[];
}

function dt_experience_layer_add(PDO $pdo,int $versionId,array $user,array $input): array
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $sceneKey=dt_experience_key((string)($input['scene_key']??''));
    $scene=$pdo->prepare('SELECT * FROM experience_scenes_v280 WHERE version_id=? AND scene_key=? LIMIT 1');
    $scene->execute([$versionId,$sceneKey]);$sceneRow=$scene->fetch();
    if(!$sceneRow)throw new RuntimeException('Scene was not found.');
    $layerKey=dt_experience_key((string)($input['layer_key']??''));
    $layerType=dt_experience_key((string)($input['layer_type']??''));
    $stmt=$pdo->prepare('INSERT INTO experience_layers_v280 (scene_id,layer_key,layer_type,sort_order,settings_json) VALUES (?,?,?,?,?)');
    $stmt->execute([(int)$sceneRow['id'],$layerKey,$layerType,(int)($input['sort_order']??0),dt_experience_json($input['settings']??null)]);
    $id=(int)$pdo->lastInsertId();
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'layer.added',['scene_key'=>$sceneKey,'layer_key'=>$layerKey]);
    $q=$pdo->prepare('SELECT * FROM experience_layers_v280 WHERE id=?');$q->execute([$id]);
    return $q->fetch()?:[];
}

function dt_experience_node_add(PDO $pdo,int $versionId,array $user,array $input): array
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $key=dt_experience_key((string)($input['node_key']??''));
    $type=dt_experience_key((string)($input['node_type']??''));
    $sceneKey=trim((string)($input['scene_key']??''));
    if($sceneKey!==''){
        $sceneKey=dt_experience_key($sceneKey);
        $q=$pdo->prepare('SELECT 1 FROM experience_scenes_v280 WHERE version_id=? AND scene_key=? LIMIT 1');
        $q->execute([$versionId,$sceneKey]);
        if(!$q->fetchColumn())throw new RuntimeException('Flow node scene was not found.');
    }else $sceneKey=null;
    $stmt=$pdo->prepare('INSERT INTO experience_flow_nodes_v280 (version_id,node_key,node_type,scene_key,position_x,position_y,settings_json) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$versionId,$key,$type,$sceneKey,(float)($input['x']??0),(float)($input['y']??0),dt_experience_json($input['settings']??null)]);
    $id=(int)$pdo->lastInsertId();
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'flow.node_added',['node_key'=>$key,'node_type'=>$type]);
    $q=$pdo->prepare('SELECT * FROM experience_flow_nodes_v280 WHERE id=?');$q->execute([$id]);
    return $q->fetch()?:[];
}

function dt_experience_edge_add(PDO $pdo,int $versionId,array $user,array $input): array
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $edgeKey=dt_experience_key((string)($input['edge_key']??''));
    $from=dt_experience_key((string)($input['from_node_key']??''));
    $to=dt_experience_key((string)($input['to_node_key']??''));
    if($from===$to)throw new RuntimeException('A flow edge cannot connect a node to itself.');
    $q=$pdo->prepare('SELECT node_key FROM experience_flow_nodes_v280 WHERE version_id=? AND node_key IN (?,?)');
    $q->execute([$versionId,$from,$to]);
    $found=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN)?:[],true);
    if(!isset($found[$from],$found[$to]))throw new RuntimeException('Flow edge nodes must exist in the same version.');
    $stmt=$pdo->prepare('INSERT INTO experience_flow_edges_v280 (version_id,edge_key,from_node_key,from_port,to_node_key,to_port,condition_json) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$versionId,$edgeKey,$from,substr(trim((string)($input['from_port']??'out')),0,80),$to,substr(trim((string)($input['to_port']??'in')),0,80),dt_experience_json($input['condition']??null)]);
    $id=(int)$pdo->lastInsertId();
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'flow.edge_added',['edge_key'=>$edgeKey]);
    $q=$pdo->prepare('SELECT * FROM experience_flow_edges_v280 WHERE id=?');$q->execute([$id]);
    return $q->fetch()?:[];
}

function dt_experience_manifest(PDO $pdo,int $versionId): array
{
    $version=dt_experience_version_row($pdo,$versionId);
    if(!$version)throw new RuntimeException('Experience version was not found.');
    $experience=dt_experience_row($pdo,(int)$version['experience_id']);
    if(!$experience)throw new RuntimeException('Experience was not found.');

    $scenesStmt=$pdo->prepare('SELECT * FROM experience_scenes_v280 WHERE version_id=? ORDER BY sort_order,id');
    $scenesStmt->execute([$versionId]);
    $scenes=[];
    foreach($scenesStmt->fetchAll()?:[] as $scene){
        $layersStmt=$pdo->prepare('SELECT * FROM experience_layers_v280 WHERE scene_id=? ORDER BY sort_order,id');
        $layersStmt->execute([(int)$scene['id']]);
        $layers=[];
        foreach($layersStmt->fetchAll()?:[] as $layer)$layers[]=[
            'key'=>(string)$layer['layer_key'],'type'=>(string)$layer['layer_type'],
            'order'=>(int)$layer['sort_order'],'settings'=>dt_experience_decode($layer['settings_json']??null)
        ];
        $scenes[]=[
            'key'=>(string)$scene['scene_key'],'title'=>(string)$scene['title'],'order'=>(int)$scene['sort_order'],
            'weight'=>(float)$scene['weight'],'enabled'=>(bool)$scene['is_enabled'],
            'settings'=>dt_experience_decode($scene['settings_json']??null),'layers'=>$layers
        ];
    }

    $nodesStmt=$pdo->prepare('SELECT * FROM experience_flow_nodes_v280 WHERE version_id=? ORDER BY id');
    $nodesStmt->execute([$versionId]);
    $nodes=array_map(static fn(array $row): array => [
        'key'=>(string)$row['node_key'],'type'=>(string)$row['node_type'],'sceneKey'=>$row['scene_key']!==null?(string)$row['scene_key']:null,
        'x'=>(float)$row['position_x'],'y'=>(float)$row['position_y'],'settings'=>dt_experience_decode($row['settings_json']??null)
    ],$nodesStmt->fetchAll()?:[]);

    $edgesStmt=$pdo->prepare('SELECT * FROM experience_flow_edges_v280 WHERE version_id=? ORDER BY id');
    $edgesStmt->execute([$versionId]);
    $edges=array_map(static fn(array $row): array => [
        'key'=>(string)$row['edge_key'],'from'=>(string)$row['from_node_key'],'fromPort'=>(string)$row['from_port'],
        'to'=>(string)$row['to_node_key'],'toPort'=>(string)$row['to_port'],'condition'=>dt_experience_decode($row['condition_json']??null)
    ],$edgesStmt->fetchAll()?:[]);

    return [
        'schema'=>'experience-v280','experienceId'=>(int)$experience['id'],'experienceKey'=>(string)$experience['experience_key'],
        'name'=>(string)$experience['name'],'owner'=>['type'=>(string)$experience['owner_type'],'id'=>(int)$experience['owner_id']],
        'version'=>['id'=>(int)$version['id'],'number'=>(int)$version['version_number']],
        'scenes'=>$scenes,'flow'=>['nodes'=>$nodes,'edges'=>$edges]
    ];
}

function dt_experience_validate_manifest(array $manifest): void
{
    $enabled=array_values(array_filter($manifest['scenes']??[],static fn(array $scene): bool => !empty($scene['enabled'])));
    if(!$enabled)throw new RuntimeException('An experience must have at least one enabled scene before publishing.');
    $sceneKeys=array_fill_keys(array_map(static fn(array $s): string => (string)$s['key'],$manifest['scenes']??[]),true);
    foreach($manifest['flow']['nodes']??[] as $node){
        $sceneKey=$node['sceneKey']??null;
        if($sceneKey!==null&&!isset($sceneKeys[$sceneKey]))throw new RuntimeException('A flow node references a missing scene.');
    }
    $nodeKeys=array_fill_keys(array_map(static fn(array $n): string => (string)$n['key'],$manifest['flow']['nodes']??[]),true);
    foreach($manifest['flow']['edges']??[] as $edge){
        if(!isset($nodeKeys[$edge['from']],$nodeKeys[$edge['to']]))throw new RuntimeException('A flow edge references a missing node.');
    }
}

function dt_experience_publish(PDO $pdo,int $versionId,array $user): array
{
    [$experience,$version]=dt_experience_require_draft($pdo,$versionId,$user);
    $manifest=dt_experience_manifest($pdo,$versionId);
    dt_experience_validate_manifest($manifest);
    $json=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    if($json===false)throw new RuntimeException('Experience manifest could not be encoded.');
    $hash=hash('sha256',$json);
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("UPDATE experience_versions_v280 SET version_status='published',manifest_json=?,manifest_sha256=?,published_at=NOW(),updated_at=NOW() WHERE id=? AND version_status='draft'");
        $stmt->execute([$json,$hash,$versionId]);
        if($stmt->rowCount()!==1)throw new RuntimeException('Experience version changed before publish.');
        $pdo->prepare('UPDATE experiences_v280 SET active_version_id=?,updated_at=NOW() WHERE id=?')->execute([$versionId,(int)$experience['id']]);
        dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'experience.published',['sha256'=>$hash,'version'=>(int)$version['version_number']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['manifest'=>$manifest,'sha256'=>$hash,'version'=>dt_experience_version_row($pdo,$versionId)];
}

function dt_experience_clone_draft(PDO $pdo,int $experienceId,array $user): array
{
    $experience=dt_experience_row($pdo,$experienceId);
    if(!$experience)throw new RuntimeException('Experience was not found.');
    dt_experience_require_owner($pdo,(string)$experience['owner_type'],(int)$experience['owner_id'],$user);
    $sourceId=(int)($experience['active_version_id']??0);
    if($sourceId<1)throw new RuntimeException('Publish the first version before creating another draft.');
    $source=dt_experience_version_row($pdo,$sourceId);
    if(!$source||(string)$source['version_status']!=='published')throw new RuntimeException('Active experience version is invalid.');

    $next=(int)$pdo->query('SELECT COALESCE(MAX(version_number),0)+1 FROM experience_versions_v280 WHERE experience_id='.(int)$experienceId)->fetchColumn();
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO experience_versions_v280 (experience_id,version_number,version_status,created_by_user_id) VALUES (?,?,'draft',?)");
        $stmt->execute([$experienceId,$next,(int)$user['id']]);
        $newId=(int)$pdo->lastInsertId();

        $scenes=$pdo->prepare('SELECT * FROM experience_scenes_v280 WHERE version_id=? ORDER BY id');$scenes->execute([$sourceId]);
        foreach($scenes->fetchAll()?:[] as $scene){
            $stmt=$pdo->prepare('INSERT INTO experience_scenes_v280 (version_id,scene_key,title,sort_order,weight,is_enabled,settings_json) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$newId,$scene['scene_key'],$scene['title'],$scene['sort_order'],$scene['weight'],$scene['is_enabled'],$scene['settings_json']]);
            $newSceneId=(int)$pdo->lastInsertId();
            $layers=$pdo->prepare('SELECT * FROM experience_layers_v280 WHERE scene_id=? ORDER BY id');$layers->execute([(int)$scene['id']]);
            foreach($layers->fetchAll()?:[] as $layer)$pdo->prepare('INSERT INTO experience_layers_v280 (scene_id,layer_key,layer_type,sort_order,settings_json) VALUES (?,?,?,?,?)')->execute([$newSceneId,$layer['layer_key'],$layer['layer_type'],$layer['sort_order'],$layer['settings_json']]);
        }
        $pdo->prepare('INSERT INTO experience_flow_nodes_v280 (version_id,node_key,node_type,scene_key,position_x,position_y,settings_json) SELECT ?,node_key,node_type,scene_key,position_x,position_y,settings_json FROM experience_flow_nodes_v280 WHERE version_id=?')->execute([$newId,$sourceId]);
        $pdo->prepare('INSERT INTO experience_flow_edges_v280 (version_id,edge_key,from_node_key,from_port,to_node_key,to_port,condition_json) SELECT ?,edge_key,from_node_key,from_port,to_node_key,to_port,condition_json FROM experience_flow_edges_v280 WHERE version_id=?')->execute([$newId,$sourceId]);
        dt_experience_event($pdo,$experienceId,$newId,(int)$user['id'],'experience.draft_created',['from_version_id'=>$sourceId,'version'=>$next]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return dt_experience_version_row($pdo,$newId)??[];
}

function dt_experience_active(PDO $pdo,string $ownerType,int $ownerId,string $key='default'): ?array
{
    $ownerType=dt_experience_owner($ownerType);$key=dt_experience_key($key);
    $stmt=$pdo->prepare("SELECT e.*,v.manifest_json,v.manifest_sha256,v.version_number
        FROM experiences_v280 e INNER JOIN experience_versions_v280 v ON v.id=e.active_version_id AND v.version_status='published'
        WHERE e.owner_type=? AND e.owner_id=? AND e.experience_key=? LIMIT 1");
    $stmt->execute([$ownerType,$ownerId,$key]);
    $row=$stmt->fetch();
    if(!$row||empty($row['manifest_json']))return null;
    $manifest=json_decode((string)$row['manifest_json'],true);
    if(!is_array($manifest))return null;
    return ['manifest'=>$manifest,'sha256'=>(string)$row['manifest_sha256'],'versionNumber'=>(int)$row['version_number']];
}

function dt_experience_public_allowed(PDO $pdo,string $ownerType,int $ownerId,?array $user): bool
{
    if($ownerType==='release'){
        $stmt=$pdo->prepare("SELECT 1 FROM music_releases_v110 WHERE id=? AND release_status='published' LIMIT 1");$stmt->execute([$ownerId]);return (bool)$stmt->fetchColumn();
    }
    if($ownerType==='recording')return dt_library_recording_is_public($pdo,$ownerId);
    if($ownerType==='artist'){
        $artist=dt_artist_by_id($pdo,$ownerId);return $artist&&(string)$artist['artist_status']==='active';
    }
    return $user&&(int)$user['id']===$ownerId;
}

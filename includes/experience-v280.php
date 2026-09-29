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
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
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



function dt_experience_scene_update(PDO $pdo,int $versionId,array $user,string $sceneKey,array $input): array
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $sceneKey=dt_experience_key($sceneKey);
    $stmt=$pdo->prepare('SELECT * FROM experience_scenes_v280 WHERE version_id=? AND scene_key=? LIMIT 1');
    $stmt->execute([$versionId,$sceneKey]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Scene was not found.');
    $title=(array_key_exists('title',$input)&&$input['title']!==null)?trim((string)$input['title']):(string)$row['title'];
    if($title===''||mb_strlen($title)>190)throw new RuntimeException('Scene title is required.');
    $order=(array_key_exists('sort_order',$input)&&$input['sort_order']!==null)?max(0,(int)$input['sort_order']):(int)$row['sort_order'];
    $weight=(array_key_exists('weight',$input)&&$input['weight']!==null)?max(.1,min(1000,(float)$input['weight'])):(float)$row['weight'];
    $enabled=(array_key_exists('is_enabled',$input)&&$input['is_enabled']!==null)?(!empty($input['is_enabled'])?1:0):(int)$row['is_enabled'];
    $settings=(array_key_exists('settings',$input)&&$input['settings']!==null)?dt_experience_json($input['settings']):($row['settings_json']??null);
    $pdo->prepare('UPDATE experience_scenes_v280 SET title=?,sort_order=?,weight=?,is_enabled=?,settings_json=? WHERE id=?')->execute([$title,$order,$weight,$enabled,$settings,(int)$row['id']]);
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'scene.updated',['scene_key'=>$sceneKey]);
    $stmt->execute([$versionId,$sceneKey]);return $stmt->fetch()?:[];
}

function dt_experience_scene_delete(PDO $pdo,int $versionId,array $user,string $sceneKey): void
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $sceneKey=dt_experience_key($sceneKey);
    $nodes=$pdo->prepare('SELECT COUNT(*) FROM experience_flow_nodes_v280 WHERE version_id=? AND scene_key=?');
    $nodes->execute([$versionId,$sceneKey]);
    if((int)$nodes->fetchColumn()>0)throw new RuntimeException('Remove flow nodes that reference this scene before deleting it.');
    $stmt=$pdo->prepare('DELETE FROM experience_scenes_v280 WHERE version_id=? AND scene_key=?');
    $stmt->execute([$versionId,$sceneKey]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Scene was not found.');
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'scene.deleted',['scene_key'=>$sceneKey]);
}

function dt_experience_layer_update(PDO $pdo,int $versionId,array $user,string $sceneKey,string $layerKey,array $input): array
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $sceneKey=dt_experience_key($sceneKey);$layerKey=dt_experience_key($layerKey);
    $stmt=$pdo->prepare('SELECT l.* FROM experience_layers_v280 l INNER JOIN experience_scenes_v280 s ON s.id=l.scene_id WHERE s.version_id=? AND s.scene_key=? AND l.layer_key=? LIMIT 1');
    $stmt->execute([$versionId,$sceneKey,$layerKey]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Layer was not found.');
    $type=(array_key_exists('layer_type',$input)&&$input['layer_type']!==null)?dt_experience_key((string)$input['layer_type']):(string)$row['layer_type'];
    $order=(array_key_exists('sort_order',$input)&&$input['sort_order']!==null)?(int)$input['sort_order']:(int)$row['sort_order'];
    $settings=(array_key_exists('settings',$input)&&$input['settings']!==null)?dt_experience_json($input['settings']):($row['settings_json']??null);
    $pdo->prepare('UPDATE experience_layers_v280 SET layer_type=?,sort_order=?,settings_json=? WHERE id=?')->execute([$type,$order,$settings,(int)$row['id']]);
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'layer.updated',['scene_key'=>$sceneKey,'layer_key'=>$layerKey]);
    $stmt->execute([$versionId,$sceneKey,$layerKey]);return $stmt->fetch()?:[];
}

function dt_experience_layer_delete(PDO $pdo,int $versionId,array $user,string $sceneKey,string $layerKey): void
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $sceneKey=dt_experience_key($sceneKey);$layerKey=dt_experience_key($layerKey);
    $stmt=$pdo->prepare('DELETE l FROM experience_layers_v280 l INNER JOIN experience_scenes_v280 s ON s.id=l.scene_id WHERE s.version_id=? AND s.scene_key=? AND l.layer_key=?');
    $stmt->execute([$versionId,$sceneKey,$layerKey]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Layer was not found.');
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'layer.deleted',['scene_key'=>$sceneKey,'layer_key'=>$layerKey]);
}

function dt_experience_node_update(PDO $pdo,int $versionId,array $user,string $nodeKey,array $input): array
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $nodeKey=dt_experience_key($nodeKey);
    $stmt=$pdo->prepare('SELECT * FROM experience_flow_nodes_v280 WHERE version_id=? AND node_key=? LIMIT 1');
    $stmt->execute([$versionId,$nodeKey]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Flow node was not found.');
    $type=(array_key_exists('node_type',$input)&&$input['node_type']!==null)?dt_experience_key((string)$input['node_type']):(string)$row['node_type'];
    $sceneKey=(array_key_exists('scene_key',$input)&&$input['scene_key']!==null)?trim((string)$input['scene_key']):($row['scene_key']??'');
    if($sceneKey!==''){
        $sceneKey=dt_experience_key($sceneKey);
        $q=$pdo->prepare('SELECT 1 FROM experience_scenes_v280 WHERE version_id=? AND scene_key=? LIMIT 1');$q->execute([$versionId,$sceneKey]);
        if(!$q->fetchColumn())throw new RuntimeException('Flow node scene was not found.');
    }else $sceneKey=null;
    $x=(array_key_exists('x',$input)&&$input['x']!==null)?(float)$input['x']:(float)$row['position_x'];
    $y=(array_key_exists('y',$input)&&$input['y']!==null)?(float)$input['y']:(float)$row['position_y'];
    $settings=(array_key_exists('settings',$input)&&$input['settings']!==null)?dt_experience_json($input['settings']):($row['settings_json']??null);
    $pdo->prepare('UPDATE experience_flow_nodes_v280 SET node_type=?,scene_key=?,position_x=?,position_y=?,settings_json=? WHERE id=?')->execute([$type,$sceneKey,$x,$y,$settings,(int)$row['id']]);
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'flow.node_updated',['node_key'=>$nodeKey]);
    $stmt->execute([$versionId,$nodeKey]);return $stmt->fetch()?:[];
}

function dt_experience_node_delete(PDO $pdo,int $versionId,array $user,string $nodeKey): void
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $nodeKey=dt_experience_key($nodeKey);
    $edges=$pdo->prepare('SELECT COUNT(*) FROM experience_flow_edges_v280 WHERE version_id=? AND (from_node_key=? OR to_node_key=?)');
    $edges->execute([$versionId,$nodeKey,$nodeKey]);
    if((int)$edges->fetchColumn()>0)throw new RuntimeException('Remove connected edges before deleting this flow node.');
    $stmt=$pdo->prepare('DELETE FROM experience_flow_nodes_v280 WHERE version_id=? AND node_key=?');$stmt->execute([$versionId,$nodeKey]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Flow node was not found.');
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'flow.node_deleted',['node_key'=>$nodeKey]);
}

function dt_experience_edge_update(PDO $pdo,int $versionId,array $user,string $edgeKey,array $input): array
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $edgeKey=dt_experience_key($edgeKey);
    $stmt=$pdo->prepare('SELECT * FROM experience_flow_edges_v280 WHERE version_id=? AND edge_key=? LIMIT 1');$stmt->execute([$versionId,$edgeKey]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Flow edge was not found.');
    $from=(array_key_exists('from_node_key',$input)&&$input['from_node_key']!==null)?dt_experience_key((string)$input['from_node_key']):(string)$row['from_node_key'];
    $to=(array_key_exists('to_node_key',$input)&&$input['to_node_key']!==null)?dt_experience_key((string)$input['to_node_key']):(string)$row['to_node_key'];
    if($from===$to)throw new RuntimeException('A flow edge cannot connect a node to itself.');
    $q=$pdo->prepare('SELECT node_key FROM experience_flow_nodes_v280 WHERE version_id=? AND node_key IN (?,?)');$q->execute([$versionId,$from,$to]);
    $found=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN)?:[],true);
    if(!isset($found[$from],$found[$to]))throw new RuntimeException('Flow edge nodes must exist in the same version.');
    $fromPort=(array_key_exists('from_port',$input)&&$input['from_port']!==null)?substr(trim((string)$input['from_port']),0,80):(string)$row['from_port'];
    $toPort=(array_key_exists('to_port',$input)&&$input['to_port']!==null)?substr(trim((string)$input['to_port']),0,80):(string)$row['to_port'];
    $condition=(array_key_exists('condition',$input)&&$input['condition']!==null)?dt_experience_json($input['condition']):($row['condition_json']??null);
    $pdo->prepare('UPDATE experience_flow_edges_v280 SET from_node_key=?,from_port=?,to_node_key=?,to_port=?,condition_json=? WHERE id=?')->execute([$from,$fromPort,$to,$toPort,$condition,(int)$row['id']]);
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'flow.edge_updated',['edge_key'=>$edgeKey]);
    $stmt->execute([$versionId,$edgeKey]);return $stmt->fetch()?:[];
}

function dt_experience_edge_delete(PDO $pdo,int $versionId,array $user,string $edgeKey): void
{
    [$experience]=dt_experience_require_draft($pdo,$versionId,$user);
    $edgeKey=dt_experience_key($edgeKey);
    $stmt=$pdo->prepare('DELETE FROM experience_flow_edges_v280 WHERE version_id=? AND edge_key=?');$stmt->execute([$versionId,$edgeKey]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Flow edge was not found.');
    dt_experience_event($pdo,(int)$experience['id'],$versionId,(int)$user['id'],'flow.edge_deleted',['edge_key'=>$edgeKey]);
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
        $pdo->prepare('INSERT INTO experience_flow_nodes_v280 (version_id,node_key,node_type,scene_key,position_x,position_y,settings_json) SELECT ?,node_key,node_type,scene_key,position_x,position_y,settings_json FROM experience_flow_nodes_v280 WHERE version_id=? ORDER BY id')->execute([$newId,$sourceId]);
        $pdo->prepare('INSERT INTO experience_flow_edges_v280 (version_id,edge_key,from_node_key,from_port,to_node_key,to_port,condition_json) SELECT ?,edge_key,from_node_key,from_port,to_node_key,to_port,condition_json FROM experience_flow_edges_v280 WHERE version_id=? ORDER BY id')->execute([$newId,$sourceId]);
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


function dt_experience_editor_snapshot(PDO $pdo,int $experienceId,array $user): array
{
    $experience=dt_experience_row($pdo,$experienceId);
    if(!$experience)throw new RuntimeException('Experience was not found.');
    dt_experience_require_owner($pdo,(string)$experience['owner_type'],(int)$experience['owner_id'],$user);
    $stmt=$pdo->prepare("SELECT * FROM experience_versions_v280 WHERE experience_id=? ORDER BY version_number DESC,id DESC");
    $stmt->execute([$experienceId]);
    $versions=$stmt->fetchAll()?:[];
    $draft=null;
    foreach($versions as $version)if((string)$version['version_status']==='draft'){$draft=$version;break;}
    if(!$draft&&$experience['active_version_id'])$draft=dt_experience_clone_draft($pdo,$experienceId,$user);
    if(!$draft)throw new RuntimeException('Experience draft could not be resolved.');
    $manifest=dt_experience_manifest($pdo,(int)$draft['id']);
    return [
        'experience'=>[
            'id'=>(int)$experience['id'],'name'=>(string)$experience['name'],'key'=>(string)$experience['experience_key'],
            'owner'=>['type'=>(string)$experience['owner_type'],'id'=>(int)$experience['owner_id']],
            'activeVersionId'=>$experience['active_version_id']!==null?(int)$experience['active_version_id']:null,
        ],
        'draft'=>[
            'id'=>(int)$draft['id'],'number'=>(int)$draft['version_number'],'status'=>(string)$draft['version_status'],
            'manifest'=>$manifest
        ],
        'versions'=>array_map(static fn(array $v): array => [
            'id'=>(int)$v['id'],'number'=>(int)$v['version_number'],'status'=>(string)$v['version_status'],
            'sha256'=>(string)($v['manifest_sha256']??''),'publishedAt'=>$v['published_at']!==null?(string)$v['published_at']:null
        ],$versions)
    ];
}

function dt_experience_find_for_owner(PDO $pdo,string $ownerType,int $ownerId,string $key='default'): ?array
{
    $ownerType=dt_experience_owner($ownerType);$key=dt_experience_key($key);
    $stmt=$pdo->prepare('SELECT * FROM experiences_v280 WHERE owner_type=? AND owner_id=? AND experience_key=? LIMIT 1');
    $stmt->execute([$ownerType,$ownerId,$key]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}


function dt_experience_owner_context(PDO $pdo,string $ownerType,int $ownerId): array
{
    $ownerType=dt_experience_owner($ownerType);
    if($ownerType==='release'){
        $stmt=$pdo->prepare("SELECT r.id,r.title,r.release_status,r.artist_id,a.name artist_name FROM music_releases_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
        $stmt->execute([$ownerId]);
        $release=$stmt->fetch();
        if(!$release)return ['title'=>'Album Experience Studio','subtitle'=>'Build the interactive experience for this album.','experience_name'=>'Album Experience','back_url'=>''];
        return [
            'title'=>(string)$release['title'].' · Experience Studio',
            'subtitle'=>'Author the interactive album journey for '.(string)$release['artist_name'].'. Drafts stay private until you publish.',
            'experience_name'=>(string)$release['title'].' Experience',
            'back_url'=>'/album.php?release='.(int)$release['id'],
            'release_status'=>(string)$release['release_status'],
            'artist_id'=>(int)$release['artist_id'],
        ];
    }
    if($ownerType==='artist'){
        $artist=dt_artist_by_id($pdo,$ownerId);
        return [
            'title'=>($artist?(string)$artist['name']:'Artist').' · Experience Studio',
            'subtitle'=>'Build the public artist desktop experience. Drafts stay private until you publish.',
            'experience_name'=>($artist?(string)$artist['name']:'Artist').' Experience',
            'back_url'=>$artist?'/artist.php?artist='.rawurlencode((string)$artist['slug']):'',
        ];
    }
    if($ownerType==='recording'){
        $stmt=$pdo->prepare("SELECT title FROM music_recordings_v110 WHERE id=? LIMIT 1");$stmt->execute([$ownerId]);$title=(string)($stmt->fetchColumn()?:'Recording');
        return ['title'=>$title.' · Experience Studio','subtitle'=>'Build an interactive experience for this recording.','experience_name'=>$title.' Experience','back_url'=>''];
    }
    return ['title'=>'Experience Studio','subtitle'=>'Build scenes, arrange the film-strip, connect flow nodes, preview, and publish one canonical experience graph.','experience_name'=>'My Experience','back_url'=>''];
}

<?php
declare(strict_types=1);

function dt_catalog_release_types(): array
{
    return ['single'=>'Single','ep'=>'EP','album'=>'Album'];
}

function dt_catalog_release_statuses(): array
{
    return ['draft'=>'Draft','ready'=>'Ready','scheduled'=>'Scheduled','published'=>'Published','withdrawn'=>'Withdrawn','archived'=>'Archived'];
}

function dt_catalog_edition_formats(): array
{
    return ['digital'=>'Digital','deluxe-digital'=>'Deluxe Digital','vinyl'=>'Vinyl','cd'=>'CD','bundle'=>'Bundle'];
}

function dt_catalog_slug(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
    return substr(trim($value,'-'),0,120);
}

function dt_catalog_normalize_isrc(string $value): ?string
{
    $value=strtoupper(preg_replace('/[^A-Za-z0-9]/','',trim($value))??'');
    if($value==='')return null;
    if(!preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/',$value))throw new RuntimeException('ISRC must be a valid 12-character code.');
    return $value;
}

function dt_catalog_normalize_upc(string $value): ?string
{
    $value=preg_replace('/\D+/','',trim($value))??'';
    if($value==='')return null;
    if(!in_array(strlen($value),[12,13,14],true))throw new RuntimeException('UPC/EAN must contain 12, 13, or 14 digits.');
    return $value;
}

function dt_catalog_event(PDO $pdo,int $artistId,string $entityType,int $entityId,string $eventType,?int $actorId,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO music_catalog_events_v110 (artist_id,entity_type,entity_id,event_type,actor_user_id,metadata_json) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$artistId,substr($entityType,0,30),$entityId,substr($eventType,0,80),$actorId?:null,$json]);
}

function dt_catalog_require_artist(PDO $pdo,int $artistId,array $user,string $capability='catalog'): array
{
    $userId=(int)($user['id']??0);
    $artist=dt_artist_by_id($pdo,$artistId);
    if(!$artist||(string)$artist['artist_status']==='archived')throw new RuntimeException('Artist was not found.');
    if(!dt_artist_can($pdo,$artistId,$userId,$capability))throw new RuntimeException('Your artist role does not allow this catalog action.');
    return $artist;
}

function dt_catalog_recording(PDO $pdo,int $artistId,int $recordingId): ?array
{
    if($artistId<1||$recordingId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM music_recordings_v110 WHERE id=? AND artist_id=? LIMIT 1');
    $stmt->execute([$recordingId,$artistId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_catalog_recordings(PDO $pdo,int $artistId,bool $includeArchived=false): array
{
    $sql='SELECT * FROM music_recordings_v110 WHERE artist_id=?';
    if(!$includeArchived)$sql.=" AND recording_status<>'archived'";
    $sql.=' ORDER BY title,version_label,id';
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$artistId]);
    return $stmt->fetchAll()?:[];
}

function dt_catalog_create_recording(PDO $pdo,int $artistId,array $user,array $input): array
{
    dt_catalog_require_artist($pdo,$artistId,$user,'catalog');
    $title=trim((string)($input['title']??''));
    $version=trim((string)($input['version_label']??''));
    if($title===''||mb_strlen($title)>190)throw new RuntimeException('Recording title must be between 1 and 190 characters.');
    if(mb_strlen($version)>120)throw new RuntimeException('Recording version is too long.');
    $isrc=dt_catalog_normalize_isrc((string)($input['isrc']??''));
    $duration=max(0,(int)($input['duration_ms']??0));
    $duration=$duration>0?$duration:null;
    $explicit=!empty($input['explicit_content'])?1:0;
    try{
        $stmt=$pdo->prepare("INSERT INTO music_recordings_v110
            (artist_id,created_by_user_id,title,version_label,isrc,duration_ms,explicit_content,recording_status)
            VALUES (?,?,?,?,?,?,?,'active')");
        $stmt->execute([$artistId,(int)$user['id'],$title,$version,$isrc,$duration,$explicit]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000'&&$isrc!==null)throw new RuntimeException('That ISRC is already assigned to another recording.');
        throw $e;
    }
    $id=(int)$pdo->lastInsertId();
    dt_catalog_event($pdo,$artistId,'recording',$id,'recording.created',(int)$user['id'],['isrc'=>$isrc]);
    return dt_catalog_recording($pdo,$artistId,$id)??throw new RuntimeException('Recording could not be loaded.');
}

function dt_catalog_archive_recording(PDO $pdo,int $artistId,int $recordingId,array $user): void
{
    dt_catalog_require_artist($pdo,$artistId,$user,'catalog');
    $recording=dt_catalog_recording($pdo,$artistId,$recordingId);
    if(!$recording)throw new RuntimeException('Recording was not found.');
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM music_release_tracks_v110 rt
        INNER JOIN music_releases_v110 r ON r.id=rt.release_id
        WHERE rt.recording_id=? AND r.release_status<>'archived'");
    $stmt->execute([$recordingId]);
    if((int)$stmt->fetchColumn()>0)throw new RuntimeException('Remove this recording from active releases before archiving it.');
    $pdo->prepare("UPDATE music_recordings_v110 SET recording_status='archived',updated_at=NOW() WHERE id=? AND artist_id=?")->execute([$recordingId,$artistId]);
    dt_catalog_event($pdo,$artistId,'recording',$recordingId,'recording.archived',(int)$user['id']);
}

function dt_catalog_unique_release_slug(PDO $pdo,int $artistId,string $value,int $excludeReleaseId=0): string
{
    $base=dt_catalog_slug($value);
    if($base==='')$base='release';
    for($n=0;$n<1000;$n++){
        $slug=$n===0?$base:$base.'-'.($n+1);
        $stmt=$pdo->prepare('SELECT 1 FROM music_releases_v110 WHERE artist_id=? AND slug=? AND id<>? LIMIT 1');
        $stmt->execute([$artistId,$slug,$excludeReleaseId]);
        if(!$stmt->fetchColumn())return $slug;
    }
    throw new RuntimeException('A unique release URL could not be generated.');
}

function dt_catalog_release(PDO $pdo,int $artistId,int $releaseId): ?array
{
    if($artistId<1||$releaseId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM music_releases_v110 WHERE id=? AND artist_id=? LIMIT 1');
    $stmt->execute([$releaseId,$artistId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_catalog_releases(PDO $pdo,int $artistId,bool $includeArchived=false): array
{
    $sql='SELECT r.*,(SELECT COUNT(*) FROM music_release_tracks_v110 rt WHERE rt.release_id=r.id) track_count,
        (SELECT COUNT(*) FROM music_release_editions_v110 e WHERE e.release_id=r.id AND e.edition_status=\'active\') edition_count
        FROM music_releases_v110 r WHERE r.artist_id=?';
    if(!$includeArchived)$sql.=" AND r.release_status<>'archived'";
    $sql.=' ORDER BY CASE WHEN release_date IS NULL THEN 1 ELSE 0 END,release_date DESC,id DESC';
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$artistId]);
    return $stmt->fetchAll()?:[];
}

function dt_catalog_create_release(PDO $pdo,int $artistId,array $user,array $input): array
{
    dt_catalog_require_artist($pdo,$artistId,$user,'catalog');
    $title=trim((string)($input['title']??''));
    if($title===''||mb_strlen($title)>190)throw new RuntimeException('Release title must be between 1 and 190 characters.');
    $types=dt_catalog_release_types();
    $type=(string)($input['release_type']??'album');
    if(!isset($types[$type]))throw new RuntimeException('Choose a valid release type.');
    $slug=dt_catalog_unique_release_slug($pdo,$artistId,(string)($input['slug']??$title));
    $releaseDate=trim((string)($input['release_date']??''));
    if($releaseDate!==''){
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$releaseDate);
        if(!$date||$date->format('Y-m-d')!==$releaseDate)throw new RuntimeException('Choose a valid release date.');
    }else $releaseDate=null;
    $upc=dt_catalog_normalize_upc((string)($input['upc']??''));
    try{
        $stmt=$pdo->prepare("INSERT INTO music_releases_v110
            (artist_id,created_by_user_id,title,slug,release_type,release_status,release_date,description,upc,label_name,copyright_notice)
            VALUES (?,?,?,?,?,'draft',?,?,?,?,?)");
        $stmt->execute([
            $artistId,(int)$user['id'],$title,$slug,$type,$releaseDate,
            trim((string)($input['description']??'')),$upc,
            trim((string)($input['label_name']??'')),trim((string)($input['copyright_notice']??'')),
        ]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000'&&$upc!==null)throw new RuntimeException('That UPC/EAN is already assigned to another release.');
        throw $e;
    }
    $id=(int)$pdo->lastInsertId();
    dt_catalog_event($pdo,$artistId,'release',$id,'release.created',(int)$user['id'],['release_type'=>$type]);
    return dt_catalog_release($pdo,$artistId,$id)??throw new RuntimeException('Release could not be loaded.');
}

function dt_catalog_release_tracks(PDO $pdo,int $artistId,int $releaseId): array
{
    if(!dt_catalog_release($pdo,$artistId,$releaseId))return [];
    $stmt=$pdo->prepare("SELECT rt.*,r.title recording_title,r.version_label,r.isrc,r.duration_ms
        FROM music_release_tracks_v110 rt
        INNER JOIN music_recordings_v110 r ON r.id=rt.recording_id
        WHERE rt.release_id=?
        ORDER BY rt.sequence_order,rt.disc_number,rt.track_number,rt.id");
    $stmt->execute([$releaseId]);
    return $stmt->fetchAll()?:[];
}

function dt_catalog_add_recording_to_release(PDO $pdo,int $artistId,int $releaseId,int $recordingId,array $user,int $discNumber=1,int $trackNumber=1,bool $isBonus=false): int
{
    dt_catalog_require_artist($pdo,$artistId,$user,'catalog');
    $release=dt_catalog_release($pdo,$artistId,$releaseId);
    $recording=dt_catalog_recording($pdo,$artistId,$recordingId);
    if(!$release||!$recording)throw new RuntimeException('Release and recording must belong to the same artist.');
    if((string)$release['release_status']==='archived')throw new RuntimeException('Archived releases cannot be edited.');
    if((string)$recording['recording_status']==='archived')throw new RuntimeException('Archived recordings cannot be added to releases.');
    $discNumber=max(1,$discNumber);
    $trackNumber=max(1,$trackNumber);
    try{
        $stmt=$pdo->prepare('INSERT INTO music_release_tracks_v110 (release_id,recording_id,disc_number,track_number,sequence_order,is_bonus) VALUES (?,?,?,?,?,?)');
        $sequence=(($discNumber-1)*1000)+$trackNumber;
        $stmt->execute([$releaseId,$recordingId,$discNumber,$trackNumber,$sequence,$isBonus?1:0]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')throw new RuntimeException('That disc and track position is already occupied.');
        throw $e;
    }
    $id=(int)$pdo->lastInsertId();
    dt_catalog_event($pdo,$artistId,'release_track',$id,'release.track_added',(int)$user['id'],[
        'release_id'=>$releaseId,'recording_id'=>$recordingId,'disc_number'=>$discNumber,'track_number'=>$trackNumber,
    ]);
    return $id;
}

function dt_catalog_edition_code(string $value): string
{
    $value=dt_catalog_slug($value);
    return $value!==''?$value:'standard';
}

function dt_catalog_create_edition(PDO $pdo,int $artistId,int $releaseId,array $user,array $input): array
{
    dt_catalog_require_artist($pdo,$artistId,$user,'catalog');
    $release=dt_catalog_release($pdo,$artistId,$releaseId);
    if(!$release)throw new RuntimeException('Release was not found.');
    $name=trim((string)($input['edition_name']??''));
    if($name===''||mb_strlen($name)>190)throw new RuntimeException('Edition name must be between 1 and 190 characters.');
    $format=(string)($input['edition_format']??'digital');
    if(!isset(dt_catalog_edition_formats()[$format]))throw new RuntimeException('Choose a valid edition format.');
    $code=dt_catalog_edition_code((string)($input['edition_code']??$name));
    try{
        $stmt=$pdo->prepare("INSERT INTO music_release_editions_v110
            (release_id,created_by_user_id,edition_name,edition_code,edition_format,edition_status,grants_digital_access)
            VALUES (?,?,?,?,?,'active',?)");
        $stmt->execute([$releaseId,(int)$user['id'],$name,$code,$format,!empty($input['grants_digital_access'])?1:0]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')throw new RuntimeException('That edition code already exists for this release.');
        throw $e;
    }
    $id=(int)$pdo->lastInsertId();
    dt_catalog_event($pdo,$artistId,'edition',$id,'edition.created',(int)$user['id'],['release_id'=>$releaseId,'format'=>$format]);
    $stmt=$pdo->prepare('SELECT * FROM music_release_editions_v110 WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch()?:throw new RuntimeException('Edition could not be loaded.');
}

function dt_catalog_release_editions(PDO $pdo,int $artistId,int $releaseId): array
{
    if(!dt_catalog_release($pdo,$artistId,$releaseId))return [];
    $stmt=$pdo->prepare("SELECT * FROM music_release_editions_v110 WHERE release_id=? AND edition_status='active' ORDER BY id");
    $stmt->execute([$releaseId]);
    return $stmt->fetchAll()?:[];
}

function dt_catalog_release_publishable(PDO $pdo,int $artistId,int $releaseId): bool
{
    $release=dt_catalog_release($pdo,$artistId,$releaseId);
    if(!$release||(string)$release['release_status']==='archived')return false;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM music_release_tracks_v110 rt
        INNER JOIN music_recordings_v110 r ON r.id=rt.recording_id
        WHERE rt.release_id=? AND r.artist_id=? AND r.recording_status='active'");
    $stmt->execute([$releaseId,$artistId]);
    return (int)$stmt->fetchColumn()>0;
}

function dt_catalog_publish_release(PDO $pdo,int $artistId,int $releaseId,array $user): void
{
    dt_catalog_require_artist($pdo,$artistId,$user,'releases');
    $release=dt_catalog_release($pdo,$artistId,$releaseId);
    if(!$release)throw new RuntimeException('Release was not found.');
    if(!dt_catalog_release_publishable($pdo,$artistId,$releaseId))throw new RuntimeException('A release must contain at least one active recording before publishing.');
    $pdo->prepare("UPDATE music_releases_v110
        SET release_status='published',published_at=COALESCE(published_at,NOW()),updated_at=NOW()
        WHERE id=? AND artist_id=?")->execute([$releaseId,$artistId]);
    dt_catalog_event($pdo,$artistId,'release',$releaseId,'release.published',(int)$user['id']);
}

function dt_catalog_archive_release(PDO $pdo,int $artistId,int $releaseId,array $user): void
{
    dt_catalog_require_artist($pdo,$artistId,$user,'catalog');
    if(!dt_catalog_release($pdo,$artistId,$releaseId))throw new RuntimeException('Release was not found.');
    $pdo->prepare("UPDATE music_releases_v110 SET release_status='archived',updated_at=NOW() WHERE id=? AND artist_id=?")->execute([$releaseId,$artistId]);
    dt_catalog_event($pdo,$artistId,'release',$releaseId,'release.archived',(int)$user['id']);
}

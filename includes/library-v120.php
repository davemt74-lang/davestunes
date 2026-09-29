<?php
declare(strict_types=1);

function dt_entitlement_resource_types(): array
{
    return ['recording','release','edition'];
}

function dt_entitlement_types(): array
{
    return ['own','access'];
}

function dt_entitlement_source_types(): array
{
    return ['purchase','gift','promotion','admin','subscription'];
}

function dt_library_user_exists(PDO $pdo,int $userId): bool
{
    if($userId<1)return false;
    $stmt=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND account_status='active' LIMIT 1");
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function dt_entitlement_resource_exists(PDO $pdo,string $type,int $id): bool
{
    if($id<1)return false;
    if($type==='recording'){
        $stmt=$pdo->prepare("SELECT 1 FROM music_recordings_v110 WHERE id=? AND recording_status<>'archived' LIMIT 1");
    }elseif($type==='release'){
        $stmt=$pdo->prepare("SELECT 1 FROM music_releases_v110 WHERE id=? AND release_status<>'archived' LIMIT 1");
    }elseif($type==='edition'){
        $stmt=$pdo->prepare("SELECT 1 FROM music_release_editions_v110 e
            INNER JOIN music_releases_v110 r ON r.id=e.release_id
            WHERE e.id=? AND e.edition_status='active' AND r.release_status<>'archived' LIMIT 1");
    }else return false;
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

function dt_entitlement_datetime(?string $value): ?string
{
    $value=trim((string)$value);
    if($value==='')return null;
    try{$date=new DateTimeImmutable($value);}catch(Throwable $e){throw new RuntimeException('Entitlement date is invalid.');}
    return $date->format('Y-m-d H:i:s');
}

function dt_entitlement_event(PDO $pdo,int $entitlementId,string $eventType,?int $actorUserId=null,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO music_entitlement_events_v120 (entitlement_id,event_type,actor_user_id,metadata_json) VALUES (?,?,?,?)');
    $stmt->execute([$entitlementId,substr(trim($eventType),0,80),$actorUserId?:null,$json]);
}

function dt_entitlement_by_grant_key(PDO $pdo,string $grantKey): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM music_entitlements_v120 WHERE grant_key=? LIMIT 1');
    $stmt->execute([$grantKey]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

/**
 * Internal fulfillment primitive. No public web surface calls this directly.
 * Commerce, gifts, promotions and governed admin actions must supply a durable
 * idempotency grant key from their own authoritative transaction.
 */
function dt_entitlement_grant(PDO $pdo,array $grant): array
{
    $grantKey=trim((string)($grant['grant_key']??''));
    $userId=(int)($grant['user_id']??0);
    $resourceType=(string)($grant['resource_type']??'');
    $resourceId=(int)($grant['resource_id']??0);
    $entitlementType=(string)($grant['entitlement_type']??'access');
    $sourceType=(string)($grant['source_type']??'');
    $sourceRef=substr(trim((string)($grant['source_ref']??'')),0,190);
    $grantor=max(0,(int)($grant['granted_by_user_id']??0));
    if($grantKey===''||mb_strlen($grantKey)>190)throw new RuntimeException('Entitlement grant key is required.');
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('Entitlement user must have an active account.');
    if(!in_array($resourceType,dt_entitlement_resource_types(),true)||!dt_entitlement_resource_exists($pdo,$resourceType,$resourceId))throw new RuntimeException('Entitlement resource was not found.');
    if(!in_array($entitlementType,dt_entitlement_types(),true))throw new RuntimeException('Entitlement type is invalid.');
    if(!in_array($sourceType,dt_entitlement_source_types(),true))throw new RuntimeException('Entitlement source is invalid.');
    if(in_array($sourceType,['purchase','gift','promotion','subscription'],true)&&$sourceRef==='')throw new RuntimeException('Entitlement source reference is required.');
    if($grantor>0&&!dt_library_user_exists($pdo,$grantor))throw new RuntimeException('Entitlement grantor must have an active account.');

    $starts=dt_entitlement_datetime($grant['starts_at']??null);
    $ends=dt_entitlement_datetime($grant['ends_at']??null);
    if($starts!==null&&$ends!==null&&$ends<=$starts)throw new RuntimeException('Entitlement end must be after its start.');

    $existing=dt_entitlement_by_grant_key($pdo,$grantKey);
    if($existing){
        foreach([
            'user_id'=>$userId,'resource_type'=>$resourceType,'resource_id'=>$resourceId,
            'entitlement_type'=>$entitlementType,'source_type'=>$sourceType,'source_ref'=>$sourceRef,
            'starts_at'=>$starts,'ends_at'=>$ends,'granted_by_user_id'=>$grantor?:null,
        ] as $field=>$expected){
            if((string)($existing[$field]??'')!==(string)($expected??''))throw new RuntimeException('Entitlement idempotency key conflicts with an existing grant.');
        }
        return $existing;
    }

    try{
        $stmt=$pdo->prepare("INSERT INTO music_entitlements_v120
            (grant_key,user_id,resource_type,resource_id,entitlement_type,source_type,source_ref,entitlement_status,starts_at,ends_at,granted_by_user_id)
            VALUES (?,?,?,?,?,?,?,'active',?,?,?)");
        $stmt->execute([$grantKey,$userId,$resourceType,$resourceId,$entitlementType,$sourceType,$sourceRef,$starts,$ends,$grantor?:null]);
        $id=(int)$pdo->lastInsertId();
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
        $existing=dt_entitlement_by_grant_key($pdo,$grantKey);
        if(!$existing)throw $e;
        foreach([
            'user_id'=>$userId,'resource_type'=>$resourceType,'resource_id'=>$resourceId,
            'entitlement_type'=>$entitlementType,'source_type'=>$sourceType,'source_ref'=>$sourceRef,
            'starts_at'=>$starts,'ends_at'=>$ends,'granted_by_user_id'=>$grantor?:null,
        ] as $field=>$expected){
            if((string)($existing[$field]??'')!==(string)($expected??''))throw new RuntimeException('Entitlement idempotency key conflicts with an existing grant.');
        }
        return $existing;
    }

    dt_entitlement_event($pdo,$id,'entitlement.granted',$grantor?:null,[
        'source_type'=>$sourceType,'source_ref'=>$sourceRef,'resource_type'=>$resourceType,'resource_id'=>$resourceId,
    ]);
    return dt_entitlement_by_grant_key($pdo,$grantKey)??throw new RuntimeException('Entitlement could not be loaded.');
}

/**
 * Internal revocation primitive. Commerce/refund, gift, subscription and
 * governed admin services are responsible for authorizing the caller before
 * invoking this function. There is intentionally no self-service web route.
 */
function dt_entitlement_revoke(PDO $pdo,int $entitlementId,?int $actorUserId=null,string $reason=''): void
{
    if($entitlementId<1)throw new RuntimeException('Entitlement was not found.');
    $stmt=$pdo->prepare('SELECT * FROM music_entitlements_v120 WHERE id=? LIMIT 1');
    $stmt->execute([$entitlementId]);
    $row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Entitlement was not found.');
    if((string)$row['entitlement_status']==='revoked')return;
    if($actorUserId!==null&&!dt_library_user_exists($pdo,$actorUserId))throw new RuntimeException('Revocation actor must have an active account.');
    $pdo->prepare("UPDATE music_entitlements_v120 SET entitlement_status='revoked',revoked_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$entitlementId]);
    dt_entitlement_event($pdo,$entitlementId,'entitlement.revoked',$actorUserId,['reason'=>substr(trim($reason),0,500)]);
}

function dt_entitlement_active_clause(string $alias='e'): string
{
    return "{$alias}.entitlement_status='active'
        AND ({$alias}.starts_at IS NULL OR {$alias}.starts_at<=NOW())
        AND ({$alias}.ends_at IS NULL OR {$alias}.ends_at>NOW())";
}

function dt_entitlement_user_has_release(PDO $pdo,int $userId,int $releaseId): bool
{
    if($userId<1||$releaseId<1)return false;
    $clause=dt_entitlement_active_clause('e');
    $stmt=$pdo->prepare("SELECT 1 FROM music_entitlements_v120 e
        WHERE e.user_id=? AND e.resource_type='release' AND e.resource_id=? AND {$clause}
        LIMIT 1");
    $stmt->execute([$userId,$releaseId]);
    if($stmt->fetchColumn())return true;

    $stmt=$pdo->prepare("SELECT 1 FROM music_entitlements_v120 e
        INNER JOIN music_release_editions_v110 ed ON ed.id=e.resource_id
        WHERE e.user_id=? AND e.resource_type='edition' AND ed.release_id=? AND ed.edition_status='active'
          AND ed.grants_digital_access=1 AND {$clause}
        LIMIT 1");
    $stmt->execute([$userId,$releaseId]);
    return (bool)$stmt->fetchColumn();
}

function dt_entitlement_user_has_recording(PDO $pdo,int $userId,int $recordingId): bool
{
    if($userId<1||$recordingId<1)return false;
    $clause=dt_entitlement_active_clause('e');
    $stmt=$pdo->prepare("SELECT 1 FROM music_entitlements_v120 e
        WHERE e.user_id=? AND e.resource_type='recording' AND e.resource_id=? AND {$clause}
        LIMIT 1");
    $stmt->execute([$userId,$recordingId]);
    if($stmt->fetchColumn())return true;

    $stmt=$pdo->prepare("SELECT 1 FROM music_release_tracks_v110 rt
        INNER JOIN music_entitlements_v120 e ON e.resource_type='release' AND e.resource_id=rt.release_id
        WHERE rt.recording_id=? AND e.user_id=? AND {$clause}
        LIMIT 1");
    $stmt->execute([$recordingId,$userId]);
    if($stmt->fetchColumn())return true;

    $stmt=$pdo->prepare("SELECT 1 FROM music_release_tracks_v110 rt
        INNER JOIN music_release_editions_v110 ed ON ed.release_id=rt.release_id AND ed.grants_digital_access=1 AND ed.edition_status='active'
        INNER JOIN music_entitlements_v120 e ON e.resource_type='edition' AND e.resource_id=ed.id
        WHERE rt.recording_id=? AND e.user_id=? AND {$clause}
        LIMIT 1");
    $stmt->execute([$recordingId,$userId]);
    return (bool)$stmt->fetchColumn();
}

function dt_library_release_is_public(PDO $pdo,int $releaseId): bool
{
    $stmt=$pdo->prepare("SELECT 1 FROM music_releases_v110 WHERE id=? AND release_status='published' LIMIT 1");
    $stmt->execute([$releaseId]);
    return (bool)$stmt->fetchColumn();
}

function dt_library_recording_is_public(PDO $pdo,int $recordingId): bool
{
    $stmt=$pdo->prepare("SELECT 1 FROM music_release_tracks_v110 rt
        INNER JOIN music_releases_v110 r ON r.id=rt.release_id AND r.release_status='published'
        INNER JOIN music_recordings_v110 rec ON rec.id=rt.recording_id AND rec.recording_status='active'
        WHERE rt.recording_id=? LIMIT 1");
    $stmt->execute([$recordingId]);
    return (bool)$stmt->fetchColumn();
}

function dt_library_save_release(PDO $pdo,int $userId,int $releaseId): void
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    if(!dt_library_release_is_public($pdo,$releaseId)&&!dt_entitlement_user_has_release($pdo,$userId,$releaseId))throw new RuntimeException('Release is not available to save.');
    $pdo->prepare('INSERT IGNORE INTO user_saved_releases_v120 (user_id,release_id) VALUES (?,?)')->execute([$userId,$releaseId]);
}

function dt_library_unsave_release(PDO $pdo,int $userId,int $releaseId): void
{
    $pdo->prepare('DELETE FROM user_saved_releases_v120 WHERE user_id=? AND release_id=?')->execute([$userId,$releaseId]);
}

function dt_library_save_recording(PDO $pdo,int $userId,int $recordingId): void
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    if(!dt_library_recording_is_public($pdo,$recordingId)&&!dt_entitlement_user_has_recording($pdo,$userId,$recordingId))throw new RuntimeException('Recording is not available to save.');
    $pdo->prepare('INSERT IGNORE INTO user_saved_recordings_v120 (user_id,recording_id) VALUES (?,?)')->execute([$userId,$recordingId]);
}

function dt_library_unsave_recording(PDO $pdo,int $userId,int $recordingId): void
{
    $pdo->prepare('DELETE FROM user_saved_recordings_v120 WHERE user_id=? AND recording_id=?')->execute([$userId,$recordingId]);
}

function dt_library_follow_artist(PDO $pdo,int $userId,int $artistId): void
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $artist=dt_artist_by_id($pdo,$artistId);
    if(!$artist||(string)$artist['artist_status']==='archived')throw new RuntimeException('Artist was not found.');
    $pdo->prepare('INSERT IGNORE INTO user_artist_follows_v120 (user_id,artist_id) VALUES (?,?)')->execute([$userId,$artistId]);
}

function dt_library_unfollow_artist(PDO $pdo,int $userId,int $artistId): void
{
    $pdo->prepare('DELETE FROM user_artist_follows_v120 WHERE user_id=? AND artist_id=?')->execute([$userId,$artistId]);
}

function dt_library_owned_releases(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $clause=dt_entitlement_active_clause('e');
    $sql="SELECT DISTINCT r.*
        FROM music_releases_v110 r
        LEFT JOIN music_entitlements_v120 e ON e.user_id=? AND e.entitlement_type='own' AND (
            (e.resource_type='release' AND e.resource_id=r.id)
            OR (e.resource_type='edition' AND EXISTS (
                SELECT 1 FROM music_release_editions_v110 ed
                WHERE ed.id=e.resource_id AND ed.release_id=r.id AND ed.edition_status='active' AND ed.grants_digital_access=1
            ))
        )
        WHERE e.id IS NOT NULL AND {$clause}
        ORDER BY r.release_date DESC,r.title,r.id";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_library_available_releases(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $clause=dt_entitlement_active_clause('e');
    $sql="SELECT DISTINCT r.*
        FROM music_releases_v110 r
        LEFT JOIN music_entitlements_v120 e ON e.user_id=? AND e.entitlement_type='access' AND (
            (e.resource_type='release' AND e.resource_id=r.id)
            OR (e.resource_type='edition' AND EXISTS (
                SELECT 1 FROM music_release_editions_v110 ed
                WHERE ed.id=e.resource_id AND ed.release_id=r.id AND ed.edition_status='active' AND ed.grants_digital_access=1
            ))
        )
        WHERE e.id IS NOT NULL AND {$clause}
        ORDER BY r.release_date DESC,r.title,r.id";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_library_owned_recordings(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $clause=dt_entitlement_active_clause('e');
    $sql="SELECT DISTINCT rec.*
        FROM music_recordings_v110 rec
        WHERE rec.recording_status='active' AND (
            EXISTS (
                SELECT 1 FROM music_entitlements_v120 e
                WHERE e.user_id=? AND e.entitlement_type='own'
                  AND e.resource_type='recording' AND e.resource_id=rec.id AND {$clause}
            )
            OR EXISTS (
                SELECT 1 FROM music_release_tracks_v110 rt
                INNER JOIN music_entitlements_v120 e ON e.resource_type='release' AND e.resource_id=rt.release_id
                WHERE rt.recording_id=rec.id AND e.user_id=? AND e.entitlement_type='own' AND {$clause}
            )
            OR EXISTS (
                SELECT 1 FROM music_release_tracks_v110 rt
                INNER JOIN music_release_editions_v110 ed ON ed.release_id=rt.release_id
                    AND ed.grants_digital_access=1 AND ed.edition_status='active'
                INNER JOIN music_entitlements_v120 e ON e.resource_type='edition' AND e.resource_id=ed.id
                WHERE rt.recording_id=rec.id AND e.user_id=? AND e.entitlement_type='own' AND {$clause}
            )
        )
        ORDER BY rec.title,rec.id";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$userId,$userId,$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_library_saved_recordings(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare("SELECT rec.*,a.name artist_name,s.created_at saved_at
        FROM user_saved_recordings_v120 s
        INNER JOIN music_recordings_v110 rec ON rec.id=s.recording_id
        INNER JOIN artists a ON a.id=rec.artist_id
        WHERE s.user_id=? ORDER BY s.created_at DESC,rec.id DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_library_saved_releases(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare("SELECT r.*,a.name artist_name,a.slug artist_slug,s.created_at saved_at
        FROM user_saved_releases_v120 s
        INNER JOIN music_releases_v110 r ON r.id=s.release_id
        INNER JOIN artists a ON a.id=r.artist_id
        WHERE s.user_id=? ORDER BY s.created_at DESC,r.id DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_library_followed_artists(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare("SELECT a.*,f.created_at followed_at
        FROM user_artist_follows_v120 f INNER JOIN artists a ON a.id=f.artist_id
        WHERE f.user_id=? ORDER BY f.created_at DESC,a.name");
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_library_published_releases(PDO $pdo,int $limit=60): array
{
    $limit=max(1,min(200,$limit));
    $sql="SELECT r.*,a.name artist_name,a.slug artist_slug
        FROM music_releases_v110 r INNER JOIN artists a ON a.id=r.artist_id
        WHERE r.release_status='published' AND a.artist_status='active'
        ORDER BY COALESCE(r.release_date,DATE(r.published_at)) DESC,r.published_at DESC,r.id DESC
        LIMIT ".$limit;
    return $pdo->query($sql)->fetchAll()?:[];
}

function dt_library_crate_slug(string $value): string
{
    $value=dt_catalog_slug($value);
    return $value!==''?$value:'crate';
}

function dt_library_unique_crate_slug(PDO $pdo,int $userId,string $name): string
{
    $base=dt_library_crate_slug($name);
    for($n=0;$n<1000;$n++){
        $slug=$n===0?$base:$base.'-'.($n+1);
        $stmt=$pdo->prepare('SELECT 1 FROM music_crates_v120 WHERE user_id=? AND crate_slug=? LIMIT 1');
        $stmt->execute([$userId,$slug]);
        if(!$stmt->fetchColumn())return $slug;
    }
    throw new RuntimeException('A unique crate name could not be generated.');
}

function dt_library_create_crate(PDO $pdo,int $userId,string $name): array
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $name=trim($name);
    if($name===''||mb_strlen($name)>120)throw new RuntimeException('Crate name must be between 1 and 120 characters.');
    $slug=dt_library_unique_crate_slug($pdo,$userId,$name);
    $stmt=$pdo->prepare('INSERT INTO music_crates_v120 (user_id,crate_name,crate_slug) VALUES (?,?,?)');
    $stmt->execute([$userId,$name,$slug]);
    $id=(int)$pdo->lastInsertId();
    $stmt=$pdo->prepare('SELECT * FROM music_crates_v120 WHERE id=? AND user_id=?');
    $stmt->execute([$id,$userId]);
    return $stmt->fetch()?:throw new RuntimeException('Crate could not be loaded.');
}

function dt_library_crates(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare("SELECT c.*,(SELECT COUNT(*) FROM music_crate_items_v120 i WHERE i.crate_id=c.id) item_count
        FROM music_crates_v120 c WHERE c.user_id=? ORDER BY c.updated_at DESC,c.id DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_library_crate(PDO $pdo,int $userId,int $crateId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM music_crates_v120 WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$crateId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_library_can_collect(PDO $pdo,int $userId,string $resourceType,int $resourceId): bool
{
    if($resourceType==='release')return dt_library_release_is_public($pdo,$resourceId)||dt_entitlement_user_has_release($pdo,$userId,$resourceId);
    if($resourceType==='recording')return dt_library_recording_is_public($pdo,$resourceId)||dt_entitlement_user_has_recording($pdo,$userId,$resourceId);
    return false;
}

function dt_library_add_to_crate(PDO $pdo,int $userId,int $crateId,string $resourceType,int $resourceId): void
{
    if(!in_array($resourceType,['release','recording'],true))throw new RuntimeException('Crates support releases and recordings.');
    if(!dt_library_crate($pdo,$userId,$crateId))throw new RuntimeException('Crate was not found.');
    if(!dt_library_can_collect($pdo,$userId,$resourceType,$resourceId))throw new RuntimeException('That music is not available to collect.');
    $stmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM music_crate_items_v120 WHERE crate_id=?');
    $stmt->execute([$crateId]);
    $sort=(int)$stmt->fetchColumn();
    $pdo->prepare('INSERT IGNORE INTO music_crate_items_v120 (crate_id,resource_type,resource_id,sort_order) VALUES (?,?,?,?)')->execute([$crateId,$resourceType,$resourceId,$sort]);
}

function dt_library_remove_from_crate(PDO $pdo,int $userId,int $crateId,string $resourceType,int $resourceId): void
{
    if(!dt_library_crate($pdo,$userId,$crateId))throw new RuntimeException('Crate was not found.');
    $pdo->prepare('DELETE FROM music_crate_items_v120 WHERE crate_id=? AND resource_type=? AND resource_id=?')->execute([$crateId,$resourceType,$resourceId]);
}

function dt_library_create_playlist(PDO $pdo,int $userId,string $name,string $description=''): array
{
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('User was not found.');
    $name=trim($name);
    if($name===''||mb_strlen($name)>120)throw new RuntimeException('Playlist name must be between 1 and 120 characters.');
    $stmt=$pdo->prepare('INSERT INTO music_playlists_v120 (user_id,playlist_name,description) VALUES (?,?,?)');
    $stmt->execute([$userId,$name,trim($description)]);
    $id=(int)$pdo->lastInsertId();
    $stmt=$pdo->prepare('SELECT * FROM music_playlists_v120 WHERE id=? AND user_id=?');
    $stmt->execute([$id,$userId]);
    return $stmt->fetch()?:throw new RuntimeException('Playlist could not be loaded.');
}

function dt_library_playlist(PDO $pdo,int $userId,int $playlistId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM music_playlists_v120 WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$playlistId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_library_add_to_playlist(PDO $pdo,int $userId,int $playlistId,int $recordingId): void
{
    if(!dt_library_playlist($pdo,$userId,$playlistId))throw new RuntimeException('Playlist was not found.');
    if(!dt_library_can_collect($pdo,$userId,'recording',$recordingId))throw new RuntimeException('That recording is not available to add.');
    $stmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM music_playlist_recordings_v120 WHERE playlist_id=?');
    $stmt->execute([$playlistId]);
    $sort=(int)$stmt->fetchColumn();
    $pdo->prepare('INSERT IGNORE INTO music_playlist_recordings_v120 (playlist_id,recording_id,sort_order) VALUES (?,?,?)')->execute([$playlistId,$recordingId,$sort]);
}

function dt_library_remove_from_playlist(PDO $pdo,int $userId,int $playlistId,int $recordingId): void
{
    if(!dt_library_playlist($pdo,$userId,$playlistId))throw new RuntimeException('Playlist was not found.');
    $pdo->prepare('DELETE FROM music_playlist_recordings_v120 WHERE playlist_id=? AND recording_id=?')->execute([$playlistId,$recordingId]);
}

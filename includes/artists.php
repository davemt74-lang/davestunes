<?php
declare(strict_types=1);

function dt_artist_slugify(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
    return substr(trim($value,'-'),0,120);
}

function dt_artist_unique_slug(PDO $pdo,string $value,int $excludeArtistId=0): string
{
    $base=dt_artist_slugify($value);
    if($base==='')$base='artist';
    for($n=0;$n<1000;$n++){
        $slug=$n===0?$base:$base.'-'.($n+1);
        $stmt=$pdo->prepare('SELECT 1 FROM artists WHERE slug=? AND id<>? LIMIT 1');
        $stmt->execute([$slug,$excludeArtistId]);
        if(!$stmt->fetchColumn())return $slug;
    }
    throw new RuntimeException('A unique artist URL could not be generated.');
}

function dt_artist_valid_role(string $role): bool
{
    return in_array($role,['owner','manager','editor','producer','viewer'],true);
}

function dt_artist_valid_membership_status(string $status): bool
{
    return in_array($status,['active','suspended','removed'],true);
}

function dt_artist_event(PDO $pdo,int $artistId,string $eventType,?int $actorId=null,?int $subjectId=null,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO artist_authority_events (artist_id,event_type,actor_user_id,subject_user_id,metadata_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$artistId,substr(trim($eventType),0,80),$actorId?:null,$subjectId?:null,$json]);
}

function dt_artist_by_id(PDO $pdo,int $artistId): ?array
{
    if($artistId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM artists WHERE id=? LIMIT 1');
    $stmt->execute([$artistId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_artist_by_slug(PDO $pdo,string $slug): ?array
{
    $slug=dt_artist_slugify($slug);
    if($slug==='')return null;
    $stmt=$pdo->prepare('SELECT * FROM artists WHERE slug=? LIMIT 1');
    $stmt->execute([$slug]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_artist_membership(PDO $pdo,int $artistId,int $userId): ?array
{
    if($artistId<1||$userId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_memberships WHERE artist_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$artistId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_artist_upsert_membership(PDO $pdo,int $artistId,int $userId,string $role,string $status='active',?int $createdBy=null): void
{
    if(!dt_artist_valid_role($role)||!dt_artist_valid_membership_status($status))throw new RuntimeException('Invalid artist membership.');
    $stmt=$pdo->prepare('INSERT INTO artist_memberships (artist_id,user_id,artist_role,membership_status,created_by_user_id)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE artist_role=VALUES(artist_role),membership_status=VALUES(membership_status),updated_at=NOW()');
    $stmt->execute([$artistId,$userId,$role,$status,$createdBy?:null]);
}

function dt_artist_create(PDO $pdo,array $user,string $name,string $requestedSlug=''): array
{
    $userId=(int)($user['id']??0);
    $name=trim($name);
    if($userId<1)throw new RuntimeException('Sign in is required.');
    if($name===''||mb_strlen($name)>190)throw new RuntimeException('Artist name must be between 1 and 190 characters.');

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $slug=dt_artist_unique_slug($pdo,$requestedSlug!==''?$requestedSlug:$name);
        $stmt=$pdo->prepare("INSERT INTO artists (owner_user_id,name,slug,artist_status,verification_status)
            VALUES (?,?,?,'active','unverified')");
        $stmt->execute([$userId,$name,$slug]);
        $artistId=(int)$pdo->lastInsertId();
        dt_artist_upsert_membership($pdo,$artistId,$userId,'owner','active',$userId);
        dt_artist_event($pdo,$artistId,'artist.created',$userId,$userId,['slug'=>$slug]);
        if($ownsTransaction)$pdo->commit();
        return dt_artist_by_id($pdo,$artistId)??throw new RuntimeException('Artist could not be loaded.');
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_artists_for_user(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $stmt=$pdo->prepare("SELECT a.*,m.artist_role,m.membership_status
        FROM artist_memberships m
        INNER JOIN artists a ON a.id=m.artist_id
        WHERE m.user_id=? AND m.membership_status='active' AND a.artist_status<>'archived'
        ORDER BY a.name,a.id");
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_artist_role(PDO $pdo,int $artistId,int $userId): string
{
    $artist=dt_artist_by_id($pdo,$artistId);
    if(!$artist||(string)$artist['artist_status']==='archived')return '';
    if((int)$artist['owner_user_id']===$userId)return 'owner';
    $membership=dt_artist_membership($pdo,$artistId,$userId);
    if(!$membership||(string)$membership['membership_status']!=='active')return '';
    $role=(string)$membership['artist_role'];
    return dt_artist_valid_role($role)?$role:'';
}

function dt_artist_capabilities(string $role): array
{
    return match($role){
        'owner'=>['view','profile','catalog','releases','media','commerce','team','production','credits','transfer'],
        'manager'=>['view','profile','catalog','releases','media','commerce','team','production','credits'],
        'editor'=>['view','profile','catalog','media','credits'],
        'producer'=>['view','production','credits'],
        'viewer'=>['view'],
        default=>[],
    };
}

function dt_artist_can(PDO $pdo,int $artistId,int $userId,string $capability): bool
{
    return in_array($capability,dt_artist_capabilities(dt_artist_role($pdo,$artistId,$userId)),true);
}

function dt_artist_set_membership(PDO $pdo,int $artistId,int $subjectUserId,string $role,string $status,array $actor): void
{
    $actorId=(int)($actor['id']??0);
    $artist=dt_artist_by_id($pdo,$artistId);
    if(!$artist)throw new RuntimeException('Artist was not found.');
    if(!dt_artist_can($pdo,$artistId,$actorId,'team'))throw new RuntimeException('You cannot manage this artist team.');
    if(!dt_artist_valid_role($role)||$role==='owner'||!dt_artist_valid_membership_status($status))throw new RuntimeException('Choose a valid artist team role.');
    if($subjectUserId===(int)$artist['owner_user_id'])throw new RuntimeException('Transfer ownership instead of editing the owner membership.');

    $actorRole=dt_artist_role($pdo,$artistId,$actorId);
    $before=dt_artist_membership($pdo,$artistId,$subjectUserId);
    if($actorRole==='manager'){
        if($role==='manager'||(string)($before['artist_role']??'')==='manager')throw new RuntimeException('Only the artist owner can manage Manager access.');
    }

    dt_artist_upsert_membership($pdo,$artistId,$subjectUserId,$role,$status,$actorId);
    dt_artist_event($pdo,$artistId,'membership.changed',$actorId,$subjectUserId,[
        'before_role'=>(string)($before['artist_role']??''),
        'before_status'=>(string)($before['membership_status']??''),
        'after_role'=>$role,
        'after_status'=>$status,
    ]);
}

function dt_artist_transfer_owner(PDO $pdo,int $artistId,int $newOwnerUserId,array $actor): void
{
    $actorId=(int)($actor['id']??0);
    if($artistId<1||$newOwnerUserId<1||$actorId<1)throw new RuntimeException('Artist ownership could not be resolved.');
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM artists WHERE id=? FOR UPDATE');
        $stmt->execute([$artistId]);
        $artist=$stmt->fetch();
        if(!$artist)throw new RuntimeException('Artist was not found.');
        $oldOwnerId=(int)$artist['owner_user_id'];
        if($actorId!==$oldOwnerId)throw new RuntimeException('Only the current owner can transfer artist ownership.');
        $target=dt_auth_user_by_id($pdo,$newOwnerUserId);
        if(!$target||(string)$target['account_status']!=='active')throw new RuntimeException('New owner must have an active Dave\'s Tunes account.');
        if($newOwnerUserId===$oldOwnerId){
            if($ownsTransaction)$pdo->commit();
            return;
        }
        dt_artist_upsert_membership($pdo,$artistId,$oldOwnerId,'manager','active',$actorId);
        dt_artist_upsert_membership($pdo,$artistId,$newOwnerUserId,'owner','active',$actorId);
        $pdo->prepare('UPDATE artists SET owner_user_id=?,updated_at=NOW() WHERE id=?')->execute([$newOwnerUserId,$artistId]);
        dt_artist_event($pdo,$artistId,'ownership.transferred',$actorId,$newOwnerUserId,['previous_owner_user_id'=>$oldOwnerId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

<?php
declare(strict_types=1);

function dt_admin_emails(): array
{
    $value=(string)dt_config('admin.emails','');
    $emails=array_filter(array_map(static fn(string $v): string => strtolower(trim($v)),explode(',',$value)));
    return array_values(array_unique($emails));
}

function dt_is_admin(?array $user=null): bool
{
    $user??=dt_current_user();
    if(!$user)return false;
    return in_array(strtolower((string)($user['email']??'')),dt_admin_emails(),true);
}

function dt_require_admin(?PDO $pdo=null): array
{
    $user=dt_require_user($pdo);
    if(!dt_is_admin($user)){http_response_code(403);exit('Administrator access required.');}
    return $user;
}

function dt_featured_event(PDO $pdo,?int $postId,?int $actorId,string $type,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO featured_post_events_v270 (featured_post_id,actor_user_id,event_type,metadata_json) VALUES (?,?,?,?)');
    $stmt->execute([$postId?:null,$actorId?:null,substr($type,0,80),$json]);
}

function dt_featured_validate_content(PDO $pdo,string $type,int $id): void
{
    if($type==='release'){
        $stmt=$pdo->prepare("SELECT 1 FROM music_releases_v110 WHERE id=? AND release_status='published' LIMIT 1");
    }elseif($type==='recording'){
        $stmt=$pdo->prepare("SELECT 1 FROM music_recordings_v110 WHERE id=? AND recording_status='active' LIMIT 1");
    }else throw new RuntimeException('Featured content must be an album or song.');
    $stmt->execute([$id]);
    if(!$stmt->fetchColumn())throw new RuntimeException('Featured content was not found or is not available.');
}

function dt_featured_save(PDO $pdo,array $user,array $input,?int $postId=null): int
{
    if(!dt_is_admin($user))throw new RuntimeException('Administrator access required.');
    $type=(string)($input['content_type']??'');
    $contentId=(int)($input['content_id']??0);
    dt_featured_validate_content($pdo,$type,$contentId);
    $headline=mb_substr(trim((string)($input['headline']??'')),0,190);
    $body=mb_substr(trim((string)($input['body_text']??'')),0,500);
    $status=(string)($input['post_status']??'draft');
    if(!in_array($status,['draft','active','expired'],true))throw new RuntimeException('Featured status is invalid.');
    $priority=max(-10000,min(10000,(int)($input['priority']??0)));
    $starts=trim((string)($input['starts_at']??''))?:null;
    $ends=trim((string)($input['ends_at']??''))?:null;
    if($starts!==null&&strtotime($starts)===false)throw new RuntimeException('Start date is invalid.');
    if($ends!==null&&strtotime($ends)===false)throw new RuntimeException('End date is invalid.');
    if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new RuntimeException('End date must be after start date.');
    if($postId){
        $stmt=$pdo->prepare("UPDATE featured_posts_v270 SET content_type=?,content_id=?,headline=?,body_text=?,post_status=?,priority=?,starts_at=?,ends_at=?,updated_at=NOW() WHERE id=?");
        $stmt->execute([$type,$contentId,$headline,$body,$status,$priority,$starts,$ends,$postId]);
        dt_featured_event($pdo,$postId,(int)$user['id'],'featured.updated',['status'=>$status]);
        return $postId;
    }
    $stmt=$pdo->prepare("INSERT INTO featured_posts_v270 (content_type,content_id,headline,body_text,post_status,priority,starts_at,ends_at,created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$type,$contentId,$headline,$body,$status,$priority,$starts,$ends,(int)$user['id']]);
    $id=(int)$pdo->lastInsertId();
    dt_featured_event($pdo,$id,(int)$user['id'],'featured.created',['status'=>$status]);
    return $id;
}

function dt_featured_delete(PDO $pdo,array $user,int $postId): void
{
    if(!dt_is_admin($user))throw new RuntimeException('Administrator access required.');
    $pdo->prepare('DELETE FROM featured_posts_v270 WHERE id=?')->execute([$postId]);
    dt_featured_event($pdo,null,(int)$user['id'],'featured.deleted',['post_id'=>$postId]);
}

function dt_featured_admin_rows(PDO $pdo): array
{
    return $pdo->query("SELECT f.*,
      CASE WHEN f.content_type='release' THEN r.title ELSE rec.title END content_title,
      a.name artist_name
      FROM featured_posts_v270 f
      LEFT JOIN music_releases_v110 r ON f.content_type='release' AND r.id=f.content_id
      LEFT JOIN music_recordings_v110 rec ON f.content_type='recording' AND rec.id=f.content_id
      LEFT JOIN artists a ON a.id=COALESCE(r.artist_id,rec.artist_id)
      ORDER BY f.priority DESC,f.updated_at DESC,f.id DESC")->fetchAll()?:[];
}

function dt_featured_catalog_options(PDO $pdo): array
{
    $releases=$pdo->query("SELECT r.id,r.title,a.name artist_name FROM music_releases_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.release_status='published' ORDER BY a.name,r.title")->fetchAll()?:[];
    $recordings=$pdo->query("SELECT r.id,r.title,a.name artist_name FROM music_recordings_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.recording_status='active' ORDER BY a.name,r.title")->fetchAll()?:[];
    return ['releases'=>$releases,'recordings'=>$recordings];
}

function dt_featured_desktop(PDO $pdo,array $user,int $limit=8): array
{
    $stmt=$pdo->prepare("SELECT f.* FROM featured_posts_v270 f
      WHERE f.placement='user-desktop' AND f.post_status='active'
        AND (f.starts_at IS NULL OR f.starts_at<=NOW())
        AND (f.ends_at IS NULL OR f.ends_at>NOW())
      ORDER BY f.priority DESC,f.id DESC LIMIT ?");
    $stmt->bindValue(1,max(1,min(24,$limit)),PDO::PARAM_INT);
    $stmt->execute();
    $items=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $type=(string)$row['content_type']; $id=(int)$row['content_id'];
        if($type==='release'){
            $q=$pdo->prepare("SELECT r.*,a.name artist_name,a.slug artist_slug FROM music_releases_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? AND r.release_status='published' LIMIT 1");
            $q->execute([$id]); $release=$q->fetch(); if(!$release)continue;
            $content=dt_desktop_data_release_dto($pdo,(int)$user['id'],$user,$release,'public',false);
        }else{
            $q=$pdo->prepare("SELECT r.*,a.name artist_name FROM music_recordings_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? AND r.recording_status='active' LIMIT 1");
            $q->execute([$id]); $recording=$q->fetch(); if(!$recording)continue;
            $content=dt_desktop_data_song_dto($pdo,(int)$user['id'],$user,$recording,'public',false);
        }
        $items[]=[
            'id'=>(int)$row['id'],
            'type'=>$type,
            'headline'=>(string)$row['headline'],
            'body'=>(string)$row['body_text'],
            'content'=>$content,
        ];
    }
    return $items;
}

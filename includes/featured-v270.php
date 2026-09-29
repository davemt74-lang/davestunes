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
    if($type==='recording'&&!dt_library_recording_is_public($pdo,$id))throw new RuntimeException('Featured songs must belong to a published release.');
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
    $recordings=$pdo->query("SELECT DISTINCT r.id,r.title,a.name artist_name
      FROM music_recordings_v110 r
      INNER JOIN artists a ON a.id=r.artist_id
      INNER JOIN music_release_tracks_v110 rt ON rt.recording_id=r.id
      INNER JOIN music_releases_v110 rel ON rel.id=rt.release_id AND rel.release_status='published'
      WHERE r.recording_status='active' ORDER BY a.name,r.title")->fetchAll()?:[];
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
    if(!$items){
        foreach(dt_library_published_releases($pdo,$limit) as $release){
            $releaseId=(int)$release['id'];
            $items[]=[
                'id'=>0,
                'type'=>'release',
                'headline'=>(string)$release['title'],
                'body'=>'Featured from '.(string)$release['artist_name'].'.',
                'content'=>dt_desktop_data_release_dto($pdo,(int)$user['id'],$user,$release,'public',false),
            ];
        }
    }
    return $items;
}


function dt_featured_active_rows(PDO $pdo,int $limit=10): array
{
    $limit=max(1,min(24,$limit));
    $stmt=$pdo->prepare("SELECT f.*,
      CASE WHEN f.content_type='release' THEN r.title ELSE rec.title END content_title,
      a.name artist_name,a.slug artist_slug,
      CASE WHEN f.content_type='release' THEN r.cover_path ELSE (
        SELECT rel.cover_path FROM music_release_tracks_v110 rt
        INNER JOIN music_releases_v110 rel ON rel.id=rt.release_id AND rel.release_status='published'
        WHERE rt.recording_id=rec.id ORDER BY rel.release_date DESC,rel.id DESC LIMIT 1
      ) END cover_path
      FROM featured_posts_v270 f
      LEFT JOIN music_releases_v110 r ON f.content_type='release' AND r.id=f.content_id AND r.release_status='published'
      LEFT JOIN music_recordings_v110 rec ON f.content_type='recording' AND rec.id=f.content_id AND rec.recording_status='active'
      LEFT JOIN artists a ON a.id=COALESCE(r.artist_id,rec.artist_id) AND a.artist_status='active'
      WHERE f.post_status='active'
        AND (f.starts_at IS NULL OR f.starts_at<=NOW())
        AND (f.ends_at IS NULL OR f.ends_at>NOW())
      ORDER BY f.priority DESC,f.id DESC LIMIT ?");
    $stmt->bindValue(1,$limit,PDO::PARAM_INT);
    $stmt->execute();
    return array_values(array_filter($stmt->fetchAll()?:[],static fn(array $row): bool => !empty($row['content_title'])&&!empty($row['artist_name'])));
}

function dt_featured_public_albums(PDO $pdo,int $limit=8): array
{
    $limit=max(1,min(16,$limit));
    $items=[];
    foreach(dt_featured_active_rows($pdo,$limit*2) as $row){
        if((string)$row['content_type']!=='release')continue;
        $releaseId=(int)$row['content_id'];
        $items[]=[
            'id'=>$releaseId,
            'title'=>(string)$row['content_title'],
            'artistName'=>(string)$row['artist_name'],
            'artistSlug'=>(string)$row['artist_slug'],
            'coverUrl'=>dt_desktop_data_cover_url((string)($row['cover_path']??'')),
            'headline'=>(string)$row['headline'],
            'body'=>(string)$row['body_text'],
            'albumUrl'=>'/album.php?release='.$releaseId,
            'experienceUrl'=>dt_experience_active($pdo,'release',$releaseId,'default')?'/album-experience.php?release='.$releaseId:'',
        ];
        if(count($items)>=$limit)break;
    }
    if($items)return $items;

    foreach(dt_library_published_releases($pdo,$limit) as $release){
        $releaseId=(int)$release['id'];
        $items[]=[
            'id'=>$releaseId,
            'title'=>(string)$release['title'],
            'artistName'=>(string)$release['artist_name'],
            'artistSlug'=>(string)($release['artist_slug']??''),
            'coverUrl'=>dt_desktop_data_cover_url((string)($release['cover_path']??'')),
            'headline'=>(string)$release['title'],
            'body'=>'Featured from '.(string)$release['artist_name'].'.',
            'albumUrl'=>'/album.php?release='.$releaseId,
            'experienceUrl'=>dt_experience_active($pdo,'release',$releaseId,'default')?'/album-experience.php?release='.$releaseId:'',
        ];
    }
    return $items;
}

function dt_featured_public_news(PDO $pdo,int $limit=5): array
{
    $limit=max(1,min(10,$limit));
    $items=[];
    foreach(dt_news_active($pdo,'public-desktop',$limit) as $row){
        $items[]=[
            'headline'=>(string)$row['headline'],
            'body'=>(string)$row['body_text'],
            'url'=>(string)$row['link_url']!==''?(string)$row['link_url']:'/news.php?slug='.rawurlencode((string)$row['slug']),
            'imageUrl'=>(string)$row['image_url'],
            'linkLabel'=>(string)$row['link_label'],
            'publishedAt'=>$row['published_at'],
            'source'=>'news',
        ];
    }
    if($items)return $items;
    foreach(dt_featured_active_rows($pdo,$limit) as $row){
        $headline=trim((string)$row['headline']);
        $body=trim((string)$row['body_text']);
        if($headline==='')$headline=(string)$row['content_title'];
        $items[]=[
            'headline'=>$headline,
            'body'=>$body!==''?$body:((string)$row['artist_name'].' · '.(string)$row['content_title']),
            'url'=>(string)$row['content_type']==='release'?'/album.php?release='.(int)$row['content_id']:'/artist.php?artist='.rawurlencode((string)$row['artist_slug']),
        ];
    }
    if($items)return $items;
    foreach(array_slice(dt_library_published_releases($pdo,10),0,$limit) as $release){
        $items[]=[
            'headline'=>'New release · '.(string)$release['title'],
            'body'=>(string)$release['artist_name'],
            'url'=>'/album.php?release='.(int)$release['id'],
        ];
    }
    return $items;
}


function dt_news_slug(string $value): string
{
    $slug=dt_catalog_slug($value);
    return $slug!==''?$slug:'news';
}

function dt_news_unique_slug(PDO $pdo,string $headline,?int $excludeId=null): string
{
    $base=dt_news_slug($headline);
    for($n=0;$n<1000;$n++){
        $slug=$n===0?$base:$base.'-'.($n+1);
        $sql='SELECT 1 FROM news_posts_v110 WHERE slug=?'.($excludeId?' AND id<>?':'').' LIMIT 1';
        $stmt=$pdo->prepare($sql);
        $params=[$slug];
        if($excludeId)$params[]=$excludeId;
        $stmt->execute($params);
        if(!$stmt->fetchColumn())return $slug;
    }
    throw new RuntimeException('A unique news slug could not be generated.');
}

function dt_news_url(string $value,bool $allowEmpty=true): string
{
    $value=trim($value);
    if($value==='')return $allowEmpty?'':throw new RuntimeException('URL is required.');
    if(str_starts_with($value,'/')){
        if(str_contains($value,'..')||preg_match('/[\r\n]/',$value))throw new RuntimeException('Internal URL is invalid.');
        return $value;
    }
    $parts=parse_url($value);
    if(!is_array($parts)||!isset($parts['scheme'])||!in_array(strtolower((string)$parts['scheme']),['http','https'],true)){
        throw new RuntimeException('URL must be an internal path or HTTP(S) URL.');
    }
    return $value;
}

function dt_news_event(PDO $pdo,?int $postId,?int $actorId,string $type,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO news_post_events_v110 (news_post_id,actor_user_id,event_type,metadata_json) VALUES (?,?,?,?)');
    $stmt->execute([$postId?:null,$actorId?:null,substr(trim($type),0,80),$json]);
}

function dt_news_save(PDO $pdo,array $user,array $input,?int $postId=null): int
{
    if(!dt_is_admin($user))throw new RuntimeException('Administrator access required.');
    if(!dt_news_schema_ready($pdo))throw new RuntimeException('News & Notes schema is not installed.');

    $headline=mb_substr(trim((string)($input['headline']??'')),0,190);
    if($headline==='')throw new RuntimeException('Headline is required.');
    $body=trim((string)($input['body_text']??''));
    if($body==='')throw new RuntimeException('Body is required.');
    if(mb_strlen($body)>12000)throw new RuntimeException('Body must be 12,000 characters or fewer.');

    $image=dt_news_url((string)($input['image_url']??''));
    $link=dt_news_url((string)($input['link_url']??''));
    $linkLabel=mb_substr(trim((string)($input['link_label']??'')),0,80);
    $placement=(string)($input['placement']??'public-desktop');
    if(!in_array($placement,['public-desktop','signed-in-desktop','both'],true))throw new RuntimeException('News placement is invalid.');
    $status=(string)($input['post_status']??'draft');
    if(!in_array($status,['draft','active','expired'],true))throw new RuntimeException('News status is invalid.');
    $priority=max(-10000,min(10000,(int)($input['priority']??0)));

    $starts=trim((string)($input['starts_at']??''))?:null;
    $ends=trim((string)($input['ends_at']??''))?:null;
    if($starts!==null&&strtotime($starts)===false)throw new RuntimeException('Start date is invalid.');
    if($ends!==null&&strtotime($ends)===false)throw new RuntimeException('End date is invalid.');
    if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new RuntimeException('End date must be after start date.');

    $slug=dt_news_unique_slug($pdo,$headline,$postId);
    $actorId=(int)$user['id'];
    if($postId){
        $stmt=$pdo->prepare("UPDATE news_posts_v110 SET slug=?,headline=?,body_text=?,image_url=?,link_url=?,link_label=?,placement=?,post_status=?,priority=?,starts_at=?,ends_at=?,published_at=CASE WHEN ?='active' AND published_at IS NULL THEN NOW() ELSE published_at END,updated_by_user_id=?,updated_at=NOW() WHERE id=?");
        $stmt->execute([$slug,$headline,$body,$image,$link,$linkLabel,$placement,$status,$priority,$starts,$ends,$status,$actorId,$postId]);
        if($stmt->rowCount()===0){
            $check=$pdo->prepare('SELECT 1 FROM news_posts_v110 WHERE id=? LIMIT 1');
            $check->execute([$postId]);
            if(!$check->fetchColumn())throw new RuntimeException('News post was not found.');
        }
        dt_news_event($pdo,$postId,$actorId,'news.updated',['status'=>$status,'placement'=>$placement]);
        return $postId;
    }

    $stmt=$pdo->prepare("INSERT INTO news_posts_v110
        (slug,headline,body_text,image_url,link_url,link_label,placement,post_status,priority,starts_at,ends_at,published_at,created_by_user_id,updated_by_user_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,CASE WHEN ?='active' THEN NOW() ELSE NULL END,?,?)");
    $stmt->execute([$slug,$headline,$body,$image,$link,$linkLabel,$placement,$status,$priority,$starts,$ends,$status,$actorId,$actorId]);
    $id=(int)$pdo->lastInsertId();
    dt_news_event($pdo,$id,$actorId,'news.created',['status'=>$status,'placement'=>$placement]);
    return $id;
}

function dt_news_delete(PDO $pdo,array $user,int $postId): void
{
    if(!dt_is_admin($user))throw new RuntimeException('Administrator access required.');
    $stmt=$pdo->prepare('SELECT slug,headline FROM news_posts_v110 WHERE id=? LIMIT 1');
    $stmt->execute([$postId]);
    $row=$stmt->fetch();
    if(!$row)throw new RuntimeException('News post was not found.');
    $pdo->prepare('DELETE FROM news_posts_v110 WHERE id=?')->execute([$postId]);
    dt_news_event($pdo,null,(int)$user['id'],'news.deleted',['post_id'=>$postId,'slug'=>(string)$row['slug'],'headline'=>(string)$row['headline']]);
}

function dt_news_admin_rows(PDO $pdo): array
{
    if(!dt_news_schema_ready($pdo))return [];
    return $pdo->query("SELECT * FROM news_posts_v110 ORDER BY priority DESC,updated_at DESC,id DESC")->fetchAll()?:[];
}

function dt_news_active(PDO $pdo,string $surface='public-desktop',int $limit=5): array
{
    if(!dt_news_schema_ready($pdo))return [];
    if(!in_array($surface,['public-desktop','signed-in-desktop'],true))throw new RuntimeException('News surface is invalid.');
    $limit=max(1,min(20,$limit));
    $stmt=$pdo->prepare("SELECT id,slug,headline,body_text,image_url,link_url,link_label,placement,priority,published_at,starts_at,ends_at
        FROM news_posts_v110
        WHERE post_status='active'
          AND (placement=? OR placement='both')
          AND (starts_at IS NULL OR starts_at<=NOW())
          AND (ends_at IS NULL OR ends_at>NOW())
        ORDER BY priority DESC,COALESCE(published_at,created_at) DESC,id DESC
        LIMIT ?");
    $stmt->bindValue(1,$surface,PDO::PARAM_STR);
    $stmt->bindValue(2,$limit,PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll()?:[];
}

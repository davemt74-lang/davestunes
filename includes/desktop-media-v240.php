<?php
declare(strict_types=1);

function dt_desktop_media_release(PDO $pdo,array $user,int $releaseId): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1||$releaseId<1)return null;
    if(!dt_library_can_collect($pdo,$userId,'release',$releaseId))return null;

    $stmt=$pdo->prepare("SELECT r.*,a.name artist_name,a.slug artist_slug
        FROM music_releases_v110 r
        INNER JOIN artists a ON a.id=r.artist_id
        WHERE r.id=? AND r.release_status<>'archived' AND a.artist_status='active' LIMIT 1");
    $stmt->execute([$releaseId]);
    $release=$stmt->fetch();
    if(!$release)return null;

    $savedStmt=$pdo->prepare('SELECT 1 FROM user_saved_releases_v120 WHERE user_id=? AND release_id=? LIMIT 1');
    $savedStmt->execute([$userId,$releaseId]);
    $saved=(bool)$savedStmt->fetchColumn();

    $owned=false;
    foreach(dt_library_owned_releases($pdo,$userId) as $row){
        if((int)$row['id']===$releaseId){$owned=true;break;}
    }
    $available=false;
    if(!$owned){
        foreach(dt_library_available_releases($pdo,$userId) as $row){
            if((int)$row['id']===$releaseId){$available=true;break;}
        }
    }

    $accessState=$owned?'owned':($available?'available':($saved?'saved':'public'));
    return dt_desktop_data_release_dto($pdo,$userId,$user,$release,$accessState,$saved,true);
}


function dt_desktop_media_artist(PDO $pdo,array $user,int $artistId): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1||$artistId<1)return null;
    foreach(dt_desktop_data_artists($pdo,$userId,$user,'',200) as $artist){
        if((int)$artist['id']===$artistId)return $artist;
    }
    return null;
}

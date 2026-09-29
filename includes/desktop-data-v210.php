<?php
declare(strict_types=1);

const DAVESTUNES_DESKTOP_DATA_V210='music-desktop-v1-section2-data-20260929';

function dt_desktop_data_views(): array
{
    return ['home','albums','artists','songs','crates','playlists'];
}

function dt_desktop_data_view(string $view): string
{
    $view=strtolower(trim($view));
    return in_array($view,dt_desktop_data_views(),true)?$view:'home';
}

function dt_desktop_data_query(string $query): string
{
    $query=preg_replace('/\s+/u',' ',trim($query))??'';
    return mb_substr($query,0,120);
}

function dt_desktop_data_matches(string $query,string ...$values): bool
{
    if($query==='')return true;
    $needle=mb_strtolower($query);
    foreach($values as $value){
        if($value!==''&&str_contains(mb_strtolower($value),$needle))return true;
    }
    return false;
}

function dt_desktop_data_cover_url(string $path): string
{
    $path=trim($path);
    if($path===''||!str_starts_with($path,'/'))return '';
    if(str_contains($path,'..')||!preg_match('#^/[A-Za-z0-9/_\.\-%]+$#',$path))return '';
    return $path;
}

function dt_desktop_data_saved_release_ids(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare('SELECT release_id FROM user_saved_releases_v120 WHERE user_id=?');
    $stmt->execute([$userId]);
    return array_fill_keys(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
}

function dt_desktop_data_saved_recording_ids(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare('SELECT recording_id FROM user_saved_recordings_v120 WHERE user_id=?');
    $stmt->execute([$userId]);
    return array_fill_keys(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
}

function dt_desktop_data_followed_artist_ids(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare('SELECT artist_id FROM user_artist_follows_v120 WHERE user_id=?');
    $stmt->execute([$userId]);
    return array_fill_keys(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
}

function dt_desktop_data_release_tracks(PDO $pdo,int $userId,int $releaseId,array $user): array
{
    $stmt=$pdo->prepare("SELECT rt.recording_id,rt.disc_number,rt.track_number,rt.sequence_order,rt.is_bonus,
            rec.title,rec.version_label,rec.duration_ms,rec.explicit_content
        FROM music_release_tracks_v110 rt
        INNER JOIN music_recordings_v110 rec ON rec.id=rt.recording_id
        WHERE rt.release_id=? AND rec.recording_status='active'
        ORDER BY rt.sequence_order,rt.disc_number,rt.track_number,rt.id");
    $stmt->execute([$releaseId]);
    $tracks=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $recordingId=(int)$row['recording_id'];
        $playback=dt_playback_recording_payload($pdo,$recordingId,$user);
        $tracks[]=[
            'id'=>$recordingId,
            'type'=>'recording',
            'title'=>(string)$row['title'],
            'versionLabel'=>(string)$row['version_label'],
            'discNumber'=>(int)$row['disc_number'],
            'trackNumber'=>(int)$row['track_number'],
            'durationMs'=>$row['duration_ms']!==null?(int)$row['duration_ms']:null,
            'explicit'=>(bool)$row['explicit_content'],
            'bonus'=>(bool)$row['is_bonus'],
            'playable'=>!empty($playback['stream_url']),
            'accessMode'=>(string)($playback['access_mode']??'none'),
        ];
    }
    return $tracks;
}

function dt_desktop_data_release_dto(PDO $pdo,int $userId,array $user,array $release,string $accessState,bool $saved=false,bool $includeTracks=true): array
{
    $releaseId=(int)$release['id'];
    $artistId=(int)$release['artist_id'];
    $artistName=(string)($release['artist_name']??'');
    $artistSlug=(string)($release['artist_slug']??'');
    if($artistName===''){
        $artist=dt_artist_by_id($pdo,$artistId);
        $artistName=(string)($artist['name']??'');
        $artistSlug=(string)($artist['slug']??'');
    }
    $tracks=$includeTracks?dt_desktop_data_release_tracks($pdo,$userId,$releaseId,$user):[];
    return [
        'id'=>$releaseId,
        'type'=>'release',
        'title'=>(string)$release['title'],
        'releaseType'=>(string)$release['release_type'],
        'releaseDate'=>$release['release_date']!==null?(string)$release['release_date']:null,
        'coverUrl'=>dt_desktop_data_cover_url((string)($release['cover_path']??'')),
        'artist'=>[
            'id'=>$artistId,
            'name'=>$artistName,
            'slug'=>$artistSlug,
        ],
        'accessState'=>$accessState,
        'owned'=>$accessState==='owned',
        'available'=>$accessState==='available',
        'saved'=>$saved,
        'trackCount'=>count($tracks),
        'playableTrackIds'=>array_values(array_map(
            static fn(array $track): int => (int)$track['id'],
            array_filter($tracks,static fn(array $track): bool => !empty($track['playable']))
        )),
        'tracks'=>$tracks,
    ];
}

function dt_desktop_data_release_map(PDO $pdo,int $userId,array $user,bool $includeDiscover=false): array
{
    $savedIds=dt_desktop_data_saved_release_ids($pdo,$userId);
    $map=[];

    foreach(dt_library_owned_releases($pdo,$userId) as $release){
        $id=(int)$release['id'];
        $map[$id]=dt_desktop_data_release_dto($pdo,$userId,$user,$release,'owned',isset($savedIds[$id]));
    }
    foreach(dt_library_available_releases($pdo,$userId) as $release){
        $id=(int)$release['id'];
        if(isset($map[$id]))continue;
        $map[$id]=dt_desktop_data_release_dto($pdo,$userId,$user,$release,'available',isset($savedIds[$id]));
    }
    foreach(dt_library_saved_releases($pdo,$userId) as $release){
        $id=(int)$release['id'];
        if(isset($map[$id])){
            $map[$id]['saved']=true;
            continue;
        }
        $map[$id]=dt_desktop_data_release_dto($pdo,$userId,$user,$release,'saved',true);
    }

    if($includeDiscover){
        foreach(dt_library_published_releases($pdo,80) as $release){
            $id=(int)$release['id'];
            if(isset($map[$id]))continue;
            $map[$id]=dt_desktop_data_release_dto($pdo,$userId,$user,$release,'public',false);
        }
    }
    return $map;
}

function dt_desktop_data_albums(PDO $pdo,int $userId,array $user,string $query='',bool $includeDiscover=false,int $limit=120): array
{
    $items=array_values(dt_desktop_data_release_map($pdo,$userId,$user,$includeDiscover));
    $items=array_values(array_filter($items,static fn(array $item): bool =>
        dt_desktop_data_matches($query,(string)$item['title'],(string)$item['artist']['name'],(string)$item['releaseType'])
    ));
    usort($items,static function(array $a,array $b): int {
        $rank=['owned'=>0,'available'=>1,'saved'=>2,'public'=>3];
        $byRank=($rank[$a['accessState']]??9)<=>($rank[$b['accessState']]??9);
        if($byRank!==0)return $byRank;
        return strcasecmp((string)$a['title'],(string)$b['title']);
    });
    return array_slice($items,0,max(1,min(200,$limit)));
}

function dt_desktop_data_song_dto(PDO $pdo,int $userId,array $user,array $recording,string $accessState,bool $saved=false): array
{
    $recordingId=(int)$recording['id'];
    $artistId=(int)$recording['artist_id'];
    $artistName=(string)($recording['artist_name']??'');
    if($artistName===''){
        $artist=dt_artist_by_id($pdo,$artistId);
        $artistName=(string)($artist['name']??'');
    }
    $payload=dt_playback_recording_payload($pdo,$recordingId,$user);
    return [
        'id'=>$recordingId,
        'type'=>'recording',
        'title'=>(string)$recording['title'],
        'versionLabel'=>(string)($recording['version_label']??''),
        'durationMs'=>$recording['duration_ms']!==null?(int)$recording['duration_ms']:null,
        'artist'=>['id'=>$artistId,'name'=>$artistName],
        'accessState'=>$accessState,
        'owned'=>$accessState==='owned',
        'available'=>$accessState==='available',
        'saved'=>$saved,
        'playable'=>!empty($payload['stream_url']),
        'accessMode'=>(string)($payload['access_mode']??'none'),
    ];
}

function dt_desktop_data_direct_access_recordings(PDO $pdo,int $userId): array
{
    $clause=dt_entitlement_active_clause('e');
    $stmt=$pdo->prepare("SELECT DISTINCT r.*,a.name artist_name
        FROM music_entitlements_v120 e
        INNER JOIN music_recordings_v110 r ON r.id=e.resource_id AND r.recording_status='active'
        INNER JOIN artists a ON a.id=r.artist_id AND a.artist_status='active'
        WHERE e.user_id=? AND e.resource_type='recording' AND e.entitlement_type='access' AND {$clause}
        ORDER BY r.title,r.id");
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function dt_desktop_data_songs(PDO $pdo,int $userId,array $user,string $query='',int $limit=200): array
{
    $savedIds=dt_desktop_data_saved_recording_ids($pdo,$userId);
    $map=[];

    foreach(dt_library_owned_recordings($pdo,$userId) as $recording){
        $id=(int)$recording['id'];
        $map[$id]=dt_desktop_data_song_dto($pdo,$userId,$user,$recording,'owned',isset($savedIds[$id]));
    }

    foreach(dt_library_available_releases($pdo,$userId) as $release){
        foreach(dt_desktop_data_release_tracks($pdo,$userId,(int)$release['id'],$user) as $track){
            $id=(int)$track['id'];
            if(isset($map[$id]))continue;
            $recording=dt_catalog_recording($pdo,(int)$release['artist_id'],$id);
            if(!$recording)continue;
            $map[$id]=dt_desktop_data_song_dto($pdo,$userId,$user,$recording,'available',isset($savedIds[$id]));
        }
    }

    foreach(dt_desktop_data_direct_access_recordings($pdo,$userId) as $recording){
        $id=(int)$recording['id'];
        if(isset($map[$id]))continue;
        $map[$id]=dt_desktop_data_song_dto($pdo,$userId,$user,$recording,'available',isset($savedIds[$id]));
    }

    foreach(dt_library_saved_releases($pdo,$userId) as $release){
        foreach(dt_desktop_data_release_tracks($pdo,$userId,(int)$release['id'],$user) as $track){
            $id=(int)$track['id'];
            if(isset($map[$id]))continue;
            $recording=dt_catalog_recording($pdo,(int)$release['artist_id'],$id);
            if(!$recording)continue;
            $map[$id]=dt_desktop_data_song_dto($pdo,$userId,$user,$recording,'saved',isset($savedIds[$id]));
        }
    }

    foreach(dt_library_saved_recordings($pdo,$userId) as $recording){
        $id=(int)$recording['id'];
        if(isset($map[$id])){
            $map[$id]['saved']=true;
            continue;
        }
        $map[$id]=dt_desktop_data_song_dto($pdo,$userId,$user,$recording,'saved',true);
    }

    $items=array_values(array_filter($map,static fn(array $item): bool =>
        dt_desktop_data_matches($query,(string)$item['title'],(string)$item['artist']['name'],(string)$item['versionLabel'])
    ));
    usort($items,static fn(array $a,array $b): int => strcasecmp((string)$a['title'],(string)$b['title']));
    return array_slice($items,0,max(1,min(300,$limit)));
}

function dt_desktop_data_artists(PDO $pdo,int $userId,array $user,string $query='',int $limit=120): array
{
    $followed=dt_desktop_data_followed_artist_ids($pdo,$userId);
    $artistIds=$followed;
    foreach(dt_desktop_data_release_map($pdo,$userId,$user,false) as $release)$artistIds[(int)$release['artist']['id']]=true;
    foreach(dt_desktop_data_songs($pdo,$userId,$user,'',300) as $song)$artistIds[(int)$song['artist']['id']]=true;
    if(!$artistIds)return [];

    $ids=array_keys($artistIds);
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("SELECT id,name,slug,bio,profile_image_path,cover_image_path,location,verification_status
        FROM artists WHERE id IN ({$placeholders}) AND artist_status='active' ORDER BY name,id");
    $stmt->execute($ids);
    $items=[];
    foreach($stmt->fetchAll()?:[] as $row){
        if(!dt_desktop_data_matches($query,(string)$row['name'],(string)$row['location'],(string)$row['bio']))continue;
        $id=(int)$row['id'];
        $items[]=[
            'id'=>$id,
            'type'=>'artist',
            'name'=>(string)$row['name'],
            'slug'=>(string)$row['slug'],
            'bio'=>(string)($row['bio']??''),
            'location'=>(string)$row['location'],
            'verified'=>(string)$row['verification_status']==='verified',
            'profileImageUrl'=>dt_desktop_data_cover_url((string)$row['profile_image_path']),
            'followed'=>isset($followed[$id]),
        ];
        if(count($items)>=$limit)break;
    }
    return $items;
}

function dt_desktop_data_crates(PDO $pdo,int $userId,array $user,string $query='',int $limit=100): array
{
    $crates=dt_library_crates($pdo,$userId);
    $releaseMap=dt_desktop_data_release_map($pdo,$userId,$user,false);
    $songMap=[];
    foreach(dt_desktop_data_songs($pdo,$userId,$user,'',300) as $song)$songMap[(int)$song['id']]=$song;
    $items=[];
    foreach($crates as $crate){
        if(!dt_desktop_data_matches($query,(string)$crate['crate_name']))continue;
        $crateId=(int)$crate['id'];
        $stmt=$pdo->prepare('SELECT * FROM music_crate_items_v120 WHERE crate_id=? ORDER BY sort_order,id LIMIT 200');
        $stmt->execute([$crateId]);
        $children=[];
        foreach($stmt->fetchAll()?:[] as $row){
            $resourceType=(string)$row['resource_type'];
            $resourceId=(int)$row['resource_id'];
            if($resourceType==='release'){
                $releaseStmt=$pdo->prepare("SELECT r.*,a.name artist_name,a.slug artist_slug
                    FROM music_releases_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
                $releaseStmt->execute([$resourceId]);
                $release=$releaseStmt->fetch();
                if($release&&dt_library_can_collect($pdo,$userId,'release',$resourceId)){
                    $children[]=$releaseMap[$resourceId]??dt_desktop_data_release_dto($pdo,$userId,$user,$release,'public',false,false);
                }
            }elseif($resourceType==='recording'){
                $recordingStmt=$pdo->prepare("SELECT r.*,a.name artist_name FROM music_recordings_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
                $recordingStmt->execute([$resourceId]);
                $recording=$recordingStmt->fetch();
                if($recording&&dt_library_can_collect($pdo,$userId,'recording',$resourceId)){
                    $children[]=$songMap[$resourceId]??dt_desktop_data_song_dto($pdo,$userId,$user,$recording,'public',false);
                }
            }
        }
        $items[]=[
            'id'=>$crateId,
            'type'=>'crate',
            'name'=>(string)$crate['crate_name'],
            'slug'=>(string)$crate['crate_slug'],
            'itemCount'=>(int)$crate['item_count'],
            'items'=>$children,
        ];
        if(count($items)>=$limit)break;
    }
    return $items;
}

function dt_desktop_data_playlists(PDO $pdo,int $userId,array $user,string $query='',int $limit=100): array
{
    $songMap=[];
    foreach(dt_desktop_data_songs($pdo,$userId,$user,'',300) as $song)$songMap[(int)$song['id']]=$song;
    $stmt=$pdo->prepare("SELECT p.*,(SELECT COUNT(*) FROM music_playlist_recordings_v120 pr WHERE pr.playlist_id=p.id) item_count
        FROM music_playlists_v120 p WHERE p.user_id=? ORDER BY p.updated_at DESC,p.id DESC");
    $stmt->execute([$userId]);
    $items=[];
    foreach($stmt->fetchAll()?:[] as $playlist){
        if(!dt_desktop_data_matches($query,(string)$playlist['playlist_name'],(string)($playlist['description']??'')))continue;
        $playlistId=(int)$playlist['id'];
        $tracks=$pdo->prepare("SELECT r.*,a.name artist_name,pr.sort_order
            FROM music_playlist_recordings_v120 pr
            INNER JOIN music_recordings_v110 r ON r.id=pr.recording_id
            INNER JOIN artists a ON a.id=r.artist_id
            WHERE pr.playlist_id=? ORDER BY pr.sort_order,r.id LIMIT 300");
        $tracks->execute([$playlistId]);
        $songs=[];
        foreach($tracks->fetchAll()?:[] as $recording){
            $recordingId=(int)$recording['id'];
            if(!dt_library_can_collect($pdo,$userId,'recording',$recordingId))continue;
            $songs[]=$songMap[$recordingId]??dt_desktop_data_song_dto($pdo,$userId,$user,$recording,'public',false);
        }
        $items[]=[
            'id'=>$playlistId,
            'type'=>'playlist',
            'name'=>(string)$playlist['playlist_name'],
            'description'=>(string)($playlist['description']??''),
            'itemCount'=>(int)$playlist['item_count'],
            'playableTrackIds'=>array_values(array_map(
                static fn(array $song): int => (int)$song['id'],
                array_filter($songs,static fn(array $song): bool => !empty($song['playable']))
            )),
            'tracks'=>$songs,
        ];
        if(count($items)>=$limit)break;
    }
    return $items;
}

function dt_desktop_data_recent(PDO $pdo,int $userId,string $query='',int $limit=24): array
{
    $items=[];
    foreach(dt_playback_recent_history($pdo,$userId,$limit*3) as $row){
        if(!dt_desktop_data_matches($query,(string)$row['title'],(string)$row['artist_name']))continue;
        $items[]=[
            'id'=>(int)$row['id'],
            'type'=>'listen',
            'recordingId'=>(int)$row['recording_id'],
            'title'=>(string)$row['title'],
            'artistName'=>(string)$row['artist_name'],
            'accessMode'=>(string)$row['access_mode'],
            'listenedMs'=>(int)$row['listened_ms'],
            'startedAt'=>(string)$row['started_at'],
        ];
        if(count($items)>=$limit)break;
    }
    return $items;
}

function dt_desktop_data_home(PDO $pdo,int $userId,array $user,string $query=''): array
{
    $libraryMap=dt_desktop_data_release_map($pdo,$userId,$user,false);
    $libraryAlbums=dt_desktop_data_albums($pdo,$userId,$user,$query,false,24);
    $discoverAll=dt_desktop_data_albums($pdo,$userId,$user,$query,true,80);
    $libraryIds=array_fill_keys(array_map('intval',array_keys($libraryMap)),true);
    $discover=array_values(array_filter($discoverAll,static fn(array $item): bool => $item['accessState']==='public'&&!isset($libraryIds[(int)$item['id']])));
    return [
        'hero'=>$libraryAlbums[0]??$discover[0]??null,
        'sections'=>[
            ['id'=>'collection','title'=>'Your Albums','items'=>array_slice($libraryAlbums,0,12)],
            ['id'=>'recent','title'=>'Recently Played','items'=>dt_desktop_data_recent($pdo,$userId,$query,10)],
            ['id'=>'artists','title'=>'Your Artists','items'=>array_slice(dt_desktop_data_artists($pdo,$userId,$user,$query,12),0,12)],
            ['id'=>'discover','title'=>'Discover','items'=>array_slice($discover,0,12)],
        ],
    ];
}

function dt_desktop_data_payload(PDO $pdo,array $user,string $view,string $query=''): array
{
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('Authentication required.');
    $view=dt_desktop_data_view($view);
    $query=dt_desktop_data_query($query);

    $data=match($view){
        'albums'=>['items'=>dt_desktop_data_albums($pdo,$userId,$user,$query,false)],
        'artists'=>['items'=>dt_desktop_data_artists($pdo,$userId,$user,$query)],
        'songs'=>['items'=>dt_desktop_data_songs($pdo,$userId,$user,$query)],
        'crates'=>['items'=>dt_desktop_data_crates($pdo,$userId,$user,$query)],
        'playlists'=>['items'=>dt_desktop_data_playlists($pdo,$userId,$user,$query)],
        default=>dt_desktop_data_home($pdo,$userId,$user,$query),
    };

    return [
        'schemaVersion'=>'desktop-data-v210',
        'view'=>$view,
        'query'=>$query,
        'data'=>$data,
        'meta'=>[
            'generatedAt'=>(new DateTimeImmutable())->format(DATE_ATOM),
            'userId'=>$userId,
        ],
    ];
}

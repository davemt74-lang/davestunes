<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$recordingId=max(0,(int)($_GET['recording']??0));
$requestedAssetId=max(0,(int)($_GET['asset']??0));
$user=dt_current_user($pdo);
$selected=dt_playback_select_media($pdo,$recordingId,$user);
if(!$selected||$requestedAssetId<1||(int)$selected['asset']['id']!==$requestedAssetId){http_response_code(404);exit;}

$asset=$selected['asset'];
$path=dt_playback_storage_path((string)$asset['storage_key']);
if(!is_file($path)){http_response_code(404);exit;}

$size=(int)filesize($path);
$start=0;
$end=max(0,$size-1);
$status=200;
$range=(string)($_SERVER['HTTP_RANGE']??'');
if($range!==''){
    if(!preg_match('/^bytes=(\d*)-(\d*)$/',$range,$m)){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
    if($m[1]===''&&$m[2]===''){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
    if($m[1]===''){
        $suffix=(int)$m[2];
        if($suffix<1){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
        $start=max(0,$size-$suffix);
    }else{
        $start=(int)$m[1];
        if($m[2]!=='')$end=min($end,(int)$m[2]);
    }
    if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
    $status=206;
}

$length=$end-$start+1;
http_response_code($status);
header('Content-Type: '.(string)$asset['mime_type']);
header('Content-Length: '.$length);
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="track.'.dt_playback_allowed_mimes()[(string)$asset['mime_type']].'"');
if($status===206)header("Content-Range: bytes {$start}-{$end}/{$size}");

$fp=fopen($path,'rb');
if(!$fp){http_response_code(404);exit;}
fseek($fp,$start);
$remaining=$length;
while($remaining>0&&!feof($fp)){
    $chunk=fread($fp,min(1048576,$remaining));
    if($chunk===false)break;
    echo $chunk;
    $remaining-=strlen($chunk);
}
fclose($fp);

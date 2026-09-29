<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$releaseId=max(0,(int)($_GET['release']??0));
$stmt=$pdo->prepare("SELECT r.id,r.title,r.release_status,r.artist_id,a.name artist_name FROM music_releases_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
$stmt->execute([$releaseId]);
$release=$stmt->fetch();
if(!$release||(string)$release['release_status']!=='published'){http_response_code(404);exit('Album experience not found.');}
$active=dt_experience_active($pdo,'release',$releaseId,'default');
if(!$active){header('Location: /album.php?release='.$releaseId);exit;}
$boot=[
  'version'=>'music-desktop-v1-section12',
  'releaseId'=>$releaseId,
  'releaseTitle'=>(string)$release['title'],
  'artistName'=>(string)$release['artist_name'],
  'manifest'=>$active['manifest']??null,
  'sha256'=>$active['sha256']??'',
];
$bootJson=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?:'{}';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=dt_e((string)$release['title'])?> · Experience</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/desktop/desktop.css">
</head>
<body class="desktop-page album-experience-page">
<main id="dt-desktop-root" class="desktop-shell" data-default-template="midnight-desk" data-desktop-mode="desktop">
  <script type="application/json" id="dt-album-experience-boot"><?=$bootJson?></script>
  <div class="desktop-topbar">
    <a class="desktop-brand" href="/album.php?release=<?=$releaseId?>"><span class="desktop-brand-mark"></span><?=dt_e((string)$release['title'])?></a>
    <div class="desktop-actions"><a class="desktop-chip" href="/album.php?release=<?=$releaseId?>">Exit Experience</a></div>
  </div>
  <section class="desktop-layer desktop-layer-zscroll" data-desktop-layer="z-scroll" aria-hidden="false">
    <div class="z-scroll-stage" data-zscroll-stage aria-hidden="false">
      <div class="z-scroll-stage-bar">
        <div><span class="z-scroll-kicker"><?=dt_e((string)$release['artist_name'])?></span><span class="z-scroll-instruction">Scroll through the album experience</span></div>
        <a class="z-scroll-close" href="/album.php?release=<?=$releaseId?>">Close</a>
      </div>
      <div class="z-scroll-scenes" data-zscroll-scenes></div>
      <div class="z-scroll-progress" aria-hidden="true"><span data-zscroll-progress-fill></span></div>
      <nav class="z-scroll-markers" data-zscroll-markers aria-label="Experience scenes"></nav>
    </div>
  </section>
</main>
<script src="/assets/desktop/desktop-core.js"></script>
<script src="/assets/desktop/template-midnight.js"></script>
<script src="/assets/desktop/experience-effects.js"></script>
<script src="/assets/desktop/module-experience-runtime.js"></script>
<script src="/assets/desktop/module-z-scroll.js"></script>
<script src="/assets/album-experience.js"></script>
</body>
</html>

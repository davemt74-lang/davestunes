<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$artistId=max(0,(int)($_GET['artist']??0));
$artist=dt_artist_by_id($pdo,$artistId);
if(!$artist||(string)$artist['artist_status']!=='active'){http_response_code(404);exit('Artist experience not found.');}
$active=dt_experience_active($pdo,'artist',$artistId,'default');
if(!$active){header('Location: /artist.php?artist='.rawurlencode((string)$artist['slug']));exit;}
$boot=[
  'version'=>'music-desktop-v1-section13',
  'artistId'=>$artistId,
  'artistName'=>(string)$artist['name'],
  'manifest'=>$active['manifest']??null,
  'sha256'=>$active['sha256']??'',
];
$bootJson=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?:'{}';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=dt_e((string)$artist['name'])?> · Experience</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/desktop/desktop.css">
</head>
<body class="desktop-page artist-experience-page">
<main id="dt-desktop-root" class="desktop-shell" data-default-template="midnight-desk" data-desktop-mode="desktop">
  <script type="application/json" id="dt-artist-experience-boot"><?=$bootJson?></script>
  <div class="desktop-topbar">
    <a class="desktop-brand" href="/artist.php?artist=<?=rawurlencode((string)$artist['slug'])?>"><span class="desktop-brand-mark"></span><?=dt_e((string)$artist['name'])?></a>
    <div class="desktop-actions"><a class="desktop-chip" href="/artist.php?artist=<?=rawurlencode((string)$artist['slug'])?>">Exit Experience</a></div>
  </div>
  <section class="desktop-layer desktop-layer-zscroll" data-desktop-layer="z-scroll" aria-hidden="false">
    <div class="z-scroll-stage" data-zscroll-stage aria-hidden="false">
      <div class="z-scroll-stage-bar">
        <div><span class="z-scroll-kicker">Artist Experience</span><span class="z-scroll-instruction">Scroll through <?=dt_e((string)$artist['name'])?></span></div>
        <a class="z-scroll-close" href="/artist.php?artist=<?=rawurlencode((string)$artist['slug'])?>">Close</a>
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
<script src="/assets/artist-experience.js"></script>
</body>
</html>

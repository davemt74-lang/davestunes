<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$user=dt_require_user($pdo);
$boot=[
    'version'=>'music-desktop-v1-section3',
    'user'=>[
        'id'=>(int)$user['id'],
        'displayName'=>(string)($user['display_name']??''),
    ],
    'capabilities'=>[
        'player'=>true,
        'dataAdapter'=>true,
        'objects'=>true,
        'turntable'=>false,
        'zScroll'=>false,
        'flowBuilder'=>false,
    ],
];
$bootJson=json_encode($boot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
if($bootJson===false)$bootJson='{}';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?=dt_e(dt_csrf_token())?>">
  <title>Music Desktop · <?=dt_e((string)dt_config('app.name',"Dave's Tunes"))?></title>
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/desktop/desktop.css">
</head>
<body class="desktop-page">
  <main id="dt-desktop-root" class="desktop-shell" data-default-template="midnight-desk" data-desktop-mode="desktop">
    <script type="application/json" id="dt-desktop-boot"><?=$bootJson?></script>

    <div class="desktop-topbar">
      <a class="desktop-brand" href="/desktop.php"><span class="desktop-brand-mark" aria-hidden="true"></span>Dave's Tunes</a>
      <div class="desktop-actions">
        <a class="desktop-chip" href="/library.php">Library</a>
        <button class="desktop-chip" data-desktop-reset type="button">Reset Desktop</button>
        <a class="desktop-chip" href="/dashboard.php">Account</a>
      </div>
    </div>

    <section class="desktop-layer desktop-layer-site" data-desktop-layer="site" aria-label="Music website layer">
      <div class="library-surface" data-desktop-mount="library">
        <header class="library-surface-header">
          <div class="library-surface-title">Your Library</div>
          <input class="library-search" data-library-search type="search" placeholder="Search artists, albums, songs…" aria-label="Search music" autocomplete="off">
          <button class="desktop-chip" type="button" disabled>View</button>
        </header>
        <nav class="library-tabs" aria-label="Library sections">
          <button class="library-tab" data-library-view="home" aria-selected="true" type="button">Home</button>
          <button class="library-tab" data-library-view="albums" aria-selected="false" type="button">Albums</button>
          <button class="library-tab" data-library-view="artists" aria-selected="false" type="button">Artists</button>
          <button class="library-tab" data-library-view="songs" aria-selected="false" type="button">Songs</button>
          <button class="library-tab" data-library-view="crates" aria-selected="false" type="button">Crates</button>
          <button class="library-tab" data-library-view="playlists" aria-selected="false" type="button">Playlists</button>
        </nav>
        <div class="library-canvas" data-desktop-mount="library-content">
          <div class="library-empty">
            <div><strong>Loading your library…</strong>Your canonical albums, artists, songs, crates, and playlists will appear here.</div>
          </div>
        </div>
      </div>
    </section>

    <section class="desktop-layer desktop-layer-objects" data-desktop-layer="objects" aria-label="Spatial object layer"></section>

    <section class="desktop-layer desktop-layer-turntable" data-desktop-layer="turntable" aria-label="Turntable layer">
      <div class="turntable-mount" aria-hidden="true">
        <div class="turntable-platter"></div>
        <div class="turntable-arm"></div>
        <div class="turntable-caption">Digital turntable mount</div>
      </div>
    </section>

    <section class="desktop-layer desktop-layer-zscroll" data-desktop-layer="z-scroll" aria-hidden="true">
      <div class="z-scroll-stage">
        <div class="z-scroll-stage-inner"><strong>Z-Scroll Scene Layer</strong><p>Reserved for the modular scene/effect runtime.</p></div>
      </div>
    </section>

    <section class="desktop-layer desktop-layer-system" data-desktop-layer="system" aria-label="Desktop system controls">
      <div class="desktop-object-controls" data-desktop-object-controls hidden></div>
      <div class="system-mount" aria-hidden="true"></div>
    </section>
  </main>

  <?php dt_player_dock(); ?>
  <script src="/assets/desktop/desktop-core.js"></script>
  <script src="/assets/desktop/template-midnight.js"></script>
  <script src="/assets/desktop/module-player-bridge.js"></script>
  <script src="/assets/desktop/module-library-adapter.js"></script>
  <script src="/assets/desktop/module-object-runtime.js"></script>
</body>
</html>

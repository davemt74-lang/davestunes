<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$user=dt_require_user($pdo);
$personalizationState=dt_desktop_personalization_state($pdo,(int)$user['id']);
$boot=[
    'version'=>'music-desktop-v1-section15',
    'release'=>dt_release_version(),
    'releaseChannel'=>dt_release_channel(),
    'personalizationState'=>$personalizationState,
    'user'=>[
        'id'=>(int)$user['id'],
        'displayName'=>(string)($user['display_name']??''),
    ],
    'capabilities'=>[
        'player'=>true,
        'dataAdapter'=>true,
        'objects'=>true,
        'turntable'=>true,
        'unifiedPlayer'=>true,
        'featuredContent'=>true,
        'experienceGraph'=>true,
        'zScroll'=>true,
        'flowBuilder'=>true,
        'effectsLibrary'=>true,
        'collectionIntegration'=>true,
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
  <main id="dt-desktop-root" class="desktop-shell" data-default-template="midnight-desk" data-desktop-mode="desktop" data-personalization-state="<?=dt_e($personalizationState)?>">
    <script type="application/json" id="dt-desktop-boot"><?=$bootJson?></script>

    <div class="desktop-topbar">
      <a class="desktop-brand" href="/desktop.php"><span class="desktop-brand-mark" aria-hidden="true"></span>Dave's Tunes</a>
      <div class="desktop-actions">
        <button class="desktop-chip" data-zscroll-toggle type="button" aria-pressed="false">Explore</button>
        <a class="desktop-chip" href="/library.php">Library</a>
        <button class="desktop-chip" data-desktop-reset type="button">Reset Desktop</button>
        <span class="desktop-release-chip"><?=dt_e(dt_release_label())?></span>
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

    <section class="desktop-featured-layer" data-desktop-featured aria-label="Featured music" hidden></section>

    <section class="desktop-layer desktop-layer-objects" data-desktop-layer="objects" aria-label="Spatial object layer"></section>

    <section class="desktop-layer desktop-layer-turntable" data-desktop-layer="turntable" aria-label="Digital vinyl turntable">
      <div class="turntable-mount" data-turntable data-turntable-playing="false" data-turntable-loaded="false" data-turntable-drop="idle">
        <div class="turntable-platter" data-turntable-platter>
          <div class="turntable-record-label" data-turntable-label><span>♪</span></div>
        </div>
        <div class="turntable-spindle" aria-hidden="true"></div>
        <div class="turntable-arm-base" aria-hidden="true"></div>
        <div class="turntable-arm" data-turntable-arm aria-hidden="true"><span class="turntable-cartridge"></span></div>
        <div class="turntable-meta">
          <span class="turntable-caption">Now spinning</span>
          <strong data-turntable-title>No record loaded</strong>
          <span data-turntable-artist>Drop an album here or choose a track.</span>
        </div>
        <div class="turntable-controls" data-object-interactive="true">
          <button type="button" data-turntable-action="previous" aria-label="Previous track">‹</button>
          <button type="button" class="turntable-play" data-turntable-action="toggle" aria-label="Play or pause">●</button>
          <button type="button" data-turntable-action="next" aria-label="Next track">›</button>
        </div>
        <input class="turntable-progress" data-turntable-progress data-object-interactive="true" type="range" min="0" max="1000" value="0" aria-label="Turntable playback position">
        <div class="turntable-drop-hint" data-turntable-drop-hint aria-hidden="true">Drop album to play</div>
      </div>
    </section>

    <section class="desktop-layer desktop-layer-zscroll" data-desktop-layer="z-scroll" aria-hidden="true">
      <div class="z-scroll-stage" data-zscroll-stage aria-hidden="true">
        <div class="z-scroll-stage-bar">
          <div>
            <span class="z-scroll-kicker">Explore mode</span>
            <span class="z-scroll-instruction">Hold Z + scroll · or use Explore</span>
          </div>
          <button class="z-scroll-close" data-zscroll-close type="button">Close</button>
        </div>
        <div class="z-scroll-scenes" data-zscroll-scenes></div>
        <div class="z-scroll-progress" aria-hidden="true"><span data-zscroll-progress-fill></span></div>
        <nav class="z-scroll-markers" data-zscroll-markers aria-label="Explore scenes"></nav>
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
  <script src="/assets/desktop/module-featured-content.js"></script>
  <script src="/assets/desktop/module-object-runtime.js"></script>
  <script src="/assets/desktop/module-media-objects.js"></script>
  <script src="/assets/desktop/module-turntable.js"></script>
  <script src="/assets/desktop/z-scroll-effects.js"></script>
  <script src="/assets/desktop/experience-effects.js"></script>
  <script src="/assets/desktop/module-experience-runtime.js"></script>
  <script src="/assets/desktop/module-z-scroll.js"></script>
</body>
</html>

<?php
declare(strict_types=1);
if(!is_file(__DIR__.'/config.php')){header('Location: /install.php');exit;}
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
if(dt_current_user($pdo)){header('Location: /desktop.php');exit;}

$albums=dt_featured_public_albums($pdo,8);
$news=dt_featured_public_news($pdo,5);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dave's Tunes · <?=dt_e(dt_release_label())?></title>
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/desktop/desktop.css">
</head>
<body class="desktop-page public-desktop-home">
  <div class="desktop-splash" data-desktop-splash data-splash-mode="public" role="status" aria-live="polite">
    <div class="desktop-splash-mark" aria-hidden="true"><span></span><span></span></div>
    <strong>Dave's Tunes</strong>
    <span>Setting the needle…</span>
    <div class="desktop-splash-progress" aria-hidden="true"><i></i></div>
  </div>

  <main id="dt-desktop-root" class="desktop-shell" data-default-template="midnight-desk" data-desktop-mode="desktop" data-desktop-ready="true">
    <div class="desktop-topbar">
      <a class="desktop-brand" href="/"><span class="desktop-brand-mark" aria-hidden="true"></span>Dave's Tunes</a>
      <div class="desktop-actions public-home-auth">
        <span class="desktop-release-chip"><?=dt_e(dt_release_label())?></span>
        <a class="desktop-chip" href="/login.php">Sign in</a>
        <a class="desktop-chip primary" href="/signup.php">Create account</a>
      </div>
    </div>

    <section class="desktop-layer desktop-layer-site" data-desktop-layer="site" aria-label="Dave's Tunes home">
      <div class="public-home-surface">
        <header class="public-home-heading">
          <div>
            <div class="eyebrow">Music Desktop</div>
            <h1>Discover what’s playing.</h1>
            <p class="public-home-intro">Featured albums and notes live here first. Once you start listening, saving, following, and placing music, this Desktop gradually becomes yours.</p>
          </div>
        </header>

        <section aria-labelledby="featured-heading">
          <div class="desktop-section-heading"><h2 id="featured-heading">Featured Albums</h2></div>
          <div class="public-featured-grid">
            <?php if(!$albums):?>
              <div class="library-empty"><div><strong>No featured albums yet.</strong>Published albums will appear here automatically.</div></div>
            <?php endif;?>
            <?php foreach($albums as $album):?>
              <article class="public-featured-album">
                <a class="public-featured-art" href="<?=dt_e((string)$album['albumUrl'])?>">
                  <?php if((string)$album['coverUrl']!==''):?><img src="<?=dt_e((string)$album['coverUrl'])?>" alt="" loading="eager"><?php else:?><?=dt_e(mb_strtoupper(mb_substr((string)$album['title'],0,1)))?><?php endif;?>
                </a>
                <div class="public-featured-copy">
                  <strong><?=dt_e((string)$album['title'])?></strong>
                  <span><?=dt_e((string)$album['artistName'])?></span>
                </div>
                <div class="public-featured-actions">
                  <a href="<?=dt_e((string)$album['albumUrl'])?>">Open Album</a>
                  <?php if((string)$album['experienceUrl']!==''):?><a href="<?=dt_e((string)$album['experienceUrl'])?>">Experience</a><?php endif;?>
                </div>
              </article>
            <?php endforeach;?>
          </div>
        </section>

        <section class="public-news" aria-labelledby="news-heading">
          <div class="desktop-section-heading"><h2 id="news-heading">News & Notes</h2></div>
          <div class="public-news-list">
            <?php if(!$news):?><p class="muted">Dave's Tunes updates will appear here.</p><?php endif;?>
            <?php foreach($news as $item):?>
              <a class="public-news-item" href="<?=dt_e((string)$item['url'])?>">
                <div><strong><?=dt_e((string)$item['headline'])?></strong><br><span><?=dt_e((string)$item['body'])?></span></div>
                <span>Open →</span>
              </a>
            <?php endforeach;?>
          </div>
        </section>
      </div>
    </section>

    <section class="desktop-layer desktop-layer-turntable" data-desktop-layer="turntable" aria-label="Digital vinyl turntable">
      <div class="turntable-mount" data-turntable-playing="false" data-turntable-loaded="false">
        <div class="turntable-platter"><div class="turntable-record-label"><span>♪</span></div></div>
        <div class="turntable-spindle" aria-hidden="true"></div>
        <div class="turntable-arm-base" aria-hidden="true"></div>
        <div class="turntable-arm" aria-hidden="true"><span class="turntable-cartridge"></span></div>
        <div class="turntable-meta">
          <span class="turntable-caption">Dave's Tunes</span>
          <strong>Build your collection</strong>
          <span>Sign in to play, save, arrange, and personalize.</span>
        </div>
      </div>
      <div class="public-turntable-note"><strong>Your Desktop starts here.</strong>Once you interact, featured discovery gives way to your albums, artists, recent plays, crates, playlists, and placed objects.</div>
    </section>
  </main>

  <script src="/assets/desktop/loading-splash.js"></script>
</body>
</html>

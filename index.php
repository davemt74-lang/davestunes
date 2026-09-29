<?php
declare(strict_types=1);
if(!is_file(__DIR__.'/config.php')){header('Location: /install.php');exit;}
require __DIR__.'/includes/bootstrap.php';
dt_page_header('Home');
?>
<main class="shell soft-launch-home">
  <section class="hero soft-launch-hero">
    <div class="eyebrow"><?=dt_e(dt_release_label())?></div>
    <h1>Your music should feel like yours.</h1>
    <p class="lede">Build a personal record collection, explore artists and albums through a spatial Music Desktop, spin records on the digital turntable, and step into interactive album and artist experiences.</p>
    <div class="action-row">
      <a class="button" href="<?=dt_current_user()?'/desktop.php':'/signup.php'?>"><?=dt_current_user()?'Open Music Desktop':'Create your account'?></a>
      <?php if(!dt_current_user()):?><a class="button secondary-button" href="/login.php">Sign in</a><?php endif;?>
    </div>
  </section>

  <section class="soft-launch-grid" aria-label="Dave's Tunes soft launch features">
    <article class="card"><div class="eyebrow">Collect</div><h2>Your Library</h2><p class="muted">Save albums and songs, follow artists, build crates and playlists, and keep your collection in one canonical library.</p></article>
    <article class="card"><div class="eyebrow">Arrange</div><h2>Music Desktop</h2><p class="muted">Place albums and artists on a persistent spatial desktop and open them directly from the objects you arrange.</p></article>
    <article class="card"><div class="eyebrow">Listen</div><h2>Digital Turntable</h2><p class="muted">Drop an album onto the turntable and play through the same canonical player used everywhere in Dave's Tunes.</p></article>
    <article class="card"><div class="eyebrow">Explore</div><h2>Interactive Experiences</h2><p class="muted">Artists can publish scroll-driven album and profile experiences with scenes, layers, effects, and versioned delivery.</p></article>
  </section>
</main>
<?php dt_page_footer(); ?>

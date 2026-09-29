<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$artistParam=trim((string)($_GET['artist']??''));
$artist=$artistParam!==''?(ctype_digit($artistParam)?dt_artist_by_id($pdo,(int)$artistParam):dt_artist_by_slug($pdo,$artistParam)):null;
if(!$artist||(string)$artist['artist_status']==='archived'){http_response_code(404);exit('Artist not found.');}

$user=dt_current_user($pdo);
$canEdit=$user?dt_artist_can($pdo,(int)$artist['id'],(int)$user['id'],'catalog'):false;
$releases=dt_catalog_releases($pdo,(int)$artist['id']);
$published=array_values(array_filter($releases,static fn(array $r): bool => (string)$r['release_status']==='published'));
$experience=dt_experience_active($pdo,'artist',(int)$artist['id'],'default');
$hasExperience=is_array($experience);

dt_page_header((string)$artist['name']);
?>
<main class="shell artist-desktop-shell">
  <section class="artist-desktop-hero">
    <div class="artist-desktop-avatar">
      <?php if((string)$artist['profile_image_path']!==''):?><img src="<?=dt_e((string)$artist['profile_image_path'])?>" alt=""><?php else:?><?=dt_e(mb_strtoupper(mb_substr((string)$artist['name'],0,1)))?><?php endif;?>
    </div>
    <div class="artist-desktop-copy">
      <div class="eyebrow">Artist Desktop</div>
      <h1><?=dt_e((string)$artist['name'])?></h1>
      <?php if((string)$artist['location']!==''):?><p class="muted"><?=dt_e((string)$artist['location'])?></p><?php endif;?>
      <?php if((string)$artist['bio']!==''):?><p class="lede"><?=nl2br(dt_e((string)$artist['bio']))?></p><?php endif;?>
      <div class="artist-desktop-actions">
        <?php if($hasExperience):?><a class="button" href="/artist-experience.php?artist=<?=(int)$artist['id']?>">Enter Experience</a><?php endif;?>
        <?php if($canEdit):?><a class="button secondary-button" href="/experience-studio.php?owner_type=artist&owner_id=<?=(int)$artist['id']?>&key=default">Build Experience</a><?php endif;?>
      </div>
      <div class="artist-desktop-links">
        <?php foreach(['website_url'=>'Website','instagram_url'=>'Instagram','tiktok_url'=>'TikTok','youtube_url'=>'YouTube','spotify_url'=>'Spotify','apple_music_url'=>'Apple Music'] as $field=>$label):?>
          <?php if((string)$artist[$field]!==''):?><a href="<?=dt_e((string)$artist[$field])?>" rel="noopener noreferrer" target="_blank"><?=dt_e($label)?></a><?php endif;?>
        <?php endforeach;?>
      </div>
    </div>
  </section>

  <section class="card artist-desktop-section">
    <h2>Music</h2>
    <div class="artist-desktop-release-grid">
      <?php if(!$published):?><p class="muted">No published releases yet.</p><?php endif;?>
      <?php foreach($published as $release):?>
        <article class="artist-release-card">
          <div class="artist-release-art"><?=dt_e(mb_strtoupper(mb_substr((string)$release['title'],0,1)))?></div>
          <div>
            <div class="eyebrow"><?=dt_e((string)$release['release_type'])?></div>
            <h3><?=dt_e((string)$release['title'])?></h3>
            <?php if($release['release_date']):?><p class="muted"><?=dt_e((string)$release['release_date'])?></p><?php endif;?>
          </div>
          <a href="/album.php?release=<?=(int)$release['id']?>">Open Album →</a>
        </article>
      <?php endforeach;?>
    </div>
  </section>

  <?php if($canEdit):?>
  <section class="card artist-desktop-section">
    <h2>Artist Tools</h2>
    <p class="action-row"><a href="/catalog.php?artist=<?=(int)$artist['id']?>">Manage Catalog</a><a href="/experience-studio.php?owner_type=artist&owner_id=<?=(int)$artist['id']?>&key=default">Experience Studio</a></p>
  </section>
  <?php endif;?>
</main>
<?php dt_page_footer(); ?>

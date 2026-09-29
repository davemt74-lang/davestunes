<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$releaseId=max(0,(int)($_GET['release']??0));
$stmt=$pdo->prepare("SELECT r.*,a.name artist_name,a.slug artist_slug FROM music_releases_v110 r INNER JOIN artists a ON a.id=r.artist_id WHERE r.id=? LIMIT 1");
$stmt->execute([$releaseId]);
$release=$stmt->fetch();
if(!$release){http_response_code(404);exit('Album not found.');}

$user=dt_current_user($pdo);
$canEdit=$user?dt_artist_can($pdo,(int)$release['artist_id'],(int)$user['id'],'catalog'):false;
if((string)$release['release_status']!=='published'&&!$canEdit){http_response_code(404);exit('Album not found.');}

$tracks=dt_catalog_release_tracks($pdo,(int)$release['artist_id'],$releaseId);
$experience=dt_experience_active($pdo,'release',$releaseId,'default');
$hasExperience=(string)$release['release_status']==='published'&&is_array($experience);

dt_page_header((string)$release['title']);
?>
<main class="shell">
  <section class="album-hero">
    <div class="album-cover" aria-hidden="true"><?=dt_e(mb_strtoupper(mb_substr((string)$release['title'],0,1)))?></div>
    <div class="album-meta">
      <div class="eyebrow"><?=dt_e((string)$release['release_type'])?></div>
      <h1><?=dt_e((string)$release['title'])?></h1>
      <p class="lede"><?=dt_e((string)$release['artist_name'])?></p>
      <?php if(!empty($release['description'])):?><p><?=dt_e((string)$release['description'])?></p><?php endif;?>
      <div class="album-experience-launch">
        <?php if($hasExperience):?><a class="button" href="/album-experience.php?release=<?=$releaseId?>">Enter Experience</a><?php endif;?>
        <?php if($canEdit):?><a class="button secondary-button" href="/experience-studio.php?owner_type=release&owner_id=<?=$releaseId?>&key=default">Build Experience</a><?php endif;?>
      </div>
    </div>
  </section>
  <section class="card" style="margin-top:24px">
    <h2>Track List</h2>
    <div class="album-track-list">
      <?php if(!$tracks):?><p class="muted">No tracks have been attached yet.</p><?php endif;?>
      <?php foreach($tracks as $track):?>
        <div class="album-track">
          <span><?= (int)$track['track_number'] ?></span>
          <div><strong><?=dt_e((string)$track['recording_title'])?></strong><?php if($track['version_label']):?><span class="muted"> · <?=dt_e((string)$track['version_label'])?></span><?php endif;?></div>
          <?php if($track['duration_ms']):?><span class="muted"><?=gmdate('i:s',(int)round(((int)$track['duration_ms'])/1000))?></span><?php endif;?>
        </div>
      <?php endforeach;?>
    </div>
  </section>
</main>
<?php dt_page_footer(); ?>

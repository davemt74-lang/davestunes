<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$user=dt_require_user($pdo);
$artistId=max(0,(int)($_GET['artist']??$_POST['artist_id']??0));
$artist=dt_artist_by_id($pdo,$artistId);
if(!$artist||!dt_artist_can($pdo,$artistId,(int)$user['id'],'view')){http_response_code(404);exit('Artist not found.');}
$canCatalog=dt_artist_can($pdo,$artistId,(int)$user['id'],'catalog');
$error=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        dt_verify_csrf();
        if(!$canCatalog)throw new RuntimeException('Your artist role cannot edit the catalog.');
        $action=(string)($_POST['action']??'');
        if($action==='create_recording'){
            dt_catalog_create_recording($pdo,$artistId,$user,[
                'title'=>$_POST['title']??'',
                'version_label'=>$_POST['version_label']??'',
                'isrc'=>$_POST['isrc']??'',
                'explicit_content'=>!empty($_POST['explicit_content']),
            ]);
        }elseif($action==='create_release'){
            dt_catalog_create_release($pdo,$artistId,$user,[
                'title'=>$_POST['title']??'',
                'release_type'=>$_POST['release_type']??'album',
                'release_date'=>$_POST['release_date']??'',
            ]);
        }elseif($action==='add_track'){
            dt_catalog_add_recording_to_release(
                $pdo,$artistId,(int)($_POST['release_id']??0),(int)($_POST['recording_id']??0),$user,
                (int)($_POST['disc_number']??1),(int)($_POST['track_number']??1)
            );
        }elseif($action==='publish_release'){
            dt_catalog_publish_release($pdo,$artistId,(int)($_POST['release_id']??0),$user);
        }
        dt_redirect('/catalog.php?artist='.$artistId);
    }catch(Throwable $e){$error=$e->getMessage();}
}

$recordings=dt_catalog_recordings($pdo,$artistId);
$releases=dt_catalog_releases($pdo,$artistId);
dt_page_header('Catalog');
?>
<main class="shell">
  <p><a href="/dashboard.php">← Dashboard</a></p>
  <div class="eyebrow">Artist catalog</div>
  <h2><?=dt_e($artist['name'])?></h2>
  <?php dt_form_error($error); ?>
  <section class="grid">
    <article class="card">
      <h2>Recordings / Songs</h2>
      <div class="stack">
        <?php foreach($recordings as $recording):?>
          <div><strong><?=dt_e($recording['title'])?></strong><?php if($recording['version_label']):?> <span class="muted">(<?=dt_e($recording['version_label'])?>)</span><?php endif;?><?php if($recording['isrc']):?> <span class="pill"><?=dt_e($recording['isrc'])?></span><?php endif;?></div>
        <?php endforeach;?>
      </div>
      <?php if($canCatalog):?>
      <hr><form method="post" class="stack"><?=dt_csrf_field()?><input type="hidden" name="artist_id" value="<?=$artistId?>"><input type="hidden" name="action" value="create_recording">
        <label>Song title<input name="title" maxlength="190" required></label>
        <label>Version<input name="version_label" maxlength="120" placeholder="Acoustic, Radio Edit, Demo..."></label>
        <label>ISRC<input name="isrc" maxlength="20" placeholder="USABC2612345"></label>
        <label><span><input style="width:auto" type="checkbox" name="explicit_content" value="1"> Explicit</span></label>
        <button type="submit">Add recording</button>
      </form>
      <?php endif;?>
    </article>

    <article class="card">
      <h2>Releases / Albums</h2>
      <div class="stack">
      <?php foreach($releases as $release):?>
        <div class="artist-card">
          <h3><?=dt_e($release['title'])?></h3>
          <span class="pill"><?=dt_e($release['release_type'])?></span>
          <span class="pill"><?=dt_e($release['release_status'])?></span>
          <span class="pill"><?=$release['track_count']?> tracks</span>
          <?php if($canCatalog&&$release['release_status']!=='published'):?>
          <form method="post" style="margin-top:8px"><?=dt_csrf_field()?><input type="hidden" name="artist_id" value="<?=$artistId?>"><input type="hidden" name="action" value="publish_release"><input type="hidden" name="release_id" value="<?=(int)$release['id']?>"><button type="submit">Publish</button></form>
          <?php endif;?>
        </div>
      <?php endforeach;?>
      </div>
      <?php if($canCatalog):?>
      <hr><form method="post" class="stack"><?=dt_csrf_field()?><input type="hidden" name="artist_id" value="<?=$artistId?>"><input type="hidden" name="action" value="create_release">
        <label>Release title<input name="title" maxlength="190" required></label>
        <label>Type<select name="release_type"><option value="single">Single</option><option value="ep">EP</option><option value="album" selected>Album</option></select></label>
        <label>Release date<input type="date" name="release_date"></label>
        <button type="submit">Create release</button>
      </form>
      <?php endif;?>
    </article>
  </section>

  <?php if($canCatalog&&$recordings&&$releases):?>
  <section class="card" style="margin-top:18px">
    <h2>Attach recording to release</h2>
    <form method="post" class="grid"><?=dt_csrf_field()?><input type="hidden" name="artist_id" value="<?=$artistId?>"><input type="hidden" name="action" value="add_track">
      <label>Release<select name="release_id"><?php foreach($releases as $release):?><option value="<?=(int)$release['id']?>"><?=dt_e($release['title'])?></option><?php endforeach;?></select></label>
      <label>Recording<select name="recording_id"><?php foreach($recordings as $recording):?><option value="<?=(int)$recording['id']?>"><?=dt_e($recording['title'])?></option><?php endforeach;?></select></label>
      <label>Disc<input type="number" name="disc_number" min="1" value="1"></label>
      <label>Track<input type="number" name="track_number" min="1" value="1"></label>
      <div><button type="submit">Add to release</button></div>
    </form>
  </section>
  <?php endif;?>
</main>
<?php dt_page_footer(); ?>

<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$user=dt_require_user($pdo);
$artistId=max(0,(int)($_GET['artist']??$_POST['artist_id']??0));
$recordingId=max(0,(int)($_GET['recording']??$_POST['recording_id']??0));
$artist=dt_artist_by_id($pdo,$artistId);
$recording=dt_catalog_recording($pdo,$artistId,$recordingId);
if(!$artist||!$recording||!dt_artist_can($pdo,$artistId,(int)$user['id'],'media')){http_response_code(404);exit('Recording not found.');}

$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        dt_verify_csrf();
        $role=(string)($_POST['media_role']??'');
        $start=(int)round(((float)($_POST['preview_start_seconds']??0))*1000);
        $end=(int)round(((float)($_POST['preview_end_seconds']??0))*1000);
        dt_playback_store_upload($pdo,$artistId,$recordingId,$user,$_FILES['audio_file']??[],$role,$start,$end);
        dt_redirect('/media-manage.php?artist='.$artistId.'&recording='.$recordingId);
    }catch(Throwable $e){$error=$e->getMessage();}
}
$full=dt_playback_active_media($pdo,$recordingId,'full');
$preview=dt_playback_active_media($pdo,$recordingId,'preview');
dt_page_header('Recording media');
?>
<main class="shell">
  <p><a href="/catalog.php?artist=<?=$artistId?>">← Catalog</a></p>
  <div class="eyebrow">Protected recording media</div>
  <h2><?=dt_e($recording['title'])?></h2>
  <p class="muted">Upload a protected full master and an optional public preview. Replacing either role preserves the previous asset as superseded history.</p>
  <?php dt_form_error($error); ?>

  <section class="grid">
    <article class="card">
      <h2>Current media</h2>
      <div class="stack">
        <div><strong>Full master</strong><br><?php if($full):?><span class="pill"><?=dt_e($full['mime_type'])?></span> <span class="muted"><?=number_format((int)$full['byte_size'])?> bytes · <?=dt_e(substr((string)$full['sha256'],0,12))?>…</span><?php else:?><span class="muted">Not uploaded</span><?php endif;?></div>
        <div><strong>Preview</strong><br><?php if($preview):?><span class="pill"><?=dt_e($preview['mime_type'])?></span> <span class="muted"><?=number_format((int)$preview['byte_size'])?> bytes · <?=dt_e(substr((string)$preview['sha256'],0,12))?>…</span><?php else:?><span class="muted">Not uploaded</span><?php endif;?></div>
      </div>
    </article>

    <article class="card">
      <h2>Upload / replace</h2>
      <form method="post" enctype="multipart/form-data" class="stack">
        <?=dt_csrf_field()?>
        <input type="hidden" name="artist_id" value="<?=$artistId?>">
        <input type="hidden" name="recording_id" value="<?=$recordingId?>">
        <label>Media role<select name="media_role" required><option value="full">Full master</option><option value="preview">Public preview</option></select></label>
        <label>Audio file<input type="file" name="audio_file" accept="audio/*,.mp3,.m4a,.aac,.ogg,.wav,.flac" required></label>
        <div class="grid">
          <label>Preview start (seconds)<input type="number" name="preview_start_seconds" min="0" step="0.1" value="0"></label>
          <label>Preview end (seconds)<input type="number" name="preview_end_seconds" min="0" step="0.1" value="30"></label>
        </div>
        <button type="submit">Store protected audio</button>
      </form>
    </article>
  </section>
</main>
<?php dt_page_footer(); ?>

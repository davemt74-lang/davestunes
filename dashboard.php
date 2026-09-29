<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$pdo=dt_db();
$user=dt_require_user($pdo);
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        dt_verify_csrf();
        if((string)($_POST['action']??'')==='create_artist'){
            dt_artist_create($pdo,$user,(string)($_POST['artist_name']??''));
            dt_redirect('/dashboard.php');
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$artists=dt_artists_for_user($pdo,(int)$user['id']);
dt_page_header('Dashboard');
?>
<main class="shell">
  <div class="eyebrow">Music platform foundation</div>
  <h2>Welcome, <?=dt_e((string)$user['display_name'])?></h2>
  <p class="muted"><?=dt_e((string)$user['email'])?></p>
  <p class="action-row"><a class="button" href="/desktop.php">Open Music Desktop</a><a class="secondary-button button" href="/library.php">Open personal library</a><a class="secondary-button button" href="/experience-studio.php">Experience Studio</a></p>
  <?php dt_form_error($error); ?>
  <section class="grid">
    <article class="card">
      <h2>Your artist identities</h2>
      <?php if(!$artists):?><p class="muted">Create an artist identity to begin publishing music.</p><?php endif;?>
      <div class="stack">
      <?php foreach($artists as $artist):?>
        <div class="artist-card"><h3><?=dt_e($artist['name'])?></h3><span class="pill"><?=dt_e($artist['artist_role'])?></span> <span class="pill">@<?=dt_e($artist['slug'])?></span><p><a href="/catalog.php?artist=<?=(int)$artist['id']?>">Open catalog →</a></p></div>
      <?php endforeach;?>
      </div>
    </article>
    <article class="card">
      <h2>Create artist</h2>
      <p class="muted">Artist identities are separate from login accounts, so one person can manage multiple artists and an artist can have a team.</p>
      <form method="post" class="stack"><?=dt_csrf_field()?><input type="hidden" name="action" value="create_artist">
        <label>Artist name<input name="artist_name" maxlength="190" required></label>
        <button type="submit">Create artist</button>
      </form>
    </article>
  </section>
</main>
<?php dt_page_footer(); ?>

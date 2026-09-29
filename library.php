<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$user=dt_require_user($pdo);
$userId=(int)$user['id'];
$error=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        dt_verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='save_release'){
            dt_library_save_release($pdo,$userId,(int)($_POST['release_id']??0));
        }elseif($action==='unsave_release'){
            dt_library_unsave_release($pdo,$userId,(int)($_POST['release_id']??0));
        }elseif($action==='follow_artist'){
            dt_library_follow_artist($pdo,$userId,(int)($_POST['artist_id']??0));
        }elseif($action==='create_crate'){
            dt_library_create_crate($pdo,$userId,(string)($_POST['crate_name']??''));
        }
        dt_redirect('/library.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$owned=dt_library_owned_releases($pdo,$userId);
$saved=dt_library_saved_releases($pdo,$userId);
$followed=dt_library_followed_artists($pdo,$userId);
$crates=dt_library_crates($pdo,$userId);
$discover=dt_library_published_releases($pdo,30);
$savedIds=array_fill_keys(array_map(static fn(array $row):(int)=>(int)$row['id'],$saved),true);
$followedIds=array_fill_keys(array_map(static fn(array $row):(int)=>(int)$row['id'],$followed),true);

dt_page_header('Library');
?>
<main class="shell">
  <div class="eyebrow">Personal library</div>
  <h2>Your collection</h2>
  <?php dt_form_error($error); ?>

  <section class="grid">
    <article class="card"><h2>Owned</h2>
      <?php if(!$owned):?><p class="muted">Purchased and granted albums will appear here.</p><?php endif;?>
      <div class="stack"><?php foreach($owned as $release):?><div><strong><?=dt_e($release['title'])?></strong> <span class="pill"><?=dt_e($release['release_type'])?></span></div><?php endforeach;?></div>
    </article>

    <article class="card"><h2>Saved</h2>
      <?php if(!$saved):?><p class="muted">Save albums from Discover to keep them close.</p><?php endif;?>
      <div class="stack"><?php foreach($saved as $release):?><div><strong><?=dt_e($release['title'])?></strong><div class="muted"><?=dt_e($release['artist_name'])?></div></div><?php endforeach;?></div>
    </article>

    <article class="card"><h2>Following</h2>
      <?php if(!$followed):?><p class="muted">Artists you follow will appear here.</p><?php endif;?>
      <div class="stack"><?php foreach($followed as $artist):?><div><strong><?=dt_e($artist['name'])?></strong> <span class="pill">@<?=dt_e($artist['slug'])?></span></div><?php endforeach;?></div>
    </article>

    <article class="card"><h2>Crates</h2>
      <div class="stack"><?php foreach($crates as $crate):?><div><strong><?=dt_e($crate['crate_name'])?></strong> <span class="pill"><?=(int)$crate['item_count']?> items</span></div><?php endforeach;?></div>
      <hr><form method="post" class="stack"><?=dt_csrf_field()?><input type="hidden" name="action" value="create_crate"><label>New crate<input name="crate_name" maxlength="120" required placeholder="Late Night, Favorites, 90s..."></label><button type="submit">Create crate</button></form>
    </article>
  </section>

  <section style="margin-top:32px">
    <div class="eyebrow">Discover</div>
    <h2>Published releases</h2>
    <div class="grid">
      <?php foreach($discover as $release):?>
      <article class="card artist-card">
        <h3><?=dt_e($release['title'])?></h3>
        <p class="muted"><?=dt_e($release['artist_name'])?> · <?=dt_e($release['release_type'])?></p>
        <div class="action-row">
          <form method="post"><?=dt_csrf_field()?><input type="hidden" name="release_id" value="<?=(int)$release['id']?>"><input type="hidden" name="action" value="<?=isset($savedIds[(int)$release['id']])?'unsave_release':'save_release'?>"><button type="submit"><?=isset($savedIds[(int)$release['id']])?'Saved ✓':'Save'?></button></form>
          <?php if(!isset($followedIds[(int)$release['artist_id']])):?><form method="post"><?=dt_csrf_field()?><input type="hidden" name="artist_id" value="<?=(int)$release['artist_id']?>"><input type="hidden" name="action" value="follow_artist"><button class="secondary-button" type="submit">Follow artist</button></form><?php endif;?>
        </div>
      </article>
      <?php endforeach;?>
    </div>
  </section>
</main>
<?php dt_page_footer(); ?>

<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$user=dt_require_admin($pdo);
$error=null;
$notice=null;

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        dt_verify_csrf();
        $action=(string)($_POST['action']??'save');
        if($action==='delete'){
            dt_featured_delete($pdo,$user,(int)($_POST['id']??0));
            $notice='Featured post deleted.';
        }else{
            dt_featured_save($pdo,$user,[
                'content_type'=>$_POST['content_type']??'',
                'content_id'=>$_POST['content_id']??0,
                'headline'=>$_POST['headline']??'',
                'body_text'=>$_POST['body_text']??'',
                'post_status'=>$_POST['post_status']??'draft',
                'priority'=>$_POST['priority']??0,
                'starts_at'=>$_POST['starts_at']??'',
                'ends_at'=>$_POST['ends_at']??'',
            ],(int)($_POST['id']??0)?:null);
            $notice='Featured post saved.';
        }
    }catch(RuntimeException $e){$error=$e->getMessage();}
}

$options=dt_featured_catalog_options($pdo);
$rows=dt_featured_admin_rows($pdo);

dt_page_header('Admin · Featured Content');
?>
<main class="page narrow">
  <div class="page-heading">
    <div><span class="eyebrow">Admin</span><h1>Featured Content</h1><p>Curate albums and songs for the listener Desktop without changing anyone’s saved Desktop layout.</p></div>
    <a class="button secondary" href="/desktop.php">Open Desktop</a>
  </div>
  <?php dt_form_error($error); ?>
  <?php if($notice): ?><div class="notice success"><?=dt_e($notice)?></div><?php endif; ?>

  <section class="panel">
    <h2>Create featured post</h2>
    <form method="post" class="form-grid">
      <?=dt_csrf_field()?>
      <input type="hidden" name="action" value="save">
      <label>Content type
        <select name="content_type" required>
          <option value="release">Album / release</option>
          <option value="recording">Song / recording</option>
        </select>
      </label>
      <label>Content ID
        <input type="number" name="content_id" min="1" required placeholder="Release or recording ID">
      </label>
      <label class="wide">Headline
        <input type="text" name="headline" maxlength="190" placeholder="Featured this week">
      </label>
      <label class="wide">Description
        <textarea name="body_text" maxlength="500" rows="3" placeholder="Short discovery copy"></textarea>
      </label>
      <label>Status
        <select name="post_status"><option value="draft">Draft</option><option value="active">Active</option><option value="expired">Expired</option></select>
      </label>
      <label>Priority
        <input type="number" name="priority" min="-10000" max="10000" value="0">
      </label>
      <label>Starts at
        <input type="datetime-local" name="starts_at">
      </label>
      <label>Ends at
        <input type="datetime-local" name="ends_at">
      </label>
      <button class="button" type="submit">Create featured post</button>
    </form>
  </section>

  <section class="panel">
    <h2>Published catalog reference</h2>
    <details><summary>Albums (<?=count($options['releases'])?>)</summary>
      <div class="table-wrap"><table><thead><tr><th>ID</th><th>Artist</th><th>Album</th></tr></thead><tbody>
      <?php foreach($options['releases'] as $item): ?><tr><td><?=dt_e((string)$item['id'])?></td><td><?=dt_e((string)$item['artist_name'])?></td><td><?=dt_e((string)$item['title'])?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </details>
    <details><summary>Songs (<?=count($options['recordings'])?>)</summary>
      <div class="table-wrap"><table><thead><tr><th>ID</th><th>Artist</th><th>Song</th></tr></thead><tbody>
      <?php foreach($options['recordings'] as $item): ?><tr><td><?=dt_e((string)$item['id'])?></td><td><?=dt_e((string)$item['artist_name'])?></td><td><?=dt_e((string)$item['title'])?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </details>
  </section>

  <section class="panel">
    <h2>Featured posts</h2>
    <?php if(!$rows): ?><p>No featured posts yet.</p><?php endif; ?>
    <?php foreach($rows as $row): ?>
      <form method="post" class="featured-admin-row">
        <?=dt_csrf_field()?>
        <input type="hidden" name="id" value="<?=dt_e((string)$row['id'])?>">
        <input type="hidden" name="action" value="save">
        <div><strong><?=dt_e((string)$row['content_title'])?></strong><span><?=dt_e((string)$row['artist_name'])?> · <?=dt_e((string)$row['content_type'])?> #<?=dt_e((string)$row['content_id'])?></span></div>
        <label>Headline<input name="headline" maxlength="190" value="<?=dt_e((string)$row['headline'])?>"></label>
        <label>Description<textarea name="body_text" maxlength="500" rows="2"><?=dt_e((string)$row['body_text'])?></textarea></label>
        <input type="hidden" name="content_type" value="<?=dt_e((string)$row['content_type'])?>">
        <input type="hidden" name="content_id" value="<?=dt_e((string)$row['content_id'])?>">
        <label>Status<select name="post_status">
          <?php foreach(['draft','active','expired'] as $status): ?><option value="<?=$status?>" <?=$row['post_status']===$status?'selected':''?>><?=ucfirst($status)?></option><?php endforeach; ?>
        </select></label>
        <label>Priority<input type="number" name="priority" min="-10000" max="10000" value="<?=dt_e((string)$row['priority'])?>"></label>
        <label>Starts<input type="datetime-local" name="starts_at" value="<?=dt_e($row['starts_at']?str_replace(' ','T',substr((string)$row['starts_at'],0,16)):'')?>"></label>
        <label>Ends<input type="datetime-local" name="ends_at" value="<?=dt_e($row['ends_at']?str_replace(' ','T',substr((string)$row['ends_at'],0,16)):'')?>"></label>
        <div class="actions"><button class="button small" type="submit">Save</button>
      </form>
      <form method="post" onsubmit="return confirm('Delete this featured post?')">
        <?=dt_csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=dt_e((string)$row['id'])?>">
        <button class="button small secondary" type="submit">Delete</button>
      </form></div>
    <?php endforeach; ?>
  </section>
</main>
<style>
.featured-admin-row{display:grid;grid-template-columns:1.1fr 1.2fr 1.4fr .7fr .5fr .8fr .8fr auto;gap:10px;align-items:end;padding:14px 0;border-bottom:1px solid rgba(255,255,255,.1)}.featured-admin-row>div:first-child{display:grid;gap:3px}.featured-admin-row span{font-size:12px;opacity:.62}.featured-admin-row label{display:grid;gap:5px;font-size:11px}.featured-admin-row input,.featured-admin-row select,.featured-admin-row textarea{width:100%}.featured-admin-row .actions{display:flex;gap:6px}@media(max-width:1000px){.featured-admin-row{grid-template-columns:1fr 1fr}.featured-admin-row .actions{grid-column:1/-1}}
</style>
<?php dt_page_footer(); ?>

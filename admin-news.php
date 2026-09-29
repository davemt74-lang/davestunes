<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=dt_db();
$user=dt_require_admin($pdo);
dt_featured_ensure_schema($pdo);
$error=null;$notice=null;

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        dt_verify_csrf();
        $action=(string)($_POST['action']??'save');
        if($action==='delete'){
            dt_news_delete($pdo,$user,(int)($_POST['id']??0));
            $notice='News post deleted.';
        }else{
            dt_news_save($pdo,$user,[
                'headline'=>$_POST['headline']??'',
                'body_text'=>$_POST['body_text']??'',
                'image_url'=>$_POST['image_url']??'',
                'link_url'=>$_POST['link_url']??'',
                'link_label'=>$_POST['link_label']??'',
                'placement'=>$_POST['placement']??'public-desktop',
                'post_status'=>$_POST['post_status']??'draft',
                'priority'=>$_POST['priority']??0,
                'starts_at'=>$_POST['starts_at']??'',
                'ends_at'=>$_POST['ends_at']??'',
            ],(int)($_POST['id']??0)?:null);
            $notice='News post saved.';
        }
    }catch(RuntimeException $e){$error=$e->getMessage();}
}
$rows=dt_news_admin_rows($pdo);
dt_page_header('Admin · News & Notes');
?>
<main class="page narrow">
  <div class="page-heading">
    <div><span class="eyebrow">Admin</span><h1>News & Notes</h1><p>Publish editorial updates directly to the public or signed-in Music Desktop.</p></div>
    <div class="action-row"><a class="button secondary" href="/admin-featured.php">Featured</a><a class="button secondary" href="/">Public Desktop</a></div>
  </div>
  <?php dt_form_error($error); ?>
  <?php if($notice):?><div class="notice success"><?=dt_e($notice)?></div><?php endif;?>

  <section class="panel">
    <h2>Create post</h2>
    <form method="post" class="form-grid">
      <?=dt_csrf_field()?><input type="hidden" name="action" value="save">
      <label class="wide">Headline<input name="headline" maxlength="190" required placeholder="What’s happening at Dave's Tunes"></label>
      <label class="wide">Body<textarea name="body_text" maxlength="12000" rows="6" required placeholder="Write the update…"></textarea></label>
      <label class="wide">Image URL<input name="image_url" maxlength="500" placeholder="/uploads/news/image.jpg or https://…"></label>
      <label>Link URL<input name="link_url" maxlength="500" placeholder="/album.php?release=…"></label>
      <label>Link label<input name="link_label" maxlength="80" placeholder="Read more"></label>
      <label>Placement<select name="placement"><option value="public-desktop">Public Desktop</option><option value="signed-in-desktop">Signed-in Desktop</option><option value="both">Both</option></select></label>
      <label>Status<select name="post_status"><option value="draft">Draft</option><option value="active">Active</option><option value="expired">Expired</option></select></label>
      <label>Priority<input type="number" name="priority" min="-10000" max="10000" value="0"></label>
      <label>Starts at<input type="datetime-local" name="starts_at"></label>
      <label>Ends at<input type="datetime-local" name="ends_at"></label>
      <button class="button" type="submit">Create post</button>
    </form>
  </section>

  <section class="panel">
    <h2>Posts</h2>
    <?php if(!$rows):?><p>No News & Notes posts yet.</p><?php endif;?>
    <?php foreach($rows as $row):?>
      <form method="post" class="news-admin-row">
        <?=dt_csrf_field()?><input type="hidden" name="id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="save">
        <div class="news-admin-copy"><strong><?=dt_e((string)$row['headline'])?></strong><span>/news.php?slug=<?=dt_e((string)$row['slug'])?></span></div>
        <label>Headline<input name="headline" maxlength="190" required value="<?=dt_e((string)$row['headline'])?>"></label>
        <label class="wide">Body<textarea name="body_text" maxlength="12000" rows="3" required><?=dt_e((string)$row['body_text'])?></textarea></label>
        <label>Image URL<input name="image_url" maxlength="500" value="<?=dt_e((string)$row['image_url'])?>"></label>
        <label>Link URL<input name="link_url" maxlength="500" value="<?=dt_e((string)$row['link_url'])?>"></label>
        <label>Link label<input name="link_label" maxlength="80" value="<?=dt_e((string)$row['link_label'])?>"></label>
        <label>Placement<select name="placement"><?php foreach(['public-desktop'=>'Public Desktop','signed-in-desktop'=>'Signed-in Desktop','both'=>'Both'] as $v=>$label):?><option value="<?=$v?>" <?=$row['placement']===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
        <label>Status<select name="post_status"><?php foreach(['draft','active','expired'] as $status):?><option value="<?=$status?>" <?=$row['post_status']===$status?'selected':''?>><?=ucfirst($status)?></option><?php endforeach;?></select></label>
        <label>Priority<input type="number" name="priority" min="-10000" max="10000" value="<?=(int)$row['priority']?>"></label>
        <label>Starts<input type="datetime-local" name="starts_at" value="<?=dt_e($row['starts_at']?str_replace(' ','T',substr((string)$row['starts_at'],0,16)):'')?>"></label>
        <label>Ends<input type="datetime-local" name="ends_at" value="<?=dt_e($row['ends_at']?str_replace(' ','T',substr((string)$row['ends_at'],0,16)):'')?>"></label>
        <div class="actions"><button class="button small">Save</button><button class="button small secondary" type="submit" name="action" value="delete" onclick="return confirm('Delete this news post?')">Delete</button></div>
      </form>
    <?php endforeach;?>
  </section>
</main>
<style>
.news-admin-row{display:grid;grid-template-columns:1fr 1.2fr 1.5fr;gap:10px;padding:16px 0;border-bottom:1px solid rgba(255,255,255,.1)}.news-admin-row label{display:grid;gap:5px;font-size:11px}.news-admin-row input,.news-admin-row select,.news-admin-row textarea{width:100%}.news-admin-copy{display:grid;gap:3px}.news-admin-copy span{font-size:11px;opacity:.5}.news-admin-row .wide{grid-column:span 2}.news-admin-row .actions{display:flex;align-items:end;gap:6px}@media(max-width:800px){.news-admin-row{grid-template-columns:1fr}.news-admin-row .wide{grid-column:auto}}
</style>
<?php dt_page_footer(); ?>

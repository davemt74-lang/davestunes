<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$pdo=dt_db();
$user=dt_require_user($pdo);

$ownerType=(string)($_GET['owner_type']??'user');
$ownerId=(int)($_GET['owner_id']??($ownerType==='user'?(int)$user['id']:0));
$key=(string)($_GET['key']??'default');
$experience=dt_experience_find_for_owner($pdo,$ownerType,$ownerId,$key);
$ownerContext=dt_experience_owner_context($pdo,$ownerType,$ownerId);
$error=null;

if(!$experience&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  try{
    dt_verify_csrf();
    $created=dt_experience_create($pdo,$user,$ownerType,$ownerId,(string)($_POST['name']??'Untitled Experience'),$key);
    $experience=$created['experience'];
    dt_redirect('/experience-studio.php?experience_id='.(int)$experience['id']);
  }catch(Throwable $e){$error=$e->getMessage();}
}
if(isset($_GET['experience_id'])){
  $experience=dt_experience_row($pdo,(int)$_GET['experience_id']);
  if($experience)dt_experience_require_owner($pdo,(string)$experience['owner_type'],(int)$experience['owner_id'],$user);
}
dt_page_header('Experience Studio');
?>
<main class="experience-studio-page">
  <header class="experience-studio-header">
    <div>
      <span class="eyebrow">Music Desktop V1</span>
      <h1><?=dt_e((string)($ownerContext['title']??'Experience Studio'))?></h1>
      <p><?=dt_e((string)($ownerContext['subtitle']??'Build scenes, arrange the film-strip, connect flow nodes, preview, and publish one canonical experience graph.'))?></p>
    </div>
    <div class="action-row">
      <?php if(($ownerContext['back_url']??'')!==''):?><a class="button secondary" href="<?=dt_e((string)$ownerContext['back_url'])?>">Back to Album</a><?php endif;?>
      <a class="button secondary" href="/desktop.php">Desktop</a>
      <a class="button secondary" href="/dashboard.php">Dashboard</a>
    </div>
  </header>
  <?php dt_form_error($error); ?>

  <?php if(!$experience): ?>
    <section class="panel studio-empty-state">
      <h2>Create this experience</h2>
      <p>This owner does not have a <code><?=dt_e($key)?></code> experience yet.</p>
      <form method="post" class="stack">
        <?=dt_csrf_field()?>
        <label>Name<input name="name" maxlength="190" value="<?=dt_e((string)($ownerContext['experience_name']??'My Experience'))?>" required></label>
        <button type="submit">Create Experience</button>
      </form>
    </section>
  <?php else: ?>
    <section class="experience-studio-shell" data-studio data-experience-id="<?=(int)$experience['id']?>">
      <div class="studio-toolbar">
        <div><strong><?=dt_e((string)$experience['name'])?></strong><span data-studio-version></span></div>
        <div class="studio-toolbar-actions">
          <button type="button" data-studio-action="add-scene">+ Scene</button>
          <button type="button" data-studio-action="add-node">+ Node</button>
          <button type="button" data-studio-action="connect">+ Connect</button>
          <button type="button" data-studio-action="effect-preset">+ Effect</button>
          <button type="button" data-studio-action="preview">Preview</button>
          <button type="button" class="primary" data-studio-action="publish">Publish</button>
        </div>
      </div>

      <div class="studio-filmstrip" data-studio-filmstrip aria-label="Scene navigator"></div>

      <div class="studio-workspace">
        <section class="studio-canvas-panel">
          <header><span>Flow Builder</span><span data-studio-flow-status></span></header>
          <div class="studio-flow-canvas" data-studio-canvas>
            <svg class="studio-edge-layer" data-studio-edges aria-hidden="true"></svg>
            <div class="studio-node-layer" data-studio-nodes></div>
          </div>
        </section>
        <aside class="studio-inspector" data-studio-inspector>
          <h2>Inspector</h2>
          <p>Select a scene or node to edit it.</p>
        </aside>
      </div>

      <section class="studio-preview" data-studio-preview hidden>
        <div class="studio-preview-toolbar">
          <strong>Preview</strong>
          <button type="button" data-studio-preview-close>Close</button>
        </div>
        <div class="studio-preview-stage" data-studio-preview-stage></div>
      </section>
    </section>
    <script>
      window.DaveTunesStudioBoot={
        experienceId:<?=(int)$experience['id']?>,
        csrf:<?=json_encode(dt_csrf_token(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>
      };
    </script>
    <script src="/assets/desktop/experience-effects.js"></script>
    <script src="/assets/desktop/module-experience-runtime.js"></script>
    <script src="/assets/experience-studio.js"></script>
  <?php endif; ?>
</main>
<?php dt_page_footer(); ?>

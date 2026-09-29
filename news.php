<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$pdo=dt_db();
dt_featured_ensure_schema($pdo);
$slug=trim((string)($_GET['slug']??''));
$stmt=$pdo->prepare("SELECT * FROM news_posts_v110 WHERE slug=? AND post_status='active' AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>NOW()) LIMIT 1");
$stmt->execute([$slug]);
$post=$stmt->fetch();
if(!$post){http_response_code(404);exit('News post not found.');}
dt_page_header((string)$post['headline']);
?>
<main class="page narrow news-article">
  <article class="panel">
    <div class="eyebrow">News & Notes</div>
    <h1><?=dt_e((string)$post['headline'])?></h1>
    <?php if((string)$post['image_url']!==''):?><img class="news-article-image" src="<?=dt_e((string)$post['image_url'])?>" alt=""><?php endif;?>
    <div class="news-article-body"><?=nl2br(dt_e((string)$post['body_text']))?></div>
    <?php if((string)$post['link_url']!==''):?><p><a class="button" href="<?=dt_e((string)$post['link_url'])?>"><?=dt_e((string)($post['link_label']!==''?$post['link_label']:'Open'))?></a></p><?php endif;?>
  </article>
</main>
<?php dt_page_footer(); ?>

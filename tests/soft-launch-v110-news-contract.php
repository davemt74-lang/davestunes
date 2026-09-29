<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$schema=file_get_contents($root.'/includes/featured-schema-v270.php');
$service=file_get_contents($root.'/includes/featured-v270.php');
$admin=file_get_contents($root.'/admin-news.php');
$article=file_get_contents($root.'/news.php');
$endpoint=file_get_contents($root.'/desktop-featured.php');
$module=file_get_contents($root.'/assets/desktop/module-featured-content.js');
$index=file_get_contents($root.'/index.php');
$view=file_get_contents($root.'/includes/view.php');
$workflow=file_get_contents($root.'/.github/workflows/foundation-v1.yml');

foreach(compact('schema','service','admin','article','endpoint','module','index','view','workflow') as $name=>$content){
    if($content===false)throw new RuntimeException('Could not load '.$name.'.');
}

foreach([
    'news_posts_v110',
    'news_post_events_v110',
    'uniq_news_slug_v110',
    'idx_news_active_v110',
] as $needle){
    if(!str_contains($schema,$needle))throw new RuntimeException('News schema missing '.$needle.'.');
}

foreach([
    'function dt_news_save',
    'function dt_news_delete',
    'function dt_news_admin_rows',
    'function dt_news_active',
    'function dt_news_unique_slug',
    'function dt_news_url',
    'news.created',
    'news.updated',
    'news.deleted',
] as $needle){
    if(!str_contains($service,$needle))throw new RuntimeException('News service missing '.$needle.'.');
}
if(!str_contains($service,"['http','https']"))throw new RuntimeException('News URL validation must restrict external schemes.');
if(!str_contains($service,"placement='both'")&&!str_contains($service,"placement=? OR placement='both'"))throw new RuntimeException('News active projection must support both placement.');
if(!str_contains($service,"mb_strlen($body)>420"))throw new RuntimeException('Desktop news projection must bound article excerpts.');
if(!str_contains($service,"dt_news_active($pdo,'public-desktop'"))throw new RuntimeException('Public News & Notes must prefer canonical CMS posts.');

foreach(['News & Notes','image_url','link_url','link_label','placement','starts_at','ends_at','post_status','priority'] as $needle){
    if(!str_contains($admin,$needle))throw new RuntimeException('Admin News CMS missing '.$needle.'.');
}
if(!str_contains($admin,'dt_require_admin'))throw new RuntimeException('Admin News CMS must require administrator authority.');
if(!str_contains($admin,'dt_verify_csrf'))throw new RuntimeException('Admin News CMS must enforce CSRF.');

if(!str_contains($article,"post_status='active'"))throw new RuntimeException('Public article page must only serve active posts.');
if(!str_contains($article,"placement']==='signed-in-desktop'&&!$user"))throw new RuntimeException('Signed-in-only articles must not be exposed publicly.');
if(!str_contains($article,'nl2br(dt_e'))throw new RuntimeException('Article body must be escaped before rendering.');

if(!str_contains($endpoint,"'news'=>dt_news_active($pdo,'signed-in-desktop',5)"))throw new RuntimeException('Signed-in Desktop endpoint must project News & Notes.');
if(!str_contains($module,'renderNewsItem'))throw new RuntimeException('Featured Desktop module must render news cards.');
if(!str_contains($module,'featured-news-rail'))throw new RuntimeException('Signed-in Desktop news rail is missing.');

if(!str_contains($index,'News & Notes'))throw new RuntimeException('Public Desktop News & Notes section is missing.');
if(!str_contains($view,'/admin-news.php'))throw new RuntimeException('Admin navigation must expose News & Notes.');

if(!str_contains($workflow,'php tests/soft-launch-v110-news-contract.php'))throw new RuntimeException('News contract gate is not wired into CI.');
if(!str_contains($workflow,'php tests/soft-launch-v110-news-mysql.php'))throw new RuntimeException('News MySQL gate is not wired into CI.');

echo "SOFT_LAUNCH_V110_NEWS_CONTRACT=PASS\n";

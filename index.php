<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
dt_page_header('Home');
?>
<main class="shell">
  <section class="hero">
    <div class="eyebrow">A spatial music library</div>
    <h1>Your music should feel like yours.</h1>
    <p class="lede">Build a personal record collection, support artists directly, and explore albums through a living desktop, digital turntable, and interactive scenes.</p>
    <p><a class="button" href="<?=dt_current_user()?'/dashboard.php':'/signup.php'?>"><?=dt_current_user()?'Open your library':'Create your account'?></a></p>
  </section>
</main>
<?php dt_page_footer(); ?>

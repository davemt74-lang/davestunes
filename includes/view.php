<?php
declare(strict_types=1);

function dt_page_header(string $title): void
{
    $app=dt_e((string)dt_config('app.name',"Dave's Tunes"));
    $title=dt_e($title);
    $user=dt_current_user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.$title.' · '.$app.'</title><link rel="stylesheet" href="/assets/app.css"></head><body>';
    echo '<header class="site-header"><a class="brand" href="/">Dave\'s Tunes</a><nav>';
    if($user){
        echo '<a href="/dashboard.php">Dashboard</a>';
        echo '<form class="nav-form" method="post" action="/logout.php">'.dt_csrf_field().'<button class="nav-link" type="submit">Sign out</button></form>';
    }else{
        echo '<a href="/login.php">Sign in</a><a class="button small" href="/signup.php">Create account</a>';
    }
    echo '</nav></header>';
}

function dt_page_footer(): void
{
    echo '<footer>Dave\'s Tunes · Music belongs in a place that feels alive.</footer></body></html>';
}

function dt_form_error(?string $error): void
{
    if($error!==null&&$error!=='')echo '<div class="notice error" role="alert">'.dt_e($error).'</div>';
}

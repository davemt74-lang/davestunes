<?php
declare(strict_types=1);

function dt_page_header(string $title): void
{
    $app=dt_e((string)dt_config('app.name',"Dave's Tunes"));
    $title=dt_e($title);
    $user=dt_current_user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.$title.' · '.$app.'</title>';
    if($user)echo '<meta name="csrf-token" content="'.dt_e(dt_csrf_token()).'">';
    echo '<link rel="stylesheet" href="/assets/app.css"></head><body>';
    echo '<header class="site-header"><a class="brand" href="/">Dave\'s Tunes</a><nav>';
    if($user){
        echo '<a href="/dashboard.php">Dashboard</a><a href="/library.php">Library</a>';
        echo '<form class="nav-form" method="post" action="/logout.php">'.dt_csrf_field().'<button class="nav-link" type="submit">Sign out</button></form>';
    }else{
        echo '<a href="/login.php">Sign in</a><a class="button small" href="/signup.php">Create account</a>';
    }
    echo '</nav></header>';
}

function dt_player_dock(): void
{
    if(!dt_current_user())return;
    echo '<aside id="dt-player-dock" class="player-dock" hidden aria-label="Now playing">';
    echo '<div class="player-copy"><strong data-player-title>Nothing playing</strong><span data-player-artist></span></div>';
    echo '<button type="button" class="player-icon" data-player-action="previous" aria-label="Previous">‹</button>';
    echo '<button type="button" class="player-toggle" data-player-action="toggle">Play</button>';
    echo '<button type="button" class="player-icon" data-player-action="next" aria-label="Next">›</button>';
    echo '<input class="player-progress" data-player-progress type="range" min="0" max="1" step="0.1" value="0" aria-label="Playback position">';
    echo '</aside><script src="/assets/player.js" defer></script>';
}

function dt_page_footer(): void
{
    echo '<footer>Dave\'s Tunes · Music belongs in a place that feels alive.</footer>';
    dt_player_dock();
    echo '</body></html>';
}

function dt_form_error(?string $error): void
{
    if($error!==null&&$error!=='')echo '<div class="notice error" role="alert">'.dt_e($error).'</div>';
}

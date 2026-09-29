<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if(dt_current_user())dt_redirect('/dashboard.php');
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        dt_verify_csrf();
        $user=dt_auth_attempt_login(dt_db(),(string)($_POST['email']??''),(string)($_POST['password']??''));
        if(!$user)throw new RuntimeException('Email or password was not recognized.');
        dt_auth_begin_session($user);
        dt_redirect('/dashboard.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}
dt_page_header('Sign in');
?>
<main class="shell"><section class="card auth-card"><div class="eyebrow">Welcome back</div><h2>Sign in</h2>
<?php dt_form_error($error); ?>
<form method="post" class="stack"><?=dt_csrf_field()?>
<label>Email<input type="email" name="email" maxlength="254" required autocomplete="email"></label>
<label>Password<input type="password" name="password" required autocomplete="current-password"></label>
<button type="submit">Sign in</button>
</form><p class="muted">Need an account? <a href="/signup.php">Create one</a>.</p></section></main>
<?php dt_page_footer(); ?>

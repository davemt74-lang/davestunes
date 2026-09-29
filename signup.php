<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if(dt_current_user())dt_redirect('/dashboard.php');
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        dt_verify_csrf();
        $user=dt_auth_register(dt_db(),(string)($_POST['email']??''),(string)($_POST['password']??''),(string)($_POST['display_name']??''));
        dt_auth_begin_session($user);
        dt_redirect('/dashboard.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}
dt_page_header('Create account');
?>
<main class="shell"><section class="card auth-card"><div class="eyebrow">Join Dave's Tunes</div><h2>Create your account</h2>
<?php dt_form_error($error); ?>
<form method="post" class="stack"><?=dt_csrf_field()?>
<label>Display name<input name="display_name" maxlength="120" required autocomplete="name"></label>
<label>Email<input type="email" name="email" maxlength="254" required autocomplete="email"></label>
<label>Password<input type="password" name="password" minlength="12" required autocomplete="new-password"><span class="muted">Use at least 12 characters.</span></label>
<button type="submit">Create account</button>
</form><p class="muted">Already a member? <a href="/login.php">Sign in</a>.</p></section></main>
<?php dt_page_footer(); ?>
